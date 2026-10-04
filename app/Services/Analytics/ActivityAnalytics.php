<?php

namespace App\Services\Analytics;

use App\Models\Habit;
use App\Models\HabitEntry;
use App\Models\User;
use App\Services\SocialAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class ActivityAnalytics
{
    private const COUNTERS = ['sum', 'count', 'positive', 'failures', 'na', 'pending', 'unavailable', 'unlogged'];

    public function __construct(private HabitScore $scoring, private StreakCalculator $streaks) {}

    public static function day(string $date): int
    {
        return (int) floor(CarbonImmutable::parse($date, 'UTC')->getTimestamp() / 86400);
    }

    public static function date(int $day): string
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400)->toDateString();
    }

    public function report(User $user, int $year, ?int $habitId = null): array
    {
        return $this->buildReport($user, $year, $habitId);
    }

    public function reportForViewer(User $viewer, User $subject, int $year, ?int $habitId = null): array
    {
        return $this->buildReport($subject, $year, $habitId, $viewer);
    }

    private function buildReport(User $user, int $year, ?int $habitId, ?User $viewer = null): array
    {
        // A single repeatable-read snapshot includes timezone, ownership, history and entries.
        // No locking reads: analytics must not block daily recording.
        return DB::transaction(function () use ($user, $year, $habitId, $viewer) {
            $user = User::query()->select(['id', 'timezone', 'username', 'is_private'])->findOrFail($user->id);
            if ($viewer !== null) {
                $viewer = User::findOrFail($viewer->id);
                abort_unless(Gate::forUser($viewer)->allows('viewShared', $user), 404);
            }
            $today = $user->localToday();
            Validator::make(['year' => $year], ['year' => ['integer', 'min:1000', 'max:'.substr($today, 0, 4)]])->validate();
            $last = self::day($today);
            $first = self::day(sprintf('%04d-01-01', $year));
            $yearEnd = self::day(sprintf('%04d-12-31', $year));
            $minimum = self::day('1000-01-01');
            $recentStart = max($minimum, $last - 29);
            $events = [];
            $details = [];
            $habitCount = 0;
            $lifecycle = null;
            $query = ($viewer === null ? $user->habits() : app(SocialAccess::class)->habits($viewer, $user))->select(['id', 'user_id', 'archived_at', 'deleted_at']);
            if ($habitId !== null) {
                $query->whereKey($habitId);
                $habit = (clone $query)->firstOrFail();
                Gate::forUser($viewer ?? $user)->authorize($viewer === null ? 'view' : 'viewShared', $habit);
            } elseif ($viewer === null) {
                Gate::forUser($user)->authorize('viewAny', Habit::class);
            }
            $query->with(['versions' => fn ($q) => $q->orderBy('effective_date')->with('checkpoints:id,habit_tracking_version_id'), 'suspensions'])
                ->chunkById(50, function ($habits) use (&$events, &$details, &$habitCount, &$lifecycle, $habitId, $user, $last, $minimum, $first, $yearEnd) {
                    $contexts = [];
                    foreach ($habits as $habit) {
                        $habitCount++;
                        $versions = $habit->versions->keyBy('id');
                        $ranges = $this->ranges($habit, $last);
                        $contexts[$habit->id] = compact('habit', 'versions', 'ranges');
                        if ($versions->isEmpty()) {
                            $this->add($events, $minimum, $last, $this->scoring->state('unavailable'));
                        } else {
                            foreach ($ranges as [$start, $end]) {
                                $this->add($events, $start, min($end, $last - 1), $this->scoring->state('unlogged'));
                                if ($end === $last) {
                                    $this->add($events, $last, $last, $this->scoring->state('pending'));
                                }
                            }
                        }
                        if ($habitId !== null) {
                            $current = $habit->versions->last(fn ($v) => $v->effective_date->toDateString() <= self::date($last));
                            $lifecycle = $habit->archived_at ? 'Archived — streak paused across inactive dates' : ($current?->end_date && $current->end_date->toDateString() < self::date($last) ? 'Ended — last eligible streak retained' : null);
                        }
                    }
                    // Stream lifetime entries: only event boundaries and visible-year details survive a batch.
                    HabitEntry::query()
                        ->where('user_id', $user->id)->whereIn('habit_id', $habits->modelKeys())
                        ->where('entry_date', '<=', self::date($last))
                        ->select(['id', 'habit_id', 'habit_tracking_version_id', 'entry_date', 'status', 'value'])
                        ->with(['checkpoints:id,habit_entry_id,habit_tracking_checkpoint_id,is_completed'])
                        ->chunkById(500, function ($entries) use (&$events, &$details, $contexts, $last, $first, $yearEnd, $habitId) {
                            foreach ($entries as $entry) {
                                $day = self::day($entry->entry_date->toDateString());
                                $context = $contexts[$entry->habit_id];
                                $base = $context['versions']->isEmpty() ? $this->scoring->state('unavailable') : $this->scoring->state($this->contains($context['ranges'], $day) ? ($day === $last ? 'pending' : 'unlogged') : 'inactive');
                                $version = $context['versions']->get($entry->habit_tracking_version_id);
                                $result = $this->entryScore($entry, $version, $context['habit']);
                                $this->add($events, $day, $day, $base, -1);
                                $this->add($events, $day, $day, $result);
                                if ($habitId !== null && $day >= $first && $day <= $yearEnd) {
                                    $details[$day] = ['assessment' => $entry->status->label(), 'actual' => $entry->value, 'type' => $version?->type->label(), 'goal' => $version?->goal_value, 'unit' => $version?->goal_unit];
                                }
                            }
                        });
                });
            $segments = $this->segments($events, $minimum, $last);
            $streaks = $this->streaks->calculate($segments);
            $cells = [];
            $index = 0;
            for ($day = $first; $day <= $yearEnd; $day++) {
                while (isset($segments[$index + 1]) && $segments[$index]['end'] < $day) {
                    $index++;
                }
                $counts = $day <= $last ? $segments[$index] : $this->emptyCounts();
                $cells[] = $this->cell($day, $counts, $day > $last, $habitId, $viewer !== null) + ($details[$day] ?? []);
            }

            return [
                'year' => $year, 'today' => $today, 'timezone' => $user->timezone,
                'cells' => $cells, 'streaks' => $streaks, 'habit_count' => $habitCount, 'lifecycle' => $lifecycle,
                'yearly' => $this->consistency($segments, $first, min($yearEnd, $last)),
                'recent' => $this->consistency($segments, $recentStart, $last),
                'recent_start' => self::date($recentStart),
                'offset' => CarbonImmutable::parse(sprintf('%04d-01-01', $year), 'UTC')->dayOfWeekIso - 1,
            ];
        });
    }

    private function entryScore($entry, $version, Habit $habit): array
    {
        if (! $version || $version->habit_id !== $habit->id || $entry->entry_date < $version->effective_date) {
            return $this->scoring->state('unavailable');
        }
        $date = $entry->entry_date->toDateString();
        if ($date < $version->start_date->toDateString() || ($version->end_date && $date > $version->end_date->toDateString()) || $habit->suspensions->contains(fn ($s) => $s->start_date->toDateString() <= $date && (! $s->end_date || $s->end_date->toDateString() > $date))) {
            return $this->scoring->state('inactive');
        }
        $ids = $version->checkpoints->modelKeys();
        $actualIds = $entry->checkpoints->pluck('habit_tracking_checkpoint_id')->all();
        $valid = count($actualIds) === count($ids) && count(array_diff($actualIds, $ids)) === 0;

        return $this->scoring->calculate($version->type->value, $version->goal_value, $entry->status->value, $entry->value, $entry->checkpoints->where('is_completed', true)->count(), count($ids), $valid);
    }

    /** Eligible intervals, clipped by the next configuration and all suspension periods. */
    private function ranges(Habit $habit, int $last): array
    {
        $ranges = [];
        foreach ($habit->versions as $index => $version) {
            $start = max(self::day($version->effective_date->toDateString()), self::day($version->start_date->toDateString()));
            $end = min($last, $version->end_date ? self::day($version->end_date->toDateString()) : $last, isset($habit->versions[$index + 1]) ? self::day($habit->versions[$index + 1]->effective_date->toDateString()) - 1 : $last);
            $parts = $start <= $end ? [[$start, $end]] : [];
            foreach ($habit->suspensions as $suspension) {
                $a = self::day($suspension->start_date->toDateString());
                $b = $suspension->end_date ? self::day($suspension->end_date->toDateString()) - 1 : $last;
                if ($a > $b) {
                    continue;
                }
                $remaining = [];
                foreach ($parts as [$left, $right]) {
                    if ($b < $left || $a > $right) {
                        $remaining[] = [$left, $right];

                        continue;
                    }
                    if ($left < $a) {
                        $remaining[] = [$left, $a - 1];
                    }
                    if ($right > $b) {
                        $remaining[] = [$b + 1, $right];
                    }
                }
                $parts = $remaining;
            }
            array_push($ranges, ...$parts);
        }

        return $ranges;
    }

    private function contains(array $ranges, int $day): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($day >= $start && $day <= $end) {
                return true;
            }
        }

        return false;
    }

    private function emptyCounts(): array
    {
        return array_fill_keys(self::COUNTERS, 0);
    }

    private function add(array &$events, int $start, int $end, array $result, int $direction = 1): void
    {
        if ($start > $end) {
            return;
        }
        $counts = $this->emptyCounts();
        if ($result['score'] !== null) {
            $counts['sum'] = $result['score'];
            $counts['count'] = 1;
            $counts['positive'] = $result['score'] > 0 ? 1 : 0;
            $counts['failures'] = $result['success'] ? 0 : 1;
        }
        if (array_key_exists($result['state'], $counts)) {
            $counts[$result['state']] = 1;
        }
        foreach ([$start => $direction, $end + 1 => -$direction] as $date => $sign) {
            $events[$date] ??= $this->emptyCounts();
            foreach (self::COUNTERS as $key) {
                $events[$date][$key] += $counts[$key] * $sign;
            }
        }
    }

    private function segments(array $events, int $first, int $last): array
    {
        $events[$first] ??= $this->emptyCounts();
        $events[$last + 1] ??= $this->emptyCounts();
        ksort($events, SORT_NUMERIC);
        $segments = [];
        $counts = $this->emptyCounts();
        $start = $first;
        foreach ($events as $day => $change) {
            if ($day > $start) {
                $segments[] = ['start' => $start, 'end' => $day - 1] + $counts;
            }
            foreach (self::COUNTERS as $key) {
                $counts[$key] += $change[$key];
            }
            $start = $day;
        }

        return $segments;
    }

    private function consistency(array $segments, int $first, int $last): array
    {
        $sum = 0.0;
        $result = ['days' => 0, 'na' => 0, 'pending' => 0, 'unavailable' => 0, 'unlogged' => 0];
        foreach ($segments as $segment) {
            $length = min($last, $segment['end']) - max($first, $segment['start']) + 1;
            if ($length <= 0) {
                continue;
            }
            if ($segment['count'] > 0) {
                $sum += $this->mean($segment) * $length;
                $result['days'] += $length;
            }
            foreach (['na', 'pending', 'unavailable', 'unlogged'] as $key) {
                $result[$key] += $segment[$key] * $length;
            }
        }

        return $result + ['percent' => $result['days'] ? max(0.0, min(100.0, 100 * $sum / $result['days'])) : null];
    }

    private function mean(array $counts): ?float
    {
        if ($counts['count'] === 0) {
            return null;
        }
        // Exact outcome counts distinguish true zero/full success from floating-point residue.
        if ($counts['positive'] === 0) {
            return 0.0;
        }
        if ($counts['failures'] === 0) {
            return 1.0;
        }

        return max(0.0, min(1.0, $counts['sum'] / $counts['count']));
    }

    private function cell(int $day, array $counts, bool $future, ?int $habitId, bool $shared): array
    {
        $score = $this->mean($counts);
        $state = $future ? 'future' : ($score !== null ? ($counts['unlogged'] === $counts['count'] ? 'unlogged' : 'recorded') : ($counts['unavailable'] ? 'unavailable' : ($counts['pending'] ? 'pending' : ($counts['na'] ? 'na' : 'inactive'))));
        $success = $counts['count'] > 0 && $counts['failures'] === 0 && $counts['pending'] === 0 && $counts['unavailable'] === 0;
        $label = match ($state) {
            'future' => 'Future date', 'unlogged' => 'Not logged', 'unavailable' => 'Data unavailable', 'pending' => 'Pending', 'na' => 'N/A', 'inactive' => $habitId ? 'Inactive date' : 'No eligible data', default => $success ? 'Fully successful' : 'Recorded performance'
        };
        $band = $score === null ? 'neutral' : ($score === 0.0 ? '0' : (string) min(4, (int) ceil($score * 4)));
        $symbol = $success ? '✓' : match ($state) {
            'na' => '—', 'pending' => '…', 'unavailable' => '!', 'unlogged' => '?', 'recorded' => $score > 0 ? '·' : '×', default => ''
        };
        $date = self::date($day);
        $link = ! $shared && in_array($state, ['recorded', 'unlogged', 'pending', 'na'], true) ? ($habitId ? route('entries.edit', ['habit' => $habitId, 'date' => $date]) : route('tracking.index', ['date' => $date])) : null;

        return compact('date', 'state', 'score', 'success', 'label', 'band', 'symbol', 'link') + array_intersect_key($counts, array_flip(['count', 'na', 'pending', 'unavailable', 'unlogged']));
    }
}

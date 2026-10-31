<?php

namespace App\Console\Commands;

use App\Actions\Habits\SaveHabit;
use App\Actions\Habits\SaveHabitEntry;
use App\Livewire\Forms\HabitForm;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use RuntimeException;

class DemoHabitsCommand extends Command
{
    protected $signature = 'demo:habits {--email= : Exact existing local account email} {--cleanup : Remove the unchanged demo dataset} {--yes : Confirm cleanup without a prompt}';

    protected $description = 'Explicitly populate or clean up private synthetic habits in a local environment';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Demo habits require APP_ENV=local. No changes made.');

            return self::FAILURE;
        }
        $email = $this->option('email');
        if (! is_string($email) || $email === '') {
            $this->error('Specify the exact existing account with --email. No account is created.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->sole();
        if ($this->option('cleanup') && ! $this->option('yes') && ! $this->confirm('Permanently remove only this account’s unchanged demo dataset?')) {
            return self::FAILURE;
        }
        $directory = storage_path('app/private/demo-habits');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create the demo manifest directory.');
        }
        $file = $directory.'/'.hash('sha256', DB::connection()->getDatabaseName().'|'.$this->identity($user)).'.json';
        $lock = fopen($file.'.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another demo operation is running.');
        }
        chmod($file.'.lock', 0600);
        $previous = Auth::user();
        try {
            Auth::setUser($user); // In-memory policy/form context only; never sign in or write a session.
            DB::transaction(function () use ($user, $file) {
                $user = User::whereKey($user->id)->lockForUpdate()->sole();
                if (is_file($file)) {
                    $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                    if ($manifest['owner'] !== $this->identity($user) || $manifest['database'] !== DB::connection()->getDatabaseName()) {
                        throw new RuntimeException('Demo manifest identity mismatch. Nothing changed.');
                    }
                    $graph = $this->graph($manifest['habits']);
                    if (! hash_equals($manifest['hash'], $this->fingerprint($graph))) {
                        throw new RuntimeException('Demo records changed or are missing. Refusing to overwrite or delete them; keep the manifest for review.');
                    }
                    if ($this->option('cleanup')) {
                        foreach (['habit_entries', 'habit_tracking_versions', 'habit_suspensions', 'habits'] as $table) {
                            DB::table($table)->whereIn('id', array_column($graph[$table], 'id'))->delete();
                        }
                        $this->info('Removed '.$manifest['entries'].' demo entries and '.count($manifest['habits']).' demo habits.');
                    } else {
                        $this->info('Already populated: '.count($manifest['habits']).' habits, '.$manifest['entries'].' entries; no changes. Anchor: '.$manifest['anchor']);
                    }

                    return;
                }
                if ($this->option('cleanup')) {
                    throw new RuntimeException('No manifest exists. Nothing removed.');
                }
                if ($user->habits()->withTrashed()->where('name', 'like', '[Demo] %')->exists()) {
                    throw new RuntimeException('Demo-labeled habits already exist without a manifest. Refusing to adopt or duplicate them.');
                }
                $manifest = $this->populate($user);
                $manifest['hash'] = $this->fingerprint($this->graph($manifest['habits']));
                // Publish before DB commit: a filesystem failure rolls back all inserts.
                // An ambiguous DB commit leaves evidence and fails closed on the next run.
                $temporary = tempnam(dirname($file), 'manifest-');
                try {
                    if (file_put_contents($temporary, json_encode($manifest, JSON_THROW_ON_ERROR), LOCK_EX) === false || ! rename($temporary, $file)) {
                        throw new RuntimeException('Cannot publish demo manifest.');
                    }
                } finally {
                    if (is_file($temporary)) {
                        unlink($temporary);
                    }
                }
                $this->info('Created '.count($manifest['habits']).' demo habits and '.$manifest['entries'].' entries. Anchor: '.$manifest['anchor']);
            });
            if ($this->option('cleanup')) {
                unlink($file); // Only after deletion committed; a failed unlink fails closed next time.
            }
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }

    private function identity(User $user): string
    {
        // Existing local databases may predate the recovery-UUID migration.
        // Never migrate them or use a missing UUID as a shared account key.
        return hash('sha256', json_encode([$user->id, $user->getRawOriginal('created_at'), $user->recovery_uuid], JSON_THROW_ON_ERROR));
    }

    private function populate(User $user): array
    {
        $anchor = CarbonImmutable::parse($user->localToday(), $user->timezone);
        $marker = 'Synthetic local demo '.Str::uuid();
        $definitions = [
            ['Morning walk', 'boolean', null, null, 'Health & Fitness', 0, 7],
            ['Read a chapter', 'boolean', null, null, 'Learning', 14, 7],
            ['Drink water', 'count', 8, 'glasses', 'Nutrition', 0, 7],
            ['Practice vocabulary', 'count', 20, 'words', 'Learning', 30, 5],
            ['Focused work', 'duration', 60, 'minutes', 'Work & Productivity', 0, 5],
            ['Stretch and unwind', 'duration', 20, 'minutes', 'Health & Fitness', 45, 7],
            ['Limit sweet snacks', 'avoidance', 1, 'servings', 'Nutrition', 0, 7],
            ['Evening without social media', 'avoidance', 0, 'checks', 'Mindfulness', 60, 7],
            ['Morning routine', 'checkpoint', null, null, 'Home & Personal Care', 0, 7],
            ['Weekly home reset', 'checkpoint', null, null, 'Home & Personal Care', 21, 1],
        ];
        $ids = [];
        $entries = 0;
        foreach ($definitions as $index => [$name, $type, $goal, $unit, $category, $delay, $schedule]) {
            $start = $anchor->subMonthsNoOverflow(9)->addDays($delay);
            $categoryId = Category::where('is_system', true)->where('name', $category)->sole()->id;
            $form = new HabitForm(new class extends Component {}, 'form');
            $form->fill([
                'name' => '[Demo] '.$name, 'description' => $marker.'. '.($schedule === 5 ? 'Weekdays; weekends are recorded as N/A rest days.' : ($schedule === 1 ? 'Sundays; other days are N/A.' : 'Daily practice.')),
                'category_id' => $categoryId, 'type' => $type, 'visibility' => 'private',
                'goal_value' => $goal, 'goal_unit' => $unit, 'start_date' => $start->toDateString(), 'end_date' => null,
                'checkpoints' => $type === 'checkpoint' ? array_map(fn ($label) => ['label' => $label], $index === 8 ? ['Make bed', 'Drink water', 'Plan the day', 'Stretch'] : ['Laundry', 'Clean surfaces', 'Prepare meals', 'Plan the week']) : [],
            ]);
            $habit = app(SaveHabit::class)->handle($user, null, $form->validatedData(null));
            $ids[] = $habit->id;
            $version = $habit->versions()->with('checkpoints')->sole();
            for ($date = $start; $date <= $anchor; $date = $date->addDay()) {
                $age = (int) $date->diffInDays($anchor);
                if ($age === 0 && $index >= 6) {
                    continue; // Today's remaining habits stay pending, not missed.
                }
                $rest = ($schedule === 5 && $date->isWeekend()) || ($schedule === 1 && ! $date->isSunday());
                $roll = (int) hexdec(substr(hash('sha256', $index.'|'.$date->toDateString()), 0, 6)) % 100;
                $successfulRun = $age <= 7 || ($age >= 45 && $age <= 58);
                if (! $rest && ! $successfulRun && $roll < 7) {
                    continue; // Truly unlogged past dates derive zero; do not insert fabricated misses.
                }
                $quality = $successfulRun ? 1.0 : ($roll < 18 ? 0.0 : ($roll < 35 ? 0.25 : ($roll < 50 ? 0.5 : ($roll < 62 ? 0.75 : 1.0))));
                if ($age >= 90 && $age <= 99) {
                    $quality = $age < 95 ? 0.0 : 0.25;
                }
                $status = $rest ? 'not_applicable' : ($quality === 1.0 ? 'completed' : ($quality === 0.0 || $type === 'boolean' ? 'missed' : 'partial'));
                $value = match ($type) {
                    'count', 'duration' => (int) ($goal * $quality),
                    'avoidance' => $quality === 1.0 ? $goal : $goal + 1,
                    default => null,
                };
                app(SaveHabitEntry::class)->handle($user, $habit->id, $date->toDateString(), [
                    'status' => $status, 'value' => $value, 'completed' => $type === 'checkpoint' && ! $rest ? array_slice($version->checkpoints->modelKeys(), 0, (int) (4 * $quality)) : [],
                    'note' => null, 'not_applicable_reason' => $rest ? 'Planned demo rest day' : null,
                ], null, $version->id, $version->revision, $user->timezone);
                $entries++;
            }
        }

        return ['version' => 1, 'database' => DB::connection()->getDatabaseName(), 'owner' => $this->identity($user), 'anchor' => $anchor->toDateString(), 'habits' => $ids, 'entries' => $entries];
    }

    /** Include incoming references so cleanup refuses cross-habit corruption too. */
    private function graph(array $ids): array
    {
        $queries = [
            'habits' => DB::table('habits')->whereIn('id', $ids),
            'habit_checkpoints' => DB::table('habit_checkpoints')->whereIn('habit_id', $ids),
            'habit_tracking_versions' => DB::table('habit_tracking_versions')->whereIn('habit_id', $ids),
            'habit_suspensions' => DB::table('habit_suspensions')->whereIn('habit_id', $ids),
        ];
        $versions = (clone $queries['habit_tracking_versions'])->pluck('id');
        $checkpoints = (clone $queries['habit_checkpoints'])->pluck('id');
        $queries['habit_entries'] = DB::table('habit_entries')->whereIn('habit_id', $ids)->orWhereIn('habit_tracking_version_id', $versions);
        $queries['habit_tracking_checkpoints'] = DB::table('habit_tracking_checkpoints')->whereIn('habit_tracking_version_id', $versions)->orWhereIn('habit_checkpoint_id', $checkpoints);
        $queries['habit_checkpoint_entries'] = DB::table('habit_checkpoint_entries')->whereIn('habit_entry_id', (clone $queries['habit_entries'])->pluck('id'))->orWhereIn('habit_tracking_checkpoint_id', (clone $queries['habit_tracking_checkpoints'])->pluck('id'));

        return array_map(fn ($query) => $query->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $queries);
    }

    private function fingerprint(array $graph): string
    {
        return hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR));
    }
}

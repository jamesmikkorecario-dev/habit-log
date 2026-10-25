<?php

namespace App\Livewire;

use App\Livewire\Concerns\RedirectsRevokedSharedAccess;
use App\Models\Habit;
use App\Models\User;
use App\Services\Analytics\ActivityAnalytics;
use App\Services\SocialAccess;
use App\Services\TrackingDate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class ActivityHeatmap extends Component
{
    use RedirectsRevokedSharedAccess;

    private bool $sharedInteraction = false;

    public function hydrate(): void
    {
        $this->sharedInteraction = $this->subjectId !== null;
        if ($this->sharedInteraction) {
            $this->owner();
        }
    }

    public function rendered(): void
    {
        if ($this->sharedInteraction && ! $this->sharedAccessRedirected) {
            // Keep the surrounding identity/list in sync with the report's
            // fresh authorization, without remounting this date selection.
            $this->dispatch('shared-activity-updated.'.$this->subjectId);
        }
    }

    #[Locked]
    public ?int $habitId = null;

    #[Locked]
    public ?int $subjectId = null;

    #[Locked]
    public int $viewerId;

    #[Url(as: 'year')]
    public $year = '';

    public $yearInput = '';

    public $selectedDate = '';

    public $listPage = 1;

    public function mount(?int $habitId = null, ?int $subjectId = null): void
    {
        $this->viewerId = auth()->id();
        $this->subjectId = $subjectId;
        $this->habitId = $habitId;
        $this->year = $this->year === '' ? (int) substr($this->owner()->localToday(), 0, 4) : $this->year;
        $this->validateYear();
        $this->yearInput = $this->year;
    }

    private function owner()
    {
        abort_unless(auth()->check(), 401);
        abort_unless(auth()->id() === $this->viewerId, 403);
        $viewer = auth()->user()->fresh();
        if ($this->subjectId !== null) {
            $user = User::findOrFail($this->subjectId);
            abort_unless(Gate::forUser($viewer)->allows('viewShared', $user), 404);
            if ($this->habitId !== null) {
                $habit = app(SocialAccess::class)->habits($viewer, $user)->findOrFail($this->habitId);
                $this->authorize('viewShared', $habit);
            }

            return $user;
        }
        $user = $viewer;
        if ($this->habitId !== null) {
            $habit = $user->habits()->find($this->habitId);
            abort_if($habit === null, 404);
            $this->authorize('view', $habit);
        } else {
            $this->authorize('viewAny', Habit::class);
        }

        return $user;
    }

    #[Computed]
    public function activity(): array
    {
        $user = $this->owner();
        $this->validateYear();

        return $this->subjectId === null
            ? app(ActivityAnalytics::class)->report($user, (int) $this->year, $this->habitId)
            : app(ActivityAnalytics::class)->reportForViewer(auth()->user(), $user, (int) $this->year, $this->habitId);
    }

    public function changeYear(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 0, 1], true), 422);
        $report = $this->activity;
        $this->year = $direction === 0 ? (int) substr($report['today'], 0, 4) : max(1000, min((int) substr($report['today'], 0, 4), (int) $this->year + $direction));
        $this->yearInput = $this->year;
        $this->reset('selectedDate', 'listPage');
        unset($this->activity);
    }

    public function updatedYear(): void
    {
        $this->validateYear();
        $this->yearInput = $this->year;
        $this->reset('selectedDate', 'listPage');
        unset($this->activity);
    }

    private function validateYear(): void
    {
        abort_if(Validator::make(['year' => $this->year], ['year' => ['required', 'integer', 'min:1000', 'max:'.substr($this->owner()->localToday(), 0, 4)]])->fails(), 422);
    }

    public function goToYear(): void
    {
        $this->validate(['yearInput' => ['required', 'integer', 'min:1000', 'max:'.substr($this->owner()->localToday(), 0, 4)]]);
        $this->year = (int) $this->yearInput;
        $this->updatedYear();
    }

    public function selectDay($date): void
    {
        $this->owner();
        Validator::make(['date' => $date], ['date' => ['required', 'string', 'date_format:Y-m-d']])->validate();
        app(TrackingDate::class)->validate($date);
        abort_unless(substr($date, 0, 4) === (string) $this->activity['year'], 422);
        $this->selectedDate = $date;
    }

    public function moveList(int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1], true), 422);
        $this->listPage = max(1, min((int) ceil(count($this->activity['cells']) / 20), $this->validPage() + $direction));
    }

    private function validPage(): int
    {
        abort_if(Validator::make(['page' => $this->listPage], ['page' => ['required', 'integer', 'min:1', 'max:19']])->fails(), 422);

        return (int) $this->listPage;
    }

    private function emptyMessage(array $period, bool $newAccount, bool $noCheckIns): ?string
    {
        // Presentation only: a valid zero must always retain its calculated value.
        if ($period['percent'] !== null) {
            return null;
        }

        return match (true) {
            $newAccount => 'Create your first habit to get started.',
            $period['unavailable'] > 0 => 'Some activity data is unavailable for this period.',
            $period['pending'] > 0 && $noCheckIns => 'Your progress will appear after your first check-in.',
            $period['pending'] > 0 => 'Some activity is still awaiting a check-in.',
            $period['na'] > 0 => 'Not applicable for this period.',
            default => 'No eligible activity for this period.',
        };
    }

    public function render()
    {
        $report = $this->activity;
        abort_if(Validator::make(['selectedDate' => $this->selectedDate], ['selectedDate' => ['nullable', 'string', 'date_format:Y-m-d']])->fails(), 422);
        $selected = $this->selectedDate === '' ? null : collect($report['cells'])->firstWhere('date', $this->selectedDate);
        abort_if($this->selectedDate !== '' && $selected === null, 422);

        // Only the owner's dashboard may use account-wide onboarding context.
        // Shared views rely exclusively on their already-authorized report.
        $ownerDashboard = $this->subjectId === null && $this->habitId === null;
        $newAccount = $ownerDashboard && $report['habit_count'] === 0
            && ! auth()->user()->habits()->withTrashed()->exists();
        $noCheckIns = $ownerDashboard && ($report['recent']['percent'] === null || $report['yearly']['percent'] === null)
            && ! auth()->user()->habits()->withTrashed()->whereHas('entries', fn ($query) => $query->where('user_id', auth()->id()))->exists();

        return view('livewire.activity-heatmap', [
            'report' => $report,
            'newAccount' => $newAccount,
            'emptyMessages' => [
                'recent' => $this->emptyMessage($report['recent'], $newAccount, $noCheckIns),
                'yearly' => $this->emptyMessage($report['yearly'], $newAccount, $noCheckIns),
            ],
            'selected' => $selected,
            'listCells' => array_slice($report['cells'], ($this->validPage() - 1) * 20, 20),
        ]);
    }
}

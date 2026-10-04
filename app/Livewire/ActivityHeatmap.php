<?php

namespace App\Livewire;

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

    public function render()
    {
        $report = $this->activity;
        abort_if(Validator::make(['selectedDate' => $this->selectedDate], ['selectedDate' => ['nullable', 'string', 'date_format:Y-m-d']])->fails(), 422);
        $selected = $this->selectedDate === '' ? null : collect($report['cells'])->firstWhere('date', $this->selectedDate);
        abort_if($this->selectedDate !== '' && $selected === null, 422);

        return view('livewire.activity-heatmap', [
            'report' => $report,
            'selected' => $selected,
            'listCells' => array_slice($report['cells'], ($this->validPage() - 1) * 20, 20),
        ]);
    }
}

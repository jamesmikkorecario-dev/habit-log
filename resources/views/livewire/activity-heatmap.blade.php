@php($minimalCalendar = $habitId === null && $subjectId === null)
<section class="min-w-0 space-y-6" aria-label="{{ $habitId ? 'Habit activity' : 'Overall profile activity' }}"
    x-data="{ detailOpen: false, origin: null, closeDetail() { this.detailOpen = false; this.$nextTick(() => this.$root.querySelector(`[data-focus-key='${this.origin}']`)?.focus()); } }"
    @if($minimalCalendar) x-effect="if (!$wire.selectedDate) detailOpen = false" @endif
    @dashboard-date-selected="origin = 'browse-date'; detailOpen = true; $nextTick(() => $refs.detailHeading?.focus())">
    <section aria-label="Progress overview" class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-2">
            <div><p class="font-mono text-xs uppercase tracking-widest text-emerald-300">The bigger picture</p><div class="mt-2 flex items-center gap-2"><h2 class="text-xl font-semibold">{{ $habitId ? 'Habit progress' : 'Progress overview' }}</h2>
                @if($minimalCalendar)
                    <flux:modal.trigger name="dashboard-numbers">
                        <flux:button icon="information-circle" variant="ghost" class="min-h-11 min-w-11" aria-label="How these numbers work" title="How these numbers work" />
                    </flux:modal.trigger>
                @endif
            </div></div>
            <p class="text-xs text-zinc-400">As of {{ $report['today'] }} · {{ $report['timezone'] }}</p>
        </header>
        <x-activity-summary :report="$report" :empty-messages="$emptyMessages" :new-account="$newAccount" :shared="$subjectId !== null" />
        @if($minimalCalendar)
            <flux:modal name="dashboard-numbers" class="w-[calc(100%-2rem)] min-w-0! max-w-xl [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11"
                x-on:keydown.tab.prevent="$event.shiftKey ? $focus.within(Array.from($el.querySelectorAll('button'))).wrap().previous() : $focus.within(Array.from($el.querySelectorAll('button'))).wrap().next()"
                x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'dashboard-numbers-title')">
                <h2 id="dashboard-numbers-title" class="mb-4 pr-6 text-lg font-semibold">How these numbers work</h2>
                <div class="text-sm text-zinc-300"><x-activity-explanation :report="$report" :habit-id="$habitId" :subject-id="$subjectId" /></div>
                <div class="mt-5 flex justify-end"><flux:modal.close><flux:button autofocus class="min-h-11">Close explanation</flux:button></flux:modal.close></div>
            </flux:modal>
        @else
        <details wire:ignore.self class="rounded-lg border border-zinc-800 px-4 py-1 text-sm text-zinc-400">
            <summary class="cursor-pointer rounded py-3 font-medium text-zinc-300 focus-visible:outline-2 focus-visible:outline-emerald-300">How these numbers work</summary>
            <div class="pb-4"><x-activity-explanation :report="$report" :habit-id="$habitId" :subject-id="$subjectId" /></div>
        </details>
        @endif
    </section>

    <section aria-label="Activity history" @class(['min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/20 p-4 sm:p-5', 'dashboard-activity-history' => $minimalCalendar])>
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div><h2 class="text-xl font-semibold">Activity history</h2><p class="mt-2 text-sm text-zinc-400">{{ $report['year'] }} · Every day adds to the picture.</p></div>
            <div class="flex min-w-0 flex-wrap items-end gap-2">
                <flux:button wire:click="changeYear(-1)" class="min-h-11" :disabled="$report['year'] <= 1000">Previous year</flux:button>
                <flux:button wire:click="changeYear(0)" class="min-h-11">Current year</flux:button>
                <flux:button wire:click="changeYear(1)" class="min-h-11" :disabled="$report['year'] >= (int) substr($report['today'], 0, 4)">Next year</flux:button>
                <form wire:submit="goToYear" class="flex flex-wrap items-end gap-2"><div class="w-28"><flux:input wire:model="yearInput" type="number" label="Year" class:input="min-h-11" min="1000" :max="substr($report['today'], 0, 4)" /></div><flux:button type="submit" class="min-h-11">Go to year</flux:button></form>
            </div>
        </header>
        <flux:error name="yearInput" />
        @if($emptyMessages['yearly'])
            <p class="text-sm leading-relaxed text-zinc-400" data-activity-context>{{ $emptyMessages['yearly'] }}</p>
        @endif
        <p class="text-xs leading-relaxed text-zinc-400">Monday-first weeks. Use arrow keys to move between dates, Home / End for the year boundaries, and Enter or Space for details.</p>
        <x-activity-heatmap :report="$report" :minimal="$minimalCalendar" />
        @if($minimalCalendar)
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-t border-zinc-800 pt-4 text-xs text-zinc-400" aria-label="Performance legend">
                <div class="flex items-center gap-2"><span>Performance</span><span>Less</span>@foreach(['0', '1', '2', '3', '4'] as $band)<span class="heat-cell heat-band-{{ $band }}" aria-hidden="true"></span>@endforeach<span>More</span></div>
                <div>
                <flux:modal.trigger name="dashboard-status-key"><flux:button variant="ghost" class="min-h-11">Status key</flux:button></flux:modal.trigger>
                <flux:modal name="dashboard-status-key" class="w-[calc(100%-2rem)] min-w-0! max-w-lg [&_[data-flux-modal-close]_button]:min-h-11 [&_[data-flux-modal-close]_button]:min-w-11"
                    x-on:keydown.tab.prevent="$event.shiftKey ? $focus.within(Array.from($el.querySelectorAll('button'))).wrap().previous() : $focus.within(Array.from($el.querySelectorAll('button'))).wrap().next()"
                    x-init="$el.querySelector('dialog').setAttribute('aria-labelledby', 'dashboard-status-title')">
                    <h2 id="dashboard-status-title" class="pr-6 text-lg font-semibold">Status key</h2>
                    <p class="mt-3 text-sm leading-relaxed text-zinc-300">Performance: 0%, above 0–25%, above 25–50%, above 50–75%, above 75–100%. Zero is recorded; not logged means a missing entry.</p>
                    <dl class="mt-4 space-y-4 text-sm text-zinc-300">
                        @foreach(['unlogged' => ['Not logged', 'A missing past entry contributes zero performance.'], 'pending' => ['Pending', "Today is awaiting a check-in. Any recorded score stays provisional."], 'na' => ['N/A', 'Recorded as not applicable; excluded from performance and streak days.'], 'unavailable' => ['Unavailable', 'Activity data cannot be scored; excluded from performance.'], 'inactive' => ['Inactive / Future', 'Outside active dates or after today; neutral, with no score.']] as $state => [$meaning, $description])
                            <div><dt class="flex items-center gap-2 font-medium text-zinc-100"><span class="heat-cell heat-band-neutral shrink-0" data-state="{{ $state }}" aria-hidden="true"></span>{{ $meaning }}</dt><dd class="mt-1 pl-6 leading-relaxed">{{ $description }}</dd></div>
                        @endforeach
                    </dl>
                    <div class="mt-5 flex justify-end"><flux:modal.close><flux:button autofocus class="min-h-11">Close status key</flux:button></flux:modal.close></div>
                </flux:modal>
                </div>
            </div>
        @else
        <div class="grid gap-5 border-t border-zinc-800 pt-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" aria-label="Performance legend">
            <div>
                <h3 class="text-sm font-medium">Performance intensity</h3>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-zinc-400"><span>Less</span>@foreach(['0', '1', '2', '3', '4'] as $band)<span class="heat-cell heat-band-{{ $band }}" aria-hidden="true"></span>@endforeach<span>More</span></div>
                <p class="mt-2 max-w-sm text-xs leading-relaxed text-zinc-400">0%, above 0–25%, above 25–50%, above 50–75%, above 75–100%.</p>
            </div>
            <div>
                <h3 class="text-sm font-medium">What each day means</h3>
                <ul class="mt-3 grid gap-x-4 gap-y-2 text-xs text-zinc-300 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach(['✓' => 'Full success', '×' => 'Zero performance', '?' => 'Not logged', '—' => 'Not applicable', '…' => 'Pending', '!' => 'Unavailable', 'Empty' => 'Inactive / future'] as $symbol => $meaning)
                        <li class="flex items-center gap-2"><span class="min-w-5 font-mono text-zinc-100" aria-hidden="true">{{ $symbol }}</span><span>{{ $meaning }}</span></li>
                    @endforeach
                </ul>
                <p class="mt-3 text-xs leading-relaxed text-zinc-400">Neutral days have dashed borders. Today's overall score is provisional while any habits remain pending.</p>
            </div>
        </div>
        @endif
    @if($minimalCalendar)
        <form wire:submit="browseToDate" aria-label="Browse dates" class="space-y-3 border-t border-zinc-800 pt-4">
            <div><h3 class="text-sm font-medium">Browse dates</h3><p id="browse-date-help" class="mt-1 text-xs text-zinc-400">Choose any date, including unlogged and inactive days. Use the year controls above or enter a date directly.</p></div>
            <div class="flex flex-wrap items-end gap-2">
                <flux:button wire:click="moveBrowseMonth(-1)" icon="chevron-left" class="min-h-11 min-w-11" aria-label="Previous month" title="Previous month" :disabled="$browseDate <= '1000-01-01'" />
                <div class="min-w-0 flex-1 sm:max-w-56"><flux:input type="date" wire:model="browseDate" label="Date to view" data-focus-key="browse-date" aria-describedby="browse-date-help" min="1000-01-01" :max="substr($report['today'], 0, 4).'-12-31'" class:input="min-h-11" /></div>
                <flux:button wire:click="moveBrowseMonth(1)" icon="chevron-right" class="min-h-11 min-w-11" aria-label="Next month" title="Next month" :disabled="$browseDate >= substr($report['today'], 0, 4).'-12-31'" />
                <flux:button type="submit" class="min-h-11 w-full sm:w-auto">Show date</flux:button>
            </div>
            <p class="sr-only" role="status" aria-atomic="true">Date to view: {{ $browseDate }}. Select Show date to open details.</p>
        </form>
    @endif
    <div x-show="detailOpen" x-cloak @keydown.escape.prevent.stop="closeDetail()" class="rounded-lg border border-emerald-800 bg-zinc-900 p-4" aria-live="polite" aria-atomic="true">
        @if($selected)
            <h3 class="font-semibold" tabindex="-1" x-ref="detailHeading" @if($minimalCalendar) x-effect="if (detailOpen) $nextTick(() => $el.focus())" @endif>{{ $selected['date'] }} · {{ $selected['label'] }}</h3>
            <p class="mt-2">{{ $selected['score'] === null ? 'No performance score' : number_format($selected['score'] * 100, 1).'% performance' }}{{ $selected['success'] ? ' · Full success' : '' }}</p>
            <p class="text-sm text-zinc-400">{{ $selected['count'] }} included · {{ $selected['na'] }} N/A · {{ $selected['pending'] }} pending · {{ $selected['unlogged'] }} unlogged · {{ $selected['unavailable'] }} unavailable</p>
            @if(isset($selected['assessment']))
                <p class="mt-2 text-sm">Recorded assessment: {{ $selected['assessment'] }}.</p>
                @if($selected['state'] !== 'na' && $selected['actual'] !== null)<p class="break-words text-sm">Actual: {{ $selected['actual'] }} {{ $selected['unit'] }} · {{ $selected['type'] }} · {{ $selected['goal'] === null ? 'No numeric goal' : 'Historical goal/limit: '.$selected['goal'] }}</p>@endif
                <p class="text-xs text-zinc-400">With a goal, performance uses {{ $subjectId !== null ? 'the recorded' : 'your' }} measurement and historical setup; it may differ from {{ $subjectId !== null ? 'the recorded' : 'your' }} assessment. Without a numeric goal, Completed / Partial / Missed scores 100% / 50% / 0%.</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @if($selected['link'])<flux:button :href="$selected['link']" wire:navigate>{{ $habitId ? 'Open daily entry' : 'Open daily overview' }}</flux:button>@endif
                <flux:button @click="closeDetail()" class="min-h-11">Close details</flux:button>
            </div>
        @else<p>Loading date details…</p>@endif
    </div>
    @unless($minimalCalendar)
    <details wire:ignore.self class="rounded-lg border border-zinc-800 p-3">
        <summary class="min-h-11 cursor-pointer py-3 font-medium focus-visible:outline-2 focus-visible:outline-emerald-300">Accessible day list</summary>
        <ul class="mt-3 divide-y divide-zinc-800">
            @foreach($listCells as $cell)
                <li wire:key="list-{{ $cell['date'] }}"><button type="button" data-focus-key="list-{{ $cell['date'] }}" class="flex min-h-11 w-full flex-wrap items-center justify-between gap-2 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-emerald-300"
                    @click="origin = 'list-{{ $cell['date'] }}'; detailOpen = true; await $wire.selectDay('{{ $cell['date'] }}'); $nextTick(() => $refs.detailHeading?.focus())">
                    <span>{{ $cell['date'] }}</span><span>{{ $cell['label'] }}{{ $cell['score'] === null ? '' : ' · '.number_format($cell['score'] * 100, 1).'%' }}</span>
                </button></li>
            @endforeach
        </ul>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <flux:button wire:click="moveList(-1)" :disabled="$listPage <= 1" class="min-h-11">Previous days</flux:button>
            <span class="text-sm" role="status">Page {{ $listPage }} of {{ (int) ceil(count($report['cells']) / 20) }}</span>
            <flux:button wire:click="moveList(1)" :disabled="$listPage >= ceil(count($report['cells']) / 20)" class="min-h-11">Next days</flux:button>
        </div>
    </details>
    @endunless
    </section>
</section>

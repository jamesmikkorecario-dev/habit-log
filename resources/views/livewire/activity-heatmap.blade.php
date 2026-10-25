<section class="min-w-0 space-y-6" aria-label="{{ $habitId ? 'Habit activity' : 'Overall profile activity' }}"
    x-data="{ detailOpen: false, origin: null, closeDetail() { this.detailOpen = false; this.$nextTick(() => this.$root.querySelector(`[data-focus-key='${this.origin}']`)?.focus()); } }">
    <section aria-label="Progress overview" class="space-y-4">
        <header class="flex flex-wrap items-end justify-between gap-2">
            <div><p class="font-mono text-xs uppercase tracking-widest text-emerald-300">The bigger picture</p><h2 class="mt-2 text-xl font-semibold">{{ $habitId ? 'Habit progress' : 'Progress overview' }}</h2></div>
            <p class="text-xs text-zinc-400">As of {{ $report['today'] }} · {{ $report['timezone'] }}</p>
        </header>
        <x-activity-summary :report="$report" :empty-messages="$emptyMessages" :new-account="$newAccount" :shared="$subjectId !== null" />
        <details wire:ignore.self class="rounded-lg border border-zinc-800 px-4 py-1 text-sm text-zinc-400">
            <summary class="cursor-pointer rounded py-3 font-medium text-zinc-300 focus-visible:outline-2 focus-visible:outline-emerald-300">How these numbers work</summary>
            <div class="space-y-3 pb-4 leading-relaxed">
                <p>Consistency averages performance, including partial progress. The 30-day window is {{ $report['recent_start'] }}–{{ $report['today'] }}; yearly consistency uses the selected calendar year through today. Streaks use all history and count only full success. N/A and inactive gaps preserve a run without adding days. Unlogged past days count as zero; today stays pending until recorded.</p>
                <p>{{ $subjectId === null ? 'Your management view includes private habits. Shared profiles follow your visibility settings.' : 'Only habits you may view are included.' }}</p>
                @if($report['lifecycle'])<p class="text-zinc-300">{{ $report['lifecycle'] }}</p>@endif
                @if(!$habitId)<p>{{ $report['habit_count'] }} habits included. Archived history is preserved; trashed habits are excluded until restored.</p>@endif
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach(['recent' => 'Last 30 days', 'yearly' => $report['year'].' calendar year'] as $key => $title)
                        <div><h3 class="font-medium text-zinc-300">{{ $title }}</h3>
                            <p>{{ $report[$key]['days'] }} scored days · {{ $report[$key]['na'] }} N/A {{ $habitId ? 'days' : 'habit-days' }} · {{ $report[$key]['unlogged'] }} unlogged {{ $habitId ? 'days' : 'habit-days' }}</p>
                            <p>{{ $report[$key]['pending'] }} pending · {{ $report[$key]['unavailable'] }} unavailable</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    </section>

    <section aria-label="Activity history" class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/20 p-4 sm:p-5">
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
        <x-activity-heatmap :report="$report" />
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
    <div x-show="detailOpen" x-cloak @keydown.escape.prevent.stop="closeDetail()" class="rounded-lg border border-emerald-800 bg-zinc-900 p-4" aria-live="polite" aria-atomic="true">
        @if($selected)
            <h3 class="font-semibold" tabindex="-1" x-ref="detailHeading">{{ $selected['date'] }} · {{ $selected['label'] }}</h3>
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
    </section>
</section>

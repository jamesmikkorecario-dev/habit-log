<section class="min-w-0 space-y-5 rounded-xl border border-emerald-900/50 p-4 sm:p-6" aria-label="{{ $habitId ? 'Habit activity' : 'Overall profile activity' }}"
    x-data="{ detailOpen: false, origin: null, closeDetail() { this.detailOpen = false; this.$nextTick(() => this.$root.querySelector(`[data-focus-key='${this.origin}']`)?.focus()); } }">
    <header class="space-y-2">
        <h2 class="text-xl font-semibold">{{ $habitId ? 'Habit activity' : 'Overall profile activity' }}</h2>
        <p class="text-sm text-zinc-400">As of {{ $report['today'] }} · {{ $report['timezone'] }} · {{ $subjectId === null ? 'Your management view includes private habits. Shared profiles follow your visibility settings.' : 'Only habits you may view are included.' }}</p>
        @if($report['lifecycle'])<p class="text-sm text-zinc-300">{{ $report['lifecycle'] }}</p>@endif
        @if(!$habitId)<p class="text-sm text-zinc-400">{{ $report['habit_count'] }} habits included. Archived history is preserved; trashed habits are excluded until restored.</p>@endif
    </header>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg bg-zinc-900 p-3"><p class="text-sm text-zinc-400">{{ $habitId ? 'Current streak' : 'Overall current streak' }}</p><p class="text-2xl font-semibold">{{ $report['streaks']['current'] }} <span class="text-sm font-normal">successful days</span></p></div>
        <div class="rounded-lg bg-zinc-900 p-3"><p class="text-sm text-zinc-400">{{ $habitId ? 'Longest streak' : 'Overall longest streak' }}</p><p class="text-2xl font-semibold">{{ $report['streaks']['longest'] }} <span class="text-sm font-normal">successful days</span></p></div>
        @foreach(['recent' => '30-day consistency', 'yearly' => $report['year'].' consistency'] as $key => $title)
            <div class="rounded-lg bg-zinc-900 p-3">
                <p class="text-sm text-zinc-400">{{ $title }}</p>
                <p class="text-2xl font-semibold">{{ $report[$key]['percent'] === null ? 'No eligible data' : number_format($report[$key]['percent'], 1).'%' }}</p>
                <p class="mt-1 text-xs text-zinc-400">{{ $report[$key]['days'] }} scored days · {{ $report[$key]['na'] }} N/A {{ $habitId ? 'days' : 'habit-days' }} · {{ $report[$key]['unlogged'] }} unlogged {{ $habitId ? 'days' : 'habit-days' }}</p>
                <p class="text-xs text-zinc-400">{{ $report[$key]['pending'] }} pending · {{ $report[$key]['unavailable'] }} unavailable</p>
            </div>
        @endforeach
    </div>
    <p class="text-sm text-zinc-400">Consistency averages performance, including partial progress. The 30-day window is {{ $report['recent_start'] }}–{{ $report['today'] }}; yearly consistency uses the selected calendar year through today. Streaks use all history and count only full success. N/A and inactive gaps preserve a run without adding days. Unlogged past days count as zero; today stays pending until recorded.</p>
    <div class="flex flex-wrap items-end gap-2">
        <flux:button wire:click="changeYear(-1)" :disabled="$report['year'] <= 1000">Previous year</flux:button>
        <flux:button wire:click="changeYear(0)">Current year</flux:button>
        <flux:button wire:click="changeYear(1)" :disabled="$report['year'] >= (int) substr($report['today'], 0, 4)">Next year</flux:button>
        <form wire:submit="goToYear" class="flex items-end gap-2"><div class="w-28"><flux:input wire:model="yearInput" type="number" label="Year" min="1000" :max="substr($report['today'], 0, 4)" /></div><flux:button type="submit">Go to year</flux:button></form>
    </div>
    <flux:error name="yearInput" />
    <p class="text-sm text-zinc-400">{{ $report['year'] }} · Monday-first weeks. Use arrow keys to move between dates; Enter or Space shows details. On small screens, scroll the calendar or use the day list.</p>
    <x-activity-heatmap :report="$report" />
    <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-300" aria-label="Performance legend">
        <span>Less</span>@foreach(['0', '1', '2', '3', '4'] as $band)<span class="heat-cell heat-band-{{ $band }}" aria-hidden="true"></span>@endforeach<span>More performance</span>
        <span>✓ Full success</span><span>× Zero</span><span>? Not logged</span><span>— N/A</span><span>… Pending</span><span>! Unavailable</span><span>Empty: inactive/future</span>
    </div>
    <p class="text-xs text-zinc-400">Bands: 0%, above 0–25%, above 25–50%, above 50–75%, above 75–100%. Full success is marked ✓. Today's overall score is provisional while any habits remain pending.</p>
    <div x-show="detailOpen" x-cloak @keydown.escape.prevent.stop="closeDetail()" class="rounded-lg border border-emerald-800 bg-zinc-900 p-4" aria-live="polite" aria-atomic="true">
        @if($selected)
            <h3 class="font-semibold" tabindex="-1" x-ref="detailHeading">{{ $selected['date'] }} · {{ $selected['label'] }}</h3>
            <p class="mt-2">{{ $selected['score'] === null ? 'No performance score' : number_format($selected['score'] * 100, 1).'% performance' }}{{ $selected['success'] ? ' · Full success' : '' }}</p>
            <p class="text-sm text-zinc-400">{{ $selected['count'] }} included · {{ $selected['na'] }} N/A · {{ $selected['pending'] }} pending · {{ $selected['unlogged'] }} unlogged · {{ $selected['unavailable'] }} unavailable</p>
            @if(isset($selected['assessment']))
                <p class="mt-2 text-sm">Recorded assessment: {{ $selected['assessment'] }}.</p>
                @if($selected['state'] !== 'na' && $selected['actual'] !== null)<p class="text-sm">Actual: {{ $selected['actual'] }} {{ $selected['unit'] }} · {{ $selected['type'] }} · {{ $selected['goal'] === null ? 'No numeric goal' : 'Historical goal/limit: '.$selected['goal'] }}</p>@endif
                <p class="text-xs text-zinc-400">With a goal, performance uses your measurement and historical setup; it may differ from your assessment. Without a numeric goal, Completed / Partial / Missed scores 100% / 50% / 0%.</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @if($selected['link'])<flux:button :href="$selected['link']" wire:navigate>{{ $habitId ? 'Open daily entry' : 'Open daily overview' }}</flux:button>@endif
                <flux:button @click="closeDetail()">Close details</flux:button>
            </div>
        @else<p>Loading date details…</p>@endif
    </div>
    <details wire:ignore.self class="rounded-lg border border-zinc-800 p-3">
        <summary class="cursor-pointer py-2 font-medium focus-visible:outline-2 focus-visible:outline-emerald-300">Accessible day list</summary>
        <ul class="mt-3 divide-y divide-zinc-800">
            @foreach($listCells as $cell)
                <li wire:key="list-{{ $cell['date'] }}"><button type="button" data-focus-key="list-{{ $cell['date'] }}" class="flex min-h-11 w-full flex-wrap items-center justify-between gap-2 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-emerald-300"
                    @click="origin = 'list-{{ $cell['date'] }}'; detailOpen = true; await $wire.selectDay('{{ $cell['date'] }}'); $nextTick(() => $refs.detailHeading?.focus())">
                    <span>{{ $cell['date'] }}</span><span>{{ $cell['label'] }}{{ $cell['score'] === null ? '' : ' · '.number_format($cell['score'] * 100, 1).'%' }}</span>
                </button></li>
            @endforeach
        </ul>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <flux:button wire:click="moveList(-1)" :disabled="$listPage <= 1">Previous days</flux:button>
            <span class="text-sm" role="status">Page {{ $listPage }} of {{ (int) ceil(count($report['cells']) / 20) }}</span>
            <flux:button wire:click="moveList(1)" :disabled="$listPage >= ceil(count($report['cells']) / 20)">Next days</flux:button>
        </div>
    </details>
</section>

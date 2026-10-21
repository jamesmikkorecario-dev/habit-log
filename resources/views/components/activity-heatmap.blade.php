@props(['report'])
@php($columns = (int) ceil(($report['offset'] + count($report['cells'])) / 7))
<div class="activity-calendar min-w-0" wire:key="calendar-{{ $report['year'] }}" style="--calendar-columns: {{ $columns }}"
    x-data="{
        index: 0,
        init() { this.$nextTick(() => { this.$refs.scroll.scrollLeft = 0; }); },
        move(event) {
            if (!event.target.matches('[data-date]')) return;
            const steps = { ArrowLeft: -7, ArrowRight: 7, ArrowUp: -1, ArrowDown: 1, Home: -366, End: 366 };
            if (!(event.key in steps)) return;
            event.preventDefault();
            const buttons = this.$refs.days.querySelectorAll('button');
            this.index = Math.max(0, Math.min(buttons.length - 1, this.index + steps[event.key]));
            buttons[this.index].focus();
        }
    }" @keydown="move($event)">
    <div class="grid min-w-0 grid-cols-[2.75rem_minmax(0,1fr)] gap-2" dir="ltr">
        <div class="calendar-weekdays text-xs text-zinc-400" aria-hidden="true">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)<span class="flex items-center">{{ $day }}</span>@endforeach
        </div>
        <div x-ref="scroll" data-calendar-scroll tabindex="0" role="region" aria-label="{{ $report['year'] }} activity calendar" class="min-w-0 overflow-x-auto overscroll-x-contain rounded-md border border-zinc-800 p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
            <div class="w-max">
                <div class="calendar-months text-xs text-zinc-400" aria-hidden="true">
                    @foreach($report['cells'] as $i => $cell)
                        @if(substr($cell['date'], -2) === '01')
                            <span data-month="{{ substr($cell['date'], 0, 7) }}" style="grid-column: {{ intdiv($report['offset'] + $i, 7) + 1 }}">{{ \Carbon\CarbonImmutable::parse($cell['date'])->format('M') }}</span>
                        @endif
                    @endforeach
                </div>
                <div x-ref="days" class="calendar-days">
                    @for($i = 0; $i < $report['offset']; $i++)<span aria-hidden="true"></span>@endfor
                    @foreach($report['cells'] as $i => $cell)
                        <button type="button" class="heat-cell heat-band-{{ $cell['band'] }}" data-date="{{ $cell['date'] }}" data-focus-key="calendar-{{ $cell['date'] }}" data-state="{{ $cell['state'] }}"
                            :tabindex="index === {{ $i }} ? 0 : -1" @focus="index = {{ $i }}"
                            aria-label="{{ $cell['date'] }}: {{ $cell['label'] }}{{ $cell['score'] === null ? '' : ', '.number_format($cell['score'] * 100, 1).'% performance' }}; {{ $cell['pending'] }} pending; {{ $cell['unavailable'] }} unavailable"
                            @click="origin = 'calendar-{{ $cell['date'] }}'; detailOpen = true; await $wire.selectDay('{{ $cell['date'] }}'); $nextTick(() => $refs.detailHeading?.focus())"><span aria-hidden="true">{{ $cell['symbol'] }}</span></button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <p class="mt-3 text-xs leading-relaxed text-zinc-400">January → December. Scroll the calendar horizontally when needed, or use the accessible day list below.</p>
</div>

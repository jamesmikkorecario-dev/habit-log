@props(['report'])
<div class="min-w-0 overflow-x-auto rounded-lg border border-zinc-800 p-3" wire:key="calendar-{{ $report['year'] }}"
    x-data="{
        index: {{ max(0, array_search($report['today'], array_column($report['cells'], 'date'), true) ?: 0) }},
        init() { this.$nextTick(() => { const button = this.$refs.days?.querySelectorAll('button')[this.index]; if (button) this.$el.scrollLeft = Math.max(0, button.offsetLeft - this.$el.offsetLeft - this.$el.clientWidth / 2); }); },
        move(event) {
            const steps = { ArrowLeft: -7, ArrowRight: 7, ArrowUp: -1, ArrowDown: 1, Home: -366, End: 366 };
            if (!(event.key in steps)) return;
            event.preventDefault();
            const buttons = this.$refs.days.querySelectorAll('button');
            this.index = Math.max(0, Math.min(buttons.length - 1, this.index + steps[event.key]));
            buttons[this.index].focus();
        }
    }" @keydown="move($event)">
    <div class="flex w-max gap-2">
        <div class="sticky left-0 z-10 grid grid-rows-7 gap-1 bg-zinc-950 pr-1 pt-5 text-xs text-zinc-400" aria-hidden="true">@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)<span class="h-5 leading-5">{{ $day }}</span>@endforeach</div>
        <div>
            <div class="mb-1 flex h-4 justify-between text-xs text-zinc-400" aria-hidden="true">@foreach(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $month)<span>{{ $month }}</span>@endforeach</div>
            <div x-ref="days" class="grid grid-flow-col grid-rows-7 gap-1">
                @for($i = 0; $i < $report['offset']; $i++)<span class="h-5 w-5" aria-hidden="true"></span>@endfor
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

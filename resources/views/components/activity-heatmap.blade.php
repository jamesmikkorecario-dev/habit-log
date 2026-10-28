@props(['report', 'minimal' => false])
@php($columns = (int) ceil(($report['offset'] + count($report['cells'])) / 7))
<div @class(['activity-calendar min-w-0', 'activity-calendar-minimal' => $minimal]) wire:key="calendar-{{ $report['year'] }}" style="--calendar-columns: {{ $columns }}"
    x-data="activityCalendar(@js($minimal))" @keydown="move($event)"
    @keydown.escape.window="if (preview) { $event.preventDefault(); dismissPreview(); }"
    @mouseleave="leavePreview()" @focusout="if (!$el.contains($event.relatedTarget)) hidePreview()"
    @resize.window="positionPreview()" @scroll.window="positionPreview()">
    <div class="grid min-w-0 grid-cols-[2.75rem_minmax(0,1fr)] gap-2" dir="ltr">
        <div class="calendar-weekdays text-xs text-zinc-400" aria-hidden="true">
            @foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)<span class="flex items-center">{{ $day }}</span>@endforeach
        </div>
        <div x-ref="scroll" data-calendar-scroll tabindex="0" role="region" aria-label="{{ $report['year'] }} activity calendar" @scroll="positionPreview()" class="min-w-0 overflow-x-auto overscroll-x-contain rounded-md border border-zinc-800 p-1 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-300">
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
                            :tabindex="index === {{ $i }} ? 0 : -1" @focus="index = {{ $i }}; showPreview($el)" @mouseenter="showPreview($el, true)"
                            @mousemove="if (dismissed && ($event.movementX || $event.movementY)) { dismissed = false; showPreview($el, true); }"
                            @if($minimal)
                                data-preview-outcome="{{ $cell['label'] }} · {{ $cell['score'] === null ? 'No performance score' : number_format($cell['score'] * 100, 1).'% performance' }}{{ $cell['pending'] && $cell['score'] !== null ? ' · Provisional' : '' }}"
                                data-preview-counts="{{ $cell['count'] }} included · {{ $cell['na'] }} N/A · {{ $cell['pending'] }} pending · {{ $cell['unlogged'] }} unlogged · {{ $cell['unavailable'] }} unavailable"
                                :aria-describedby="previewDate === '{{ $cell['date'] }}' ? 'calendar-preview-{{ $report['year'] }}' : null"
                            @endif
                            aria-label="{{ $cell['date'] }}: {{ $cell['label'] }}{{ $cell['score'] === null ? '' : ', '.number_format($cell['score'] * 100, 1).'% performance' }}; {{ $cell['pending'] }} pending; {{ $cell['unavailable'] }} unavailable"
                            @click="hidePreview(); origin = 'calendar-{{ $cell['date'] }}'; detailOpen = true; await $wire.selectDay('{{ $cell['date'] }}'); $nextTick(() => $refs.detailHeading?.focus())">@unless($minimal)<span aria-hidden="true">{{ $cell['symbol'] }}</span>@endunless</button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    @if($minimal)
        <div x-ref="preview" x-cloak id="calendar-preview-{{ $report['year'] }}" role="tooltip" style="visibility: hidden"
            class="calendar-preview fixed z-50 w-72 max-w-[calc(100vw-1.5rem)] rounded-lg border border-zinc-600 bg-zinc-900 px-3 py-2 text-xs leading-relaxed text-zinc-300 shadow-lg"
            :style="{ visibility: preview ? 'visible' : 'hidden', left: (preview ? previewLeft : 0) + 'px', top: (preview ? previewTop : 0) + 'px' }">
            <p class="font-mono font-medium text-zinc-100" x-text="preview?.date"></p>
            <p x-text="preview?.outcome"></p>
            <p class="mt-1 text-zinc-400" x-text="preview?.counts"></p>
        </div>
    @endif
    <p class="mt-3 text-xs leading-relaxed text-zinc-400">January → December. Scroll the calendar horizontally when needed, or use {{ $minimal ? 'Browse dates' : 'the accessible day list' }} below.</p>
</div>

@props(['setup', 'heading', 'id', 'description'])

<section class="min-w-0 space-y-5 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 sm:p-6" aria-labelledby="{{ $id }}">
    <div>
        <h2 id="{{ $id }}" class="text-lg font-semibold text-zinc-100">{{ $heading }}</h2>
        <p class="mt-2 text-sm leading-relaxed text-zinc-400">{{ $description }}</p>
    </div>
    <dl class="grid gap-5 text-sm sm:grid-cols-2">
        <div><dt class="text-zinc-400">Tracking type</dt><dd class="mt-1 text-zinc-200">{{ $setup->type->label() }}</dd></div>
        <div><dt class="text-zinc-400">Start date</dt><dd class="mt-1 text-zinc-200">{{ $setup->start_date->toDateString() }}</dd></div>
        <div><dt class="text-zinc-400">End date</dt><dd class="mt-1 text-zinc-200">{{ $setup->end_date?->toDateString() ?? 'No end date' }}</dd></div>
        @if(in_array($setup->type->value, ['count', 'duration', 'avoidance'], true))
            <div class="min-w-0">
                <dt class="text-zinc-400">{{ $setup->type->value === 'avoidance' ? 'Acceptable maximum' : 'Goal' }}</dt>
                <dd class="mt-1 break-words text-zinc-200">{{ $setup->goal_value === null ? 'No numeric goal' : $setup->goal_value }}@if($setup->goal_value !== null && $setup->goal_unit) {{ $setup->goal_unit }}@endif</dd>
            </div>
            @if($setup->goal_value === null && $setup->goal_unit)
                <div class="min-w-0"><dt class="text-zinc-400">Recorded in</dt><dd class="mt-1 break-words text-zinc-200">{{ $setup->goal_unit }}</dd></div>
            @endif
        @endif
    </dl>
    @if($setup->type->value === 'checkpoint')
        <div class="space-y-3 border-t border-zinc-800 pt-4">
            <h3 class="text-sm font-medium text-zinc-200">Checkpoints</h3>
            <ol class="list-outside list-decimal space-y-2 pl-5 text-sm leading-relaxed text-zinc-300">
                @foreach($setup->checkpoints as $checkpoint)<li class="break-words pl-1">{{ $checkpoint->label }}</li>@endforeach
            </ol>
        </div>
    @elseif($setup->type->value === 'boolean')
        <p class="text-sm leading-relaxed text-zinc-400">Record whether you completed the habit, or mark the day not applicable.</p>
    @elseif($setup->goal_value === null)
        <p class="text-sm leading-relaxed text-zinc-400">Record your measurement and personal assessment. Without a numeric goal, your assessment determines performance.</p>
    @elseif($setup->type->value === 'avoidance')
        <p class="text-sm leading-relaxed text-zinc-400">At or below this maximum counts as success. A zero maximum means abstinence.</p>
    @endif
</section>

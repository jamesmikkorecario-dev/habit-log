@props(['current', 'pending'])
@if($current)
<section class="space-y-2 rounded-xl border border-emerald-900/50 p-4" aria-label="Effective tracking setup">
    <h2 class="font-medium">Current tracking setup</h2>
    <p>{{ $current->type->label() }} · {{ $current->start_date->toDateString() }} to {{ $current->end_date?->toDateString() ?? 'no end date' }}</p>
    @if($current->goal_value !== null)<p>{{ $current->type->value === 'avoidance' ? 'Maximum' : 'Goal' }}: {{ $current->goal_value }} {{ $current->goal_unit }}</p>@endif
    @if($current->checkpoints->isNotEmpty())<ol class="list-inside list-decimal">@foreach($current->checkpoints as $checkpoint)<li class="break-words">{{ $checkpoint->label }}</li>@endforeach</ol>@endif
    @if($pending)<p class="text-emerald-300">Pending setup: {{ $pending->type->label() }}, effective {{ $pending->effective_date->toDateString() }}. The configuration fields show this pending setup.</p>@endif
</section>
@endif

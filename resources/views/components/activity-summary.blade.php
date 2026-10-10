@props(['report', 'emptyMessages', 'newAccount' => false])
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @foreach(['current' => 'Current streak', 'longest' => 'Longest streak'] as $key => $label)
        <article class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5" aria-label="{{ $label }}">
            <h3 class="text-sm text-zinc-400">{{ $label }}</h3>
            <p class="mt-3 font-mono text-3xl font-semibold text-emerald-300">{{ $report['streaks'][$key] }} <span class="font-sans text-sm font-normal text-zinc-400">days</span></p>
            <p class="mt-3 text-xs leading-relaxed text-zinc-400">{{ $newAccount ? 'Create your first habit to get started.' : ($key === 'current' ? 'Your current run of fully successful days.' : 'Your longest run across all history.') }}</p>
        </article>
    @endforeach
    @foreach(['recent' => '30-day consistency', 'yearly' => 'Yearly consistency'] as $key => $label)
        <article class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5" aria-label="{{ $label }}">
            <h3 class="text-sm text-zinc-400">{{ $label }}</h3>
            <p class="mt-3 font-mono text-3xl font-semibold">{{ $report[$key]['percent'] === null ? '—' : number_format($report[$key]['percent'], 1).'%' }}</p>
            <p class="mt-3 text-xs leading-relaxed text-zinc-400">{{ $emptyMessages[$key] ?? (($key === 'recent' ? 'Average performance over the last 30 days.' : $report['year'].' average performance through today.').' Partial progress counts.') }}</p>
        </article>
    @endforeach
</div>

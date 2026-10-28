@props(['report', 'habitId', 'subjectId'])
<div class="space-y-3 leading-relaxed">
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

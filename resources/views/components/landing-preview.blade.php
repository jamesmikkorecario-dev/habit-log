{{-- Illustrative content only: never query or render a user's activity here. --}}
<figure aria-labelledby="preview-caption" class="min-w-0 rounded-xl border border-zinc-700 bg-zinc-900 shadow-lg shadow-black/20">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-700 px-5 py-4 sm:px-6">
        <span class="flex items-center gap-2 font-mono text-xs text-zinc-300"><span class="size-1.5 rounded-full bg-emerald-400" aria-hidden="true"></span>Your daily log</span>
        <span class="font-mono text-xs text-zinc-400">Illustrative preview</span>
    </div>
    <div class="px-5 py-6 sm:px-6 sm:py-7">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-xl font-semibold tracking-tight">A day, well spent.</p>
            <span class="font-mono text-xs text-zinc-400">Sample day</span>
        </div>
        <ul class="mt-5 divide-y divide-zinc-800">
            <li class="flex items-center gap-3 py-4">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-md border border-emerald-700 bg-emerald-950 text-emerald-300"><flux:icon.check class="size-4" aria-hidden="true" /></span>
                <div class="min-w-0 flex-1"><p class="text-sm font-medium">Read a few pages</p><p class="mt-1 font-mono text-xs text-zinc-400">Yes / no</p></div>
                <span class="text-xs font-medium text-emerald-300">Completed</span>
            </li>
            <li class="flex items-center gap-3 py-4">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-md border border-zinc-600 text-zinc-300" aria-hidden="true">◐</span>
                <div class="min-w-0 flex-1"><p class="text-sm font-medium">Move your body</p><p class="mt-1 font-mono text-xs text-zinc-400">Duration · partial</p></div>
                <span class="text-xs text-zinc-300">20 / 30 min</span>
            </li>
            <li class="flex items-center gap-3 py-4">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-md border border-emerald-700 bg-emerald-950 text-emerald-300"><flux:icon.check class="size-4" aria-hidden="true" /></span>
                <div class="min-w-0 flex-1"><p class="text-sm font-medium">Less caffeine</p><p class="mt-1 font-mono text-xs text-zinc-400">Avoidance · within limit</p></div>
                <span class="text-xs text-emerald-300">1 / 2 cups</span>
            </li>
        </ul>
        <div class="mt-6 border-t border-zinc-700 pt-5">
            <p class="mb-4 font-mono text-xs text-zinc-300">Every day adds to the picture.</p>
            <div aria-hidden="true" class="grid grid-flow-col grid-rows-7 gap-1">
                @foreach (['0102100', '1210210', '2101321', '1223032', '0321231', '2132230', '3212321', '1223132', '2313232', '3221343', '2332342', '3243343', '3432344', '2333432', '3342343', '3433434', '4234343', '3443444'] as $week)
                    @foreach (str_split($week) as $band)
                        <span @class(['heat-cell aspect-square h-auto! w-full!', 'heat-band-'.$band])></span>
                    @endforeach
                @endforeach
            </div>
            <div class="mt-3 flex items-center justify-between gap-2 font-mono text-xs text-zinc-400">
                <span>Your activity, over time</span>
                <span class="flex items-center gap-1.5" aria-hidden="true"><span class="mr-1">Less</span>@foreach (range(0, 4) as $band)<span @class(['heat-cell size-2.5!', 'heat-band-'.$band])></span>@endforeach<span class="ml-1">More</span></span>
            </div>
        </div>
    </div>
    <figcaption id="preview-caption" class="border-t border-zinc-700 px-5 py-4 text-xs leading-relaxed text-zinc-400 sm:px-6">A glimpse of tracking and progress in habit.log. Sample habits and activity, just to show what’s possible.</figcaption>
</figure>

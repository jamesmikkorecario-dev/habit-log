<?php

namespace App\Actions\Habits;

use App\Models\Category;
use App\Models\Habit;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveHabit
{
    public function handle(User $user, ?int $habitId, array $validated): Habit
    {
        return DB::transaction(function () use ($user, $habitId, $validated) {
            $user = $user->newQuery()->whereKey($user->id)->sharedLock()->firstOrFail();
            $habit = $habitId === null ? new Habit : $user->habits()->lockForUpdate()->findOrFail($habitId);
            Gate::forUser($user)->authorize($habitId === null ? 'create' : 'update', $habitId === null ? Habit::class : $habit);
            $category = Category::availableTo($user)->find($validated['category_id']);
            if (! $category) {
                throw ValidationException::withMessages(['form.category_id' => 'Choose an available category.']);
            }
            Gate::forUser($user)->authorize('view', $category);

            $existing = $habitId === null ? collect() : $habit->checkpoints()->get()->keyBy('id');
            foreach ($validated['checkpoints'] as $checkpoint) {
                if (isset($checkpoint['id']) && ! $existing->has($checkpoint['id'])) {
                    throw ValidationException::withMessages(['form.checkpoints' => 'A checkpoint changed. Reload this habit and try again.']);
                }
            }

            $habit->fill(Arr::except($validated, 'checkpoints'));
            $habit->user()->associate($user);
            $habit->save();

            $retained = [];
            foreach ($validated['checkpoints'] as $order => $data) {
                $checkpoint = isset($data['id']) ? $existing->get($data['id']) : $habit->checkpoints()->make();
                $checkpoint->fill(['label' => $data['label'], 'sort_order' => $order])->save();
                $retained[] = $checkpoint->id;
            }
            $habit->checkpoints()->whereNotIn('id', $retained)->delete();

            app(SyncTrackingVersion::class)->handle($user, $habit);

            return $habit;
        });
    }
}

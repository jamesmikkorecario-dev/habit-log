<?php

namespace App\Actions\Accounts;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageSessions
{
    public function listing(Request $request): array
    {
        if (! SessionSecurity::supported()) {
            return ['available' => false];
        }
        $user = $request->user();
        $query = DB::table('sessions')->where('user_id', $user->id)
            ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
            ->where('id', '!=', $request->session()->getId())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('session_revocations')
                ->whereRaw('session_hash = sessions.origin_hash OR session_hash = SHA2(sessions.id, 256)'));
        $sessions = $query->orderByDesc('last_activity')->orderBy('id')->paginate(10, ['id', 'management_id', 'user_agent', 'last_activity']);
        $sessions->through(function ($row) {
            // Legacy/manual rows can lack metadata. Assign a public selector;
            // session identifiers and payloads never leave the server.
            if (! $row->management_id) {
                DB::table('sessions')->where('id', $row->id)->whereNull('management_id')->update(['management_id' => (string) Str::uuid()]);
                $row->management_id = DB::table('sessions')->where('id', $row->id)->value('management_id');
            }

            return ['key' => $row->management_id, 'device' => self::device($row->user_agent), 'last_active' => CarbonImmutable::createFromTimestamp($row->last_activity)->setTimezone(auth()->user()->timezone)->format('M j, Y H:i')];
        });

        return ['available' => true, 'sessions' => $sessions, 'currentDevice' => self::device($request->userAgent())];
    }

    public static function device(?string $agent): string
    {
        $agent ??= '';
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari', default => 'Unknown browser',
        };
        $device = match (true) {
            str_contains($agent, 'iPad') => 'iPad', str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => 'Android', str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac', str_contains($agent, 'Linux') => 'Linux', default => 'unknown device',
        };

        return $browser.' on '.$device;
    }

    public function revoke(Request $request, #[\SensitiveParameter] string $password, ?string $target): void
    {
        abort_unless(SessionSecurity::supported(), 503);
        $user = $request->user();
        abort_unless($user && Auth::id() === $user->id, 403);
        $fresh = DB::transaction(function () use ($user, $request, $password, $target) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            if (! $fresh || ! hash_equals($fresh->password, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your session changed. Reload Security Settings and try again.']);
            }
            if (! Hash::check($password, $fresh->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password could not be verified.']);
            }
            $rows = DB::table('sessions')->where('user_id', $fresh->id)->where('id', '!=', $request->session()->getId())
                ->when($target !== null, fn ($q) => $q->where('management_id', $target)->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp))
                ->lockForUpdate()->get(['id', 'origin_hash']);
            if ($target !== null && ($rows->isEmpty() || SessionSecurity::revoked(hash('sha256', $rows->first()->id), $rows->first()->origin_hash ?? hash('sha256', $rows->first()->id)))) {
                throw ValidationException::withMessages(['session_target' => 'This session is no longer available. Refresh the list.']);
            }
            SessionSecurity::revokeRows($fresh, $rows);
            $fresh->remember_token = Str::random(60);
            if ($target === null) {
                $fresh->password = Hash::make($password);
            }
            $fresh->save();

            return $fresh;
        });
        $user->setRawAttributes($fresh->getAttributes(), true);
        $request->attributes->remove('session_recalled_token');
        app(FinalizeCredentialSession::class)->handle($request);
    }
}

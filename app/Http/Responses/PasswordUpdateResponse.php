<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function toResponse($request)
    {
        $guard = Auth::guard(config('fortify.guard'));
        $request->session()->put('password_hash_'.config('fortify.guard'), $guard->hashPasswordForCookie($request->user()->getAuthPassword()));
        $request->session()->regenerate(true);
        $request->session()->forget('auth.password_confirmed_at');
        Cookie::queue(Cookie::forget($guard->getRecallerName()));

        $message = __('Password changed. Your other sessions and remembered logins have been signed out.');

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('settings.security')->with('password-status', $message);
    }
}

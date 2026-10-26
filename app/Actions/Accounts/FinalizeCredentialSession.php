<?php

namespace App\Actions\Accounts;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

class FinalizeCredentialSession
{
    public function handle(Request $request): void
    {
        // This response has committed a deliberate credential rotation. Do not
        // compare its final write with the remember token used for admission.
        $request->attributes->remove('session_recalled_token');
        $guard = Auth::guard(config('fortify.guard'));
        $request->session()->put('password_hash_'.config('fortify.guard'), $guard->hashPasswordForCookie($request->user()->getAuthPassword()));
        $request->session()->regenerate(true);
        $request->session()->forget('auth.password_confirmed_at');
        Cookie::queue(Cookie::forget($guard->getRecallerName()));
    }
}

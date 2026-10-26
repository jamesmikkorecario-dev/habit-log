<?php

namespace App\Http\Responses;

use App\Actions\Accounts\FinalizeCredentialSession;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function toResponse($request)
    {
        app(FinalizeCredentialSession::class)->handle($request);

        $message = __('Password changed. Your other sessions and remembered logins have been signed out.');

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : redirect()->route('settings.security')->with('password-status', $message);
    }
}

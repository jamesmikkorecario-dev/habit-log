<?php

namespace App\Http\Responses;

use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class RecoveryLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(private string $status) {}

    public function toResponse($request)
    {
        if (! in_array($this->status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return (new \Laravel\Fortify\Http\Responses\FailedPasswordResetLinkRequestResponse($this->status))->toResponse($request);
        }

        $message = __('If an account matches that email, a password reset link will be sent. Please wait a minute before trying again.');

        return $request->wantsJson()
            ? response()->json(['message' => $message])
            : back()->withInput($request->only('email'))->with('status', $message);
    }
}

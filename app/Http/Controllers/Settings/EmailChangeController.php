<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Accounts\EmailChanges;
use App\Actions\Accounts\FinalizeCredentialSession;
use App\Actions\Accounts\ManageSessions;
use App\Http\Controllers\Controller;
use App\Models\PendingEmailChange;
use App\Notifications\EmailChanged;
use App\Notifications\VerifyEmailChange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class EmailChangeController extends Controller
{
    public function settings(Request $request)
    {
        return view('pages.settings.security', ['pendingEmail' => PendingEmailChange::where('user_id', $request->user()->id)->first(), 'sessionListing' => app(ManageSessions::class)->listing($request)]);
    }

    public function store(Request $request, EmailChanges $changes)
    {
        $resend = $request->routeIs('settings.email.resend');
        $bag = $resend ? 'emailResend' : 'emailRequest';
        try {
            [$pending, $url] = $changes->request($request->user(), $request->only('current_password', 'new_email', 'generation'), $resend);
        } catch (ValidationException $exception) {
            $email = $request->input('new_email');

            return redirect()->route('settings.security')->withErrors($exception->errors(), $bag)
                ->withInput(is_string($email) ? ['new_email' => mb_substr($email, 0, 255)] : []);
        }
        try {
            // Production authentication links must never go to application logs.
            app('mail.manager')->assertSafeMailer();
            Notification::route('mail', $pending->new_email)->notify(new VerifyEmailChange($url));
        } catch (\Throwable) {
            return redirect()->route('settings.security')->withErrors(['email_change' => 'We could not deliver the verification email. Your current email has not changed. Wait one minute, then retry.'], $bag);
        }

        return redirect()->route('settings.security')->with('email-status', 'Verification email sent. Open it to confirm the change within 60 minutes. Your current email remains active.');
    }

    public function cancel(Request $request, EmailChanges $changes)
    {
        try {
            $data = $request->validateWithBag('emailCancel', ['generation' => ['required', 'string', 'max:36']]);
            $changes->cancel($request->user(), $data['generation']);
        } catch (ValidationException $exception) {
            return redirect()->route('settings.security')->withErrors($exception->errors(), 'emailCancel');
        }

        return redirect()->route('settings.security')->with('email-status', 'Email change cancelled. Your current email remains active.');
    }

    public function verify(Request $request, EmailChanges $changes)
    {
        $pending = $request->hasValidSignature() ? $changes->pending($request->user(), $request->query()) : null;

        return view('pages.settings.verify-email-change', ['pendingEmail' => $pending, 'verificationUrl' => $request->fullUrl()]);
    }

    public function confirm(Request $request, EmailChanges $changes, FinalizeCredentialSession $session)
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('settings.security')->withErrors(['email_change' => EmailChanges::INVALID], 'emailConfirm');
        }
        try {
            $oldEmail = $changes->confirm($request->user(), $request->query(), $request->only('current_password'), $request->session()->getId());
        } catch (ValidationException $exception) {
            // Never flash the signed query, token or password into old input.
            return redirect()->to($request->fullUrl())->withErrors($exception->errors(), 'emailConfirm');
        }
        $session->handle($request);
        $message = 'Email changed. Your other sessions and remembered logins have been signed out.';
        try {
            app('mail.manager')->assertSafeMailer();
            Notification::route('mail', $oldEmail)->notify(new EmailChanged);
        } catch (\Throwable) {
            $message .= ' We could not deliver the security alert to your previous address.';
        }

        return redirect()->route('settings.security')->with('email-status', $message);
    }
}

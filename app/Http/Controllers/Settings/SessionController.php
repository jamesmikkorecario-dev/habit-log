<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Accounts\ManageSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function destroy(Request $request, ManageSessions $sessions)
    {
        try {
            $data = $request->validate(['current_password' => ['required', 'string'], 'session_target' => ['required', 'string', 'max:36']]);
            if ($data['session_target'] !== 'all' && ! Str::isUuid($data['session_target'])) {
                throw ValidationException::withMessages(['session_target' => 'This session is no longer available. Refresh the list.']);
            }
            $sessions->revoke($request, $data['current_password'], $data['session_target'] === 'all' ? null : $data['session_target']);
        } catch (ValidationException $e) {
            return redirect()->route('settings.security')->withErrors($e->errors(), 'sessionRevoke');
        }

        return redirect()->route('settings.security')->with('session-status', $data['session_target'] === 'all'
            ? 'Other sessions signed out. You are still signed in here.'
            : 'Selected session signed out. Other active sessions remain signed in. Remembered sign-ins have been cleared.');
    }
}

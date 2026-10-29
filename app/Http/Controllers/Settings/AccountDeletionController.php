<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Accounts\DeleteAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AccountDeletionController extends Controller
{
    public function destroy(Request $request, DeleteAccount $delete)
    {
        try {
            $delete->handle($request, $request->only('current_password', 'acknowledge_deletion', 'account_id'));
        } catch (ValidationException $exception) {
            return redirect()->route('settings.security')->withErrors($exception->errors(), 'accountDeletion');
        }
        // Do not log out until the complete deletion has committed. Avoid saving
        // a remember token onto the now-deleted user model during normal logout.
        Auth::guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.deleted');
    }
}

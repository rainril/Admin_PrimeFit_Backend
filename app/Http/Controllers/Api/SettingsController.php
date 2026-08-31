<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    // GET /api/settings/{accountId} - kunin ang kasalukuyang settings
    public function show($accountId)
    {
        $admin = Admin::where('account_id', $accountId)->firstOrFail();

        return response()->json([
            'notificationsEnabled' => (bool) $admin->notifications_enabled,
            'darkMode' => (bool) $admin->dark_mode,
        ]);
    }

    // PUT /api/settings/{accountId} - i-update ang notifications/dark mode
    public function update(Request $request, $accountId)
    {
        $request->validate([
            'notificationsEnabled' => 'sometimes|boolean',
            'darkMode' => 'sometimes|boolean',
        ]);

        $admin = Admin::where('account_id', $accountId)->firstOrFail();

        if ($request->has('notificationsEnabled')) {
            $admin->notifications_enabled = $request->notificationsEnabled;
        }
        if ($request->has('darkMode')) {
            $admin->dark_mode = $request->darkMode;
        }
        $admin->save();

        return response()->json([
            'message' => 'Settings updated successfully',
            'notificationsEnabled' => (bool) $admin->notifications_enabled,
            'darkMode' => (bool) $admin->dark_mode,
        ]);
    }

    // POST /api/change-password
    public function changePassword(Request $request)
    {
        $request->validate([
            'accountId' => 'required|integer',
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:6',
        ]);

        $account = Account::find($request->accountId);

        if (!$account || !Hash::check($request->currentPassword, $account->password)) {
            throw ValidationException::withMessages([
                'currentPassword' => ['Current password is incorrect.'],
            ]);
        }

        $account->password = Hash::make($request->newPassword);
        $account->save();

        return response()->json(['message' => 'Password changed successfully']);
    }
}
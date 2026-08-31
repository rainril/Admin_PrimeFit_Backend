<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetCode;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:accounts',
            'password' => 'required|string|min:6',
            'adminLevel' => 'required|in:owner,staff',
        ]);

        $account = Account::create([
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin',
        ]);

        $admin = Admin::create([
            'account_id' => $account->id,
            'first_name' => $request->firstName,
            'last_name' => $request->lastName,
            'admin_level' => $request->adminLevel,
        ]);

        $token = $account->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registered successfully',
            'accountId' => $account->id,
            'firstName' => $admin->first_name,
            'lastName' => $admin->last_name,
            'adminLevel' => $admin->admin_level,
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        $account = Account::where('email', $request->email)->first();

        if (! $account || ! Hash::check($request->password, $account->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $admin = Admin::where('account_id', $account->id)->first();

        if (! $admin) {
            throw ValidationException::withMessages([
                'email' => ['This account is not registered as staff or admin.'],
            ]);
        }

        $token = $account->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'accountId' => $account->id,
            'firstName' => $admin->first_name,
            'lastName' => $admin->last_name,
            'adminLevel' => $admin->admin_level,
            'token' => $token,
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
        ]);

        $account = Account::where('email', $request->email)->first();

        if (!$account) {
            return response()->json([
                'message' => 'If that email exists, a reset code has been sent.',
            ]);
        }

        $code = random_int(100000, 999999);

        $account->reset_code = $code;
        $account->reset_code_expires_at = now()->addMinutes(15);
        $account->save();

        Mail::to($account->email)->send(new PasswordResetCode((string) $code));

        return response()->json([
            'message' => 'If that email exists, a reset code has been sent.',
        ]);
    }

    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string',
        ]);

        $account = Account::where('email', $request->email)->first();

        if (!$account || $account->reset_code !== $request->code) {
            throw ValidationException::withMessages([
                'code' => ['Invalid reset code.'],
            ]);
        }

        if (!$account->reset_code_expires_at || now()->greaterThan($account->reset_code_expires_at)) {
            throw ValidationException::withMessages([
                'code' => ['Reset code has expired. Please request a new one.'],
            ]);
        }

        return response()->json([
            'message' => 'Code verified successfully.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'code' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        $account = Account::where('email', $request->email)->first();

        if (!$account || $account->reset_code !== $request->code) {
            throw ValidationException::withMessages([
                'code' => ['Invalid reset code.'],
            ]);
        }

        if (!$account->reset_code_expires_at || now()->greaterThan($account->reset_code_expires_at)) {
            throw ValidationException::withMessages([
                'code' => ['Reset code has expired. Please request a new one.'],
            ]);
        }

        $account->password = Hash::make($request->password);
        $account->reset_code = null;
        $account->reset_code_expires_at = null;
        $account->save();

        return response()->json([
            'message' => 'Password reset successfully.',
        ]);
    }
}
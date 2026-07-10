<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials']]);
        }

        $plainToken = Str::random(60);
        $user->forceFill(['api_token' => hash('sha256', $plainToken)])->save();

        return response()->json(['accessToken' => $plainToken]);
    }

    /**
     * Lets the admin rotate their own password/email from the API. Important on hosts with no
     * shell access (e.g. cPanel without SSH), where editing the users table by hand would
     * otherwise be the only way to change the seeded credentials.
     */
    public function updateCredentials(Request $request)
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['sometimes', 'string', 'min:8'],
            'newEmail' => ['sometimes', 'email'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            throw ValidationException::withMessages(['currentPassword' => ['Incorrect password']]);
        }

        if (isset($data['newPassword'])) {
            $user->password = $data['newPassword'];
        }
        if (isset($data['newEmail'])) {
            $user->email = $data['newEmail'];
        }
        $user->save();

        return response()->json(['updated' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:255', 'device_name' => 'sometimes|string|max:100']);
        $user = User::where('email', strtolower($d['email']))->first();
        if (! $user || ! $user->is_active || ! Hash::check($d['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }
        $token = $user->createToken($d['device_name'] ?? 'api-client', ['*'], now()->addDay());

        return response()->json(['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_in' => 86400, 'user' => $user]);
    }

    public function me(Request $r)
    {
        return response()->json(['data' => $r->user()]);
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);

        return User::orderBy('id')->paginate($d['per_page'] ?? 20);
    }

    public function store(Request $r)
    {
        $r->merge(['email' => strtolower((string) $r->input('email'))]);
        $d = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()], 'role' => 'required|in:manager,warehouse,sales']);

        return response()->json(['data' => User::create($d)], 201);
    }

    public function update(Request $r, User $user)
    {
        if ($r->has('email')) {
            $r->merge(['email' => strtolower((string) $r->input('email'))]);
        }
        $d = $r->validate(['name' => 'sometimes|required|string|max:100', 'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)], 'password' => ['sometimes', 'required', 'string', 'max:255', Password::min(12)->letters()->numbers()], 'role' => 'sometimes|required|in:manager,warehouse,sales', 'is_active' => 'sometimes|boolean']);

        return DB::transaction(function () use ($user, $d) {
            DB::select("SELECT pg_advisory_xact_lock(hashtextextended('active-managers',0))");
            $user->refresh();
            if ($user->role === 'manager' && $user->is_active && (($d['role'] ?? 'manager') !== 'manager' || array_key_exists('is_active', $d) && ! $d['is_active'])) {
                abort_if(User::where('role', 'manager')->where('is_active', true)->count() <= 1, 409, 'The last active manager cannot be disabled or demoted.');
            }
            $user->update($d);
            if (array_key_exists('password', $d) || array_key_exists('is_active', $d) && ! $d['is_active']) {
                $user->tokens()->delete();
            }

            return response()->json(['data' => $user]);
        }, 3);
    }
}

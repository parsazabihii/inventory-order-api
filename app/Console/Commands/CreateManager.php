<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateManager extends Command
{
    protected $signature = 'inventory:create-manager {email} {--name=Manager}';

    protected $description = 'Create a manager account with an interactively entered password';

    public function handle(): int
    {
        $d = ['email' => strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Password (12+ characters, letters and numbers)')];
        $v = Validator::make($d, ['email' => 'required|email|max:255|unique:users,email', 'name' => 'required|string|max:100', 'password' => ['required', 'string', 'max:255', Password::min(12)->letters()->numbers()]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        User::create([...$d, 'role' => 'manager']);
        $this->info('Manager created. Log in through POST /api/v1/auth/login.');

        return self::SUCCESS;
    }
}

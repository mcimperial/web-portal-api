<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * One-off user insertion command, safe to run directly in production.
 *
 * Unlike `php artisan db:seed`, custom Artisan commands are not wrapped in
 * Laravel's "ConfirmableTrait" production safety prompt, so this can be run
 * on Forge (or any production server) without needing --force and without
 * being interactively cancelled.
 *
 * Usage:
 *   php artisan user:create-anne-ly
 */
class CreateAnnelyUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create-annely';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create (or update) the Ann ELy user and assign the employee-se role';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = 'annely@llibi.com';
        $roleSlug = 'employee-se';

        $role = Role::where('slug', $roleSlug)->first();

        if (!$role) {
            $this->error("Role with slug '{$roleSlug}' was not found. Aborting.");
            return self::FAILURE;
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'role' => 'employee-se',
                'name' => 'Ann ELy',
                'password' => Hash::make('#@!12DcAnnELy##@!'),
                'email_verified_at' => now(),
            ]
        );

        $user->roles()->sync($role->id);

        $this->info("User ready: [{$user->id}] {$user->name} <{$user->email}> with role '{$roleSlug}'.");

        return self::SUCCESS;
    }
}

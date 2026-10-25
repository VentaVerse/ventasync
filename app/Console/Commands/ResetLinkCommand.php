<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\ResetLink;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetLinkCommand extends Command
{
    protected $signature = 'ventasync:reset-link {username? : The user who cannot sign in}';

    protected $description = 'Print a one-time link where a user sets a new username and password, for when email cannot be used';

    public function handle(): int
    {
        $username = $this->argument('username');
        $user = filled($username) ? User::where('username', $username)->first() : null;

        if ($user === null) {
            if (filled($username)) {
                $this->error("No user is named {$username}.");
            }
            $this->administrators();

            return filled($username) ? self::FAILURE : self::SUCCESS;
        }

        $this->line(ResetLink::for($user));
        $this->info('Works once, for ' . ResetLink::MINUTES . ' minutes. Send it to ' . $user->name . ' directly.');

        return self::SUCCESS;
    }

    private function administrators(): void
    {
        $group = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        $rows = User::where('user_group_id', $group)->orderBy('username')->get(['username', 'name', 'email']);

        if ($rows->isEmpty()) {
            $this->line('There are no administrators. Open /setup to create the first account.');

            return;
        }

        $this->line('Administrators:');
        $this->table(['Username', 'Name', 'Email'], $rows->map->only('username', 'name', 'email')->all());
        $this->line('Run ventasync:reset-link <username> for the link.');
    }
}

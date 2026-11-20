<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reviewer:token {email=admin@gmail.com} {--name=reviewer} {--days=7}')]
#[Description('Issue a short-lived Sanctum token for an active account')]
class IssueReviewerToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $user = \App\Models\User::query()->where('email', $this->argument('email'))->where('is_active', true)->first();
        if ($user === null || (int) $this->option('days') < 1 || (int) $this->option('days') > 30) {
            $this->error('Active account and 1–30 day expiry required.');
            return self::FAILURE;
        }
        $token = $user->createToken((string) $this->option('name'), ['*'], now()->addDays((int) $this->option('days')));
        $this->line($token->plainTextToken);
        return self::SUCCESS;
    }
}

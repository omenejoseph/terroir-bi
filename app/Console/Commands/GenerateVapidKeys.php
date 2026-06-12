<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a fresh VAPID keypair for Web Push. One-off operator task: run it, then
 * paste the keys into the backend `.env` (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY)
 * and the frontend `.env.local` (NEXT_PUBLIC_VAPID_PUBLIC_KEY). Keys are never
 * stored by the app — losing them just means every device must re-subscribe.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Generate a VAPID keypair for Web Push notifications';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('VAPID keys generated. Add these to your environment:');
        $this->newLine();
        $this->line('# backend .env');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->line('# frontend .env.local');
        $this->line('NEXT_PUBLIC_VAPID_PUBLIC_KEY='.$keys['publicKey']);

        return self::SUCCESS;
    }
}

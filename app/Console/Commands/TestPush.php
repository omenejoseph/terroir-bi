<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DataTransferObjects\PushMessageData;
use App\Enums\NotificationType;
use App\Models\User;
use App\Services\Notifications\WebPushSender;
use Illuminate\Console\Command;

/**
 * Sends a real Web Push to one user's devices, synchronously, and prints what the
 * push service said for each — bypassing the queue so it isolates "is the queue
 * worker not running?" from "is delivery itself failing?". Operator debug tool.
 *
 *   php artisan push:test someone@example.com
 *   php artisan push:test 01J...ulid --body="custom text"
 */
class TestPush extends Command
{
    protected $signature = 'push:test {user : User email or id} {--body=It works! 🎉}';

    protected $description = 'Send a test Web Push to a user and report the result';

    public function handle(WebPushSender $sender): int
    {
        if (! $sender->isConfigured()) {
            $this->error('Web push is NOT configured — VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are missing in this environment.');

            return self::FAILURE;
        }

        $this->line('VAPID subject: '.(string) config('services.webpush.subject'));

        $arg = (string) $this->argument('user');
        $user = User::query()->where('email', $arg)->first() ?? User::query()->find($arg);

        if ($user === null) {
            $this->error("No user found for \"{$arg}\".");

            return self::FAILURE;
        }

        $count = $user->pushSubscriptions()->count();
        $this->info("User: {$user->email} ({$user->id}) — {$count} subscription(s).");

        if ($count === 0) {
            $this->warn('This user has no push subscriptions. Enable notifications in the installed PWA first, then re-run.');

            return self::FAILURE;
        }

        $message = new PushMessageData(
            title: 'Terroir BI',
            body: (string) $this->option('body'),
            type: NotificationType::Announcement,
        );

        $reports = $sender->deliver($user->id, $message);

        foreach ($reports as $r) {
            if ($r['success']) {
                $this->info('  ✓ sent  '.$r['endpoint']);
            } elseif ($r['expired']) {
                $this->warn('  ⌫ expired (pruned)  '.$r['endpoint']);
            } else {
                $this->error("  ✗ failed [{$r['status']}] {$r['reason']}  ".$r['endpoint']);
            }
        }

        $ok = count(array_filter($reports, static fn ($r) => $r['success']));
        $this->newLine();
        $this->line("Delivered to {$ok}/".count($reports).' device(s). If a device shows ✓ but no banner appears, check the phone is the installed PWA and notifications are allowed in iOS Settings.');

        return self::SUCCESS;
    }
}

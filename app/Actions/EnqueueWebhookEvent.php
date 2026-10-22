<?php

namespace App\Actions;

use App\Jobs\ProcessMarketplaceWebhook;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;

class EnqueueWebhookEvent
{
    public function handle(int $eventId): bool
    {
        return DB::transaction(function () use ($eventId): bool {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($eventId);

            if ($event->status !== WebhookEvent::STATUS_PENDING || $event->queued_at !== null) {
                return false;
            }

            ProcessMarketplaceWebhook::dispatch($event->id)
                ->onConnection('database')
                ->beforeCommit();

            $event->update(['queued_at' => now()]);

            return true;
        });
    }
}

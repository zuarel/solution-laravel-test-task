<?php

namespace App\Jobs;

use App\Actions\ApplyMarketplaceOrder;
use App\Actions\QueueOrderNotification;
use App\Actions\RebuildOrderStatusHistory;
use App\Enums\MarketplaceEventType;
use App\Models\WebhookEvent;
use App\Validation\MarketplaceOrderValidator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessMarketplaceWebhook implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $eventId) {}

    public function handle(
        MarketplaceOrderValidator $validator,
        ApplyMarketplaceOrder $apply,
        RebuildOrderStatusHistory $history,
        QueueOrderNotification $notifications,
    ): void {
        DB::transaction(function () use ($validator, $apply, $history, $notifications): void {
            $event = WebhookEvent::query()->lockForUpdate()->findOrFail($this->eventId);

            if (! in_array($event->status, [WebhookEvent::STATUS_PENDING, WebhookEvent::STATUS_FAILED], true)) {
                return;
            }

            $event->last_error = null;
            $event->validation_errors = null;
            $event->checked_at = now();

            if (MarketplaceEventType::tryFrom($event->type) === null) {
                $event->status = WebhookEvent::STATUS_UNSUPPORTED;
                $event->save();

                return;
            }

            try {
                $payload = json_decode($event->raw_body, true, flags: JSON_THROW_ON_ERROR);
                $validator->validate($payload);
            } catch (ValidationException $exception) {
                $event->status = WebhookEvent::STATUS_INVALID;
                $event->validation_errors = $exception->errors();
                $event->save();

                return;
            }

            $order = $apply->handle($event, $payload['data']['order']);
            $history->handle($order);

            if ($event->status === WebhookEvent::STATUS_PROCESSED) {
                $notifications->handle($order);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()
            ->whereKey($this->eventId)
            ->whereIn('status', [WebhookEvent::STATUS_PENDING, WebhookEvent::STATUS_FAILED])
            ->update([
                'status' => WebhookEvent::STATUS_FAILED,
                'last_error' => $exception?->getMessage(),
            ]);
    }
}

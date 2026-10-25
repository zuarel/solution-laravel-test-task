<?php

namespace App\Actions;

use App\Enums\OrderNotificationStatus;
use App\Enums\OrderStatus;
use App\Jobs\SendOrderNotification;
use App\Models\Order;
use App\Models\OrderNotification;

class QueueOrderNotification
{
    public function handle(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Cancelled], true)) {
            return;
        }

        $notification = OrderNotification::query()->firstOrCreate([
            'order_id' => $order->id,
            'type' => $order->status->value,
        ], ['status' => OrderNotificationStatus::Pending]);

        // Планируем только новое уведомление или ранее пропущенное, которое снова актуально.
        $canBeQueued = $notification->wasRecentlyCreated
            || $notification->status === OrderNotificationStatus::Skipped;

        if ($canBeQueued === false) {
            return;
        }

        if ($notification->status === OrderNotificationStatus::Skipped) {
            $notification->update([
                'status' => OrderNotificationStatus::Pending,
                'attempted_at' => null,
            ]);
        }

        SendOrderNotification::dispatch($order->id, $notification->id)
            ->onConnection('database')
            ->beforeCommit();
    }
}

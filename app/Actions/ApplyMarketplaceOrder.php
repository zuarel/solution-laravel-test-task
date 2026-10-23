<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WebhookEvent;
use DateTimeImmutable;
use DateTimeZone;

class ApplyMarketplaceOrder
{
    public function handle(WebhookEvent $event, array $snapshot): Order
    {
        $attributes = $this->attributes($snapshot);
        $order = $this->findOrCreateOrder($snapshot['id'], $attributes);
        $apply = $order->wasRecentlyCreated || $this->shouldApply($order, $snapshot);

        if ($apply) {
            if (! $order->wasRecentlyCreated) {
                $order->update($attributes);
            }

            $this->replaceItems($order, $snapshot['items']);
        }

        $event->order()->associate($order);

        $event->status = $apply
            ? WebhookEvent::STATUS_PROCESSED
            : WebhookEvent::STATUS_IGNORED;

        $event->save();

        return $order;
    }

    private function findOrCreateOrder(string $marketplaceId, array $attributes): Order
    {
        $order = Order::query()->where('marketplace_id', $marketplaceId)->lockForUpdate()->first();

        if ($order !== null) {
            return $order;
        }

        $order = Order::query()->createOrFirst(['marketplace_id' => $marketplaceId], $attributes);

        if (! $order->wasRecentlyCreated) {
            return Order::query()->lockForUpdate()->findOrFail($order->id);
        }

        return $order;
    }

    private function shouldApply(Order $order, array $snapshot): bool
    {
        $updatedAt = $this->date($snapshot['updated_at']);

        if ($updatedAt > $order->marketplace_updated_at) {
            return true;
        }

        if ($updatedAt < $order->marketplace_updated_at) {
            return false;
        }

        return OrderStatus::from($snapshot['status'])->tieBreakPriority() > $order->status->tieBreakPriority();
    }

    private function attributes(array $snapshot): array
    {
        return [
            'number' => $snapshot['number'],
            'status' => OrderStatus::from($snapshot['status']),
            'currency' => $snapshot['currency'],
            'customer_email' => $snapshot['customer']['email'],
            'customer_name' => $snapshot['customer']['name'],
            'customer_phone' => $snapshot['customer']['phone'],
            'delivery_price' => $snapshot['delivery_price'],
            'discount' => $snapshot['discount'],
            'total' => $snapshot['total'],
            'shipment_carrier' => $snapshot['shipment']['carrier'] ?? null,
            'shipment_tracking_number' => $snapshot['shipment']['tracking_number'] ?? null,
            'shipment_tracking_url' => $snapshot['shipment']['tracking_url'] ?? null,
            'paid_at' => $this->date($snapshot['paid_at']),
            'cancelled_at' => $this->date($snapshot['cancelled_at']),
            'cancel_reason' => $snapshot['cancel_reason'],
            'marketplace_created_at' => $this->date($snapshot['created_at']),
            'marketplace_updated_at' => $this->date($snapshot['updated_at']),
        ];
    }

    private function replaceItems(Order $order, array $items): void
    {
        $order->items()->delete();
        $rows = [];

        foreach ($items as $position => $item) {
            $rows[] = [
                'order_id' => $order->id,
                'sku' => $item['sku'],
                'name' => $item['name'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
                'position' => $position,
            ];
        }

        OrderItem::query()->insert($rows);
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        return $value === null
            ? null
            : (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }
}

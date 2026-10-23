<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use DateTimeImmutable;
use DateTimeZone;

class RebuildOrderStatusHistory
{
    public function handle(Order $order): void
    {
        $statusEntries = [];
        $events = WebhookEvent::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_IGNORED])
            ->get(['id', 'raw_body']);

        foreach ($events as $event) {
            $snapshot = json_decode($event->raw_body, true, flags: JSON_THROW_ON_ERROR)['data']['order'];
            $statusEntries[] = $this->makeStatusEntry($event->id, OrderStatus::Created, $snapshot['created_at']);

            if ($snapshot['paid_at'] !== null) {
                $statusEntries[] = $this->makeStatusEntry($event->id, OrderStatus::Paid, $snapshot['paid_at']);
            }

            if ($snapshot['cancelled_at'] !== null) {
                $statusEntries[] = $this->makeStatusEntry($event->id, OrderStatus::Cancelled, $snapshot['cancelled_at']);
            }

            $statusEntries[] = $this->makeStatusEntry($event->id, OrderStatus::from($snapshot['status']), $snapshot['updated_at']);
        }

        usort($statusEntries, $this->compareStatusEntries(...));

        $order->statusHistory()->delete();
        $previousStatus = null;
        $rows = [];

        foreach ($statusEntries as $observation) {
            if ($observation['status'] === $previousStatus) {
                continue;
            }

            $rows[] = [
                'order_id' => $order->id,
                'webhook_event_id' => $observation['event_id'],
                'status' => $observation['status']->value,
                'occurred_at' => $observation['at']->format('Y-m-d H:i:s.uP'),
            ];

            $previousStatus = $observation['status'];
        }

        OrderStatusHistory::query()->insert($rows);
    }

    private function compareStatusEntries(array $left, array $right): int
    {
        return ($left['at'] <=> $right['at'])
            ?: ($left['status']->tieBreakPriority() <=> $right['status']->tieBreakPriority())
            ?: ($left['event_id'] <=> $right['event_id']);
    }

    private function makeStatusEntry(int $eventId, OrderStatus $status, string $at): array
    {
        return [
            'event_id' => $eventId,
            'status' => $status,
            'at' => (new DateTimeImmutable($at))->setTimezone(new DateTimeZone('UTC')),
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\ProcessMarketplaceWebhook;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ApplyMarketplaceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_order_with_items_and_links_original_event(): void
    {
        $payload = $this->payload('created', '2026-10-21T10:02:11Z');
        $event = $this->process($payload);
        $order = $event->order;

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame($payload['data']['order']['id'], $order->marketplace_id);
        $this->assertSame(OrderStatus::Created, $order->status);
        $this->assertSame('anna.smirnova@example.com', $order->customer_email);
        $this->assertSame('RUB', $order->currency);
        $this->assertSame('3619.50', $order->total);
        $this->assertSame('349.00', $order->delivery_price);
        $this->assertSame('200.00', $order->discount);
        $this->assertSame(['TSHIRT-BLK-M', 'CAP-NVY'], $order->items->pluck('sku')->all());
        $this->assertSame([0, 1], $order->items->pluck('position')->all());
        $this->assertSame(['1290.00', '890.50'], $order->items->pluck('price')->all());
        $this->assertNull($order->shipment_carrier);
        $this->assertNull($order->paid_at);
        $this->assertSame(['created'], $this->history($order));
        $this->assertSame($event->id, $order->statusHistory->sole()->webhook_event_id);
        $this->assertSame($payload, json_decode($event->raw_body, true));
    }

    public function test_newer_snapshot_replaces_items_and_clears_nullable_fields(): void
    {
        $payload = $this->payload('shipped', '2026-10-21T11:00:00Z');
        $payload['data']['order']['shipment'] = [
            'carrier' => 'Carrier', 'tracking_number' => '123', 'tracking_url' => 'https://example.com/123',
        ];
        $payload['data']['order']['paid_at'] = '2026-10-21T10:30:00Z';
        $this->process($payload);

        $payload = $this->payload('created', '2026-10-21T12:00:00Z');
        $payload['data']['order']['customer']['email'] = 'new@example.com';
        $payload['data']['order']['customer']['phone'] = null;
        $payload['data']['order']['items'] = [$payload['data']['order']['items'][1]];
        $payload['data']['order']['total'] = '890.50';
        $event = $this->process($payload);
        $order = $event->order;

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(OrderStatus::Created, $order->status);
        $this->assertSame('new@example.com', $order->customer_email);
        $this->assertSame('890.50', $order->total);
        $this->assertSame('CAP-NVY', $order->items->sole()->sku);
        $this->assertSame(0, $order->items->sole()->position);
        foreach (['customer_phone', 'paid_at', 'shipment_carrier', 'shipment_tracking_number', 'shipment_tracking_url'] as $field) {
            $this->assertNull($order->$field);
        }
        $this->assertSame(['created', 'paid', 'shipped', 'created'], $this->history($order));
    }

    public function test_old_snapshot_only_adds_missing_history(): void
    {
        $new = $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $items = $new->order->items->toArray();
        $old = $this->payload('paid', '2026-10-21T11:00:00Z');
        $old['data']['order']['customer']['email'] = 'old@example.com';
        $old['data']['order']['items'] = [$old['data']['order']['items'][0]];
        $event = $this->process($old);

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertSame($new->order_id, $event->order_id);
        $this->assertSame(OrderStatus::Shipped, $event->order->status);
        $this->assertSame('anna.smirnova@example.com', $event->order->customer_email);
        $this->assertSame($items, $event->order->items->toArray());
        $this->assertSame(['created', 'paid', 'shipped'], $this->history($event->order));
        $this->assertSame($event->id, $event->order->statusHistory[1]->webhook_event_id);
    }

    public static function sameSecondStatuses(): array
    {
        return [
            ['created', 'paid', 'paid'],
            ['paid', 'shipped', 'shipped'],
            ['shipped', 'delivered', 'delivered'],
            ['delivered', 'cancelled', 'cancelled'],
            ['cancelled', 'paid', 'cancelled'],
            ['cancelled', 'delivered', 'cancelled'],
            ['shipped', 'paid', 'shipped'],
        ];
    }

    #[DataProvider('sameSecondStatuses')]
    public function test_status_priority_breaks_same_second_ties(string $first, string $second, string $expected): void
    {
        $this->process($this->payload($first, '2026-10-21T11:00:00Z'));
        // Same instant, different timezone representation.
        $event = $this->process($this->payload($second, '2026-10-21T14:00:00+03:00'));

        $this->assertSame($expected, $event->order->status->value);
        $this->assertSame($second === $expected ? WebhookEvent::STATUS_PROCESSED : WebhookEvent::STATUS_IGNORED, $event->status);
        $this->assertSame($expected, $event->order->statusHistory->last()->status->value);
    }

    public function test_same_status_and_timestamp_keep_first_snapshot(): void
    {
        $first = $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $payload = $this->payload('paid', '2026-10-21T11:00:00Z');
        $payload['data']['order']['customer']['name'] = 'Changed';
        $payload['data']['order']['items'][0]['quantity'] = 100;
        $second = $this->process($payload);

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $second->status);
        $this->assertSame($first->order->customer_name, $second->order->customer_name);
        $this->assertSame(2, $second->order->items->first()->quantity);
        $this->assertSame(['created', 'paid'], $this->history($second->order));
    }

    public function test_newer_snapshot_can_correct_cancellation_backwards(): void
    {
        $this->process($this->payload('cancelled', '2026-10-21T11:00:00Z'));
        $event = $this->process($this->payload('paid', '2026-10-21T11:00:00.000001Z'));

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame(OrderStatus::Paid, $event->order->status);
        $this->assertSame('000001', $event->order->marketplace_updated_at->format('u'));
        $this->assertSame(['created', 'cancelled', 'paid'], $this->history($event->order));
    }

    public function test_late_event_restores_transition_between_repeated_status_observations(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $second = $this->process($this->payload('paid', '2026-10-21T13:00:00Z'));
        $this->assertSame(['created', 'paid'], $this->history($second->order));

        $late = $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));

        $this->assertSame(['created', 'paid', 'shipped', 'paid'], $this->history($late->order));
        $this->assertSame(OrderStatus::Paid, $late->order->status);
    }

    public function test_repeated_job_does_not_change_items_or_history(): void
    {
        $event = $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $items = $event->order->items->toArray();
        $history = $event->order->statusHistory->toArray();

        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);

        $this->assertSame($items, $event->order->fresh()->items->toArray());
        $this->assertSame($history, $event->order->fresh()->statusHistory->toArray());
    }

    public function test_snapshot_dates_preserve_known_creation_and_payment_history(): void
    {
        $payload = $this->payload('shipped', '2026-10-21T12:00:00Z');
        $payload['data']['order']['paid_at'] = '2026-10-21T11:00:00Z';
        $event = $this->process($payload);

        $this->assertSame(['created', 'paid', 'shipped'], $this->history($event->order));
        $this->assertSame(['10:02:11', '11:00:00', '12:00:00'], $event->order->statusHistory->map(fn ($row) => $row->occurred_at->format('H:i:s'))->all());
    }

    public static function failingModels(): array
    {
        return [[OrderItem::class, false], [OrderStatusHistory::class, false], [OrderItem::class, true], [OrderStatusHistory::class, true]];
    }

    #[DataProvider('failingModels')]
    public function test_failure_rolls_back_order_items_history_and_event(string $model, bool $existing): void
    {
        $original = $existing ? $this->process($this->payload('paid', '2026-10-21T11:00:00Z'))->order : null;
        $before = $original?->toArray();
        $items = $original?->items->toArray();
        $history = $original?->statusHistory->toArray();
        $event = $this->store($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $connection = DB::connection();
        $insertPrefix = 'insert into '.$connection->getQueryGrammar()->wrapTable((new $model)->getTable());
        $failWrites = true;
        $connection->beforeExecuting(function (string $query) use ($insertPrefix, &$failWrites): void {
            if ($failWrites && str_starts_with($query, $insertPrefix)) {
                throw new RuntimeException('Write failed');
            }
        });

        try {
            $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);
            $this->fail('Expected write failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Write failed', $exception->getMessage());
        } finally {
            $failWrites = false;
        }

        $event->refresh();
        $this->assertSame(WebhookEvent::STATUS_PENDING, $event->status);
        $this->assertNull($event->order_id);
        $this->assertNull($event->checked_at);
        if ($original === null) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('order_status_history', 0);
        } else {
            $this->assertSame($before, $original->fresh()->toArray());
            $this->assertSame($items, $original->fresh()->items->toArray());
            $this->assertSame($history, $original->fresh()->statusHistory->toArray());
        }
        // A normal retry succeeds after the transient write failure.
        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
    }

    private function payload(string $status, string $updatedAt): array
    {
        $payload = json_decode(file_get_contents(base_path('task/examples/order-created.json')), true);
        $payload['id'] = 'evt_'.bin2hex(random_bytes(12));
        $payload['type'] = 'order.updated';
        $payload['data']['order']['status'] = $status;
        $payload['data']['order']['updated_at'] = $updatedAt;

        return $payload;
    }

    private function store(array $payload): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'event_id' => $payload['id'], 'type' => $payload['type'],
            'raw_body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => WebhookEvent::STATUS_PENDING, 'received_at' => now(), 'queued_at' => now(),
        ]);
    }

    private function process(array $payload): WebhookEvent
    {
        $event = $this->store($payload);
        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);

        return $event->fresh();
    }

    private function history(Order $order): array
    {
        return $order->statusHistory->map(fn ($row) => $row->status->value)->all();
    }
}

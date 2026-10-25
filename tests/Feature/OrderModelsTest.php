<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_keep_position_order_and_allow_repeated_sku(): void
    {
        $order = Order::query()->create($this->orderAttributes());
        $order->items()->createMany([
            ['sku' => 'SAME-SKU', 'name' => 'Second item', 'quantity' => 1, 'price' => '19.90', 'position' => 1],
            ['sku' => 'SAME-SKU', 'name' => 'First item', 'quantity' => 1, 'price' => '19.90', 'position' => 0],
        ]);

        $items = $order->fresh()->items;
        $this->assertSame(['First item', 'Second item'], $items->pluck('name')->all());
        $this->assertSame('19.90', $items->first()->price);
        $this->assertSame(1, $items->first()->quantity);
        $this->assertTrue($items->first()->order->is($order));
    }

    public function test_marketplace_order_id_is_unique(): void
    {
        Order::query()->create($this->orderAttributes());

        $this->expectException(QueryException::class);
        Order::query()->create($this->orderAttributes());
    }

    public function test_deleting_order_removes_children_but_preserves_source_webhook(): void
    {
        $order = Order::query()->create($this->orderAttributes());
        $event = $this->webhookEvent('evt_created', 'order.created');
        $order->items()->create(['sku' => 'SKU', 'name' => 'Item', 'quantity' => 1, 'price' => '39.80', 'position' => 0]);
        $order->statusHistory()->create(['webhook_event_id' => $event->id, 'status' => OrderStatus::Created, 'occurred_at' => '2026-10-21T10:02:11+00:00']);

        $order->delete();

        $this->assertSame(0, OrderItem::query()->count());
        $this->assertSame(0, OrderStatusHistory::query()->count());
        $this->assertTrue($event->fresh()->is($event));
    }

    private function orderAttributes(): array
    {
        return [
            'marketplace_id' => 'ord_model_test',
            'number' => 'MH-TEST',
            'status' => OrderStatus::Created,
            'currency' => 'RUB',
            'customer_email' => 'customer@example.test',
            'customer_name' => 'Customer',
            'customer_phone' => null,
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'total' => '39.80',
            'marketplace_created_at' => '2026-10-21T13:02:11.123456+03:00',
            'marketplace_updated_at' => '2026-10-21T13:02:11.123456+03:00',
        ];
    }

    private function webhookEvent(string $id, string $type): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'event_id' => $id,
            'type' => $type,
            'raw_body' => '{}',
            'status' => WebhookEvent::STATUS_PROCESSED,
            'received_at' => now(),
        ]);
    }
}

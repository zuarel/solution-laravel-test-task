<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\WebhookEvent;
use DateTimeImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('postgresql')]
class OrderDetailsApiTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Order details require PostgreSQL; run this group with a separate PostgreSQL test database.');
        }
    }

    public function test_card_matches_contract_and_keeps_nullable_fields(): void
    {
        $order = $this->createOrder();
        $order->items()->create([
            'sku' => 'SKU', 'name' => 'Товар', 'quantity' => 2, 'price' => '19.90', 'position' => 0,
        ]);

        $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()->assertExactJson([
            'data' => [
                'id' => $order->marketplace_id,
                'number' => 'MH-DETAILS',
                'status' => 'created',
                'customer' => ['email' => 'customer@example.com', 'name' => 'Покупатель', 'phone' => null],
                'currency' => 'RUB',
                'items' => [['sku' => 'SKU', 'name' => 'Товар', 'quantity' => 2, 'price' => '19.90', 'subtotal' => '39.80']],
                'delivery_price' => '0.00',
                'discount' => '0.00',
                'total' => '39.80',
                'shipment' => null,
                'paid_at' => null,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'created_at' => '2026-10-21T10:00:00.000000+00:00',
                'updated_at' => '2026-10-21T11:00:00.000000+00:00',
                'history' => [],
            ],
        ]);
    }

    public function test_card_returns_delivery_and_preserves_marketplace_date_instants(): void
    {
        $createdAt = '2026-10-21T13:00:00.123456+03:00';
        $updatedAt = '2026-10-22T13:00:00.654321+03:00';
        $order = $this->createOrder([
            'status' => OrderStatus::Shipped,
            'customer_phone' => '+79161234567',
            'shipment_carrier' => 'СДЭК',
            'shipment_tracking_number' => '123456',
            'shipment_tracking_url' => 'https://example.com/track?id=123456',
            'paid_at' => '2026-10-21T14:00:00.123456+03:00',
            'marketplace_created_at' => $createdAt,
            'marketplace_updated_at' => $updatedAt,
            'created_at' => '2026-10-25T12:00:00Z',
        ]);

        $response = $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.customer.phone', '+79161234567')
            ->assertJsonPath('data.shipment', [
                'carrier' => 'СДЭК', 'tracking_number' => '123456', 'tracking_url' => 'https://example.com/track?id=123456',
            ]);
        foreach (['created_at' => $createdAt, 'updated_at' => $updatedAt, 'paid_at' => '2026-10-21T14:00:00.123456+03:00'] as $field => $expected) {
            $this->assertSame((new DateTimeImmutable($expected))->format('U.u'), (new DateTimeImmutable($response->json('data.'.$field)))->format('U.u'));
        }
    }

    public function test_cancelled_order_contains_reason_and_cancellation_date(): void
    {
        $order = $this->createOrder([
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => '2026-10-21T12:00:00Z',
            'cancel_reason' => 'Покупатель отказался от заказа',
        ]);

        $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()
            ->assertJsonPath('data.cancel_reason', 'Покупатель отказался от заказа')
            ->assertJsonPath('data.cancelled_at', '2026-10-21T12:00:00.000000+00:00')
            ->assertJsonPath('data.paid_at', null);
    }

    public function test_items_keep_position_order_and_are_limited_to_requested_order(): void
    {
        $order = $this->createOrder();
        $other = $this->createOrder();
        $order->items()->createMany([
            ['sku' => 'SAME', 'name' => 'Вторая', 'quantity' => 3, 'price' => '0.10', 'position' => 1],
            ['sku' => 'SAME', 'name' => 'Первая', 'quantity' => 2, 'price' => '-19.90', 'position' => 0],
        ]);
        $other->items()->create(['sku' => 'OTHER', 'name' => 'Чужая', 'quantity' => 1, 'price' => '1.00', 'position' => 0]);

        $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Первая')->assertJsonPath('data.items.0.subtotal', '-39.80')
            ->assertJsonPath('data.items.1.name', 'Вторая')->assertJsonPath('data.items.1.subtotal', '0.30');
    }

    public function test_history_is_chronological_and_does_not_include_other_orders(): void
    {
        $order = $this->createOrder();
        $other = $this->createOrder();
        $event = WebhookEvent::query()->create([
            'event_id' => 'evt_card_history', 'type' => 'order.paid', 'raw_body' => '{}',
            'status' => WebhookEvent::STATUS_PROCESSED, 'received_at' => now(),
        ]);
        $order->statusHistory()->createMany([
            ['webhook_event_id' => $event->id, 'status' => OrderStatus::Paid, 'occurred_at' => '2026-10-21T11:00:00Z'],
            ['webhook_event_id' => $event->id, 'status' => OrderStatus::Created, 'occurred_at' => '2026-10-21T10:00:00Z'],
        ]);
        $other->statusHistory()->create([
            'webhook_event_id' => $event->id, 'status' => OrderStatus::Cancelled, 'occurred_at' => '2026-10-21T12:00:00Z',
        ]);

        $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()->assertJsonPath('data.history', [
            ['status' => 'created', 'at' => '2026-10-21T10:00:00.000000+00:00'],
            ['status' => 'paid', 'at' => '2026-10-21T11:00:00.000000+00:00'],
        ]);
    }

    public function test_lookup_uses_marketplace_identifier_and_unknown_order_returns_json_404(): void
    {
        $order = $this->createOrder();

        $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()->assertJsonPath('data.id', $order->marketplace_id);
        $this->getJson('/api/orders/'.$order->id)->assertNotFound();
        $this->get('/api/orders/ord_missing')->assertNotFound()->assertHeader('Content-Type', 'application/json');
    }

    public function test_card_keeps_one_snapshot_when_order_changes_before_relations_are_loaded(): void
    {
        $order = $this->createOrder();
        $order->items()->create([
            'sku' => 'OLD', 'name' => 'Первоначальный товар', 'quantity' => 2, 'price' => '19.90', 'position' => 0,
        ]);
        $event = WebhookEvent::query()->create([
            'event_id' => 'evt_concurrent_update', 'type' => 'order.paid', 'raw_body' => '{}',
            'status' => WebhookEvent::STATUS_PROCESSED, 'received_at' => now(),
        ]);
        $order->statusHistory()->create([
            'webhook_event_id' => $event->id, 'status' => OrderStatus::Created, 'occurred_at' => '2026-10-21T10:00:00Z',
        ]);

        $reader = DB::connection();
        config(['database.connections.order_details_writer' => $reader->getConfig()]);
        $writer = DB::connection('order_details_writer');
        $writer->statement("SET lock_timeout = '2s'");
        $updated = false;

        DB::listen(function (QueryExecuted $query) use ($reader, $writer, $order, $event, &$updated): void {
            if ($updated || $query->connection !== $reader
                || ! str_starts_with($query->sql, 'select * from "orders"')
                || ! in_array($order->marketplace_id, $query->bindings, true)) {
                return;
            }

            // Второе соединение фиксирует обновление между SELECT заказа и SELECT его связей.
            $updated = true;
            $writer->transaction(function () use ($writer, $order, $event): void {
                $writer->table('orders')->where('id', $order->id)->update([
                    'status' => OrderStatus::Paid->value, 'total' => '89.70',
                    'paid_at' => '2026-10-21T12:00:00Z', 'marketplace_updated_at' => '2026-10-21T12:00:00Z',
                ]);
                $writer->table('order_items')->where('order_id', $order->id)->delete();
                $writer->table('order_items')->insert([
                    'order_id' => $order->id, 'sku' => 'NEW', 'name' => 'Обновлённый товар',
                    'quantity' => 3, 'price' => '29.90', 'position' => 0,
                ]);
                $writer->table('order_status_history')->where('order_id', $order->id)->delete();
                $writer->table('order_status_history')->insert([
                    ['order_id' => $order->id, 'webhook_event_id' => $event->id, 'status' => 'created', 'occurred_at' => '2026-10-21T10:00:00Z'],
                    ['order_id' => $order->id, 'webhook_event_id' => $event->id, 'status' => 'paid', 'occurred_at' => '2026-10-21T12:00:00Z'],
                ]);
            });
        });

        try {
            $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()
                ->assertJsonPath('data.status', 'created')
                ->assertJsonPath('data.total', '39.80')
                ->assertJsonPath('data.paid_at', null)
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.sku', 'OLD')
                ->assertJsonPath('data.items.0.subtotal', '39.80')
                ->assertJsonPath('data.history', [
                    ['status' => 'created', 'at' => '2026-10-21T10:00:00.000000+00:00'],
                ]);
            $this->assertTrue($updated);

            $this->getJson('/api/orders/'.$order->marketplace_id)->assertOk()
                ->assertJsonPath('data.status', 'paid')
                ->assertJsonPath('data.total', '89.70')
                ->assertJsonPath('data.paid_at', '2026-10-21T12:00:00.000000+00:00')
                ->assertJsonCount(1, 'data.items')
                ->assertJsonPath('data.items.0.sku', 'NEW')
                ->assertJsonPath('data.items.0.subtotal', '89.70')
                ->assertJsonPath('data.history', [
                    ['status' => 'created', 'at' => '2026-10-21T10:00:00.000000+00:00'],
                    ['status' => 'paid', 'at' => '2026-10-21T12:00:00.000000+00:00'],
                ]);
        } finally {
            DB::purge('order_details_writer');
        }
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'marketplace_id' => 'ord_details_'.bin2hex(random_bytes(8)),
            'number' => 'MH-DETAILS', 'status' => OrderStatus::Created, 'currency' => 'RUB',
            'customer_email' => 'customer@example.com', 'customer_name' => 'Покупатель',
            'delivery_price' => '0.00', 'discount' => '0.00', 'total' => '39.80',
            'marketplace_created_at' => '2026-10-21T10:00:00Z',
            'marketplace_updated_at' => '2026-10-21T11:00:00Z',
        ], $attributes));
    }
}

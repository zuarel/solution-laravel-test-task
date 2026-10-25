<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\WebhookEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_guest_can_list_orders_and_combine_status_and_case_insensitive_email_filters(): void
    {
        $matching = $this->order('matching', OrderStatus::Paid, 'Buyer@Example.test');
        $otherStatus = $this->order('created', OrderStatus::Created, 'Buyer@Example.test');
        $otherEmail = $this->order('other', OrderStatus::Paid, 'other@example.test');

        $this->get('/admin')->assertRedirect('/admin/orders');
        $this->get('/admin/orders')->assertOk();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$matching, $otherStatus, $otherEmail])
            ->filterTable('status', 'paid')
            ->filterTable('email', ['email' => 'BUYER@EXAMPLE.TEST'])
            ->assertCanSeeTableRecords([$matching])
            ->assertCanNotSeeTableRecords([$otherStatus, $otherEmail]);
    }

    public function test_guest_can_view_order_items_and_chronological_status_history(): void
    {
        $order = $this->order('details', OrderStatus::Paid, 'buyer@example.test');
        $order->items()->create([
            'sku' => 'SKU-ADMIN', 'name' => 'Тестовый товар', 'quantity' => 2,
            'price' => '19.90', 'position' => 0,
        ]);
        $event = WebhookEvent::create([
            'event_id' => 'evt_admin', 'type' => 'order.paid', 'raw_body' => '{}',
            'status' => WebhookEvent::STATUS_PROCESSED, 'received_at' => now(),
        ]);
        $order->statusHistory()->createMany([
            ['webhook_event_id' => $event->id, 'status' => OrderStatus::Paid, 'occurred_at' => '2026-10-21T11:00:00Z'],
            ['webhook_event_id' => $event->id, 'status' => OrderStatus::Created, 'occurred_at' => '2026-10-21T10:00:00Z'],
        ]);

        $this->get('/admin/orders/'.$order->id)
            ->assertOk()
            ->assertSee('Заказ №'.$order->number)
            ->assertSee('Тестовый товар')
            ->assertSee('SKU-ADMIN')
            ->assertSee('История статусов')
            ->assertSeeInOrder(['21.10.2026 10:00:00', '21.10.2026 11:00:00']);

        $this->get('/admin/orders/999999')->assertNotFound();
    }

    public function test_admin_resource_only_allows_reading_orders(): void
    {
        $order = $this->order('readonly', OrderStatus::Created, 'buyer@example.test');

        $this->assertFalse(OrderResource::canCreate());
        $this->assertFalse(OrderResource::canEdit($order));
        $this->assertFalse(OrderResource::canDelete($order));
        $this->assertFalse(OrderResource::canDeleteAny());
        $this->get('/admin/orders/'.$order->id.'/edit')->assertNotFound();
    }

    private function order(string $id, OrderStatus $status, string $email): Order
    {
        return Order::create([
            'marketplace_id' => 'ord_'.$id,
            'number' => 'ADMIN-'.$id,
            'status' => $status,
            'currency' => 'RUB',
            'customer_email' => $email,
            'customer_name' => 'Покупатель',
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'total' => '39.80',
            'marketplace_created_at' => '2026-10-21T10:00:00Z',
            'marketplace_updated_at' => '2026-10-21T11:00:00Z',
        ]);
    }
}

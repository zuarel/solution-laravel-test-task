<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_matches_contract_and_uses_marketplace_identifiers_and_dates(): void
    {
        $order = $this->createOrder([
            'created_at' => '2026-10-25T12:00:00Z',
            'marketplace_created_at' => '2026-10-21T10:00:00.123456Z',
        ]);

        $response = $this->getJson('/api/orders')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'number', 'status', 'customer' => ['email', 'name'], 'total', 'currency', 'created_at', 'updated_at']],
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->marketplace_id)
            ->assertJsonPath('data.0.status', 'created')
            ->assertJsonPath('data.0.total', '39.80')
            ->assertJsonPath('data.0.customer.email', 'customer@example.com')
            ->assertJsonPath('meta.per_page', 20);
        $this->assertSame('2026-10-21T10:00:00.123456+00:00', $response->json('data.0.created_at'));
        $this->assertArrayNotHasKey('marketplace_id', $response->json('data.0'));
        $this->assertArrayNotHasKey('items', $response->json('data.0'));
    }

    public function test_list_sorts_by_marketplace_creation_time_and_has_stable_tie_order(): void
    {
        $old = $this->createOrder(['marketplace_created_at' => '2026-10-20T10:00:00Z', 'created_at' => '2026-10-25T12:00:00Z']);
        $new = $this->createOrder(['marketplace_created_at' => '2026-10-21T10:00:00Z', 'created_at' => '2026-10-24T12:00:00Z']);
        $tie = $this->createOrder(['marketplace_created_at' => '2026-10-21T10:00:00Z']);

        $response = $this->getJson('/api/orders')->assertOk();

        $this->assertSame([$tie->marketplace_id, $new->marketplace_id, $old->marketplace_id], array_column($response->json('data'), 'id'));
    }

    public function test_list_filters_status_and_email_together_without_case_sensitivity(): void
    {
        $matching = $this->createOrder(['status' => OrderStatus::Paid, 'customer_email' => 'Buyer@Example.com']);
        $this->createOrder(['status' => OrderStatus::Created, 'customer_email' => 'Buyer@Example.com']);
        $this->createOrder(['status' => OrderStatus::Paid, 'customer_email' => 'other@example.com']);

        $this->getJson('/api/orders?status=paid&email=BUYER%40EXAMPLE.COM')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->marketplace_id);
        $this->getJson('/api/orders?status=created')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/orders?email=buyer%40example.com')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_email_filter_uses_exact_match_with_literal_wildcard_characters(): void
    {
        $matching = $this->createOrder(['customer_email' => 'buyer_%@example.com']);
        $this->createOrder(['customer_email' => 'buyer_one@example.com']);

        $this->getJson('/api/orders?'.http_build_query(['email' => 'BUYER_%@EXAMPLE.COM']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $matching->marketplace_id);
        $this->getJson('/api/orders?email=buyer')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_list_paginates_and_keeps_filters_in_links(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createOrder(['status' => OrderStatus::Paid]);
        }

        $response = $this->getJson('/api/orders?status=paid&email=customer%40example.com&per_page=2&page=2')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        parse_str(parse_url($response->json('links.next'), PHP_URL_QUERY), $parameters);
        $this->assertSame(['status' => 'paid', 'email' => 'customer@example.com', 'per_page' => '2', 'page' => '3'], $parameters);
        $this->getJson('/api/orders?per_page=2&page=4')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_empty_list_and_empty_filters_are_supported(): void
    {
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->createOrder();
        $this->getJson('/api/orders?status=&email=')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/orders?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public static function invalidFilters(): array
    {
        return [
            ['status', 'unknown'], ['status', ['paid']], ['email', ['buyer@example.com']],
            ['page', 0], ['page', -1], ['page', '1.5'], ['page', 'invalid'], ['page', ''],
            ['per_page', 0], ['per_page', 101], ['per_page', '1.5'], ['per_page', ['20']],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_validation_errors(string $field, mixed $value): void
    {
        $this->getJson('/api/orders?'.http_build_query([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    private function createOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_replace([
            'marketplace_id' => 'ord_api_'.bin2hex(random_bytes(8)),
            'number' => 'MH-API',
            'status' => OrderStatus::Created,
            'currency' => 'RUB',
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Покупатель',
            'delivery_price' => '0.00',
            'discount' => '0.00',
            'total' => '39.80',
            'marketplace_created_at' => '2026-10-21T10:00:00Z',
            'marketplace_updated_at' => '2026-10-21T11:00:00Z',
        ], $attributes));
    }
}

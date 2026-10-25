<?php

namespace Tests\Feature;

use App\Actions\ApplyMarketplaceOrder;
use App\Actions\QueueOrderNotification;
use App\Actions\RebuildOrderStatusHistory;
use App\Jobs\ProcessMarketplaceWebhook;
use App\Jobs\SendOrderNotification;
use App\Models\WebhookEvent;
use App\Validation\MarketplaceOrderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessMarketplaceWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Mail::fake();
        Queue::fake();
    }

    public static function examples(): array
    {
        return array_map(fn ($name) => [$name], [
            'order-created', 'order-updated', 'order-paid', 'order-shipped', 'order-cancelled',
        ]);
    }

    #[DataProvider('examples')]
    public function test_validates_saved_examples_without_changing_original_payload(string $example): void
    {
        $payload = json_decode(file_get_contents(base_path("task/examples/{$example}.json")), true);
        $event = $this->storeEvent($payload);
        $body = $event->raw_body;

        (new ProcessMarketplaceWebhook($event->id))->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

        $event->refresh();
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertEquals(now(), $event->checked_at);
        $this->assertNull($event->validation_errors);
        $this->assertNull($event->last_error);
        $this->assertSame($body, $event->raw_body);
        Mail::assertNothingOutgoing();
        if (in_array($example, ['order-paid', 'order-shipped', 'order-cancelled'], true)) {
            Queue::assertPushed(SendOrderNotification::class, 1);
        } else {
            Queue::assertNothingPushed();
        }
    }

    public function test_validates_delivered_event(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'order.delivered';
        $payload['data']['order']['status'] = 'delivered';
        $event = $this->storeEvent($payload);

        (new ProcessMarketplaceWebhook($event->id))->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
    }

    public function test_unknown_type_is_retained_without_validating_its_order(): void
    {
        $payload = $this->payload();
        $payload['type'] = 'order.future-event';
        $payload['data']['order'] = ['new_field' => true];
        $event = $this->storeEvent($payload);
        $validator = $this->mock(MarketplaceOrderValidator::class);
        $validator->shouldNotReceive('validate');

        (new ProcessMarketplaceWebhook($event->id))->handle($validator, $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

        $stored = $event->fresh();
        $this->assertSame(WebhookEvent::STATUS_UNSUPPORTED, $stored->status);
        $this->assertSame($event->raw_body, $stored->raw_body);
        $this->assertEquals(now(), $stored->checked_at);
        $this->assertNull($stored->validation_errors);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function invalidOrders(): array
    {
        return [
            ['data.order', []],
            ['data.order.id', 123],
            ['data.order.number', ''],
            ['data.order.status', 'unknown'],
            ['data.order.currency', 'rub'],
            ['data.order.customer.email', 'invalid-email'],
            ['data.order.customer.name', null],
            ['data.order.customer.phone', 123],
            ['data.order.items', []],
            ['data.order.items', (object) ['sku' => 'wrong']],
            ['data.order.items.0.sku', null],
            ['data.order.items.0.name', 123],
            ['data.order.items.0.quantity', '2'],
            ['data.order.items.0.quantity', 0],
            ['data.order.items.0.quantity', 2147483648],
            ['data.order.items.0.quantity', PHP_INT_MAX],
            ['data.order.items.0.price', 1290.00],
            ['data.order.items.0.price', '10000000000000000.00'],
            ['data.order.items.0.price', '-10000000000000000.00'],
            ['data.order.delivery_price', '1.1'],
            ['data.order.delivery_price', '10000000000000000.00'],
            ['data.order.delivery_price', '-10000000000000000.00'],
            ['data.order.discount', '0'],
            ['data.order.discount', '10000000000000000.00'],
            ['data.order.discount', '-10000000000000000.00'],
            ['data.order.total', ' 3619.50 '],
            ['data.order.total', '10000000000000000.00'],
            ['data.order.total', '-10000000000000000.00'],
            ['data.order.shipment', []],
            ['data.order.shipment', ['carrier' => 'Carrier'], 'data.order.shipment.tracking_number'],
            ['data.order.shipment', ['carrier' => 'Carrier', 'tracking_number' => '123', 'tracking_url' => 'javascript:alert(1)'], 'data.order.shipment.tracking_url'],
            ['data.order.paid_at', ''],
            ['data.order.cancelled_at', '2026-02-30T10:00:00+00:00'],
            ['data.order.cancel_reason', false],
            ['data.order.created_at', '2026-10-21'],
            ['data.order.updated_at', '2026-10-21T25:00:00+00:00'],
        ];
    }

    #[DataProvider('invalidOrders')]
    public function test_records_validation_errors_without_throwing_or_losing_payload(string $field, mixed $value, ?string $errorField = null): void
    {
        $payload = $this->payload();
        data_set($payload, $field, $value);
        $event = $this->storeEvent($payload);

        (new ProcessMarketplaceWebhook($event->id))->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

        $stored = $event->fresh();
        $this->assertSame(WebhookEvent::STATUS_INVALID, $stored->status);
        $this->assertArrayHasKey($errorField ?? $field, $stored->validation_errors);
        $this->assertEquals(now(), $stored->checked_at);
        $this->assertNull($stored->last_error);
        $this->assertSame($event->raw_body, $stored->raw_body);
        $this->assertDatabaseCount('orders', 0);
    }

    public static function validNumericBoundaries(): array
    {
        return [
            'maximum amount' => ['9999999999999999.99'],
            'minimum amount' => ['-9999999999999999.99'],
            'leading zeros at maximum' => ['0009999999999999999.99'],
            'zero with leading zeros' => ['000000000000000000000.00'],
        ];
    }

    #[DataProvider('validNumericBoundaries')]
    public function test_accepts_values_at_numeric_storage_boundaries(string $amount): void
    {
        $payload = $this->payload();
        foreach (['items.0.price', 'delivery_price', 'discount', 'total'] as $field) {
            data_set($payload, 'data.order.'.$field, $amount);
        }
        $payload['data']['order']['items'][0]['quantity'] = 2147483647;
        $event = $this->storeEvent($payload);

        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);

        $event->refresh();
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNull($event->validation_errors);
        $this->assertNull($event->last_error);
        $this->assertSame(2147483647, $event->order->items->first()->quantity);
        $this->assertSame($payload, json_decode($event->raw_body, true));
    }

    public function test_nullable_fields_must_still_be_present(): void
    {
        foreach (['customer.phone', 'shipment', 'paid_at', 'cancelled_at', 'cancel_reason'] as $field) {
            $payload = $this->payload();
            $payload['id'] = 'evt_missing_'.$field;
            data_forget($payload, 'data.order.'.$field);
            $event = $this->storeEvent($payload);

            (new ProcessMarketplaceWebhook($event->id))->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

            $this->assertSame(WebhookEvent::STATUS_INVALID, $event->fresh()->status);
            $this->assertArrayHasKey('data.order.'.$field, $event->fresh()->validation_errors);
        }
    }

    public function test_completed_event_is_not_checked_again(): void
    {
        $event = $this->storeEvent($this->payload());
        $job = new ProcessMarketplaceWebhook($event->id);
        $job->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));
        $checkedAt = $event->fresh()->checked_at;

        $this->travel(1)->minutes();
        $validator = $this->mock(MarketplaceOrderValidator::class);
        $validator->shouldNotReceive('validate');
        $job->handle($validator, $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));
        $job->failed(new RuntimeException('Late failure callback'));

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertEquals($checkedAt, $event->fresh()->checked_at);
        $this->assertNull($event->fresh()->last_error);
    }

    public function test_technical_exception_leaves_event_retryable(): void
    {
        $event = $this->storeEvent($this->payload());
        $job = new ProcessMarketplaceWebhook($event->id);
        $validator = $this->mock(MarketplaceOrderValidator::class);
        $validator->shouldReceive('validate')->once()->andThrow(new RuntimeException('Temporary failure'));

        try {
            $job->handle($validator, $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));
            $this->fail('Expected the technical exception to reach the worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Temporary failure', $exception->getMessage());
        }

        $this->assertSame(WebhookEvent::STATUS_PENDING, $event->fresh()->status);
        $this->assertNull($event->fresh()->checked_at);
        $job->handle(new MarketplaceOrderValidator, $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
    }

    public function test_failed_event_can_be_retried_and_clears_technical_error(): void
    {
        $event = $this->storeEvent($this->payload());
        $job = new ProcessMarketplaceWebhook($event->id);
        $job->failed(new RuntimeException('Attempts exhausted'));

        $this->assertSame(WebhookEvent::STATUS_FAILED, $event->fresh()->status);
        $this->assertSame('Attempts exhausted', $event->fresh()->last_error);

        $job->handle($this->app->make(MarketplaceOrderValidator::class), $this->app->make(ApplyMarketplaceOrder::class), $this->app->make(RebuildOrderStatusHistory::class), $this->app->make(QueueOrderNotification::class));

        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->fresh()->status);
        $this->assertNull($event->fresh()->last_error);
    }

    private function payload(): array
    {
        return json_decode(file_get_contents(base_path('task/examples/order-created.json')), true);
    }

    private function storeEvent(array $payload): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'event_id' => $payload['id'],
            'type' => $payload['type'],
            'raw_body' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n",
            'status' => WebhookEvent::STATUS_PENDING,
            'received_at' => now(),
            'queued_at' => now(),
        ]);
    }
}

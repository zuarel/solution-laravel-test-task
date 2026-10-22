<?php

namespace Tests\Feature;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketplaceWebhookTest extends TestCase
{
    private const SECRET = 'webhook-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['marketplace.webhook_secret' => self::SECRET]);
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
    public function test_accepts_documented_examples_without_side_effects(string $example): void
    {
        $queries = [];
        $this->app['db']->listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->sendWebhook(file_get_contents(base_path("task/examples/{$example}.json")))
            ->assertOk()->assertContent('OK');

        $this->assertSame([], $queries);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
    }

    public function test_accepts_order_without_detailed_validation(): void
    {
        $event = $this->event();
        $event['data']['order'] = [];

        $this->sendWebhook(json_encode($event))->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_accepts_delivered_and_unknown_event_types(): void
    {
        $event = $this->event();
        $event['type'] = 'order.delivered';
        $event['data']['order']['status'] = 'delivered';
        $this->sendWebhook(json_encode($event))->assertOk();

        $event['type'] = 'order.future-event';
        $event['data']['order'] = (object) ['future_field' => true];
        $this->sendWebhook(json_encode($event))->assertOk();
    }

    public function test_accepts_the_documented_signature_vector(): void
    {
        config(['marketplace.webhook_secret' => 'whsec_test_5f1d8c2a9b']);
        $this->travelTo(now()->setTimestamp(1792577149));

        $this->sendWebhook(file_get_contents(base_path('task/examples/signature-example-body.json')), [
            'HTTP_X_WEBHOOK_TIMESTAMP' => '1792577149',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=4d4f2d50f98596ab503d29dc96db6fda247e3c482bff130bd97353564cb163f7',
        ])->assertOk();
    }

    public static function invalidHeaders(): array
    {
        return [
            'missing ID' => ['HTTP_X_WEBHOOK_ID', '', Response::HTTP_BAD_REQUEST],
            'missing timestamp' => ['HTTP_X_WEBHOOK_TIMESTAMP', '', Response::HTTP_BAD_REQUEST],
            'noninteger timestamp' => ['HTTP_X_WEBHOOK_TIMESTAMP', '1.5', Response::HTTP_BAD_REQUEST],
            'overflow timestamp' => ['HTTP_X_WEBHOOK_TIMESTAMP', str_repeat('9', 50), Response::HTTP_BAD_REQUEST],
            'missing signature' => ['HTTP_X_WEBHOOK_SIGNATURE', '', Response::HTTP_BAD_REQUEST],
            'wrong signature format' => ['HTTP_X_WEBHOOK_SIGNATURE', 'sha512=abc', Response::HTTP_BAD_REQUEST],
            'uppercase signature' => ['HTTP_X_WEBHOOK_SIGNATURE', 'sha256='.str_repeat('A', 64), Response::HTTP_BAD_REQUEST],
            'wrong signature' => ['HTTP_X_WEBHOOK_SIGNATURE', 'sha256='.str_repeat('0', 64), Response::HTTP_UNAUTHORIZED],
            'wrong content type' => ['CONTENT_TYPE', 'text/plain', Response::HTTP_UNSUPPORTED_MEDIA_TYPE],
            'non UTF-8 charset' => ['CONTENT_TYPE', 'application/json; charset=windows-1251', Response::HTTP_UNSUPPORTED_MEDIA_TYPE],
        ];
    }

    #[DataProvider('invalidHeaders')]
    public function test_rejects_invalid_headers(string $header, string $value, int $status): void
    {
        $this->sendWebhook(json_encode($this->event()), [$header => $value])->assertStatus($status);
    }

    public function test_checks_timestamp_window_in_both_directions(): void
    {
        foreach ([-301, 301] as $offset) {
            $this->sendWebhook(json_encode($this->event()), [
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) (now()->getTimestamp() + $offset),
            ])->assertUnauthorized();
        }

        foreach ([-300, 300] as $offset) {
            $this->sendWebhook(json_encode($this->event()), [
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) (now()->getTimestamp() + $offset),
            ])->assertOk();
        }
    }

    public function test_signature_covers_the_exact_body_bytes(): void
    {
        $body = json_encode($this->event());
        $timestamp = (string) now()->getTimestamp();
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);

        $this->sendWebhook($body."\n", ['HTTP_X_WEBHOOK_SIGNATURE' => $signature])->assertUnauthorized();
        $this->sendWebhook($body."\n")->assertOk();
    }

    public function test_rejects_requests_when_secret_is_not_configured(): void
    {
        config(['marketplace.webhook_secret' => '']);
        $this->sendWebhook(json_encode($this->event()))->assertStatus(Response::HTTP_SERVICE_UNAVAILABLE);
    }

    public static function invalidBodies(): array
    {
        return [
            ['', Response::HTTP_BAD_REQUEST],
            ['{', Response::HTTP_BAD_REQUEST],
            ['null', Response::HTTP_BAD_REQUEST],
            ['[]', Response::HTTP_UNPROCESSABLE_ENTITY],
            ['{}', Response::HTTP_UNPROCESSABLE_ENTITY],
            ['[{"id":"evt_test"}]', Response::HTTP_UNPROCESSABLE_ENTITY],
            ['"text"', Response::HTTP_BAD_REQUEST],
            ['123', Response::HTTP_BAD_REQUEST],
            ["{\"id\":\"\xFF\"}", Response::HTTP_BAD_REQUEST],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function test_rejects_invalid_json_or_event_body(string $body, int $status): void
    {
        $this->sendWebhook($body)->assertStatus($status);
    }

    public static function invalidFields(): array
    {
        return [
            ['id', 123],
            ['type', []],
            ['created_at', '2026-02-30T10:00:00+00:00'],
            ['created_at', '2026-10-21T10:00:00'],
            ['data', 'wrong'],
            ['data.order', 'wrong'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_invalid_event_fields(string $field, mixed $value): void
    {
        $event = $this->event();
        data_set($event, $field, $value);

        $this->sendWebhook(json_encode($event))->assertUnprocessable()->assertJsonValidationErrors($field);
        Queue::assertNothingPushed();
    }

    public function test_requires_event_envelope_fields(): void
    {
        $event = $this->event();
        $fields = ['id', 'type', 'created_at', 'data', 'data.order'];
        foreach ($fields as $field) {
            $body = $event;
            data_forget($body, $field);
            $this->sendWebhook(json_encode($body))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_rejects_id_mismatch_and_does_not_use_query_parameters(): void
    {
        $event = $this->event();
        $body = json_encode($event);
        $this->sendWebhook($body, ['HTTP_X_WEBHOOK_ID' => 'different'])->assertUnprocessable()->assertJsonValidationErrors('id');

        unset($event['id']);
        $this->sendWebhook(json_encode($event), [], '?id=evt_test')->assertUnprocessable()->assertJsonValidationErrors('id');
    }

    public function test_accepts_timezone_offsets_z_and_fractional_seconds(): void
    {
        foreach (['2026-10-21T10:00:00Z', '2026-10-21T10:00:00.123456+03:00'] as $date) {
            $event = $this->event();
            $event['created_at'] = $date;
            $this->sendWebhook(json_encode($event), ['CONTENT_TYPE' => 'application/json; charset=UTF-8'])->assertOk();
        }
    }

    private function event(): array
    {
        return json_decode(file_get_contents(base_path('task/examples/order-created.json')), true);
    }

    private function sendWebhook(string $body, array $headers = [], string $query = ''): TestResponse
    {
        $timestamp = $headers['HTTP_X_WEBHOOK_TIMESTAMP'] ?? (string) now()->getTimestamp();
        $event = json_decode($body, true);
        $id = is_array($event) && is_string($event['id'] ?? null) ? $event['id'] : 'evt_test';

        return $this->call('POST', '/api/webhooks/marketplace'.$query, server: array_replace([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => $id,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], $headers), content: $body);
    }
}

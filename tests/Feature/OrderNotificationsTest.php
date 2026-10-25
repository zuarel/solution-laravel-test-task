<?php

namespace Tests\Feature;

use App\Enums\OrderNotificationStatus;
use App\Enums\OrderStatus;
use App\Jobs\ProcessMarketplaceWebhook;
use App\Jobs\SendOrderNotification;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Mail::fake();
        $this->queueManager = $this->app['queue'];
        Queue::fake();
    }

    public function test_paid_email_contains_subject_items_and_total(): void
    {
        $event = $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();

        Mail::assertNothingOutgoing();
        Queue::assertPushed(SendOrderNotification::class, fn ($job) => $job->orderId === $event->order_id
            && $job->notificationId === $notification->id && $job->connection === 'database');
        $this->send($notification);

        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $this->assertSame('Заказ №MH-1048213 оплачен', $mail->envelope()->subject);
            $mail->assertSeeInText('Футболка базовая, чёрная, M');
            $mail->assertSeeInText('2 × 1290.00 RUB');
            $mail->assertSeeInText('Бейсболка "Classic" / синяя');
            $mail->assertSeeInText('3619.50 RUB');

            return $mail->hasTo('anna.smirnova@example.com');
        });
        $this->assertSame(OrderNotificationStatus::Sent, $notification->fresh()->status);
        $this->assertEquals(now(), $notification->fresh()->sent_at);
        $this->assertEquals(now(), $notification->fresh()->attempted_at);
    }

    public function test_shipped_email_contains_carrier_tracking_number_and_url(): void
    {
        $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $this->send(OrderNotification::query()->sole());

        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $this->assertSame('Заказ №MH-1048213 передан в доставку', $mail->envelope()->subject);
            $mail->assertSeeInText('СДЭК');
            $mail->assertSeeInText('1098765432');
            $mail->assertSeeInText('https://example.com/tracking?id=1098765432&lang=ru');

            return true;
        });
    }

    public function test_paid_cancellation_mentions_refund(): void
    {
        $this->process($this->payload('cancelled', '2026-10-21T12:00:00Z'));
        $this->send(OrderNotification::query()->sole());

        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $this->assertSame('Заказ №MH-1048213 отменён', $mail->envelope()->subject);
            $mail->assertSeeInText('Покупатель отказался от заказа');
            $mail->assertSeeInText('Деньги вернутся в течение 10 дней.');

            return true;
        });
    }

    public function test_unpaid_cancellation_does_not_promise_refund(): void
    {
        $payload = $this->payload('cancelled', '2026-10-21T12:00:00Z');
        $payload['data']['order']['paid_at'] = null;
        $this->process($payload);
        $this->send(OrderNotification::query()->sole());

        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $mail->assertDontSeeInText('Деньги вернутся');

            return $mail->order->paid_at === null;
        });
    }

    public function test_refund_text_uses_current_snapshot_even_when_history_contains_payment(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $cancelled = $this->payload('cancelled', '2026-10-21T12:00:00Z');
        $cancelled['data']['order']['paid_at'] = null;
        $this->process($cancelled);
        $this->send($this->notification(OrderStatus::Cancelled));

        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $mail->assertDontSeeInText('Деньги вернутся');

            return $mail->order->paid_at === null;
        });
    }

    public function test_created_and_delivered_do_not_schedule_notifications(): void
    {
        $this->process($this->payload('created', '2026-10-21T10:02:11Z'));
        $this->process($this->payload('delivered', '2026-10-21T13:00:00Z'));

        $this->assertDatabaseCount('order_notifications', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_invalid_and_unknown_events_do_not_schedule_notifications(): void
    {
        $invalid = $this->payload('paid', '2026-10-21T11:00:00Z');
        $invalid['data']['order']['customer']['email'] = 'invalid';
        $this->process($invalid);
        $unknown = $this->payload('paid', '2026-10-21T12:00:00Z');
        $unknown['type'] = 'order.future';
        $this->process($unknown);

        $this->assertDatabaseCount('order_notifications', 0);
        Queue::assertNothingPushed();
        Mail::assertNothingOutgoing();
    }

    public function test_repeated_events_and_jobs_send_each_kind_of_email_only_once(): void
    {
        $event = $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        $this->send($notification);
        $this->send($notification);
        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);
        $this->process($this->payload('paid', '2026-10-21T12:00:00Z'));

        $this->assertDatabaseCount('order_notifications', 1);
        Queue::assertPushed(SendOrderNotification::class, 1);
        Mail::assertSent(OrderStatusChanged::class, 1);
    }

    public function test_pending_payment_email_uses_current_recipient_and_snapshot(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $updated = $this->payload('paid', '2026-10-21T12:00:00Z');
        $updated['data']['order']['customer']['email'] = 'updated@example.com';
        $updated['data']['order']['items'] = [$updated['data']['order']['items'][0]];
        $updated['data']['order']['items'][0]['quantity'] = 4;
        $updated['data']['order']['total'] = '5160.00';
        $this->process($updated);
        $this->send(OrderNotification::query()->sole());

        Queue::assertPushed(SendOrderNotification::class, 1);
        Mail::assertSent(OrderStatusChanged::class, function ($mail): bool {
            $mail->assertSeeInText('4 × 1290.00 RUB');
            $mail->assertSeeInText('5160.00 RUB');
            $mail->assertDontSeeInText('Бейсболка');

            return $mail->hasTo('updated@example.com');
        });
    }

    public function test_only_current_cancellation_is_sent_when_order_has_already_advanced(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $this->process($this->payload('cancelled', '2026-10-21T13:00:00Z'));

        foreach (OrderNotification::query()->get() as $notification) {
            $this->send($notification);
        }

        Mail::assertSent(OrderStatusChanged::class, 1);
        Mail::assertSent(OrderStatusChanged::class, fn ($mail) => $mail->order->status === OrderStatus::Cancelled);
        $this->assertSame(OrderNotificationStatus::Skipped, $this->notification(OrderStatus::Paid)->status);
        $this->assertSame(OrderNotificationStatus::Skipped, $this->notification(OrderStatus::Shipped)->status);
    }

    public function test_late_paid_snapshot_does_not_send_payment_email_after_cancellation(): void
    {
        $this->process($this->payload('cancelled', '2026-10-21T12:00:00Z'));
        $this->send(OrderNotification::query()->sole());
        $late = $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));

        $this->assertSame(WebhookEvent::STATUS_IGNORED, $late->status);
        $this->assertDatabaseCount('order_notifications', 1);
        Queue::assertPushed(SendOrderNotification::class, 1);
        Mail::assertSent(OrderStatusChanged::class, 1);
    }

    public function test_delivered_order_skips_pending_shipping_email(): void
    {
        $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $this->process($this->payload('delivered', '2026-10-21T13:00:00Z'));
        $this->send(OrderNotification::query()->sole());

        Mail::assertNothingOutgoing();
        $this->assertSame(OrderNotificationStatus::Skipped, OrderNotification::query()->sole()->status);
    }

    public function test_manual_status_correction_can_reactivate_a_skipped_unsent_notification(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $notification = $this->notification(OrderStatus::Paid);
        $this->send($notification);
        $this->assertSame(OrderNotificationStatus::Skipped, $notification->fresh()->status);

        $this->process($this->payload('paid', '2026-10-21T13:00:00Z'));
        $this->assertSame(OrderNotificationStatus::Pending, $notification->fresh()->status);
        $this->send($notification);
        $this->send($notification);

        Mail::assertSent(OrderStatusChanged::class, 1);
        $this->assertDatabaseCount('order_notifications', 2);
    }

    public function test_sent_email_is_not_repeated_after_manual_status_correction(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        $this->send($notification);
        $this->process($this->payload('shipped', '2026-10-21T12:00:00Z'));
        $this->process($this->payload('paid', '2026-10-21T13:00:00Z'));
        $this->send($notification);

        Mail::assertSent(OrderStatusChanged::class, 1);
        $this->assertSame(OrderNotificationStatus::Sent, $notification->fresh()->status);
    }

    public function test_smtp_runs_after_preparation_transaction_and_uses_prepared_snapshot(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        // RefreshDatabase держит внешнюю тестовую транзакцию; задание не должно добавлять свою на время SMTP.
        $transactionLevel = DB::connection()->transactionLevel();
        Mail::shouldReceive('to')->once()->with('anna.smirnova@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function (OrderStatusChanged $mail) use ($notification, $transactionLevel): void {
            $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
            $this->assertSame(OrderNotificationStatus::Sending, $notification->fresh()->status);
            $this->assertTrue($mail->order->relationLoaded('items'));

            // Изменение после подготовки не должно менять уже подготовленное письмо.
            Order::query()->whereKey($notification->order_id)->update(['status' => OrderStatus::Cancelled->value]);
            $this->assertSame(OrderStatus::Paid, $mail->order->status);
        });

        $this->send($notification);

        $this->assertSame(OrderNotificationStatus::Sent, $notification->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($notification->order_id)->status);
    }

    public function test_smtp_failure_does_not_roll_back_order_changes_made_during_delivery(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnUsing(function () use ($notification): void {
            Order::query()->whereKey($notification->order_id)->update(['status' => OrderStatus::Cancelled->value]);

            throw new RuntimeException('SMTP disconnected');
        });

        $this->send($notification);

        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($notification->order_id)->status);
        $this->assertSame(OrderNotificationStatus::Uncertain, $notification->fresh()->status);
        $this->assertSame('SMTP disconnected', $notification->fresh()->last_error);
        $this->send($notification);
    }

    public function test_smtp_failure_is_recorded_and_not_sent_again_automatically(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        Mail::shouldReceive('to')->once()->with('anna.smirnova@example.com')->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP disconnected'));

        $this->send($notification);

        $this->assertSame(OrderNotificationStatus::Uncertain, $notification->fresh()->status);
        $this->assertSame('SMTP disconnected', $notification->fresh()->last_error);
        $this->assertNull($notification->fresh()->sent_at);
        $this->send($notification);
    }

    public function test_retry_after_a_crash_does_not_repeat_an_already_claimed_delivery(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        $notification->update(['status' => OrderNotificationStatus::Sending, 'attempted_at' => now()]);

        $this->send($notification);

        Mail::assertNothingOutgoing();
        $this->assertSame(OrderNotificationStatus::Sending, $notification->fresh()->status);
    }

    public function test_failure_recording_sent_state_does_not_repeat_smtp_delivery(): void
    {
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        $fail = true;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$fail): void {
            if ($fail && str_starts_with($sql, 'update "order_notifications"') && in_array(OrderNotificationStatus::Sent->value, $bindings, true)) {
                throw new RuntimeException('Cannot record SMTP success');
            }
        });

        try {
            $this->send($notification);
        } finally {
            $fail = false;
        }

        $this->send($notification);

        Mail::assertSent(OrderStatusChanged::class, 1);
        $this->assertSame(OrderNotificationStatus::Uncertain, $notification->fresh()->status);
        $this->assertSame('Cannot record SMTP success', $notification->fresh()->last_error);
    }

    public function test_queue_failure_rolls_back_order_notification_and_webhook_processing(): void
    {
        // Restore real dispatch so the database queue write can be made to fail.
        Queue::swap($this->queueManager);
        config(['queue.connections.database.table' => 'missing_notification_jobs']);
        $payload = $this->payload('paid', '2026-10-21T11:00:00Z');
        $event = $this->store($payload);

        try {
            $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);
            $this->fail('Expected queue insert failure.');
        } catch (QueryException) {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_notifications', 0);
            $this->assertDatabaseCount('order_items', 0);
            $this->assertDatabaseCount('order_status_history', 0);
            $this->assertSame(WebhookEvent::STATUS_PENDING, $event->fresh()->status);
        }
    }

    public function test_database_queue_delivers_email_and_repeated_job_does_not_send_again(): void
    {
        Queue::swap($this->queueManager);
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        $this->assertDatabaseCount('jobs', 1);
        Mail::assertNothingOutgoing();

        $job = $this->queueManager->connection('database')->pop();
        $job->fire();
        $job->delete();
        SendOrderNotification::dispatch($notification->order_id, $notification->id)->onConnection('database');
        $duplicate = $this->queueManager->connection('database')->pop();
        $duplicate->fire();
        $duplicate->delete();

        $this->assertDatabaseCount('jobs', 0);
        Mail::assertSent(OrderStatusChanged::class, 1);
        $this->assertSame(OrderNotificationStatus::Sent, $notification->fresh()->status);
    }

    public function test_smtp_failure_marks_database_job_failed_without_replaying_delivery(): void
    {
        Queue::swap($this->queueManager);
        $this->process($this->payload('paid', '2026-10-21T11:00:00Z'));
        $notification = OrderNotification::query()->sole();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP delivery unknown'));
        Event::fake([JobFailed::class]);

        $job = $this->queueManager->connection('database')->pop();
        $job->fire();

        $this->assertTrue($job->hasFailed());
        Event::assertDispatched(JobFailed::class, fn ($event) => $event->exception->getMessage() === 'SMTP delivery unknown');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(OrderNotificationStatus::Uncertain, $notification->fresh()->status);
        $this->send($notification);
    }

    private function payload(string $status, string $updatedAt): array
    {
        $payload = json_decode(file_get_contents(base_path('task/examples/order-created.json')), true);
        $payload['id'] = 'evt_mail_'.bin2hex(random_bytes(8));
        $payload['type'] = 'order.updated';
        $order = &$payload['data']['order'];
        $order['status'] = $status;
        $order['updated_at'] = $updatedAt;
        if ($status !== 'created') {
            $order['paid_at'] = '2026-10-21T10:05:00Z';
        }
        if (in_array($status, ['shipped', 'delivered'], true)) {
            $order['shipment'] = [
                'carrier' => 'СДЭК', 'tracking_number' => '1098765432',
                'tracking_url' => 'https://example.com/tracking?id=1098765432&lang=ru',
            ];
        }
        if ($status === 'cancelled') {
            $order['cancelled_at'] = $updatedAt;
            $order['cancel_reason'] = 'Покупатель отказался от заказа';
        }

        return $payload;
    }

    private function store(array $payload): WebhookEvent
    {
        return WebhookEvent::query()->create([
            'event_id' => $payload['id'], 'type' => $payload['type'],
            'raw_body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => WebhookEvent::STATUS_PENDING, 'received_at' => now(),
        ]);
    }

    private function process(array $payload): WebhookEvent
    {
        $event = $this->store($payload);
        $this->app->call([new ProcessMarketplaceWebhook($event->id), 'handle']);

        return $event->fresh();
    }

    private function notification(OrderStatus $type): OrderNotification
    {
        return OrderNotification::query()->where('type', $type->value)->sole();
    }

    private function send(OrderNotification $notification): void
    {
        (new SendOrderNotification($notification->order_id, $notification->id))->handle();
    }
}

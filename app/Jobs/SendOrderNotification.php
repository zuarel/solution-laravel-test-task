<?php

namespace App\Jobs;

use App\Enums\OrderNotificationStatus;
use App\Enums\OrderStatus;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\OrderNotification;
use App\Support\DateTimeFormat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $orderId, public int $notificationId) {}

    public function handle(): void
    {
        $mail = $this->prepareMail();

        if ($mail === null) {
            return;
        }

        try {
            Mail::to($mail->order->customer_email)->send($mail);

            OrderNotification::query()
                ->whereKey($this->notificationId)
                ->where('status', OrderNotificationStatus::Sending)
                ->update([
                    'status' => OrderNotificationStatus::Sent->value,
                    'sent_at' => now()->format(DateTimeFormat::DATABASE),
                ]);
        } catch (Throwable $exception) {
            OrderNotification::query()
                ->whereKey($this->notificationId)
                ->where('status', OrderNotificationStatus::Sending)
                ->update([
                    'status' => OrderNotificationStatus::Uncertain->value,
                    'last_error' => $exception->getMessage(),
                ]);

            // SMTP мог принять письмо. Повторная попытка не должна создать дубль.
            $this->fail($exception);
        }
    }

    private function prepareMail(): ?OrderStatusChanged
    {
        return DB::transaction(function (): ?OrderStatusChanged {
            $order = Order::query()->lockForUpdate()->find($this->orderId);
            $notification = OrderNotification::query()
                ->where('order_id', $this->orderId)
                ->lockForUpdate()->find($this->notificationId);

            if ($order === null || $notification === null || $notification->status !== OrderNotificationStatus::Pending) {
                return null;
            }

            if ($order->status !== $notification->type) {
                $notification->update(['status' => OrderNotificationStatus::Skipped]);

                return null;
            }

            if ($order->status === OrderStatus::Paid) {
                $order->load('items');
            }
            $mail = new OrderStatusChanged($order);

            // Фиксируем попытку до SMTP, чтобы сбой после доставки не привёл к дублю.
            $notification->update([
                'status' => OrderNotificationStatus::Sending,
                'attempted_at' => now(),
                'last_error' => null,
            ]);

            return $mail;
        });
    }
}

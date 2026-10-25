<?php

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use LogicException;

class OrderStatusChanged extends Mailable
{
    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->order->status) {
            OrderStatus::Paid => "Заказ №{$this->order->number} оплачен",
            OrderStatus::Shipped => "Заказ №{$this->order->number} передан в доставку",
            OrderStatus::Cancelled => "Заказ №{$this->order->number} отменён",
            default => throw new LogicException('This order status does not require a customer notification.'),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $view = match ($this->order->status) {
            OrderStatus::Paid => 'mail.orders.paid',
            OrderStatus::Shipped => 'mail.orders.shipped',
            OrderStatus::Cancelled => 'mail.orders.cancelled',
            default => throw new LogicException('This order status does not require a customer notification.'),
        };

        return new Content(text: $view);
    }
}

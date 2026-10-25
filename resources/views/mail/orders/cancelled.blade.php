Заказ №{!! $order->number !!}

Ваш заказ отменён.

Причина отмены: {!! $order->cancel_reason ?? 'Не указана' !!}
@if($order->paid_at !== null)
Деньги вернутся в течение 10 дней.
@endif

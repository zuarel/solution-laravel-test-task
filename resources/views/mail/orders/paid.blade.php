Заказ №{!! $order->number !!}

Ваш заказ оплачен.

Состав заказа:
@foreach($order->items as $item)
- {!! $item->name !!} ({!! $item->sku !!}): {!! $item->quantity !!} × {!! $item->price !!} {!! $order->currency !!}
@endforeach

Итого: {!! $order->total !!} {!! $order->currency !!}

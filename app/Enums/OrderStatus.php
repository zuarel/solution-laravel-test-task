<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
}

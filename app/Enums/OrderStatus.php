<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Created = 'created';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function tieBreakPriority(): int
    {
        return match ($this) {
            self::Created => 0,
            self::Paid => 1,
            self::Shipped => 2,
            self::Delivered => 3,
            self::Cancelled => 4,
        };
    }
}

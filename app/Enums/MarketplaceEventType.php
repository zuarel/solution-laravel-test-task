<?php

namespace App\Enums;

enum MarketplaceEventType: string
{
    case Created = 'order.created';
    case Updated = 'order.updated';
    case Paid = 'order.paid';
    case Shipped = 'order.shipped';
    case Delivered = 'order.delivered';
    case Cancelled = 'order.cancelled';
}

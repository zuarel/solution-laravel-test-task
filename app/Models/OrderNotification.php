<?php

namespace App\Models;

use App\Enums\OrderNotificationStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;

class OrderNotification extends Model
{
    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'order_id', 'type', 'status', 'attempted_at', 'sent_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderNotificationStatus::class,
            'type' => OrderStatus::class,
            'attempted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\DateTimeFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_history';

    protected $dateFormat = DateTimeFormat::DATABASE;

    protected $fillable = [
        'order_id',
        'webhook_event_id',
        'status',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<WebhookEvent, $this> */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }
}

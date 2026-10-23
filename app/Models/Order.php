<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'marketplace_id',
        'number',
        'status',
        'currency',
        'customer_email',
        'customer_name',
        'customer_phone',
        'delivery_price',
        'discount',
        'total',
        'shipment_carrier',
        'shipment_tracking_number',
        'shipment_tracking_url',
        'paid_at',
        'cancelled_at',
        'cancel_reason',
        'marketplace_created_at',
        'marketplace_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'marketplace_created_at' => 'immutable_datetime',
            'marketplace_updated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('position');
    }

    /** @return HasMany<OrderStatusHistory, $this> */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** @return HasMany<WebhookEvent, $this> */
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }
}

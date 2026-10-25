<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->marketplace_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'customer' => [
                'email' => $this->customer_email,
                'name' => $this->customer_name,
                'phone' => $this->customer_phone,
            ],
            'currency' => $this->currency,
            'items' => OrderItemResource::collection($this->items),
            'delivery_price' => $this->delivery_price,
            'discount' => $this->discount,
            'total' => $this->total,
            'shipment' => $this->shipment_carrier === null ? null : [
                'carrier' => $this->shipment_carrier,
                'tracking_number' => $this->shipment_tracking_number,
                'tracking_url' => $this->shipment_tracking_url,
            ],
            'paid_at' => ApiDateTime::format($this->paid_at),
            'cancelled_at' => ApiDateTime::format($this->cancelled_at),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => ApiDateTime::format($this->marketplace_created_at),
            'updated_at' => ApiDateTime::format($this->marketplace_updated_at),
            'history' => $this->statusHistory->map(fn (OrderStatusHistory $entry): array => [
                'status' => $entry->status->value,
                'at' => ApiDateTime::format($entry->occurred_at),
            ])->all(),
        ];
    }
}

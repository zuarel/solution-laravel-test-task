<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\OrderStatusHistory;
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
            'paid_at' => $this->paid_at?->format('Y-m-d\TH:i:s.uP'),
            'cancelled_at' => $this->cancelled_at?->format('Y-m-d\TH:i:s.uP'),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->marketplace_created_at->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $this->marketplace_updated_at->format('Y-m-d\TH:i:s.uP'),
            'history' => $this->statusHistory->map(fn (OrderStatusHistory $entry): array => [
                'status' => $entry->status->value,
                'at' => $entry->occurred_at->format('Y-m-d\TH:i:s.uP'),
            ])->all(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderListResource extends JsonResource
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
            ],
            'total' => $this->total,
            'currency' => $this->currency,
            'created_at' => $this->marketplace_created_at->format('Y-m-d\TH:i:s.uP'),
            'updated_at' => $this->marketplace_updated_at->format('Y-m-d\TH:i:s.uP'),
        ];
    }
}

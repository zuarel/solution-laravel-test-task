<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\ApiDateTime;
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
            'created_at' => ApiDateTime::format($this->marketplace_created_at),
            'updated_at' => ApiDateTime::format($this->marketplace_updated_at),
        ];
    }
}

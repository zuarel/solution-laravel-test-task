<?php

namespace App\Actions;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class GetOrderDetails
{
    public function handle(string $id): Order
    {
        return DB::transaction(function () use ($id): Order {
            // Заказ и его связи читаются из одного снимка PostgreSQL.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            return Order::query()
                ->where('marketplace_id', $id)
                ->with(['items', 'statusHistory'])
                ->firstOrFail();
        });
    }
}

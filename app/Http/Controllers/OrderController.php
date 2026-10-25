<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListOrdersRequest;
use App\Http\Resources\OrderListResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function list(ListOrdersRequest $request): AnonymousResourceCollection
    {
        $query = Order::query();

        if ($request->status() !== null) {
            $query->where('status', $request->status()->value);
        }

        $query->forEmail($request->email());

        $orders = $query
            ->orderByDesc('marketplace_created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $request->perPage(), page: $request->page())
            ->withQueryString();

        return OrderListResource::collection($orders);
    }
}

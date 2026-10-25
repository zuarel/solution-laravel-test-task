<?php

namespace Tests\Unit;

use App\Http\Resources\OrderItemResource;
use App\Models\OrderItem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderItemResourceTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['19.90', 3, '59.70'],
            ['0.10', 3, '0.30'],
            ['-19.90', 2, '-39.80'],
            ['9007199254740993.01', 3, '27021597764222979.03'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_subtotal_is_an_exact_decimal_string(string $price, int $quantity, string $subtotal): void
    {
        $item = new OrderItem(['sku' => 'SKU', 'name' => 'Товар', 'price' => $price, 'quantity' => $quantity]);

        $resource = (new OrderItemResource($item))->resolve();

        $this->assertSame($price, $resource['price']);
        $this->assertSame($quantity, $resource['quantity']);
        $this->assertSame($subtotal, $resource['subtotal']);
    }
}

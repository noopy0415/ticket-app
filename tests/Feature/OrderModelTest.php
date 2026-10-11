<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\QueryException;

test('new orders are pending', function () {
    $order = Order::factory()->create();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->isPaid())->toBeFalse();
});

test('deleting an order deletes its items', function () {
    $item = OrderItem::factory()->create();

    $item->order->delete();

    $this->assertModelMissing($item);
});

test('ticket types with order items cannot be deleted', function () {
    $item = OrderItem::factory()->create();

    expect(fn () => $item->ticketType->delete())->toThrow(QueryException::class);
});

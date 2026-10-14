<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;

/**
 * Create an order item for the ticket type with the given status.
 */
function reserve(TicketType $ticketType, int $quantity, OrderStatus $status): OrderItem
{
    return OrderItem::factory()
        ->for(Order::factory()->state(['status' => $status, 'event_id' => $ticketType->event_id]))
        ->for($ticketType)
        ->create(['quantity' => $quantity]);
}

test('paid and recent pending orders reduce the remaining tickets', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserve($ticketType, 3, OrderStatus::Paid);
    reserve($ticketType, 2, OrderStatus::Pending);
    reserve($ticketType, 4, OrderStatus::Cancelled);

    expect($ticketType->remaining())->toBe(5);
});

test('pending orders older than the reservation window are released', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserve($ticketType, 2, OrderStatus::Pending);

    $this->travel(TicketType::PENDING_MINUTES - 1)->minutes();
    expect($ticketType->remaining())->toBe(8);

    $this->travel(2)->minutes();
    expect($ticketType->remaining())->toBe(10);
});

test('the scope gives the same result as counting on demand', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    reserve($ticketType, 3, OrderStatus::Paid);

    $loaded = TicketType::query()->withReservedQuantity()->find($ticketType->id);

    expect($loaded->remaining())->toBe(7)
        ->and($ticketType->remaining())->toBe(7);
});

test('remaining tickets never go below zero', function () {
    $ticketType = TicketType::factory()->create(['capacity' => 2]);
    reserve($ticketType, 3, OrderStatus::Paid);

    expect($ticketType->remaining())->toBe(0);
});

test('a pending order exactly at the reservation window still holds its tickets', function () {
    $this->travelTo(now()->startOfSecond());
    $ticketType = TicketType::factory()->create(['capacity' => 10]);
    $item = reserve($ticketType, 2, OrderStatus::Pending);

    $item->order->forceFill(['created_at' => now()->subMinutes(TicketType::PENDING_MINUTES)])->save();
    expect($ticketType->remaining())->toBe(8);

    $item->order->forceFill(['created_at' => now()->subMinutes(TicketType::PENDING_MINUTES)->subSecond()])->save();
    expect($ticketType->remaining())->toBe(10);
});

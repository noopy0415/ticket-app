<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $this->ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 10]);
});

test('the buyer coming back from Stripe cancels the pending order and releases its tickets', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->for($this->ticketType)->create(['quantity' => 3]);

    expect($this->ticketType->remaining())->toBe(7);

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->ticketType->remaining())->toBe(10);
});

test('a paid order is not cancelled', function () {
    $order = Order::factory()->for($this->event)->paid()->create();

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('この注文は支払い済みです');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid);
});

test('the cancel page cannot be opened by anyone but the buyer', function (Closure $makeUser) {
    $order = Order::factory()->for($this->event)->create();

    $this->actingAs($makeUser($this->event))
        ->get(route('orders.cancel', $order))
        ->assertForbidden();

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
})->with([
    'another user' => [fn () => User::factory()->create()],
    'the organizer' => [fn (Event $event) => $event->organizer],
]);

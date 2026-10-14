<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\CheckoutService;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;

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

/**
 * Create a pending order that has been sent to the Stripe payment page.
 */
function orderWithCheckoutSession(Event $event, TicketType $ticketType): Order
{
    $order = Order::factory()->for($event)->create(['stripe_checkout_session_id' => 'cs_test_dummy']);
    OrderItem::factory()->for($order)->for($ticketType)->create(['quantity' => 2]);

    return $order;
}

/**
 * Build a Checkout Session as Stripe would return it.
 */
function closedCheckoutSession(Order $order, string $status, string $paymentStatus): Session
{
    return Session::constructFrom([
        'id' => 'cs_test_dummy',
        'status' => $status,
        'payment_status' => $paymentStatus,
        'payment_intent' => $paymentStatus === 'paid' ? 'pi_test_dummy' : null,
        'metadata' => ['order_id' => (string) $order->id],
    ]);
}

test('the checkout session is expired before the order is cancelled', function () {
    $order = orderWithCheckoutSession($this->event, $this->ticketType);
    $checkoutService = $this->mock(CheckoutService::class);
    $checkoutService->shouldReceive('expireSession')->once()->with('cs_test_dummy');
    $checkoutService->shouldReceive('retrieveSession')->never();

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('a session paid before it could be expired marks the order as paid instead of cancelling it', function () {
    $order = orderWithCheckoutSession($this->event, $this->ticketType);
    $checkoutService = $this->mock(CheckoutService::class);
    $checkoutService->shouldReceive('expireSession')->once()->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of open can be expired.'));
    $checkoutService->shouldReceive('retrieveSession')->once()->with('cs_test_dummy')->andReturn(closedCheckoutSession($order, 'complete', 'paid'));

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('この注文は支払い済みです');

    expect($order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->stripe_payment_intent_id)->toBe('pi_test_dummy')
        ->and($order->tickets()->count())->toBe(2);
});

test('a session that has already expired cancels the order', function () {
    $order = orderWithCheckoutSession($this->event, $this->ticketType);
    $checkoutService = $this->mock(CheckoutService::class);
    $checkoutService->shouldReceive('expireSession')->once()->andThrow(new InvalidRequestException('Only Checkout Sessions with a status of open can be expired.'));
    $checkoutService->shouldReceive('retrieveSession')->once()->andReturn(closedCheckoutSession($order, 'expired', 'unpaid'));

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

test('the order is left pending when Stripe cannot be reached', function () {
    $order = orderWithCheckoutSession($this->event, $this->ticketType);
    $checkoutService = $this->mock(CheckoutService::class);
    $checkoutService->shouldReceive('expireSession')->once()->andThrow(new ApiConnectionException('Could not connect.'));
    $checkoutService->shouldReceive('retrieveSession')->once()->andThrow(new ApiConnectionException('Could not connect.'));

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いの状況を確認できませんでした');

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->tickets()->count())->toBe(0);
});

test('Stripe is not asked when the order is already cancelled', function () {
    $order = orderWithCheckoutSession($this->event, $this->ticketType);
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    $this->mock(CheckoutService::class)->shouldReceive('expireSession')->never();

    $this->actingAs($order->user)
        ->get(route('orders.cancel', $order))
        ->assertOk()
        ->assertSee('支払いを中断しました');
});

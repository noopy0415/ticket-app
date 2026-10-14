<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\CheckoutService;
use Livewire\Livewire;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiConnectionException;

beforeEach(function () {
    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_dummy']);
    OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
});

/**
 * Build a Checkout Session as Stripe would return it.
 */
function checkoutSession(Order $order, string $paymentStatus): Session
{
    return Session::constructFrom([
        'id' => 'cs_test_dummy',
        'payment_status' => $paymentStatus,
        'payment_intent' => 'pi_test_dummy',
        'metadata' => ['order_id' => (string) $order->id],
    ]);
}

test('coming back from Stripe after paying marks the order as paid and issues tickets', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->once()
        ->with('cs_test_dummy')
        ->andReturn(checkoutSession($this->order, 'paid'));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('購入が完了しました');

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->stripe_payment_intent_id)->toBe('pi_test_dummy')
        ->and($this->order->tickets()->count())->toBe(2);
});

test('the order stays pending while the payment is not finished', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->once()
        ->andReturn(checkoutSession($this->order, 'unpaid'));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('支払いを確認しています')
        ->assertSee('wire:poll', escape: false);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('a session ID of another checkout is not looked up', function () {
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_other')
        ->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('the page is still shown when Stripe cannot be reached', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->once()
        ->andThrow(new ApiConnectionException('Could not connect.'));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('支払いを確認しています');

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a paid session of another order does not pay this order', function () {
    $other = Order::factory()->create();
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->once()
        ->andReturn(checkoutSession($other, 'paid'));

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('支払いを確認しています');

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('Stripe is not asked when the order is already paid', function () {
    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('購入が完了しました');
});

test('Stripe is not asked without a session ID', function () {
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order))
        ->assertOk();
});

test('Stripe is not asked when the order has no checkout session yet', function () {
    $this->order->update(['stripe_checkout_session_id' => null]);
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('reloading the complete page does not issue tickets twice', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->once()
        ->andReturn(checkoutSession($this->order, 'paid'));

    $url = route('orders.complete', $this->order).'?session_id=cs_test_dummy';
    $this->actingAs($this->order->user)->get($url)->assertOk();
    $this->get($url)->assertOk();

    expect($this->order->tickets()->count())->toBe(2);
});

test('polling while pending confirms the payment once Stripe says it is paid', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('retrieveSession')
        ->twice()
        ->with('cs_test_dummy')
        ->andReturn(checkoutSession($this->order, 'unpaid'), checkoutSession($this->order, 'paid'));

    Livewire::actingAs($this->order->user)
        ->withQueryParams(['session_id' => 'cs_test_dummy'])
        ->test('pages::orders.complete', ['order' => $this->order])
        ->assertSee('支払いを確認しています')
        ->call('checkPayment')
        ->assertSee('購入が完了しました')
        ->assertDontSee('wire:poll', escape: false);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->tickets()->count())->toBe(2);
});

test('polling after the webhook has paid the order does not ask Stripe again', function () {
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $component = Livewire::actingAs($this->order->user)
        ->test('pages::orders.complete', ['order' => $this->order])
        ->assertSee('支払いを確認しています');

    $this->order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $component->call('checkPayment')->assertSee('購入が完了しました');
});

test('someone other than the buyer cannot open the complete page', function () {
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs(User::factory()->create())
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertForbidden();

    $this->actingAs($this->order->event->organizer)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertForbidden();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a guest is redirected to the login page', function () {
    $this->get(route('orders.complete', $this->order))->assertRedirect(route('login'));
});

test('polling as someone other than the buyer is forbidden', function () {
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $component = Livewire::actingAs($this->order->user)
        ->test('pages::orders.complete', ['order' => $this->order]);

    $this->actingAs(User::factory()->create());

    $component->call('checkPayment')->assertForbidden();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

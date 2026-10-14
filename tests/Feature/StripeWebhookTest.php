<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Ticket;
use App\Services\CheckoutService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\Exception\SignatureVerificationException;

beforeEach(function () {
    $this->order = Order::factory()->create(['stripe_checkout_session_id' => 'cs_test_dummy']);
    OrderItem::factory()->for($this->order)->create(['quantity' => 2]);
});

/**
 * Send a webhook to the app, signed like Stripe does.
 *
 * @param  array<string, mixed>  $event
 */
function postStripeWebhook(array $event, ?string $secret = null, ?int $timestamp = null): TestResponse
{
    $payload = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? config('services.stripe.webhook_secret'));

    return test()->call('POST', route('stripe.webhook'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
    ], content: $payload);
}

/**
 * Build a checkout.session.completed event for the order.
 *
 * @return array<string, mixed>
 */
function checkoutSessionCompletedEvent(Order $order, string $paymentStatus = 'paid'): array
{
    return [
        'id' => 'evt_test_dummy',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_dummy',
                'object' => 'checkout.session',
                'payment_status' => $paymentStatus,
                'payment_intent' => 'pi_test_dummy',
                'metadata' => ['order_id' => (string) $order->id],
            ],
        ],
    ];
}

test('a completed checkout marks the order as paid and issues tickets', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->stripe_payment_intent_id)->toBe('pi_test_dummy')
        ->and($this->order->tickets()->count())->toBe(2);
});

test('a webhook with an invalid signature is rejected', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order), 'whsec_wrong')->assertStatus(400);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('the same webhook sent twice does not issue tickets twice', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();
    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Paid)
        ->and($this->order->tickets()->count())->toBe(2);
});

test('an unpaid checkout leaves the order pending', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order, 'unpaid'))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a checkout whose session does not match the order is ignored', function () {
    $this->order->update(['stripe_checkout_session_id' => 'cs_test_other']);

    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('an event type other than checkout.session.completed is ignored', function () {
    $event = checkoutSessionCompletedEvent($this->order);
    $event['type'] = 'checkout.session.expired';

    postStripeWebhook($event)->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('a checkout for an order that does not exist is acknowledged and changes nothing', function () {
    $event = checkoutSessionCompletedEvent($this->order);
    $event['data']['object']['metadata']['order_id'] = '999999';

    postStripeWebhook($event)->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(Ticket::count())->toBe(0);
});

test('a checkout without order metadata is acknowledged and changes nothing', function () {
    $event = checkoutSessionCompletedEvent($this->order);
    $event['data']['object']['metadata'] = [];

    postStripeWebhook($event)->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a cancelled order is not paid by a late webhook', function () {
    $this->order->forceFill(['status' => OrderStatus::Cancelled])->save();

    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();

    expect($this->order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('a webhook without a signature header is rejected', function () {
    $this->call('POST', route('stripe.webhook'), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(checkoutSessionCompletedEvent($this->order)))
        ->assertStatus(400);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a webhook with an invalid payload is rejected', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('constructWebhookEvent')
        ->once()
        ->andThrow(new UnexpectedValueException('Invalid payload'));

    $this->call('POST', route('stripe.webhook'), server: ['CONTENT_TYPE' => 'application/json'], content: 'not json')
        ->assertStatus(400);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a signature failure reported by the service is answered with 400', function () {
    $this->mock(CheckoutService::class)
        ->shouldReceive('constructWebhookEvent')
        ->once()
        ->andThrow(new SignatureVerificationException('No signatures found'));

    $this->call('POST', route('stripe.webhook'), content: '{}')->assertStatus(400);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending);
});

test('a webhook signed with an old timestamp is rejected', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order), timestamp: time() - 301)->assertStatus(400);

    expect($this->order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($this->order->tickets()->count())->toBe(0);
});

test('issued tickets have unique UUID codes', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();

    $codes = $this->order->tickets()->pluck('code');

    expect($codes)->toHaveCount(2)
        ->and($codes->unique())->toHaveCount(2)
        ->and($codes->every(fn (string $code) => Str::isUuid($code)))->toBeTrue();
});

test('the complete page after the webhook does not issue tickets again or ask Stripe', function () {
    postStripeWebhook(checkoutSessionCompletedEvent($this->order))->assertOk();
    $this->mock(CheckoutService::class)->shouldReceive('retrieveSession')->never();

    $this->actingAs($this->order->user)
        ->get(route('orders.complete', $this->order).'?session_id=cs_test_dummy')
        ->assertOk()
        ->assertSee('購入が完了しました');

    expect($this->order->tickets()->count())->toBe(2);
});

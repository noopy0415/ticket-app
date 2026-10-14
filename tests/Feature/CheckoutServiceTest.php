<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Replace Stripe's HTTP client so no request leaves the test.
 *
 * @param  array<int, array{method: string, url: string, params: array<string, mixed>}>  $requests
 */
function fakeStripeHttpClient(array &$requests, int $status = 200): void
{
    ApiRequestor::setHttpClient(new class($requests, $status) implements ClientInterface
    {
        public function __construct(private array &$requests, private int $status) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            $body = $this->status === 200
                ? ['id' => 'cs_test_dummy', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_dummy']
                : ['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']];

            return [json_encode($body), $this->status, []];
        }
    });
}

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('a checkout session is created from the order items and saved to the order', function () {
    $requests = [];
    fakeStripeHttpClient($requests);

    $event = Event::factory()->published()->create(['title' => '岩手山 朝焼けトレッキング']);
    $order = Order::factory()->for($event)->create(['total_amount' => 7500]);
    OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 9999]))->create(['unit_price' => 3000, 'quantity' => 2]);
    OrderItem::factory()->for($order)->for(TicketType::factory()->for($event)->create(['name' => '学生']))->create(['unit_price' => 1500, 'quantity' => 1]);

    $this->freezeTime();

    $url = app(CheckoutService::class)->createSession($order);

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_dummy')
        ->and($order->refresh()->stripe_checkout_session_id)->toBe('cs_test_dummy')
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]['url'])->toEndWith('/v1/checkout/sessions')
        ->and($requests[0]['params'])->toMatchArray([
            'mode' => 'payment',
            'line_items' => [
                ['price_data' => ['currency' => 'jpy', 'unit_amount' => 3000, 'product_data' => ['name' => '岩手山 朝焼けトレッキング 一般']], 'quantity' => 2],
                ['price_data' => ['currency' => 'jpy', 'unit_amount' => 1500, 'product_data' => ['name' => '岩手山 朝焼けトレッキング 学生']], 'quantity' => 1],
            ],
            'metadata' => ['order_id' => (string) $order->id],
            'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('orders.cancel', $order),
            'expires_at' => now()->addMinutes(OrderService::PENDING_MINUTES)->getTimestamp(),
        ]);
});

test('a Stripe API error is turned into a purchase error', function () {
    $requests = [];
    fakeStripeHttpClient($requests, 500);

    $order = Order::factory()->create();
    OrderItem::factory()->for($order)->create();

    expect(fn () => app(CheckoutService::class)->createSession($order))
        ->toThrow(PurchaseException::class, '決済ページを開けませんでした。');

    expect($order->refresh()->stripe_checkout_session_id)->toBeNull();
});

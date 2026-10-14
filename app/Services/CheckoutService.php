<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\Order;
use App\Models\OrderItem;
use Stripe\Checkout\Session;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class CheckoutService
{
    public function __construct(protected StripeClient $stripe) {}

    /**
     * Create a Stripe Checkout Session for the order and return the URL of the payment page.
     *
     * Amounts are taken from the order items, which keep the prices at the time of the order.
     * The session expires when the order stops reserving its tickets.
     *
     * @throws PurchaseException
     */
    public function createSession(Order $order): string
    {
        $order->loadMissing(['event', 'items.ticketType']);

        try {
            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'payment',
                'line_items' => $order->items->map(fn (OrderItem $item): array => [
                    'price_data' => [
                        'currency' => 'jpy',
                        'unit_amount' => $item->unit_price,
                        'product_data' => [
                            'name' => "{$order->event->title} {$item->ticketType->name}",
                        ],
                    ],
                    'quantity' => $item->quantity,
                ])->values()->all(),
                'metadata' => [
                    'order_id' => (string) $order->id,
                ],
                'success_url' => route('orders.complete', $order).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('orders.cancel', $order),
                'expires_at' => now()->addMinutes(OrderService::PENDING_MINUTES)->getTimestamp(),
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new PurchaseException('決済ページを開けませんでした。時間をおいてもう一度お試しください。');
        }

        $order->update(['stripe_checkout_session_id' => $session->id]);

        return $session->url;
    }

    /**
     * Retrieve a Checkout Session by its ID.
     *
     * @throws ApiErrorException
     */
    public function retrieveSession(string $id): Session
    {
        return $this->stripe->checkout->sessions->retrieve($id);
    }

    /**
     * Build a webhook event from the request body after verifying its signature.
     *
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public function constructWebhookEvent(string $payload, ?string $signature): Event
    {
        return Webhook::constructEvent($payload, (string) $signature, (string) config('services.stripe.webhook_secret'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Handle a webhook sent by Stripe.
     *
     * Only checkout.session.completed is handled. Other events are acknowledged and ignored.
     */
    public function __invoke(Request $request, CheckoutService $checkoutService, OrderService $orderService): Response
    {
        try {
            $event = $checkoutService->constructWebhookEvent($request->getContent(), $request->header('Stripe-Signature'));
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid webhook.', 400);
        }

        $session = $event->data->object;

        if ($event->type === 'checkout.session.completed' && $session instanceof Session) {
            $this->handleCheckoutSessionCompleted($session, $orderService);
        }

        return response('OK');
    }

    /**
     * Mark the order of the paid Checkout Session as paid.
     */
    protected function handleCheckoutSessionCompleted(Session $session, OrderService $orderService): void
    {
        if ($session->payment_status !== 'paid') {
            return;
        }

        $order = Order::query()
            ->whereKey($session->metadata->order_id ?? null)
            ->where('stripe_checkout_session_id', $session->id)
            ->first();

        if ($order !== null) {
            $orderService->markAsPaid($order, is_string($session->payment_intent) ? $session->payment_intent : null);
        }
    }
}

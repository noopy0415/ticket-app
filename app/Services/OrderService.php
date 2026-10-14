<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrderService
{
    /**
     * Minutes a pending order keeps its tickets reserved.
     */
    public const PENDING_MINUTES = 30;

    /**
     * Maximum number of tickets per ticket type in one order.
     */
    public const MAX_QUANTITY = 10;

    /**
     * Create a pending order with its items.
     *
     * Prices are taken from the ticket types, never from the caller.
     *
     * @param  array<int|string, mixed>  $quantities  Quantities keyed by ticket type ID. Zero means not selected.
     *
     * @throws PurchaseException
     */
    public function createOrder(User $user, Event $event, array $quantities): Order
    {
        if (! $event->isPublished()) {
            throw new PurchaseException('このイベントは購入できません。');
        }

        $quantities = array_filter($quantities, fn (mixed $quantity): bool => ! in_array($quantity, [0, '0', '', null], true));

        if ($quantities === []) {
            throw new PurchaseException('枚数を選んでください。');
        }

        return DB::transaction(function () use ($user, $event, $quantities): Order {
            $ticketTypes = $event->ticketTypes()
                ->whereKey(array_keys($quantities))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($ticketTypes->count() !== count($quantities)) {
                throw new PurchaseException('選んだ券種が見つかりません。');
            }

            $items = [];

            foreach ($quantities as $ticketTypeId => $quantity) {
                $ticketType = $ticketTypes->get($ticketTypeId);
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

                if ($quantity === false || $quantity < 1 || $quantity > self::MAX_QUANTITY) {
                    throw new PurchaseException(sprintf('%sの枚数は1〜%d枚で選んでください。', $ticketType->name, self::MAX_QUANTITY));
                }

                if (! $ticketType->isOnSale()) {
                    throw new PurchaseException(sprintf('%sは販売期間外です。', $ticketType->name));
                }

                $remaining = $ticketType->remaining();

                if ($remaining < $quantity) {
                    throw new PurchaseException(sprintf('%sの残りは%d枚です。', $ticketType->name, $remaining));
                }

                $items[] = [
                    'ticket_type_id' => $ticketType->id,
                    'unit_price' => $ticketType->price,
                    'quantity' => $quantity,
                ];
            }

            $order = $user->orders()->create([
                'event_id' => $event->id,
                'total_amount' => array_sum(array_map(fn (array $item): int => $item['unit_price'] * $item['quantity'], $items)),
            ]);

            $order->items()->createMany($items);

            return $order;
        });
    }

    /**
     * Cancel the order and release its reserved tickets.
     *
     * Only pending orders are cancelled, so a paid order is never changed even if this runs late.
     *
     * @return bool Whether the order was cancelled by this call.
     */
    public function cancelOrder(Order $order): bool
    {
        $cancelled = Order::whereKey($order->id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Cancelled]) > 0;

        $order->refresh();

        return $cancelled;
    }

    /**
     * Mark the order as paid and issue one ticket per seat.
     *
     * Only pending orders are updated, so the webhook and the complete page never issue tickets twice.
     * A payment for an order that is no longer pending (e.g. cancelled) is logged, since the money
     * has been received without tickets and needs to be refunded by hand.
     *
     * @param  string|null  $paymentIntentId  The Stripe PaymentIntent that paid the order. Null for free orders.
     * @return bool Whether the order was marked as paid by this call.
     */
    public function markAsPaid(Order $order, ?string $paymentIntentId = null): bool
    {
        $paid = DB::transaction(function () use ($order, $paymentIntentId): bool {
            $updated = Order::whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update([
                    'status' => OrderStatus::Paid,
                    'paid_at' => now(),
                    'stripe_payment_intent_id' => $paymentIntentId,
                ]) > 0;

            if (! $updated) {
                return false;
            }

            foreach ($order->items()->get() as $item) {
                $item->tickets()->createMany(
                    array_map(fn (): array => ['code' => (string) Str::uuid()], range(1, $item->quantity)),
                );
            }

            return true;
        });

        $order->refresh();

        if (! $paid && ! $order->isPaid()) {
            Log::critical('支払いを受け取りましたが、注文が支払い待ちではないためチケットを発行していません。', [
                'order_id' => $order->id,
                'status' => $order->status->value,
                'stripe_checkout_session_id' => $order->stripe_checkout_session_id,
                'stripe_payment_intent_id' => $paymentIntentId,
            ]);
        }

        return $paid;
    }
}

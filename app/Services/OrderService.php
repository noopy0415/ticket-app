<?php

namespace App\Services;

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Minutes a pending order keeps its tickets reserved.
     */
    public const PENDING_MINUTES = 15;

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
}

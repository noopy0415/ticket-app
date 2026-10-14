<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('支払いの中断')] class extends Component {
    public Order $order;

    /**
     * Mount the component.
     *
     * Cancels the order when the buyer comes back from the Stripe payment page without paying.
     */
    public function mount(Order $order, CheckoutService $checkoutService, OrderService $orderService): void
    {
        $this->authorize('cancel', $order);

        if ($order->status === OrderStatus::Pending) {
            $this->cancel($order, $checkoutService, $orderService);
        }

        $this->order = $order->load('event');
    }

    /**
     * Cancel the order after closing its Checkout Session.
     *
     * The Stripe payment page stays payable after the buyer leaves it, so the session is expired first.
     * The order is cancelled only when the session can no longer be paid.
     */
    protected function cancel(Order $order, CheckoutService $checkoutService, OrderService $orderService): void
    {
        if ($order->stripe_checkout_session_id === null) {
            $orderService->cancelOrder($order);

            return;
        }

        try {
            $checkoutService->expireSession($order->stripe_checkout_session_id);
        } catch (ApiErrorException) {
            $this->settleClosedSession($order, $checkoutService, $orderService);

            return;
        }

        $orderService->cancelOrder($order);
    }

    /**
     * Settle the order by the state of a Checkout Session that could not be expired.
     *
     * The session may have been paid in the meantime. If its state cannot be confirmed,
     * the order is left pending for the webhook to settle.
     */
    protected function settleClosedSession(Order $order, CheckoutService $checkoutService, OrderService $orderService): void
    {
        try {
            $session = $checkoutService->retrieveSession($order->stripe_checkout_session_id);
        } catch (ApiErrorException $exception) {
            report($exception);

            return;
        }

        if ($session->payment_status === 'paid' && (string) ($session->metadata->order_id ?? '') === (string) $order->id) {
            $orderService->markAsPaid($order, is_string($session->payment_intent) ? $session->payment_intent : null);
        } elseif ($session->status === 'expired') {
            $orderService->cancelOrder($order);
        }
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">
        {{ match ($order->status) {
            \App\Enums\OrderStatus::Paid => 'この注文は支払い済みです',
            \App\Enums\OrderStatus::Pending => '支払いの状況を確認しています',
            default => '支払いを中断しました',
        } }}
    </flux:heading>

    @if ($order->status === \App\Enums\OrderStatus::Pending)
        <flux:text>支払いの状況を確認できませんでした。しばらくしてから注文一覧でご確認ください。</flux:text>
    @elseif (! $order->isPaid())
        <flux:text>注文は取り消しました。購入する場合は、イベントのページからもう一度お手続きください。</flux:text>
    @endif

    <dl class="grid gap-2 text-sm sm:grid-cols-[6rem_1fr]">
        <dt class="text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd>{{ $order->event->title }}</dd>

        <dt class="text-zinc-500 dark:text-zinc-400">合計金額</dt>
        <dd>{{ number_format($order->total_amount) }}円</dd>

        <dt class="text-zinc-500 dark:text-zinc-400">状態</dt>
        <dd>{{ $order->status->label() }}</dd>
    </dl>

    <flux:link :href="route('events.show', $order->event)" wire:navigate>イベントに戻る</flux:link>
</section>

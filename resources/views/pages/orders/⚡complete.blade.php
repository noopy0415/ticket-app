<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Livewire\Attributes\Title;
use Livewire\Component;
use Stripe\Exception\ApiErrorException;

new #[Title('注文完了')] class extends Component {
    public Order $order;

    /**
     * Mount the component.
     *
     * When the buyer comes back from Stripe, the payment is confirmed here too,
     * so the page is correct even if the webhook has not arrived yet.
     */
    public function mount(Order $order, CheckoutService $checkoutService, OrderService $orderService): void
    {
        $this->authorize('viewComplete', $order);

        $sessionId = request()->query('session_id');

        if ($order->status === OrderStatus::Pending
            && is_string($sessionId)
            && $order->stripe_checkout_session_id !== null
            && hash_equals($order->stripe_checkout_session_id, $sessionId)) {
            $this->confirmPayment($order, $sessionId, $checkoutService, $orderService);
        }

        $this->order = $order->load('event');
    }

    /**
     * Mark the order as paid when Stripe says its Checkout Session has been paid.
     */
    protected function confirmPayment(Order $order, string $sessionId, CheckoutService $checkoutService, OrderService $orderService): void
    {
        try {
            $session = $checkoutService->retrieveSession($sessionId);
        } catch (ApiErrorException $exception) {
            report($exception);

            return;
        }

        if ($session->payment_status === 'paid' && (string) ($session->metadata->order_id ?? '') === (string) $order->id) {
            $orderService->markAsPaid($order);
        }
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">
        {{ $order->isPaid() ? '購入が完了しました' : '支払いを確認しています' }}
    </flux:heading>

    @unless ($order->isPaid())
        <flux:text>支払いの確認には少し時間がかかることがあります。しばらくしてから注文一覧でご確認ください。</flux:text>
    @endunless

    <dl class="grid gap-2 text-sm sm:grid-cols-[6rem_1fr]">
        <dt class="text-zinc-500 dark:text-zinc-400">イベント</dt>
        <dd>{{ $order->event->title }}</dd>

        <dt class="text-zinc-500 dark:text-zinc-400">合計金額</dt>
        <dd>{{ number_format($order->total_amount) }}円</dd>

        <dt class="text-zinc-500 dark:text-zinc-400">状態</dt>
        <dd>{{ $order->status->label() }}</dd>
    </dl>

    <div class="flex gap-4">
        <flux:link :href="route('orders.index')" wire:navigate>注文一覧へ</flux:link>
        <flux:link :href="route('events.show', $order->event)" wire:navigate>イベントに戻る</flux:link>
    </div>
</section>

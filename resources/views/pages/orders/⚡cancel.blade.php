<?php

use App\Models\Order;
use App\Services\OrderService;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('支払いの中断')] class extends Component {
    public Order $order;

    /**
     * Mount the component.
     *
     * Cancels the order when the buyer comes back from the Stripe payment page without paying.
     */
    public function mount(Order $order, OrderService $orderService): void
    {
        $this->authorize('cancel', $order);

        $orderService->cancelOrder($order);

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">
        {{ $order->isPaid() ? 'この注文は支払い済みです' : '支払いを中断しました' }}
    </flux:heading>

    @unless ($order->isPaid())
        <flux:text>注文は取り消しました。購入する場合は、イベントのページからもう一度お手続きください。</flux:text>
    @endunless

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

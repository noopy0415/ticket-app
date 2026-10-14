<?php

use App\Models\Order;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文完了')] class extends Component {
    public Order $order;

    /**
     * Mount the component.
     */
    public function mount(Order $order): void
    {
        $this->authorize('viewComplete', $order);

        $this->order = $order->load('event');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">注文を受け付けました（支払いは平日課題で実装）</flux:heading>

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

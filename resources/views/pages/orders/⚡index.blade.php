<?php

use App\Models\Order;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('注文一覧')] class extends Component {
    /**
     * Get the orders placed by the current user.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        return Auth::user()->orders()->with('event')->latest()->get();
    }
}; ?>

<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">注文一覧</flux:heading>

    @if ($this->orders->isEmpty())
        <flux:text>まだ注文がありません。</flux:text>
        <flux:link :href="route('events.index')" wire:navigate>イベントを探す</flux:link>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>イベント</flux:table.column>
                <flux:table.column>開催日時</flux:table.column>
                <flux:table.column align="end">合計金額</flux:table.column>
                <flux:table.column>状態</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->orders as $order)
                    <flux:table.row :key="$order->id">
                        <flux:table.cell variant="strong">{{ $order->event->title }}</flux:table.cell>
                        <flux:table.cell>{{ $order->event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format($order->total_amount) }}円</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$order->status->color()">{{ $order->status->label() }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</section>

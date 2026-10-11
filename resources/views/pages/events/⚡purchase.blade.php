<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('購入手続き')] class extends Component {
    public Event $event;

    /**
     * Mount the component.
     *
     * Goes back to the event page when nothing has been selected.
     */
    public function mount(Event $event): void
    {
        abort_if(Gate::denies('view', $event), 404);

        $this->event = $event;

        if ($this->quantities() === []) {
            $this->redirectRoute('events.show', $event);
        }
    }

    /**
     * Get the selected quantities keyed by ticket type ID.
     *
     * @return array<int, int>
     */
    protected function quantities(): array
    {
        return session("purchase.{$this->event->id}", []);
    }

    /**
     * Get the selected ticket types.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->whereKey(array_keys($this->quantities()))->orderBy('price')->get();
    }

    /**
     * Get the rows to show, with the quantity and subtotal of each ticket type.
     *
     * Prices here are for display only. The order uses the prices OrderService reads itself.
     *
     * @return list<array{name: string, price: int, quantity: int, subtotal: int}>
     */
    #[Computed]
    public function lines(): array
    {
        $quantities = $this->quantities();

        return $this->ticketTypes->map(fn (TicketType $ticketType) => [
            'name' => $ticketType->name,
            'price' => $ticketType->price,
            'quantity' => $quantities[$ticketType->id],
            'subtotal' => $ticketType->price * $quantities[$ticketType->id],
        ])->values()->all();
    }

    /**
     * Place a pending order and go to the complete page.
     */
    public function pay(OrderService $orderService): void
    {
        try {
            $order = $orderService->createOrder(Auth::user(), $this->event, $this->quantities());
        } catch (PurchaseException $exception) {
            $this->addError('purchase', $exception->getMessage());

            return;
        }

        session()->forget("purchase.{$this->event->id}");

        $this->redirectRoute('orders.complete', $order);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">購入手続き</flux:heading>
        <flux:text>{{ $event->title }}</flux:text>
    </div>

    @error('purchase')
        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
    @enderror

    <flux:table>
        <flux:table.columns>
            <flux:table.column>券種</flux:table.column>
            <flux:table.column align="end">単価</flux:table.column>
            <flux:table.column align="end">枚数</flux:table.column>
            <flux:table.column align="end">小計</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->lines as $line)
                <flux:table.row>
                    <flux:table.cell variant="strong">{{ $line['name'] }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['price']) }}円</flux:table.cell>
                    <flux:table.cell align="end">{{ $line['quantity'] }}枚</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($line['subtotal']) }}円</flux:table.cell>
                </flux:table.row>
            @endforeach

            <flux:table.row>
                <flux:table.cell variant="strong">合計</flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell></flux:table.cell>
                <flux:table.cell align="end" variant="strong">
                    {{ number_format(array_sum(array_column($this->lines, 'subtotal'))) }}円
                </flux:table.cell>
            </flux:table.row>
        </flux:table.rows>
    </flux:table>

    <div class="flex items-center justify-between">
        <flux:link :href="route('events.show', $event)" wire:navigate>イベントに戻る</flux:link>
        <flux:button wire:click="pay" variant="primary">支払いへ進む</flux:button>
    </div>
</section>

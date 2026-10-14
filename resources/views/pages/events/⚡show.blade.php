<?php

use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CheckoutService;
use App\Services\OrderService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント詳細')] class extends Component {
    public Event $event;

    /**
     * Selected quantities keyed by ticket type ID.
     *
     * @var array<int, int|string>
     */
    public array $quantities = [];

    /**
     * Mount the component.
     *
     * Events the user cannot view (drafts of other organizers etc.) are hidden as 404, not 403.
     */
    public function mount(Event $event): void
    {
        abort_if(Gate::denies('view', $event), 404);

        $this->event = $event->load('organizer');
        $this->quantities = $this->ticketTypes->mapWithKeys(fn (TicketType $ticketType) => [$ticketType->id => 0])->all();
    }

    /**
     * Get the ticket types of the event with their reserved quantities.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->withReservedQuantity()->orderBy('price')->get();
    }

    /**
     * Place a pending order for the selected tickets and go to the Stripe payment page.
     *
     * Guests are sent to login and brought back to this page afterwards.
     * Quantities are checked by OrderService, since this property can be changed by the client.
     * Free orders skip Stripe, are paid at once and go straight to the complete page.
     */
    public function pay(OrderService $orderService, CheckoutService $checkoutService): void
    {
        if (! Auth::check()) {
            session()->put('url.intended', route('events.show', $this->event));
            $this->redirectRoute('login');

            return;
        }

        try {
            $order = $orderService->createOrder(Auth::user(), $this->event, $this->quantities);
        } catch (PurchaseException $exception) {
            $this->addError('purchase', $exception->getMessage());
            unset($this->ticketTypes);

            return;
        }

        if ($order->total_amount === 0) {
            $orderService->markAsPaid($order);
            $this->redirectRoute('orders.complete', $order);

            return;
        }

        try {
            $checkoutUrl = $checkoutService->createSession($order);
        } catch (PurchaseException $exception) {
            $orderService->cancelOrder($order);
            $this->addError('purchase', $exception->getMessage());

            return;
        }

        $this->redirect($checkoutUrl);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div class="space-y-3">
        @unless ($event->isPublished())
            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        @endunless

        <flux:heading size="xl" level="1">{{ $event->title }}</flux:heading>

        <dl class="grid gap-2 text-sm sm:grid-cols-[6rem_1fr]">
            <dt class="text-zinc-500 dark:text-zinc-400">日時</dt>
            <dd>
                {{ $event->starts_at->isoFormat('M月D日(ddd) HH:mm') }}
                〜
                {{ $event->ends_at->isoFormat('M月D日(ddd) HH:mm') }}
            </dd>

            <dt class="text-zinc-500 dark:text-zinc-400">会場</dt>
            <dd>{{ $event->venue }}</dd>

            <dt class="text-zinc-500 dark:text-zinc-400">主催者</dt>
            <dd>{{ $event->organizer->name }}</dd>
        </dl>
    </div>

    <flux:separator />

    <div class="space-y-2">
        <flux:heading size="lg" level="2">イベントについて</flux:heading>
        <flux:text class="whitespace-pre-line">{{ $event->description }}</flux:text>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種はまだ登録されていません。</flux:text>
        @else
            <form wire:submit="pay" class="space-y-4">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>券種</flux:table.column>
                        <flux:table.column>価格</flux:table.column>
                        <flux:table.column>残り</flux:table.column>
                        <flux:table.column>販売期間</flux:table.column>
                        @if ($event->isPublished())
                            <flux:table.column>枚数</flux:table.column>
                        @endif
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->ticketTypes as $ticketType)
                            @php($maxQuantity = $ticketType->isOnSale() ? min(OrderService::MAX_QUANTITY, $ticketType->remaining()) : 0)
                            <flux:table.row :key="$ticketType->id">
                                <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                                <flux:table.cell>
                                    {{ $ticketType->price === 0 ? '無料' : number_format($ticketType->price).'円' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($ticketType->remaining() === 0)
                                        <flux:badge size="sm" color="red">売り切れ</flux:badge>
                                    @else
                                        残り{{ number_format($ticketType->remaining()) }}枚
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $ticketType->sales_start_at->isoFormat('M月D日(ddd) HH:mm') }}
                                    〜
                                    {{ $ticketType->sales_end_at->isoFormat('M月D日(ddd) HH:mm') }}
                                    @unless ($ticketType->isOnSale())
                                        <flux:badge size="sm" color="zinc">販売期間外</flux:badge>
                                    @endunless
                                </flux:table.cell>
                                @if ($event->isPublished())
                                    <flux:table.cell>
                                        <flux:select
                                            wire:model="quantities.{{ $ticketType->id }}"
                                            :disabled="$maxQuantity === 0"
                                            aria-label="{{ $ticketType->name }}の枚数"
                                            class="w-24"
                                        >
                                            @foreach (range(0, $maxQuantity) as $quantity)
                                                <flux:select.option :value="$quantity">{{ $quantity }}枚</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    </flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

                @if ($event->isPublished() && $this->ticketTypes->contains(fn ($ticketType) => $ticketType->isOnSale() && $ticketType->remaining() > 0))
                    @error('purchase')
                        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
                    @enderror

                    <div class="flex items-center justify-end gap-4">
                        @guest
                            <flux:text>購入にはログインが必要です。</flux:text>
                        @endguest
                        <flux:button type="submit" variant="primary">支払いへ進む</flux:button>
                    </div>
                @endif
            </form>
        @endif
    </div>
</section>

<?php

use App\Models\Event;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント詳細')] class extends Component {
    public Event $event;

    /**
     * Mount the component.
     *
     * Events the user cannot view (drafts of other organizers etc.) are hidden as 404, not 403.
     */
    public function mount(Event $event): void
    {
        abort_if(Gate::denies('view', $event), 404);

        $this->event = $event->load([
            'organizer',
            'ticketTypes' => fn (HasMany $query) => $query->withReservedQuantity()->orderBy('price'),
        ]);
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

        @if ($event->ticketTypes->isEmpty())
            <flux:text>券種はまだ登録されていません。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種</flux:table.column>
                    <flux:table.column>価格</flux:table.column>
                    <flux:table.column>残り</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($event->ticketTypes as $ticketType)
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
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>

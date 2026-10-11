<?php

use App\Concerns\EventValidationRules;
use App\Concerns\TicketTypeValidationRules;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント編集')] class extends Component {
    use EventValidationRules;
    use TicketTypeValidationRules;

    public Event $event;

    public string $title = '';
    public string $description = '';
    public string $venue = '';
    public string $starts_at = '';
    public string $ends_at = '';

    #[Locked]
    public ?int $editingTicketTypeId = null;
    public string $name = '';
    public int|string $price = '';
    public int|string $capacity = '';
    public string $sales_start_at = '';
    public string $sales_end_at = '';

    /**
     * Mount the component.
     */
    public function mount(Event $event): void
    {
        $this->authorize('update', $event);

        $this->event = $event;
        $this->title = $event->title;
        $this->description = $event->description;
        $this->venue = $event->venue;
        $this->starts_at = $event->starts_at->format('Y-m-d\TH:i');
        $this->ends_at = $event->ends_at->format('Y-m-d\TH:i');
    }

    /**
     * Update the event.
     */
    public function save(): void
    {
        $this->authorize('update', $this->event);

        $validated = $this->validate($this->eventRules());

        $this->event->update($validated);

        Flux::toast(variant: 'success', text: 'イベントを更新しました。');
    }

    /**
     * Switch the event between draft and published.
     */
    public function togglePublish(): void
    {
        $this->authorize('publish', $this->event);

        if (! $this->event->isPublished() && $this->event->ticketTypes()->doesntExist()) {
            Flux::toast(variant: 'danger', text: '券種を1つ以上登録してから公開してください。');

            return;
        }

        $this->event->status = $this->event->isPublished() ? EventStatus::Draft : EventStatus::Published;
        $this->event->save();

        Flux::toast(
            variant: 'success',
            text: $this->event->isPublished() ? 'イベントを公開しました。' : 'イベントを下書きに戻しました。',
        );
    }

    /**
     * Delete the event.
     */
    public function deleteEvent(): void
    {
        $this->authorize('delete', $this->event);

        $this->event->delete();

        Flux::toast(variant: 'success', text: 'イベントを削除しました。');

        $this->redirectRoute('organizer.events.index', navigate: true);
    }

    /**
     * Get the ticket types of the event.
     *
     * @return Collection<int, TicketType>
     */
    #[Computed]
    public function ticketTypes(): Collection
    {
        return $this->event->ticketTypes()->orderBy('id')->get();
    }

    /**
     * Create a ticket type, or update the one being edited.
     */
    public function saveTicketType(): void
    {
        $this->authorize('update', $this->event);

        $validated = $this->validate($this->ticketTypeRules(), [], $this->ticketTypeAttributes());
        $validated['price'] = (int) $validated['price'];
        $validated['capacity'] = (int) $validated['capacity'];

        if ($this->editingTicketTypeId === null) {
            $this->event->ticketTypes()->create($validated);
            $message = '券種を追加しました。';
        } else {
            $this->event->ticketTypes()->findOrFail($this->editingTicketTypeId)->update($validated);
            $message = '券種を更新しました。';
        }

        $this->resetTicketTypeForm();

        Flux::toast(variant: 'success', text: $message);
    }

    /**
     * Fill the form with the ticket type to edit.
     */
    public function editTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        $this->resetValidation(['name', 'price', 'capacity', 'sales_start_at', 'sales_end_at']);
        $this->editingTicketTypeId = $ticketType->id;
        $this->name = $ticketType->name;
        $this->price = $ticketType->price;
        $this->capacity = $ticketType->capacity;
        $this->sales_start_at = $ticketType->sales_start_at->format('Y-m-d\TH:i');
        $this->sales_end_at = $ticketType->sales_end_at->format('Y-m-d\TH:i');
    }

    /**
     * Stop editing and clear the ticket type form.
     */
    public function resetTicketTypeForm(): void
    {
        $this->reset('editingTicketTypeId', 'name', 'price', 'capacity', 'sales_start_at', 'sales_end_at');
        $this->resetValidation(['name', 'price', 'capacity', 'sales_start_at', 'sales_end_at']);
    }

    /**
     * Delete the ticket type.
     */
    public function deleteTicketType(int $ticketTypeId): void
    {
        $this->authorize('update', $this->event);

        $this->event->ticketTypes()->findOrFail($ticketTypeId)->delete();

        if ($this->editingTicketTypeId === $ticketTypeId) {
            $this->resetTicketTypeForm();
        }

        Flux::toast(variant: 'success', text: '券種を削除しました。');
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <flux:heading size="xl" level="1">イベント編集</flux:heading>
            <flux:badge size="sm" :color="$event->status->color()">{{ $event->status->label() }}</flux:badge>
        </div>

        @cannot('publish', $event)
            <flux:button disabled>管理者により非公開</flux:button>
        @elseif ($event->isPublished())
            <flux:button icon="eye-slash" wire:click="togglePublish">下書きに戻す</flux:button>
        @else
            <flux:button variant="primary" icon="eye" wire:click="togglePublish">公開する</flux:button>
        @endcannot
    </div>

    <form wire:submit="save" class="space-y-6">
        @include('pages.organizer.events.partials.form-fields')

        <div class="flex items-center justify-between gap-4">
            <flux:button variant="primary" type="submit">保存</flux:button>

            <flux:modal.trigger name="confirm-event-deletion">
                <flux:button variant="danger" icon="trash">削除</flux:button>
            </flux:modal.trigger>
        </div>
    </form>

    <flux:separator />

    <div class="space-y-6">
        <flux:heading size="lg" level="2">券種</flux:heading>

        @if ($this->ticketTypes->isEmpty())
            <flux:text>券種がありません。公開するには1つ以上必要です。</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>券種名</flux:table.column>
                    <flux:table.column align="end">価格</flux:table.column>
                    <flux:table.column align="end">定員</flux:table.column>
                    <flux:table.column>販売期間</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->ticketTypes as $ticketType)
                        <flux:table.row :key="$ticketType->id">
                            <flux:table.cell variant="strong">{{ $ticketType->name }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->price) }}円</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($ticketType->capacity) }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $ticketType->sales_start_at->isoFormat('M月D日(ddd) HH:mm') }}
                                〜 {{ $ticketType->sales_end_at->isoFormat('M月D日(ddd) HH:mm') }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" icon="pencil-square" wire:click="editTicketType({{ $ticketType->id }})">
                                        編集
                                    </flux:button>
                                    <flux:button
                                        size="sm"
                                        variant="danger"
                                        icon="trash"
                                        wire:click="deleteTicketType({{ $ticketType->id }})"
                                        wire:confirm="券種「{{ $ticketType->name }}」を削除しますか？"
                                    >
                                        削除
                                    </flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <form wire:submit="saveTicketType" class="space-y-6 rounded-lg border border-zinc-200 p-6 dark:border-zinc-700">
            <flux:heading level="3">{{ $editingTicketTypeId === null ? '券種を追加' : '券種を編集' }}</flux:heading>

            <flux:input wire:model="name" label="券種名" type="text" required maxlength="50" />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="price" label="価格（円）" type="number" min="0" max="1000000" step="1" required />

                <flux:input wire:model="capacity" label="定員" type="number" min="1" max="10000" step="1" required />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="sales_start_at" label="販売開始日時" type="datetime-local" required />

                <flux:input wire:model="sales_end_at" label="販売終了日時" type="datetime-local" required />
            </div>

            <div class="flex items-center gap-2">
                <flux:button variant="primary" type="submit">
                    {{ $editingTicketTypeId === null ? '追加' : '更新' }}
                </flux:button>

                @if ($editingTicketTypeId !== null)
                    <flux:button type="button" wire:click="resetTicketTypeForm">キャンセル</flux:button>
                @endif
            </div>
        </form>
    </div>

    <flux:modal name="confirm-event-deletion" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">このイベントを削除しますか？</flux:heading>
                <flux:subheading>削除すると元に戻せません。</flux:subheading>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">キャンセル</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" wire:click="deleteEvent">削除する</flux:button>
            </div>
        </div>
    </flux:modal>
</section>

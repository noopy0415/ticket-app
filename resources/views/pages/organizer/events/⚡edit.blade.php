<?php

use App\Concerns\EventValidationRules;
use App\Enums\EventStatus;
use App\Models\Event;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント編集')] class extends Component {
    use EventValidationRules;

    public Event $event;

    public string $title = '';
    public string $description = '';
    public string $venue = '';
    public string $starts_at = '';
    public string $ends_at = '';

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

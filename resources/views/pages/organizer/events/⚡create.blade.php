<?php

use App\Concerns\EventValidationRules;
use App\Models\Event;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('イベント作成')] class extends Component {
    use EventValidationRules;

    public string $title = '';
    public string $description = '';
    public string $venue = '';
    public string $starts_at = '';
    public string $ends_at = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Event::class);
    }

    /**
     * Store a new draft event for the current user.
     */
    public function save(): void
    {
        $this->authorize('create', Event::class);

        $validated = $this->validate($this->eventRules());

        $event = Auth::user()->events()->create($validated);

        Flux::toast(variant: 'success', text: 'イベントを下書きとして保存しました。');

        $this->redirectRoute('organizer.events.edit', $event, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">イベント作成</flux:heading>

    <form wire:submit="save" class="space-y-6">
        @include('pages.organizer.events.partials.form-fields')

        <flux:button variant="primary" type="submit">下書きとして保存</flux:button>
    </form>
</section>

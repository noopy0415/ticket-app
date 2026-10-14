<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('organizers can add a ticket type to their event', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('name', '一般')
        ->set('price', '3000')
        ->set('capacity', '100')
        ->set('sales_start_at', '2026-11-01T10:00')
        ->set('sales_end_at', '2026-11-30T23:59')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('name', '')
        ->assertSee('3,000円');

    $ticketType = $event->ticketTypes()->sole();

    expect($ticketType->name)->toBe('一般')
        ->and($ticketType->price)->toBe(3000)
        ->and($ticketType->capacity)->toBe(100)
        ->and($ticketType->sales_start_at->format('Y-m-d H:i'))->toBe('2026-11-01 10:00');
});

test('organizers can edit a ticket type with the same form', function () {
    $ticketType = TicketType::factory()->create(['name' => '一般', 'price' => 3000]);

    $this->actingAs($ticketType->event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('editTicketType', $ticketType->id)
        ->assertSet('editingTicketTypeId', $ticketType->id)
        ->assertSet('name', '一般')
        ->assertSet('price', 3000)
        ->set('name', '学生')
        ->set('price', '1500')
        ->call('saveTicketType')
        ->assertHasNoErrors()
        ->assertSet('editingTicketTypeId', null);

    $ticketType->refresh();

    expect(TicketType::count())->toBe(1)
        ->and($ticketType->name)->toBe('学生')
        ->and($ticketType->price)->toBe(1500);
});

test('organizers can delete a ticket type', function () {
    $ticketType = TicketType::factory()->create();

    $this->actingAs($ticketType->event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->call('deleteTicketType', $ticketType->id)
        ->assertHasNoErrors();

    expect(TicketType::count())->toBe(0);
});

test('ticket type fields are required', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('price', '')
        ->set('capacity', '')
        ->call('saveTicketType')
        ->assertHasErrors([
            'name' => 'required',
            'price' => 'required',
            'capacity' => 'required',
            'sales_start_at' => 'required',
            'sales_end_at' => 'required',
        ]);

    expect(TicketType::count())->toBe(0);
});

test('ticket type fields are validated', function (string $field, mixed $value, string $rule) {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('name', '一般')
        ->set('price', '1000')
        ->set('capacity', '10')
        ->set('sales_start_at', '2026-11-01T10:00')
        ->set('sales_end_at', '2026-11-30T23:59')
        ->set($field, $value)
        ->call('saveTicketType')
        ->assertHasErrors([$field => $rule]);

    expect(TicketType::count())->toBe(0);
})->with([
    'name too long' => ['name', str_repeat('あ', 51), 'max'],
    'negative price' => ['price', '-1', 'min'],
    'non-integer price' => ['price', '1.5', 'integer'],
    'price over the limit' => ['price', '1000001', 'max'],
    'zero capacity' => ['capacity', '0', 'min'],
    'capacity over the limit' => ['capacity', '10001', 'max'],
    'sales end before start' => ['sales_end_at', '2026-11-01T10:00', 'after'],
]);

test('free tickets are allowed', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('name', '無料')
        ->set('price', '0')
        ->set('capacity', '1')
        ->set('sales_start_at', '2026-11-01T10:00')
        ->set('sales_end_at', '2026-11-30T23:59')
        ->call('saveTicketType')
        ->assertHasNoErrors();

    expect($event->ticketTypes()->sole()->price)->toBe(0);
});

test('ticket type actions are authorized even after the component is mounted', function (string $method) {
    $ticketType = TicketType::factory()->create(['name' => '一般']);

    $this->actingAs($ticketType->event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->set('name', '書き換え')
        ->set('price', '1000')
        ->set('capacity', '10')
        ->set('sales_start_at', '2026-11-01T10:00')
        ->set('sales_end_at', '2026-11-30T23:59');

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method, $ticketType->id)->assertForbidden();

    expect(TicketType::sole()->name)->toBe('一般');
})->with(['saveTicketType', 'editTicketType', 'deleteTicketType']);

test('other organizers cannot add a ticket type to the event', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->organizer()->create());

    $this->get(route('organizer.events.edit', $event))->assertForbidden();

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->assertForbidden();

    expect(TicketType::count())->toBe(0);
});

test('the ticket type being edited cannot be changed from the browser', function () {
    $event = Event::factory()->create();
    $otherTicketType = TicketType::factory()->create(['name' => '一般']);

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('editingTicketTypeId', $otherTicketType->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('resetting the ticket type form keeps the event form errors', function () {
    $ticketType = TicketType::factory()->create();

    $this->actingAs($ticketType->event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $ticketType->event])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required'])
        ->call('editTicketType', $ticketType->id)
        ->call('resetTicketTypeForm')
        ->assertHasErrors('title');
});

test('ticket types of another event cannot be edited or deleted', function (string $method) {
    $event = Event::factory()->create();
    $otherTicketType = TicketType::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call($method, $otherTicketType->id)
        ->assertNotFound();

    expect(TicketType::whereKey($otherTicketType->id)->exists())->toBeTrue();
})->with(['editTicketType', 'deleteTicketType']);

test('events without ticket types cannot be published', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('ticket types are deleted with their event', function () {
    $ticketType = TicketType::factory()->create();

    $ticketType->event->delete();

    expect(TicketType::count())->toBe(0);
});

test('ticket types are on sale only within the sales period', function () {
    $ticketType = TicketType::factory()->create([
        'sales_start_at' => '2026-11-01 10:00',
        'sales_end_at' => '2026-11-30 23:59',
    ]);

    $this->travelTo('2026-11-01 09:59');
    expect($ticketType->isOnSale())->toBeFalse();

    $this->travelTo('2026-11-15 12:00');
    expect($ticketType->isOnSale())->toBeTrue();

    $this->travelTo('2026-12-01 00:00');
    expect($ticketType->isOnSale())->toBeFalse();
});

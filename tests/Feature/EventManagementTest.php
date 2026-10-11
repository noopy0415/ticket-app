<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('organizer.events.index'))->assertRedirect(route('login'));
});

test('organizers with an unverified email are redirected to the verification notice', function () {
    $this->actingAs(User::factory()->organizer()->unverified()->create());

    $this->get(route('organizer.events.index'))->assertRedirect(route('verification.notice'));
});

test('upcoming events are compared in Japan time', function () {
    $this->travelTo('2026-12-01 18:30');

    $ongoing = Event::factory()->create(['starts_at' => '2026-12-01 18:00', 'ends_at' => '2026-12-01 21:00']);
    $upcoming = Event::factory()->create(['starts_at' => '2026-12-01 19:00', 'ends_at' => '2026-12-01 21:00']);

    expect(Event::upcoming()->pluck('id')->all())->toBe([$upcoming->id])
        ->and($ongoing->starts_at->timezoneName)->toBe('Asia/Tokyo');
});

test('customers cannot access the organizer pages', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('organizer.events.index'))->assertForbidden();
    $this->get(route('organizer.events.create'))->assertForbidden();
});

test('organizers see only their own events', function () {
    $organizer = User::factory()->organizer()->create();
    Event::factory()->for($organizer, 'organizer')->create(['title' => '自分のイベント']);
    Event::factory()->create(['title' => '他人のイベント']);

    $this->actingAs($organizer);

    $this->get(route('organizer.events.index'))
        ->assertOk()
        ->assertSee('自分のイベント')
        ->assertDontSee('他人のイベント');
});

test('organizers can create a draft event', function () {
    $organizer = User::factory()->organizer()->create();

    $this->actingAs($organizer);

    $this->get(route('organizer.events.create'))->assertOk();

    Livewire::test('pages::organizer.events.create')
        ->set('title', '夏祭り')
        ->set('description', '駅前広場で開催します')
        ->set('venue', '駅前広場')
        ->set('starts_at', '2026-12-01T18:00')
        ->set('ends_at', '2026-12-01T21:00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $event = Event::sole();

    expect($event->organizer_id)->toBe($organizer->id)
        ->and($event->status)->toBe(EventStatus::Draft)
        ->and($event->starts_at->format('Y-m-d H:i'))->toBe('2026-12-01 18:00');
});

test('event fields are validated', function () {
    $this->actingAs(User::factory()->organizer()->create());

    Livewire::test('pages::organizer.events.create')
        ->call('save')
        ->assertHasErrors([
            'title' => 'required',
            'description' => 'required',
            'venue' => 'required',
            'starts_at' => 'required',
            'ends_at' => 'required',
        ]);

    Livewire::test('pages::organizer.events.create')
        ->set('title', str_repeat('あ', 101))
        ->set('description', '説明')
        ->set('venue', str_repeat('あ', 101))
        ->set('starts_at', '2026-12-01T18:00')
        ->set('ends_at', '2026-12-01T18:00')
        ->call('save')
        ->assertHasErrors(['title' => 'max', 'venue' => 'max', 'ends_at' => 'after']);

    expect(Event::count())->toBe(0);
});

test('events cannot end before they start', function () {
    $this->actingAs(User::factory()->organizer()->create());

    Livewire::test('pages::organizer.events.create')
        ->set('title', '夏祭り')
        ->set('description', '説明')
        ->set('venue', '駅前広場')
        ->set('starts_at', '2026-12-01T18:00')
        ->set('ends_at', '2026-12-01T17:00')
        ->call('save')
        ->assertHasErrors(['ends_at' => 'after']);

    expect(Event::count())->toBe(0);
});

test('organizers can update their own event', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    $this->get(route('organizer.events.edit', $event))->assertOk();

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('title', '新しいタイトル')
        ->call('save')
        ->assertHasNoErrors();

    expect($event->refresh()->title)->toBe('新しいタイトル');
});

test('organizers can toggle the event between draft and published', function () {
    $event = Event::factory()->has(TicketType::factory())->create();

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish');

    expect($event->refresh()->status)->toBe(EventStatus::Published);

    $component->call('togglePublish');

    expect($event->refresh()->status)->toBe(EventStatus::Draft);
});

test('events unpublished by an admin cannot be republished by the organizer', function () {
    $event = Event::factory()->unpublished()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('togglePublish')
        ->assertForbidden();

    expect($event->refresh()->status)->toBe(EventStatus::Unpublished);
});

test('organizers can delete their own event', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer);

    Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->call('deleteEvent')
        ->assertRedirect(route('organizer.events.index'));

    expect(Event::count())->toBe(0);
});

test('organizers cannot open the edit page of another organizer\'s event', function () {
    $event = Event::factory()->create();

    $this->actingAs(User::factory()->organizer()->create());

    $this->get(route('organizer.events.edit', $event))->assertForbidden();
});

test('actions are authorized even after the component is mounted', function (string $method) {
    $event = Event::factory()->create(['title' => '元のタイトル']);

    $this->actingAs($event->organizer);

    $component = Livewire::test('pages::organizer.events.edit', ['event' => $event])
        ->set('title', '書き換え');

    $this->actingAs(User::factory()->organizer()->create());

    $component->call($method)->assertForbidden();

    $event->refresh();

    expect($event->title)->toBe('元のタイトル')
        ->and($event->status)->toBe(EventStatus::Draft);
})->with(['save', 'togglePublish', 'deleteEvent']);

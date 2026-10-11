<?php

use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('published events can be viewed by anyone', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $event))->toBeTrue();
});

test('draft events can be viewed only by the organizer and admins', function () {
    $event = Event::factory()->create();

    expect(Gate::forUser(null)->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('view', $event))->toBeFalse()
        ->and(Gate::forUser($event->organizer)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $event))->toBeTrue();
});

test('only organizers can create events', function () {
    expect(Gate::forUser(User::factory()->organizer()->create())->allows('create', Event::class))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('create', Event::class))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('create', Event::class))->toBeFalse();
});

test('only the owner can update and delete an event', function (string $ability) {
    $event = Event::factory()->create();

    expect(Gate::forUser($event->organizer)->allows($ability, $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows($ability, $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows($ability, $event))->toBeFalse();
})->with(['update', 'delete']);

test('only the owner can publish an event unless an admin has unpublished it', function () {
    $event = Event::factory()->create();

    expect(Gate::forUser($event->organizer)->allows('publish', $event))->toBeTrue()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('publish', $event))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('publish', $event))->toBeFalse();

    $unpublished = Event::factory()->unpublished()->create();

    expect(Gate::forUser($unpublished->organizer)->allows('publish', $unpublished))->toBeFalse();
});

test('only admins can unpublish an event', function () {
    $event = Event::factory()->published()->create();

    expect(Gate::forUser(User::factory()->admin()->create())->allows('unpublish', $event))->toBeTrue()
        ->and(Gate::forUser($event->organizer)->allows('unpublish', $event))->toBeFalse();
});

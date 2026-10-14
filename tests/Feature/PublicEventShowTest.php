<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;

test('guests can view a published event with its ticket types', function () {
    $event = Event::factory()->published()->create(['venue' => '市民ホール']);
    $ticketType = TicketType::factory()->for($event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 10]);
    OrderItem::factory()
        ->for(Order::factory()->for($event)->state(['status' => OrderStatus::Paid]))
        ->for($ticketType)
        ->create(['quantity' => 4]);

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertSee($event->title)
        ->assertSee('市民ホール')
        ->assertSee($event->organizer->name)
        ->assertSee($event->starts_at->isoFormat('M月D日(ddd) HH:mm'))
        ->assertSee('一般')
        ->assertSee('3,000円')
        ->assertSee('残り6枚');
});

test('sold out ticket types are labelled', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 2]);
    OrderItem::factory()
        ->for(Order::factory()->for($event)->state(['status' => OrderStatus::Paid]))
        ->for($ticketType)
        ->create(['quantity' => 2]);

    $this->get(route('events.show', $event))->assertSee('売り切れ');
});

test('draft events are not found for guests and other users', function (?Closure $makeUser) {
    $event = Event::factory()->create();

    if ($makeUser) {
        $this->actingAs($makeUser());
    }

    $this->get(route('events.show', $event))->assertNotFound();
})->with([
    'guest' => [null],
    'customer' => [fn () => User::factory()->create()],
    'other organizer' => [fn () => User::factory()->organizer()->create()],
]);

test('draft events can be viewed by the owner and admins', function () {
    $event = Event::factory()->create();

    $this->actingAs($event->organizer)->get(route('events.show', $event))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('events.show', $event))->assertOk();
});

test('unpublished events are not found for guests and customers', function (?Closure $makeUser) {
    $event = Event::factory()->unpublished()->create();

    if ($makeUser) {
        $this->actingAs($makeUser());
    }

    $this->get(route('events.show', $event))->assertNotFound();
})->with([
    'guest' => [null],
    'customer' => [fn () => User::factory()->create()],
    'other organizer' => [fn () => User::factory()->organizer()->create()],
]);

test('unpublished events can be viewed by the owner and admins', function () {
    $event = Event::factory()->unpublished()->create();

    $this->actingAs($event->organizer)->get(route('events.show', $event))->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get(route('events.show', $event))->assertOk();
});

test('the detail page deducts recent pending orders but not stale ones', function () {
    $event = Event::factory()->published()->create();
    $ticketType = TicketType::factory()->for($event)->create(['capacity' => 10]);

    $pendingOrder = fn (DateTimeInterface $createdAt) => OrderItem::factory()
        ->for(Order::factory()->for($event)->state(['status' => OrderStatus::Pending, 'created_at' => $createdAt]))
        ->for($ticketType)
        ->create(['quantity' => 3]);

    $pendingOrder(now()->subMinutes(TicketType::PENDING_MINUTES - 1));
    $this->get(route('events.show', $event))->assertSee('残り7枚');

    $pendingOrder(now()->subMinutes(TicketType::PENDING_MINUTES + 1));
    $this->get(route('events.show', $event))->assertSee('残り7枚')->assertDontSee('残り4枚');
});

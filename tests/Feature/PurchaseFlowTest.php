<?php

use App\Enums\OrderStatus;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $this->general = TicketType::factory()->for($this->event)->create(['name' => '一般', 'price' => 3000, 'capacity' => 10]);
    $this->student = TicketType::factory()->for($this->event)->create(['name' => '学生', 'price' => 1500, 'capacity' => 10]);
});

test('paying from the event page places a pending order and shows the complete page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->general->id}", '2')
        ->set("quantities.{$this->student->id}", '1')
        ->call('pay');

    $order = Order::sole();
    $component->assertRedirect(route('orders.complete', $order));

    expect($order)
        ->user_id->toBe($user->id)
        ->status->toBe(OrderStatus::Pending)
        ->total_amount->toBe(7500);
});

test('an error is shown when the tickets sold out in the meantime', function () {
    $this->actingAs(User::factory()->create());
    $component = Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->general->id}", '2');

    OrderItem::factory()
        ->for(Order::factory()->for($this->event)->paid())
        ->for($this->general)
        ->create(['quantity' => 9]);

    $component->call('pay')
        ->assertHasErrors(['purchase'])
        ->assertSee('一般の残りは1枚です。')
        ->assertNoRedirect();

    expect(Order::where('user_id', auth()->id())->exists())->toBeFalse();
});

test('an error is shown when the sales period ended in the meantime', function () {
    $this->actingAs(User::factory()->create());
    $component = Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->general->id}", '2');

    $this->general->update(['sales_end_at' => now()->subMinute()]);

    $component->call('pay')
        ->assertHasErrors(['purchase'])
        ->assertSee('一般は販売期間外です。')
        ->assertNoRedirect();

    expect(Order::count())->toBe(0);
});

test('paying without selecting any ticket shows an error', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->call('pay')
        ->assertSee('枚数を選んでください。')
        ->assertNoRedirect();
});

test('paying with every quantity set to 0 is rejected and creates no order', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->general->id}", '0')
        ->set("quantities.{$this->student->id}", '0')
        ->call('pay')
        ->assertHasErrors(['purchase'])
        ->assertSee('枚数を選んでください。')
        ->assertNoRedirect();

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('guests are sent to login and come back to the event page', function () {
    Livewire::test('pages::events.show', ['event' => $this->event])
        ->set("quantities.{$this->general->id}", '1')
        ->call('pay')
        ->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe(route('events.show', $this->event))
        ->and(Order::count())->toBe(0);
});

test('ticket types that cannot be bought are not selectable', function () {
    $this->general->update(['sales_start_at' => now()->addDay()]);
    OrderItem::factory()
        ->for(Order::factory()->for($this->event)->paid())
        ->for($this->student)
        ->create(['quantity' => 10]);

    $this->get(route('events.show', $this->event))
        ->assertSee('販売期間外')
        ->assertSee('売り切れ')
        ->assertDontSee('支払いへ進む');
});

test('the complete page can be opened by the buyer', function () {
    $order = Order::factory()->for($this->event)->create();

    $this->actingAs($order->user)
        ->get(route('orders.complete', $order))
        ->assertOk()
        ->assertSee('注文を受け付けました');
});

test('the complete page cannot be opened by anyone but the buyer', function (Closure $makeUser) {
    $order = Order::factory()->for($this->event)->create();

    $this->actingAs($makeUser($this->event))
        ->get(route('orders.complete', $order))
        ->assertForbidden();
})->with([
    'another user' => [fn () => User::factory()->create()],
    'the organizer' => [fn (Event $event) => $event->organizer],
    'an admin' => [fn () => User::factory()->admin()->create()],
]);

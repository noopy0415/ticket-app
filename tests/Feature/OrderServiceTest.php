<?php

use App\Enums\OrderStatus;
use App\Exceptions\PurchaseException;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->event = Event::factory()->published()->create();
    $this->user = User::factory()->create();
});

test('a pending order and its items are created with the current prices', function () {
    $general = TicketType::factory()->for($this->event)->create(['price' => 3000, 'capacity' => 10]);
    $student = TicketType::factory()->for($this->event)->create(['price' => 1500, 'capacity' => 10]);

    $order = app(OrderService::class)->createOrder($this->user, $this->event, [
        $general->id => 2,
        $student->id => 1,
    ]);

    $general->update(['price' => 9999]);

    expect($order->fresh())
        ->status->toBe(OrderStatus::Pending)
        ->user_id->toBe($this->user->id)
        ->event_id->toBe($this->event->id)
        ->total_amount->toBe(7500)
        ->and($order->items()->orderBy('ticket_type_id')->get(['ticket_type_id', 'unit_price', 'quantity'])->toArray())
        ->toBe([
            ['ticket_type_id' => $general->id, 'unit_price' => 3000, 'quantity' => 2],
            ['ticket_type_id' => $student->id, 'unit_price' => 1500, 'quantity' => 1],
        ]);
});

test('an order exceeding the remaining tickets is rejected', function () {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 3]);

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 4]))
        ->toThrow(PurchaseException::class, 'の残りは3枚です。');

    expect(Order::count())->toBe(0)
        ->and(OrderItem::count())->toBe(0);
});

test('a ticket type outside its sales period cannot be ordered', function (array $period) {
    $ticketType = TicketType::factory()->for($this->event)->create($period);

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => 1]))
        ->toThrow(PurchaseException::class, 'は販売期間外です。');

    expect(Order::count())->toBe(0);
})->with([
    'before sales' => fn () => ['sales_start_at' => now()->addDay(), 'sales_end_at' => now()->addWeek()],
    'after sales' => fn () => ['sales_start_at' => now()->subWeek(), 'sales_end_at' => now()->subDay()],
]);

test('quantities outside the allowed range are rejected', function (mixed $quantity) {
    $ticketType = TicketType::factory()->for($this->event)->create(['capacity' => 100]);

    expect(fn () => app(OrderService::class)->createOrder($this->user, $this->event, [$ticketType->id => $quantity]))
        ->toThrow(PurchaseException::class, 'の枚数は1〜10枚で選んでください。');

    expect(Order::count())->toBe(0);
})->with([
    'more than the maximum' => OrderService::MAX_QUANTITY + 1,
    'negative' => -1,
]);

test('marking an order as paid issues one ticket per seat', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create(['quantity' => 2]);
    OrderItem::factory()->for($order)->create(['quantity' => 1]);

    $paid = app(OrderService::class)->markAsPaid($order);

    expect($paid)->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->items()->withCount('tickets')->pluck('tickets_count')->sort()->values()->all())->toBe([1, 2])
        ->and($order->tickets()->pluck('code')->unique())->toHaveCount(3)
        ->and($order->tickets()->pluck('code')->every(fn (string $code): bool => Str::isUuid($code)))->toBeTrue();
});

test('marking an order as paid saves the payment intent', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create();

    app(OrderService::class)->markAsPaid($order, 'pi_test_dummy');

    expect($order->stripe_payment_intent_id)->toBe('pi_test_dummy');
});

test('an order already paid does not get its tickets issued twice', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create(['quantity' => 2]);
    app(OrderService::class)->markAsPaid($order);

    $paid = app(OrderService::class)->markAsPaid($order);

    expect($paid)->toBeFalse()
        ->and($order->tickets()->count())->toBe(2);
});

test('a cancelled order is not marked as paid', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create();
    app(OrderService::class)->cancelOrder($order);

    $paid = app(OrderService::class)->markAsPaid($order);

    expect($paid)->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->tickets()->count())->toBe(0);
});

test('a payment for a cancelled order is logged for a refund', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create();
    app(OrderService::class)->cancelOrder($order);
    Log::spy();

    app(OrderService::class)->markAsPaid($order, 'pi_test_dummy');

    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['order_id'] === $order->id
            && $context['stripe_payment_intent_id'] === 'pi_test_dummy');
});

test('a payment for an order already paid is not logged', function () {
    $order = Order::factory()->for($this->event)->create();
    OrderItem::factory()->for($order)->create();
    app(OrderService::class)->markAsPaid($order);
    Log::spy();

    app(OrderService::class)->markAsPaid($order);

    Log::shouldNotHaveReceived('critical');
});

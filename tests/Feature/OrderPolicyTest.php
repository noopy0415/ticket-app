<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('orders can be viewed by the buyer, the event organizer and admins only', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser($order->user)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser($order->event->organizer)->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('view', $order))->toBeTrue()
        ->and(Gate::forUser(User::factory()->create())->allows('view', $order))->toBeFalse()
        ->and(Gate::forUser(User::factory()->organizer()->create())->allows('view', $order))->toBeFalse();
});

test('the complete page of an order can be viewed by the buyer only', function () {
    $order = Order::factory()->create();

    expect(Gate::forUser($order->user)->allows('viewComplete', $order))->toBeTrue()
        ->and(Gate::forUser($order->event->organizer)->allows('viewComplete', $order))->toBeFalse()
        ->and(Gate::forUser(User::factory()->admin()->create())->allows('viewComplete', $order))->toBeFalse()
        ->and(Gate::forUser(User::factory()->create())->allows('viewComplete', $order))->toBeFalse();
});

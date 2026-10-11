<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('orders.index'))->assertRedirect(route('login'));
});

test('users see only their own orders, newest first', function () {
    $user = User::factory()->create();

    Order::factory()->for($user)->paid()->create([
        'event_id' => Event::factory()->published()->create(['title' => '先に買ったイベント'])->id,
        'total_amount' => 3000,
        'created_at' => now()->subDay(),
    ]);
    Order::factory()->for($user)->create([
        'event_id' => Event::factory()->published()->create(['title' => '後に買ったイベント'])->id,
        'created_at' => now(),
    ]);
    Order::factory()->create([
        'event_id' => Event::factory()->published()->create(['title' => '他人のイベント'])->id,
    ]);

    $this->actingAs($user)
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSeeInOrder(['後に買ったイベント', '先に買ったイベント'])
        ->assertSee('3,000円')
        ->assertSee('支払い済み')
        ->assertDontSee('他人のイベント');
});

test('a message is shown when the user has no orders', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('orders.index'))
        ->assertOk()
        ->assertSee('まだ注文がありません。');
});

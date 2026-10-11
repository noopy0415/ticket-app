<?php

use App\Models\Event;
use App\Models\TicketType;

test('only published events that have not ended are listed, nearest first', function () {
    Event::factory()->published()->create(['title' => '後のイベント', 'starts_at' => now()->addDays(10), 'ends_at' => now()->addDays(10)->addHours(2)]);
    Event::factory()->published()->create(['title' => '先のイベント', 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
    Event::factory()->published()->create(['title' => '開催中のイベント', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    Event::factory()->published()->create(['title' => '終了したイベント', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
    Event::factory()->create(['title' => '下書きのイベント']);
    Event::factory()->unpublished()->create(['title' => '非公開のイベント']);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSeeInOrder(['開催中のイベント', '先のイベント', '後のイベント'])
        ->assertDontSee('終了したイベント')
        ->assertDontSee('下書きのイベント')
        ->assertDontSee('非公開のイベント');
});

test('the lowest price is shown, or free when it is zero', function () {
    $paid = Event::factory()->published()->create();
    TicketType::factory()->for($paid)->create(['price' => 3000]);
    TicketType::factory()->for($paid)->create(['price' => 1500]);

    $free = Event::factory()->published()->create();
    TicketType::factory()->for($free)->create(['price' => 0]);

    $this->get(route('events.index'))
        ->assertSee('1,500円〜')
        ->assertDontSee('3,000円〜')
        ->assertSee('無料');
});

test('events are paginated by 12', function () {
    Event::factory()->published()->count(12)->sequence(fn ($sequence) => [
        'title' => "イベント{$sequence->index}",
        'starts_at' => now()->addDays($sequence->index + 1),
        'ends_at' => now()->addDays($sequence->index + 1)->addHours(2),
    ])->create();
    Event::factory()->published()->create([
        'title' => '13番目のイベント',
        'starts_at' => now()->addDays(30),
        'ends_at' => now()->addDays(30)->addHours(2),
    ]);

    $this->get(route('events.index'))->assertDontSee('13番目のイベント');
    $this->get(route('events.index', ['page' => 2]))->assertSee('13番目のイベント');
});

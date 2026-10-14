<?php

use App\Enums\UserRole;
use App\Models\User;

test('guests can render the layout with login and register links', function () {
    $view = $this->blade('<x-layouts::app>本文</x-layouts::app>');

    $view->assertSee('イベント一覧')
        ->assertSee('ログイン')
        ->assertSee('会員登録')
        ->assertDontSee('マイチケット');
});

test('navigation changes by role', function (UserRole $role, array $visible, array $hidden) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get(route('profile.edit'));

    $response->assertOk()
        ->assertSee(['イベント一覧', 'マイチケット', '設定', ...$visible])
        ->assertDontSee('会員登録');

    foreach ($hidden as $text) {
        $response->assertDontSee($text);
    }
})->with([
    'customer' => [UserRole::Customer, [], ['主催者メニュー', 'イベント管理', 'ユーザー管理']],
    'organizer' => [UserRole::Organizer, ['主催者メニュー'], ['イベント管理', 'ユーザー管理']],
    'admin' => [UserRole::Admin, ['イベント管理', 'ユーザー管理'], ['主催者メニュー']],
]);

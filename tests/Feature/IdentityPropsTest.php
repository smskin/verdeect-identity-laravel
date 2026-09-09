<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Inertia\IdentityProps;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Разделяемые свойства, которые продукт отдаёт интерфейсу.
 *
 * Проверяется состав: имя пользователя и готовые данные рейла есть,
 * токенов нет ни под каким ключом.
 */
it('shares the profile and the rail for a signed in user', function (): void {
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');

    $props = app(IdentityProps::class)->share();

    expect($props['profile'])->toBeArray()
        ->and($props['profile']['sub'])->toBe('sub-1')
        ->and($props['crossService']['items'])->toHaveCount(2)
        ->and($props['crossService']['profileUrl'])->toBe(identityBaseUrl().'/profile')
        ->and($props['crossService']['logoUrl'])->toBe(identityBaseUrl().'/installation/logo');
});

/**
 * Рейл положен и гостю.
 *
 * Прежде страница без сессии получала пустую заглушку, и рейл не показывался
 * даже там, где установка его отдаёт: обязанность продукта выполнить было
 * нечем. Теперь гостевой набор приходит той же операцией навигации, а `profile`
 * остаётся `null` — профиля у гостя нет.
 */
it('shares the guest rail without a session', function (): void {
    identityFakeNavigation();

    $props = app(IdentityProps::class)->share();

    expect($props['profile'])->toBeNull()
        ->and($props['crossService']['items'])->toHaveCount(2)
        ->and($props['crossService']['logoUrl'])->toBe(identityBaseUrl().'/installation/logo');
});

/**
 * Пустой `profileUrl` у гостя — **значащее значение**, а не недосмотр:
 * страницы профиля у смотрящего нет, и показывать нечего.
 */
it('shares an empty profile url to the guest', function (): void {
    identityFakeNavigation();

    expect(app(IdentityProps::class)->share()['crossService']['profileUrl'])->toBe('');
});

/**
 * Недоступность установки страницу не роняет: свойства приходят пустыми,
 * **а не отсутствуют**. Интерфейс различает «рейла нет» и «свойство
 * не пришло», и второе означало бы ошибку доставки.
 */
it('shares empty values when the guest rail is unavailable', function (): void {
    identityFakeNavigation(null, Http::response(['error' => 'server_error'], 503));

    $props = app(IdentityProps::class)->share();

    expect($props['profile'])->toBeNull()
        ->and($props['crossService'])->toBe([
            'items' => [],
            'profileUrl' => '',
            'logoUrl' => '',
        ]);
});

/**
 * Язык гостя знает продукт, а не установка.
 *
 * У вошедшего язык рейла берётся из профиля; у гостя профиля нет, и без этого
 * поля компонент рейла откатился бы на жёсткое умолчание — на англоязычной
 * установке гостевой рейл вышел бы русским.
 */
it('shares the product locale to the guest', function (): void {
    identityFakeNavigation();
    app()->setLocale('en');

    expect(app(IdentityProps::class)->share()['locale'])->toBe('en');
});

it('shares the product locale to a signed in user', function (): void {
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');
    app()->setLocale('en');

    expect(app(IdentityProps::class)->share()['locale'])->toBe('en');
});

/**
 * Схема Backend-for-Frontend обязательна: токен доступа живёт на сервере
 * и в состав свойств не попадает ни под каким именем.
 */
it('never puts tokens into the shared props', function (): void {
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');

    $props = app(IdentityProps::class)->share();
    $accessToken = app(TokenStore::class)->get('sid-1')->accessToken;

    $encoded = (string) json_encode($props);

    expect($encoded)->not->toContain($accessToken)
        ->and($props)->not->toHaveKey('token')
        ->and($props)->not->toHaveKey('accessToken');
});

<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Inertia\IdentityProps;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Разделяемые свойства, которые продукт отдаёт интерфейсу.
 *
 * Проверяется состав: имя пользователя и готовые данные рейла есть,
 * токенов нет ни под каким ключом.
 */
it('shares the profile and the rail for a signed in user', function (): void {
    identityFakeServices();
    identityAuthenticate('sid-1', 'sub-1');

    $props = app(IdentityProps::class)->share();

    expect($props['profile'])->toBeArray()
        ->and($props['profile']['sub'])->toBe('sub-1')
        ->and($props['crossService']['items'])->toHaveCount(2)
        ->and($props['crossService']['profileUrl'])->toBe(identityBaseUrl().'/profile')
        ->and($props['crossService']['logoUrl'])->toBe(identityBaseUrl().'/installation/logo');
});

/**
 * Без сессии данные рейла **пусты, а не отсутствуют**: интерфейс различает
 * «рейла нет» и «свойство не пришло», и второе означало бы ошибку доставки.
 */
it('shares empty values without a session', function (): void {
    identityFakeHttp();

    $props = app(IdentityProps::class)->share();

    expect($props['profile'])->toBeNull()
        ->and($props['crossService'])->toBe([
            'items' => [],
            'profileUrl' => '',
            'logoUrl' => '',
        ]);
});

/**
 * Схема Backend-for-Frontend обязательна: токен доступа живёт на сервере
 * и в состав свойств не попадает ни под каким именем.
 */
it('never puts tokens into the shared props', function (): void {
    identityFakeServices();
    identityAuthenticate('sid-1', 'sub-1');

    $props = app(IdentityProps::class)->share();
    $accessToken = app(TokenStore::class)->get('sid-1')->accessToken;

    $encoded = (string) json_encode($props);

    expect($encoded)->not->toContain($accessToken)
        ->and($props)->not->toHaveKey('token')
        ->and($props)->not->toHaveKey('accessToken');
});

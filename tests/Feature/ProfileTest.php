<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Profile\ProfileProvider;

/**
 * ID-токен фиксирует момент входа и свежим не поддерживается (справка 5.2):
 * имя берётся из `/userinfo`. Это прямой запрет PRD 12.3.
 */
it('reads profile from userinfo, not id token', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::response([
            'sub' => 'sub-1',
            'name' => 'Михайлов Сергей Петрович',
            'given_name' => 'Сергей',
            'family_name' => 'Михайлов',
            'middle_name' => 'Петрович',
            'locale' => 'ru',
        ]),
    ]);

    identityAuthenticate();

    $profile = app(ProfileProvider::class)->current();

    expect($profile->name)->toBe('Михайлов Сергей Петрович')
        ->and($profile->name)->not->toContain('Устаревшее')
        ->and($profile->shortName)->toBe('Сергей Михайлов')
        ->and($profile->initials)->toBe('СМ');
});

it('serves repeated reads from cache', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::response([
            'sub' => 'sub-1',
            'name' => 'Михайлов Сергей Петрович',
            'given_name' => 'Сергей',
            'family_name' => 'Михайлов',
        ]),
    ]);

    identityAuthenticate();

    app(ProfileProvider::class)->current();
    app(ProfileProvider::class)->current();

    $userInfoCalls = 0;

    Http::assertSent(function ($request) use (&$userInfoCalls): bool {
        if (str_ends_with($request->url(), '/userinfo')) {
            $userInfoCalls++;
        }

        return true;
    });

    expect($userInfoCalls)->toBe(1);
});

/**
 * Недоступность установки не прекращает отрисовку страницы: вместо имени
 * показывается идентификатор (справка 9, пункт 8; PRD 12.11).
 */
it('degrades to sub when userinfo is down', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::response(['error' => 'server_error'], 500),
    ]);

    identityAuthenticate();

    $profile = app(ProfileProvider::class)->current();

    expect($profile->name)->toBe('sub-1')
        ->and($profile->shortName)->toBe('sub-1');
});

/**
 * Адрес приходит из `/userinfo` вместе с именем и показывается в блоке
 * пользователя единого входа: ради него в запрос входа добавлена область
 * `email`.
 */
it('reads the email from userinfo', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::response([
            'sub' => 'sub-1',
            'name' => 'Михайлов Сергей Петрович',
            'given_name' => 'Сергей',
            'family_name' => 'Михайлов',
            'email' => 'sergey@example.test',
        ]),
    ]);

    identityAuthenticate();

    expect(app(ProfileProvider::class)->current()->email)->toBe('sergey@example.test');
});

/**
 * Установка, которой область `email` не разрешена, утверждения не отдаёт
 * вовсе. Пустая строка здесь обязательна: `null` заставил бы каждого
 * потребителя проверять поле на существование, а отказ лишил бы страницу
 * имени из-за необязательного адреса.
 */
it('leaves the email empty when the installation does not return it', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::response([
            'sub' => 'sub-1',
            'name' => 'Михайлов Сергей Петрович',
            'given_name' => 'Сергей',
            'family_name' => 'Михайлов',
        ]),
    ]);

    identityAuthenticate();

    expect(app(ProfileProvider::class)->current()->email)->toBe('');
});

it('returns null without a session', function (): void {
    identityFakeHttp();

    expect(app(ProfileProvider::class)->current())->toBeNull();
});

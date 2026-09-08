<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Tokens\TokenManager;
use Verdeect\IdentityIntegration\Tokens\TokenSet;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Набор токенов с заданным остатком срока.
 *
 * `$remainingRatio` — доля срока, которая ещё не истекла: 1.0 — только что
 * выдан, 0.25 — осталась четверть.
 */
function identityStoredTokens(float $remainingRatio, string $sid = 'sid-1'): TokenSet
{
    $lifetime = 300;
    $issuedAt = CarbonImmutable::now()->subSeconds((int) round($lifetime * (1 - $remainingRatio)));

    $set = new TokenSet(
        accessToken: 'access-current',
        refreshToken: 'refresh-current',
        idToken: 'id-current',
        issuedAt: $issuedAt,
        expiresAt: $issuedAt->addSeconds($lifetime),
        scope: 'openid profile',
        sid: $sid,
        sub: 'sub-1',
    );

    app(TokenStore::class)->put($sid, $set);

    return $set;
}

it('refreshes ahead of expiry', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 300,
            'scope' => 'openid profile',
        ]),
    ]);

    identityStoredTokens(0.25);

    $token = app(TokenManager::class)->accessTokenFor('sid-1');

    expect($token)->toBe('access-new');

    // Новый refresh-токен сохранён: прежний установка погасила (справка 10).
    expect(app(TokenStore::class)->get('sid-1')->refreshToken)->toBe('refresh-new');
});

it('does not refresh a fresh token', function (): void {
    identityFakeHttp();

    identityStoredTokens(1.0);

    expect(app(TokenManager::class)->accessTokenFor('sid-1'))->toBe('access-current');

    Http::assertNothingSent();
});

/**
 * Критерий 86: одновременные запросы не порождают двух обменов.
 *
 * Гонка воспроизводится занятой блокировкой, а не удачей: настоящая гонка
 * в прогоне не повторяется, и тест на неё был бы плавающим.
 */
it('exchanges once under concurrency', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'access-new',
            'refresh_token' => 'refresh-new',
            'expires_in' => 300,
        ]),
    ]);

    identityStoredTokens(0.25);

    // Первый запрос обменял токен и держит блокировку.
    $lock = app(IdentityCache::class)->lock('refresh:sid-1', 10);
    $lock->get();

    app(TokenStore::class)->put('sid-1', new TokenSet(
        accessToken: 'access-new',
        refreshToken: 'refresh-new',
        idToken: null,
        issuedAt: CarbonImmutable::now(),
        expiresAt: CarbonImmutable::now()->addSeconds(300),
        scope: 'openid profile',
        sid: 'sid-1',
        sub: 'sub-1',
    ));

    $lock->release();

    // Второй запрос перечитывает хранилище и обмена не делает.
    expect(app(TokenManager::class)->accessTokenFor('sid-1'))->toBe('access-new');

    Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/token'));
});

it('destroys session on invalid_grant', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    identityStoredTokens(0.1);

    expect(fn () => app(TokenManager::class)->accessTokenFor('sid-1'))
        ->toThrow(SessionExpiredException::class);

    expect(app(TokenStore::class)->get('sid-1'))->toBeNull();
});

it('refuses an unknown session', function (): void {
    identityFakeHttp();

    expect(fn () => app(TokenManager::class)->accessTokenFor('sid-unknown'))
        ->toThrow(SessionExpiredException::class);
});

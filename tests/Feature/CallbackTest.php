<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Jose\Component\KeyManagement\JWKFactory;
use Verdeect\IdentityIntegration\Flow\AuthorizationFlow;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Возврат из потока кода: четыре проверки PRD 12.3 и хранение токенов
 * ключом по `sid`.
 */
function identityPendingFlow(array $overrides = []): array
{
    return [
        'state' => 'expected-state',
        'nonce' => 'test-nonce',
        'verifier' => 'expected-verifier',
        'intended_url' => null,
        ...$overrides,
    ];
}

function identityTokenResponse(array $overrides = []): array
{
    return [
        'access_token' => identityAccessToken(),
        'refresh_token' => 'refresh-1',
        'id_token' => identityIdToken(),
        'token_type' => 'Bearer',
        'expires_in' => 300,
        'scope' => 'openid profile',
        ...$overrides,
    ];
}

function identityFakeCallbackHttp(array $tokenResponse = []): void
{
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response(
            $tokenResponse === [] ? identityTokenResponse() : $tokenResponse,
        ),
    ]);
}

it('rejects mismatched state', function (): void {
    identityFakeCallbackHttp();

    $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=forged')
        ->assertStatus(400);

    expect(session()->has(AuthorizationFlow::SESSION_KEY))->toBeFalse();
});

it('rejects mismatched nonce', function (): void {
    identityFakeCallbackHttp(identityTokenResponse([
        'id_token' => identityIdToken(['nonce' => 'other-nonce']),
    ]));

    $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=expected-state')
        ->assertStatus(400);
});

it('rejects invalid signature', function (): void {
    identityFakeCallbackHttp(identityTokenResponse([
        'id_token' => identityIdToken([], JWKFactory::createRSAKey(2048, [
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key-1',
        ])),
    ]));

    $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=expected-state')
        ->assertStatus(400);
});

it('refuses a callback without a started flow', function (): void {
    identityFakeCallbackHttp();

    $this->get('/auth/callback?code=abc&state=expected-state')->assertStatus(400);
});

it('stores tokens keyed by sid', function (): void {
    identityFakeCallbackHttp();

    $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=expected-state')
        ->assertRedirect('/');

    $set = app(TokenStore::class)->get('01K6YTTESTSESSION000000001');

    expect($set)->not->toBeNull()
        ->and($set->refreshToken)->toBe('refresh-1')
        ->and($set->sub)->toBe('01K5XQTESTSUBJECT0000000001');
});

/**
 * Критерий 67: токен доступа в браузер не попадает ни в каком виде.
 */
it('never exposes tokens to the browser', function (): void {
    $tokens = identityTokenResponse();

    identityFakeCallbackHttp($tokens);

    $response = $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=expected-state');

    $serialized = $response->getContent().json_encode($response->headers->all());

    expect($serialized)->not->toContain($tokens['access_token'])
        ->and($serialized)->not->toContain($tokens['refresh_token'])
        ->and($serialized)->not->toContain($tokens['id_token']);

    $session = json_encode(session()->all());

    expect($session)->not->toContain($tokens['access_token'])
        ->and($session)->not->toContain($tokens['refresh_token']);
});

it('regenerates the session id', function (): void {
    identityFakeCallbackHttp();

    $this->withSession([AuthorizationFlow::SESSION_KEY => identityPendingFlow()])
        ->get('/auth/callback?code=abc&state=expected-state');

    // Признаки входа записаны после смены идентификатора сессии.
    expect(session('identity.sid'))->toBe('01K6YTTESTSESSION000000001')
        ->and(session('identity.sub'))->toBe('01K5XQTESTSUBJECT0000000001')
        ->and(session()->has(AuthorizationFlow::SESSION_KEY))->toBeFalse();
});

it('returns to the intended url', function (): void {
    identityFakeCallbackHttp();

    $this->withSession([
        AuthorizationFlow::SESSION_KEY => identityPendingFlow(['intended_url' => '/houses/7']),
    ])
        ->get('/auth/callback?code=abc&state=expected-state')
        ->assertRedirect('/houses/7');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Api\ServiceApiClient;
use Verdeect\IdentityIntegration\Api\ServiceTokenProvider;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;

function identityTokenRequests(): int
{
    $count = 0;

    Http::assertSent(function ($request) use (&$count): bool {
        if (str_ends_with($request->url(), '/token')) {
            $count++;
        }

        return true;
    });

    return $count;
}

it('caches the service token', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'token_type' => 'Bearer',
            'expires_in' => 300,
        ]),
    ]);

    $provider = app(ServiceTokenProvider::class);

    expect($provider->token())->toBe('service-token')
        ->and($provider->token())->toBe('service-token');

    expect(identityTokenRequests())->toBe(1);
});

/**
 * Ресурсом служебного токена выступает **сам identity** (справка 7):
 * прикладные операции — его собственный интерфейс, а не наш.
 */
it('requests identity as the resource', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'expires_in' => 300,
        ]),
    ]);

    app(ServiceTokenProvider::class)->token();

    Http::assertSent(function ($request): bool {
        if (! str_ends_with($request->url(), '/token')) {
            return true;
        }

        return $request['grant_type'] === 'client_credentials'
            && $request['scope'] === 'users:read navigation:read'
            && $request['resource'] === identityBaseUrl();
    });
});

/**
 * Упреждение — треть срока, а не фиксированное число секунд: администратор
 * установки задаёт срок от 1 до 60 минут (справка 11).
 */
it('refreshes ahead', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::sequence()
            ->push(['access_token' => 'token-1', 'expires_in' => 300])
            ->push(['access_token' => 'token-2', 'expires_in' => 300]),
    ]);

    $provider = app(ServiceTokenProvider::class);

    expect($provider->token())->toBe('token-1');

    // Прошло две трети срока: до истечения осталось меньше трети,
    // и обмен обязан произойти **до** отказа, а не после него.
    $this->travel(201)->seconds();

    expect($provider->token())->toBe('token-2')
        ->and(identityTokenRequests())->toBe(2);
});

/**
 * Один повтор на `invalid_token`: служебный токен мог быть отозван
 * до истечения срока.
 */
it('retries once on invalid_token', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::sequence()
            ->push(['access_token' => 'stale-token', 'expires_in' => 300])
            ->push(['access_token' => 'fresh-token', 'expires_in' => 300]),
        identityBaseUrl().'/api/users/resolve' => Http::sequence()
            ->push(['error' => 'invalid_token'], 401)
            ->push(['users' => []]),
    ]);

    $result = app(ServiceApiClient::class)->post(
        '/api/users/resolve',
        ['subs' => ['sub-1']],
        'users.resolve',
    );

    expect($result)->toBe(['users' => []])
        ->and(identityTokenRequests())->toBe(2);
});

it('fails after a second invalid_token', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/token' => Http::response([
            'access_token' => 'service-token',
            'expires_in' => 300,
        ]),
        identityBaseUrl().'/api/users/resolve' => Http::response(['error' => 'invalid_token'], 401),
    ]);

    expect(fn () => app(ServiceApiClient::class)->post(
        '/api/users/resolve',
        ['subs' => ['sub-1']],
        'users.resolve',
    ))->toThrow(ServiceApiException::class);
});

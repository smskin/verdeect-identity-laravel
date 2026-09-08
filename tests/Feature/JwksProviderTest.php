<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Discovery\IJwksProvider;
use Verdeect\IdentityIntegration\Exceptions\UnknownKeyException;

/**
 * Критерий 71: неизвестный `kid` вызывает перечитывание набора, а не отказ.
 *
 * Ротация ключей установки — штатное событие, и продукт, отказывающий
 * на первом же неизвестном идентификаторе, переставал бы пускать людей
 * при каждой ротации.
 */
it('refetches jwks once on unknown kid', function (): void {
    $jwksUrl = identityBaseUrl().'/.well-known/jwks.json';

    Http::fake([
        identityBaseUrl().'/.well-known/openid-configuration' => Http::response(identityDiscovery()),
        // Сначала набор без нужного ключа, затем — с ним.
        $jwksUrl => Http::sequence()
            ->push(identityJwks('other-key'))
            ->push(identityJwks('rotated-key')),
    ]);

    $key = app(IJwksProvider::class)->keyFor('rotated-key');

    expect($key->get('kid'))->toBe('rotated-key');

    Http::assertSentCount(3);
});

it('fails after second miss', function (): void {
    $jwksUrl = identityBaseUrl().'/.well-known/jwks.json';

    Http::fake([
        identityBaseUrl().'/.well-known/openid-configuration' => Http::response(identityDiscovery()),
        $jwksUrl => Http::response(identityJwks('other-key')),
    ]);

    expect(fn () => app(IJwksProvider::class)->keyFor('missing-key'))
        ->toThrow(UnknownKeyException::class);

    // Документ обнаружения читается один раз (дальше из кэша), набор — дважды.
    Http::assertSentCount(3);
});

it('serves the key set from cache on repeated reads', function (): void {
    identityFakeHttp();

    $provider = app(IJwksProvider::class);

    $provider->keySet();
    $provider->keySet();

    Http::assertSentCount(2);
});

<?php

declare(strict_types=1);

use Jose\Component\KeyManagement\JWKFactory;
use Verdeect\IdentityIntegration\Discovery\IdTokenVerifier;
use Verdeect\IdentityIntegration\Exceptions\IdTokenException;

beforeEach(function (): void {
    identityFakeHttp();
});

it('accepts a well formed token', function (): void {
    $claims = app(IdTokenVerifier::class)->verify(identityIdToken(), 'test-nonce');

    expect($claims['sub'])->toBe('01K5XQTESTSUBJECT0000000001');
});

/**
 * Причина отказа — код, а не текст: по ней различаются критерии 69 и 70
 * и ветвится журналирование.
 */
it('rejects a token and names the reason', function (string $token, string $nonce, string $reason): void {
    try {
        app(IdTokenVerifier::class)->verify($token, $nonce);

        expect(false)->toBeTrue('Проверка обязана была отказать');
    } catch (IdTokenException $exception) {
        expect($exception->reason)->toBe($reason);
    }
})->with([
    'чужая подпись' => fn () => [
        identityIdToken([], JWKFactory::createRSAKey(2048, [
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => 'test-key-1',
        ])),
        'test-nonce',
        IdTokenException::REASON_SIGNATURE,
    ],
    'чужой издатель' => fn () => [
        identityIdToken(['iss' => 'https://evil.example.test']),
        'test-nonce',
        IdTokenException::REASON_ISSUER,
    ],
    'чужой получатель' => fn () => [
        identityIdToken(['aud' => 'another-client']),
        'test-nonce',
        IdTokenException::REASON_AUDIENCE,
    ],
    'истёкший срок' => fn () => [
        identityIdToken(['exp' => time() - 3600]),
        'test-nonce',
        IdTokenException::REASON_EXPIRED,
    ],
    'чужой nonce' => fn () => [
        identityIdToken(['nonce' => 'other-nonce']),
        'test-nonce',
        IdTokenException::REASON_NONCE,
    ],
    'нечитаемый токен' => fn () => [
        'not-a-jwt',
        'test-nonce',
        IdTokenException::REASON_MALFORMED,
    ],
]);

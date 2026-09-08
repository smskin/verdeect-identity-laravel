<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Flow;

/**
 * Пара PKCE.
 *
 * PKCE обязателен для всех пользовательских клиентов установки, включая
 * конфиденциальные (справка 4.1) — это строже RFC 9700. Способ всегда `S256`:
 * `plain` установка отвергает даже у клиента с послаблением.
 */
final readonly class PkcePair
{
    private function __construct(
        public string $verifier,
        public string $challenge,
    ) {}

    public static function generate(): self
    {
        $verifier = self::base64Url(random_bytes(32));

        return new self(
            verifier: $verifier,
            challenge: self::base64Url(hash('sha256', $verifier, true)),
        );
    }

    public static function fromVerifier(string $verifier): self
    {
        return new self(
            verifier: $verifier,
            challenge: self::base64Url(hash('sha256', $verifier, true)),
        );
    }

    /**
     * base64url без выравнивания — как требует RFC 7636.
     */
    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

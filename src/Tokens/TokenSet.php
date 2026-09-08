<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tokens;

use Carbon\CarbonImmutable;

/**
 * Набор токенов одной сессии входа.
 *
 * `issuedAt` хранится рядом с `expiresAt`, потому что упреждающий обмен
 * считает долю **срока жизни**, а не остаток от фиксированного числа:
 * администратор установки вправе задать срок от 1 до 60 минут (справка 11),
 * и предполагать конкретные числа нельзя.
 */
final readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public string|null $refreshToken,
        public string|null $idToken,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $expiresAt,
        public string $scope,
        public string $sid,
        public string $sub,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'id_token' => $this->idToken,
            'issued_at' => $this->issuedAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'scope' => $this->scope,
            'sid' => $this->sid,
            'sub' => $this->sub,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            accessToken: (string) ($payload['access_token'] ?? ''),
            refreshToken: isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            idToken: isset($payload['id_token']) ? (string) $payload['id_token'] : null,
            issuedAt: CarbonImmutable::parse((string) ($payload['issued_at'] ?? 'now')),
            expiresAt: CarbonImmutable::parse((string) ($payload['expires_at'] ?? 'now')),
            scope: (string) ($payload['scope'] ?? ''),
            sid: (string) ($payload['sid'] ?? ''),
            sub: (string) ($payload['sub'] ?? ''),
        );
    }

    /**
     * Полный срок жизни токена доступа в секундах.
     */
    public function lifetimeSeconds(): int
    {
        return max(1, (int) round($this->issuedAt->diffInSeconds($this->expiresAt, absolute: true)));
    }

    public function withTokens(
        string $accessToken,
        string|null $refreshToken,
        string|null $idToken,
        CarbonImmutable $issuedAt,
        CarbonImmutable $expiresAt,
        string $scope,
    ): self {
        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken ?? $this->refreshToken,
            idToken: $idToken ?? $this->idToken,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            scope: $scope,
            sid: $this->sid,
            sub: $this->sub,
        );
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tokens;

/**
 * Разбор токена доступа **без проверки**.
 *
 * Токен выпущен для identity как ресурса: его получатель — не мы, проверять
 * подпись и `aud` мы не обязаны и не можем (PRD 12.2). Разбор нужен ровно
 * для двух утверждений — `sub` и `sid`, — по которым адресуются сообщения
 * о завершении сессии.
 *
 * `roles` и `entitlements` читаются здесь же и **в сессию не копируются**:
 * сессия живёт дольше токена, и понижение роли не применилось бы до нового
 * входа (справка 13).
 */
final readonly class AccessTokenClaims
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $entitlements
     */
    public function __construct(
        public string $sub,
        public string $sid,
        public string $scope,
        public array $roles,
        public array $entitlements,
    ) {}

    public static function parse(string $accessToken): self
    {
        $claims = self::payload($accessToken);

        return new self(
            sub: is_string($claims['sub'] ?? null) ? $claims['sub'] : '',
            sid: is_string($claims['sid'] ?? null) ? $claims['sid'] : '',
            scope: is_string($claims['scope'] ?? null) ? $claims['scope'] : '',
            roles: self::strings($claims['roles'] ?? null),
            entitlements: self::strings($claims['entitlements'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return [];
        }

        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($decoded === false) {
            return [];
        }

        $claims = json_decode($decoded, true);

        /** @var array<string, mixed> */
        return is_array($claims) ? $claims : [];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}

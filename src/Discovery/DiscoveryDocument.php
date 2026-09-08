<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Verdeect\IdentityIntegration\Exceptions\DiscoveryException;

/**
 * Документ обнаружения установки.
 *
 * Адреса эндпоинтов приходят отсюда и в код не зашиваются (PRD 12.2,
 * критерий 72): установки различаются, и строка `/authorize` в коде
 * означала бы, что вторая установка потребует правки продукта.
 */
final readonly class DiscoveryDocument
{
    /**
     * @param  list<string>  $codeChallengeMethodsSupported
     * @param  list<string>  $idTokenSigningAlgValuesSupported
     */
    public function __construct(
        public string $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $userinfoEndpoint,
        public string $jwksUri,
        public string $endSessionEndpoint,
        public array $codeChallengeMethodsSupported,
        public array $idTokenSigningAlgValuesSupported,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            issuer: self::string($payload, 'issuer'),
            authorizationEndpoint: self::string($payload, 'authorization_endpoint'),
            tokenEndpoint: self::string($payload, 'token_endpoint'),
            userinfoEndpoint: self::string($payload, 'userinfo_endpoint'),
            jwksUri: self::string($payload, 'jwks_uri'),
            endSessionEndpoint: self::string($payload, 'end_session_endpoint'),
            codeChallengeMethodsSupported: self::strings($payload, 'code_challenge_methods_supported'),
            idTokenSigningAlgValuesSupported: self::strings($payload, 'id_token_signing_alg_values_supported'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->authorizationEndpoint,
            'token_endpoint' => $this->tokenEndpoint,
            'userinfo_endpoint' => $this->userinfoEndpoint,
            'jwks_uri' => $this->jwksUri,
            'end_session_endpoint' => $this->endSessionEndpoint,
            'code_challenge_methods_supported' => $this->codeChallengeMethodsSupported,
            'id_token_signing_alg_values_supported' => $this->idTokenSigningAlgValuesSupported,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function string(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw DiscoveryException::incomplete($field);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function strings(array $payload, string $field): array
    {
        $value = $payload[$field] ?? null;

        if (! is_array($value)) {
            throw DiscoveryException::incomplete($field);
        }

        return array_values(array_filter($value, is_string(...)));
    }
}

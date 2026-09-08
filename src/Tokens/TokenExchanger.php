<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tokens;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Обмен на эндпоинте токенов установки.
 *
 * Аутентификация клиента — `client_secret_basic`: она объявлена документом
 * обнаружения и не передаёт секрет телом запроса, где он попал бы в журналы
 * посредников.
 */
final class TokenExchanger
{
    public function __construct(
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityConfig $config,
        private readonly IdentityHttp $http,
    ) {}

    /**
     * Обмен кода авторизации.
     */
    public function exchangeCode(string $code, string $verifier): TokenSet
    {
        $response = $this->post([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config->redirectUri(),
            'code_verifier' => $verifier,
        ]);

        return $this->toTokenSet($response);
    }

    /**
     * Обмен refresh-токена.
     *
     * Каждый обмен выдаёт новый refresh-токен, прежний гасится (справка 10).
     * Отказ `invalid_grant` означает, что сессия входа прекращена: продлевать
     * нечего.
     */
    public function refresh(TokenSet $current): TokenSet
    {
        if ($current->refreshToken === null) {
            throw SessionExpiredException::forSid($current->sid, 'refresh-токена нет');
        }

        $response = $this->post([
            'grant_type' => 'refresh_token',
            'refresh_token' => $current->refreshToken,
        ], $current->sid);

        return $this->toTokenSet($response, $current);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function post(array $payload, string|null $sid = null): array
    {
        $endpoint = $this->discovery->fetch()->tokenEndpoint;

        $response = $this->http->request()
            ->asForm()
            ->withBasicAuth(
                $this->config->webClientId(),
                $this->config->webClientSecret(),
            )
            ->post($endpoint, $payload);

        if ($response->status() === 401) {
            Log::error('[TokenExchanger.post] client auth failed');

            throw SessionExpiredException::forSid(
                $sid,
                'установка отклонила учётные данные клиента — проверьте секрет клиента',
            );
        }

        if (! $response->successful()) {
            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'unknown';

            Log::warning('[TokenExchanger.post] exchange rejected', [
                'grant_type' => $payload['grant_type'],
                'error' => $error,
                'status' => $response->status(),
            ]);

            throw SessionExpiredException::forSid($sid, $error);
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toTokenSet(array $payload, TokenSet|null $current = null): TokenSet
    {
        $accessToken = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '';

        /*
         * `expires_in` читается как число, а не как целое: установка отдаёт
         * временные значения с дробной частью, и проверка на целое молча
         * подставила бы умолчание вместо настоящего срока.
         */
        $expiresIn = is_numeric($payload['expires_in'] ?? null)
            ? (int) $payload['expires_in']
            : 300;

        $issuedAt = CarbonImmutable::now();
        $expiresAt = $issuedAt->addSeconds($expiresIn);

        $refreshToken = is_string($payload['refresh_token'] ?? null) ? $payload['refresh_token'] : null;
        $idToken = is_string($payload['id_token'] ?? null) ? $payload['id_token'] : null;
        $scope = is_string($payload['scope'] ?? null) ? $payload['scope'] : '';

        if ($current !== null) {
            return $current->withTokens(
                accessToken: $accessToken,
                refreshToken: $refreshToken,
                idToken: $idToken,
                issuedAt: $issuedAt,
                expiresAt: $expiresAt,
                scope: $scope,
            );
        }

        $claims = AccessTokenClaims::parse($accessToken);

        return new TokenSet(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            idToken: $idToken,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            scope: $scope,
            sid: $claims->sid,
            sub: $claims->sub,
        );
    }
}

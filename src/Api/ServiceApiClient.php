<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Клиент прикладного интерфейса identity.
 *
 * Пути `/api/users/resolve` и `/api/users/services` в документ обнаружения
 * не входят и адресуются от `base_url` — это единственное исключение
 * из правила «адреса читаются из документа обнаружения» (PRD 12.2).
 *
 * Пользовательский токен сюда **не передаётся никогда**: он даст
 * `403 insufficient_scope` (критерий 83).
 */
final class ServiceApiClient
{
    public function __construct(
        private readonly ServiceTokenProvider $tokens,
        private readonly IdentityConfig $config,
        private readonly IdentityHttp $http,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, string $operation): array
    {
        $response = $this->send($path, $payload, $this->tokens->token());

        /*
         * Один повтор на `invalid_token`: служебный токен мог быть отозван
         * до истечения срока, и заново выпущенный решает дело. Второй отказ
         * означает настоящую проблему — учётные данные либо области.
         */
        if ($response->status() === 401 && $this->errorCode($response) === 'invalid_token') {
            Log::debug('[ServiceApiClient.post] invalid_token, refreshing service token', [
                'operation' => $operation,
            ]);

            $response = $this->send($path, $payload, $this->tokens->forceRefresh());
        }

        if (! $response->successful()) {
            $error = $this->errorCode($response);

            /*
             * Тело ответа целиком не журналируется: в нём имена сотрудников.
             */
            Log::error('[ServiceApiClient.post] request failed', [
                'operation' => $operation,
                'error' => $error,
                'status' => $response->status(),
            ]);

            throw ServiceApiException::of($error, $response->status(), $operation);
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $path, array $payload, string $token): Response
    {
        return $this->http->request()
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($this->config->baseUrl().$path, $payload);
    }

    /**
     * Ошибки различаются по коду `error`, а не по тексту (справка 7.4).
     */
    private function errorCode(Response $response): string
    {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return is_string($body['error'] ?? null) ? $body['error'] : 'unknown';
    }
}

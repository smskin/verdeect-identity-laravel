<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Клиент прикладного интерфейса identity.
 *
 * Пути `/api/users/resolve` и `/api/navigation` в документ обнаружения
 * не входят и адресуются от `base_url` — это единственное исключение
 * из правила «адреса читаются из документа обнаружения» (PRD 12.2).
 *
 * **Обращение с телом и без него различаются только отправкой.** Повтор
 * на `401 invalid_token`, чтение кода `error` и журналирование отказа —
 * общие: правило, живущее в двух местах, расходится на первой же правке,
 * а расхождение здесь означало бы, что одна из операций молча теряет
 * повтор с обновлением служебного токена.
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
        return $this->call(
            method: 'post',
            path: $path,
            operation: $operation,
            dispatch: static fn (PendingRequest $request, string $url): Response => $request
                ->asJson()
                ->post($url, $payload),
        );
    }

    /**
     * Обращение без тела.
     *
     * `asJson()` здесь не вызывается: заголовок содержимого без содержимого
     * описывал бы то, чего в запросе нет.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, string $operation): array
    {
        return $this->call(
            method: 'get',
            path: $path,
            operation: $operation,
            dispatch: static fn (PendingRequest $request, string $url): Response => $request->get($url),
        );
    }

    /**
     * Общий ход обращения: отправка, один повтор, разбор отказа.
     *
     * `$method` участвует только в приставке журнальных записей — она обязана
     * называть вызванный метод, а не общий закрытый путь, иначе по журналу
     * не восстановить, какая операция ходила к установке.
     *
     * @param  Closure(PendingRequest, string): Response  $dispatch
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, string $operation, Closure $dispatch): array
    {
        $url = $this->config->baseUrl().$path;

        $response = $dispatch($this->request($this->tokens->token()), $url);

        /*
         * Один повтор на `invalid_token`: служебный токен мог быть отозван
         * до истечения срока, и заново выпущенный решает дело. Второй отказ
         * означает настоящую проблему — учётные данные либо области.
         */
        if ($response->status() === 401 && $this->errorCode($response) === 'invalid_token') {
            Log::debug('[ServiceApiClient.'.$method.'] invalid_token, refreshing service token', [
                'operation' => $operation,
            ]);

            $response = $dispatch($this->request($this->tokens->forceRefresh()), $url);
        }

        if (! $response->successful()) {
            $error = $this->errorCode($response);

            /*
             * Тело ответа целиком не журналируется: в нём имена сотрудников.
             */
            Log::error('[ServiceApiClient.'.$method.'] request failed', [
                'operation' => $operation,
                'error' => $error,
                'status' => $response->status(),
            ]);

            throw ServiceApiException::of($error, $response->status(), $operation);
        }

        /** @var array<string, mixed> */
        return $response->json() ?? [];
    }

    private function request(string $token): PendingRequest
    {
        return $this->http->request()
            ->withToken($token)
            ->acceptJson();
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

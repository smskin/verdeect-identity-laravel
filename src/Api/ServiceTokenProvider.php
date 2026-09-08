<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Служебный токен прикладного интерфейса.
 *
 * Ресурсом выступает **сам identity** (`resource = base_url`, справка 7):
 * прикладные операции — его собственный интерфейс, а не наш.
 *
 * Кэш обязателен: без него каждый вызов тянул бы обращение к эндпоинту
 * токенов, который на неверные учётные данные отвечает с задержкой около
 * двух секунд и по частоте не ограничен (справка 4.2).
 */
final class ServiceTokenProvider
{
    private const CACHE_KEY = 'service_token';

    private const LOCK_KEY = 'service_token:refresh';

    public function __construct(
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
        private readonly IdentityHttp $http,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && ! $this->needsRefresh($cached)) {
            /** @var array{token: string, issued_at: string, expires_at: string} $cached */
            return $cached['token'];
        }

        return $this->issue();
    }

    /**
     * Принудительное обновление.
     *
     * Вызывается один раз при `401 invalid_token`: токен мог быть отозван
     * до истечения срока.
     */
    public function forceRefresh(): string
    {
        $this->cache->forget(self::CACHE_KEY);

        return $this->issue();
    }

    private function issue(): string
    {
        $lock = $this->cache->lock(self::LOCK_KEY, 10);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            Log::warning('[ServiceTokenProvider.issue] lock timeout');

            $cached = $this->cache->get(self::CACHE_KEY);

            if (is_array($cached) && is_string($cached['token'] ?? null)) {
                return $cached['token'];
            }

            return $this->request();
        }

        try {
            // Пока ждали блокировку, соседний запрос мог выпустить токен.
            $cached = $this->cache->get(self::CACHE_KEY);

            if (is_array($cached) && ! $this->needsRefresh($cached)) {
                /** @var array{token: string, issued_at: string, expires_at: string} $cached */
                return $cached['token'];
            }

            return $this->request();
        } finally {
            $lock->release();
        }
    }

    private function request(): string
    {
        $endpoint = $this->discovery->fetch()->tokenEndpoint;

        $response = $this->http->request()
            ->asForm()
            ->withBasicAuth(
                $this->config->serviceClientId(),
                $this->config->serviceClientSecret(),
            )
            ->post($endpoint, [
                'grant_type' => 'client_credentials',
                'scope' => implode(' ', $this->config->serviceScopes()),
                'resource' => $this->config->baseUrl(),
            ]);

        if (! $response->successful()) {
            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'unknown';

            Log::error('[ServiceTokenProvider.request] service token rejected', [
                'error' => $error,
                'status' => $response->status(),
            ]);

            throw ServiceApiException::of($error, $response->status(), 'client_credentials');
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json() ?? [];

        $token = is_string($payload['access_token'] ?? null) ? $payload['access_token'] : '';

        // Срок читается как число: установка отдаёт его с дробной частью.
        $expiresIn = is_numeric($payload['expires_in'] ?? null)
            ? (int) $payload['expires_in']
            : 300;

        $issuedAt = CarbonImmutable::now();

        $this->cache->put(self::CACHE_KEY, [
            'token' => $token,
            'issued_at' => $issuedAt->toIso8601String(),
            'expires_at' => $issuedAt->addSeconds($expiresIn)->toIso8601String(),
        ], $expiresIn);

        Log::debug('[ServiceTokenProvider.request] issued', ['expires_in' => $expiresIn]);

        return $token;
    }

    /**
     * Упреждение — та же треть срока, что и у пользовательского токена.
     *
     * Число секунд не зашивается: `expires_in` читается из ответа,
     * администратор установки вправе задать от 1 до 60 минут (справка 11).
     *
     * @param  array<string, mixed>  $cached
     */
    private function needsRefresh(array $cached): bool
    {
        if (! is_string($cached['token'] ?? null) || $cached['token'] === '') {
            return true;
        }

        $issuedAt = CarbonImmutable::parse((string) ($cached['issued_at'] ?? 'now'));
        $expiresAt = CarbonImmutable::parse((string) ($cached['expires_at'] ?? 'now'));

        $lifetime = max(1, $issuedAt->diffInSeconds($expiresAt, absolute: true));
        $threshold = (int) round($lifetime * $this->config->refreshAheadRatio());

        return CarbonImmutable::now()
            ->greaterThanOrEqualTo($expiresAt->subSeconds($threshold));
    }
}

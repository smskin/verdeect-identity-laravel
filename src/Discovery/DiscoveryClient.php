<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Exceptions\DiscoveryException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Документ обнаружения установки: чтение и кэш.
 *
 * Кэш хранит разобранный массив, а не объект: сериализация readonly-объекта
 * в Redis привязала бы содержимое кэша к сигнатуре класса, и правка полей
 * ломала бы чтение прежних записей.
 *
 * Записей две. Первая живёт `discovery_ttl` и отвечает на обычное чтение,
 * вторая — сутки и служит запасом на случай недоступности установки:
 * адреса эндпоинтов меняются раз в жизни установки, а отказ отрисовывать
 * страницы из-за недоступного identity запрещён (PRD 12.11, справка 9.8).
 */
final class DiscoveryClient implements IDiscoveryClient
{
    private const CACHE_KEY = 'discovery';

    private const STALE_CACHE_KEY = 'discovery:stale';

    private const STALE_TTL = 86400;

    public function __construct(
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
        private readonly IdentityHttp $http,
    ) {}

    public function fetch(): DiscoveryDocument
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return DiscoveryDocument::fromArray($cached);
        }

        return $this->refresh();
    }

    public function refresh(): DiscoveryDocument
    {
        $baseUrl = $this->config->baseUrl();

        try {
            $response = $this->http->request()
                ->acceptJson()
                ->get($baseUrl.'/.well-known/openid-configuration');

            if (! $response->successful()) {
                return $this->stale($baseUrl, 'HTTP '.$response->status());
            }

            /** @var array<string, mixed> $payload */
            $payload = $response->json() ?? [];

            $document = DiscoveryDocument::fromArray($payload);
        } catch (DiscoveryException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->stale($baseUrl, $exception->getMessage());
        }

        $this->cache->put(
            self::CACHE_KEY,
            $document->toArray(),
            (int) config('identity.discovery_ttl', 3600),
        );

        $this->cache->put(self::STALE_CACHE_KEY, $document->toArray(), self::STALE_TTL);

        Log::debug('[DiscoveryClient.refresh] discovery fetched', [
            'issuer' => $document->issuer,
        ]);

        return $document;
    }

    /**
     * Запасная запись кэша.
     *
     * Пустой запас означает, что установка недоступна и не была доступна
     * сутки: вход невозможен, но исключение ловит вызывающий, и остальные
     * экраны продолжают работать.
     */
    private function stale(string $baseUrl, string $reason): DiscoveryDocument
    {
        $stale = $this->cache->get(self::STALE_CACHE_KEY);

        if (is_array($stale)) {
            Log::warning('[DiscoveryClient.refresh] using stale cache', [
                'base_url' => $baseUrl,
                'reason' => $reason,
            ]);

            /** @var array<string, mixed> $stale */
            return DiscoveryDocument::fromArray($stale);
        }

        Log::error('[DiscoveryClient.refresh] discovery unreachable', [
            'base_url' => $baseUrl,
            'reason' => $reason,
        ]);

        throw DiscoveryException::unreachable($baseUrl, $reason);
    }
}

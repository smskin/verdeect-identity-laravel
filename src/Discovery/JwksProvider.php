<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Discovery;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Throwable;
use Verdeect\IdentityIntegration\Exceptions\UnknownKeyException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityHttp;

/**
 * Набор открытых ключей установки.
 *
 * Адрес набора берётся из документа обнаружения, а не собирается из
 * `base_url`: `jwks_uri` установки может указывать куда угодно.
 */
final class JwksProvider implements IJwksProvider
{
    private const CACHE_KEY = 'jwks';

    private const REFRESH_LOCK = 'jwks:refresh';

    public function __construct(
        private readonly IdentityCache $cache,
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityHttp $http,
    ) {}

    public function keySet(): JWKSet
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array{keys: list<array<string, mixed>>} $cached */
            return JWKSet::createFromKeyData($cached);
        }

        return $this->refresh();
    }

    public function keyFor(string $kid): JWK
    {
        $set = $this->keySet();

        if ($set->has($kid)) {
            return $set->get($kid);
        }

        /*
         * Ротация ключей установки — штатное событие: неизвестный `kid`
         * означает, что набор устарел, а не что токен подделан (критерий 71).
         * Перечитывание выполняется ровно один раз — иначе поддельный токен
         * с выдуманным `kid` заставлял бы обращаться к установке на каждом
         * запросе.
         */
        Log::debug('[JwksProvider.keyFor] unknown kid, refreshing', ['kid' => $kid]);

        $set = $this->refresh();

        if ($set->has($kid)) {
            return $set->get($kid);
        }

        throw UnknownKeyException::forKid($kid);
    }

    public function refresh(): JWKSet
    {
        /*
         * Блокировка защищает установку от лавины: неизвестный `kid`
         * приходит сразу во всех параллельных запросах, и без неё каждый
         * пошёл бы за набором сам.
         */
        $lock = $this->cache->lock(self::REFRESH_LOCK, 10);

        if ($lock->get()) {
            try {
                return $this->fetch();
            } finally {
                $lock->release();
            }
        }

        // Блокировка занята: дождаться владельца и прочитать записанное им.
        try {
            $lock->block(5);
            $lock->release();
        } catch (LockTimeoutException) {
            Log::warning('[JwksProvider.refresh] lock timeout');
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            /** @var array{keys: list<array<string, mixed>>} $cached */
            return JWKSet::createFromKeyData($cached);
        }

        return $this->fetch();
    }

    private function fetch(): JWKSet
    {
        $uri = $this->discovery->fetch()->jwksUri;

        try {
            $response = $this->http->request()
                ->acceptJson()
                ->get($uri);
        } catch (Throwable $exception) {
            Log::error('[JwksProvider.fetch] key set unreachable', [
                'uri' => $uri,
                'reason' => $exception->getMessage(),
            ]);

            return JWKSet::createFromKeyData(['keys' => []]);
        }

        if (! $response->successful()) {
            Log::error('[JwksProvider.fetch] key set unreachable', [
                'uri' => $uri,
                'reason' => 'HTTP '.$response->status(),
            ]);

            return JWKSet::createFromKeyData(['keys' => []]);
        }

        /** @var array{keys?: list<array<string, mixed>>} $payload */
        $payload = $response->json() ?? [];
        $keys = ['keys' => $payload['keys'] ?? []];

        $this->cache->put(self::CACHE_KEY, $keys, (int) config('identity.jwks_ttl', 3600));

        Log::debug('[JwksProvider.fetch] key set fetched', [
            'keys' => count($keys['keys']),
        ]);

        return JWKSet::createFromKeyData($keys);
    }
}

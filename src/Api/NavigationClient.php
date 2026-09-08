<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityCache;

/**
 * Данные рейла кросс-сервисной навигации.
 *
 * Кэш живёт **на бэкенде**, а не в браузере (критерий 89), и сбрасывается
 * двумя сообщениями — `navigation.changed` и `user.rights.changed`, — а не
 * по сроку: смена роли обязана доходить до рейла без нового входа
 * (справка 9.1).
 */
final class NavigationClient
{
    private const PATH = '/api/users/services';

    public function __construct(
        private readonly ServiceApiClient $api,
        private readonly IdentityCache $cache,
        private readonly SessionMarks $marks,
    ) {}

    public function for(string $sub): NavigationData
    {
        $cached = $this->cache->get($this->key($sub));

        if (is_array($cached) && ! $this->isStale($cached)) {
            /** @var array<string, mixed> $cached */
            return NavigationData::fromArray($cached);
        }

        try {
            $payload = $this->api->post(self::PATH, ['sub' => $sub], 'users.services');
        } catch (ServiceApiException $exception) {
            /*
             * `404 user_not_found` здесь — **отказ**, в отличие от разрешения
             * имён: адресат один, и его отсутствие означает, что спросили
             * не то (справка 7.3). Асимметрия реальна, и обе операции нельзя
             * обрабатывать одним кодом.
             */
            Log::warning('[NavigationClient.for] rail unavailable', [
                'sub' => $sub,
                'error' => $exception->error,
            ]);

            return $this->staleOrEmpty($sub, $cached);
        } catch (Throwable $exception) {
            Log::warning('[NavigationClient.for] rail unreachable', [
                'sub' => $sub,
                'reason' => $exception->getMessage(),
            ]);

            return $this->staleOrEmpty($sub, $cached);
        }

        $data = NavigationData::fromResponse($payload);

        $this->cache->put($this->key($sub), [
            ...$data->toArray(),
            'cached_at' => CarbonImmutable::now()->toIso8601String(),
        ], (int) config('identity.cache.services_ttl', 3600));

        Log::debug('[NavigationClient.for] rail resolved', [
            'sub' => $sub,
            'items' => count($data->items),
        ]);

        return $data;
    }

    public function forget(string $sub): void
    {
        $this->cache->forget($this->key($sub));
    }

    /**
     * Запись кэша старше отметки `navigation.changed` считается устаревшей.
     *
     * Отметка — время, а не снимаемый признак: снимаемый забрало бы первое
     * же устройство пользователя (справка 8.1, критерий 75).
     *
     * @param  array<string, mixed>  $cached
     */
    private function isStale(array $cached): bool
    {
        $mark = $this->marks->productMark();

        if ($mark === null) {
            return false;
        }

        $cachedAt = $cached['cached_at'] ?? null;

        if (! is_string($cachedAt)) {
            return true;
        }

        return CarbonImmutable::parse($cachedAt)->lessThan($mark);
    }

    private function staleOrEmpty(string $sub, mixed $cached): NavigationData
    {
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return NavigationData::fromArray($cached);
        }

        // Пустой `items` — допустимый ответ: рейл не рисуется вовсе,
        // включая логотип (критерий 91).
        return NavigationData::empty($sub);
    }

    private function key(string $sub): string
    {
        return 'services:'.$sub;
    }
}

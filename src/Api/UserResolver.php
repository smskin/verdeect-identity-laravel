<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Illuminate\Support\Facades\Log;
use Throwable;
use Verdeect\IdentityIntegration\Exceptions\ServiceApiException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Разрешение идентификаторов сотрудников в имена.
 *
 * Журнал изменений (фаза 4) и отметки листовки (фаза 6) хранят `sub`,
 * а не снимок имени: имя изменяемо, идентификатор — нет.
 *
 * Разбиение на части выполняется **на нашей стороне**: превышение предела
 * установка отклоняет, а не усекает (справка 7.1), и длинный список без
 * разбиения потерял бы всех разом.
 */
final class UserResolver implements IUserResolver
{
    private const PATH = '/api/users/resolve';

    public function __construct(
        private readonly ServiceApiClient $api,
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
    ) {}

    /**
     * @param  list<string>  $subs
     * @return array<string, ResolvedUser>
     */
    public function resolve(array $subs): array
    {
        $unique = array_values(array_unique(array_filter($subs, static fn (string $sub): bool => $sub !== '')));

        if ($unique === []) {
            return [];
        }

        $resolved = [];
        $missing = [];

        foreach ($unique as $sub) {
            $cached = $this->cache->get($this->key($sub));

            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                $resolved[$sub] = ResolvedUser::fromArray($cached);

                continue;
            }

            $missing[] = $sub;
        }

        $batches = array_chunk($missing, $this->config->resolveBatchLimit());

        Log::debug('[UserResolver.resolve] batches', [
            'requested' => count($unique),
            'cached' => count($resolved),
            'batches' => count($batches),
        ]);

        foreach ($batches as $batch) {
            foreach ($this->fetchBatch($batch) as $sub => $user) {
                $resolved[$sub] = $user;
            }
        }

        // Порядок запроса сохраняется: вызывающий выводит список в своём.
        $ordered = [];

        foreach ($unique as $sub) {
            $ordered[$sub] = $resolved[$sub] ?? ResolvedUser::unresolved($sub);
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, ResolvedUser>
     */
    private function fetchBatch(array $batch): array
    {
        try {
            $payload = $this->api->post(self::PATH, ['subs' => $batch], 'users.resolve');
        } catch (ServiceApiException $exception) {
            if ($exception->error === 'batch_too_large') {
                /*
                 * Разбиение — наша обязанность, и его отказ означает ошибку
                 * реализации, а не состояние установки.
                 */
                Log::error('[UserResolver.fetchBatch] batch too large', [
                    'size' => count($batch),
                    'limit' => $this->config->resolveBatchLimit(),
                ]);

                throw $exception;
            }

            return $this->degrade($batch, $exception->error);
        } catch (Throwable $exception) {
            return $this->degrade($batch, $exception->getMessage());
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['users'] ?? null) ? $payload['users'] : [];

        $users = [];

        foreach ($rows as $row) {
            $user = ResolvedUser::fromResponse($row);

            if ($user->sub === '') {
                continue;
            }

            $users[$user->sub] = $user;

            $this->cache->put(
                $this->key($user->sub),
                $user->toArray(),
                (int) config('identity.cache.resolve_ttl', 3600),
            );
        }

        /*
         * Идентификаторы, отсутствующие в ответе, отказом не являются:
         * установка отвечает `200` с пустым списком (проверено 04.09.2026).
         * Отличать пустой ответ от ошибки обязательно — иначе неизвестный
         * сотрудник выглядел бы недоступностью установки.
         */
        return $users;
    }

    /**
     * @param  list<string>  $batch
     * @return array<string, ResolvedUser>
     */
    private function degrade(array $batch, string $reason): array
    {
        /*
         * Недоступность установки не прекращает отрисовку страницы: список
         * выводится с идентификаторами вместо имён (справка 9, пункт 8).
         * Имена в журнал не пишутся — это персональные данные.
         */
        Log::warning('[UserResolver.fetchBatch] resolve unavailable', [
            'size' => count($batch),
            'reason' => $reason,
        ]);

        $users = [];

        foreach ($batch as $sub) {
            $users[$sub] = ResolvedUser::unresolved($sub);
        }

        return $users;
    }

    private function key(string $sub): string
    {
        return 'user:'.$sub;
    }
}

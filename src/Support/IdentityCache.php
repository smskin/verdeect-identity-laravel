<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Единственная точка обращения пакета к хранилищу.
 *
 * Хранилище берётся из настройки, а не из драйвера сессий: к хранилищу
 * сессий пакет не обращается вовсе (PRD 12.1) — сессия уничтожается
 * штатным механизмом Laravel.
 *
 * Общий префикс держит все ключи пакета в одном пространстве: содержимое
 * видно одной командой `KEYS identity:*`, а очистка не задевает чужого.
 */
final class IdentityCache
{
    private const PREFIX = 'identity:';

    public function store(): Repository
    {
        $store = config('identity.cache.store');

        return Cache::store(is_string($store) ? $store : null);
    }

    public function get(string $key): mixed
    {
        return $this->store()->get(self::PREFIX.$key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->store()->put(self::PREFIX.$key, $value, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->store()->forget(self::PREFIX.$key);
    }

    /**
     * Блокировка обмена токенов.
     *
     * Нужна и пользовательскому, и служебному токену: параллельные запросы
     * иначе предъявят прежний refresh-токен, и семейство будет отозвано
     * как украденное (справка 9, пункт 4).
     */
    public function lock(string $key, int $seconds): Lock
    {
        $store = $this->store()->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(
                'Хранилище кэша интеграции не поддерживает блокировок. '.
                'Обмен токенов без блокировки по sid приводит к отзыву семейства '.
                '(справка identity, раздел 10): задайте IDENTITY_CACHE_STORE '.
                'на redis либо array.',
            );
        }

        return $store->lock(self::PREFIX.$key, $seconds);
    }
}

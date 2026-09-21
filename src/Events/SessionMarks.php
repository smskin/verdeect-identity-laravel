<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events;

use Carbon\CarbonImmutable;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Отметки гашения сессий.
 *
 * **Отметка — время, а не снимаемый признак** (критерий 75): признак,
 * удаляемый при чтении, забрало бы первое же устройство пользователя,
 * и остальные его сессии продолжили бы работать.
 *
 * Обработчик очереди только ставит отметки; сессию гасит ближайший запрос
 * самого пользователя, по значениям `sub` и `sid` из его сессии. Обратный
 * индекс «пользователь → сессии» поэтому не нужен (справка 8.1).
 */
final class SessionMarks
{
    public function __construct(
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
    ) {}

    /**
     * Гасит все сессии сотрудника.
     */
    public function markSub(string $sub, CarbonImmutable $at): void
    {
        $this->put('mark:sub:'.$sub, $at);
    }

    /**
     * Гасит одну названную сессию входа.
     */
    public function markSid(string $sid, CarbonImmutable $at): void
    {
        $this->put('mark:sid:'.$sid, $at);
    }

    /**
     * Отметка устаревших прав учётной записи.
     *
     * **Сессию не гасит** и в `isStale()` не участвует: изменение роли —
     * не повод выгонять человека, а повод обменять его токен. Права живут
     * в токене доступа и читаются из него при каждом запросе, поэтому
     * до обмена продукт видит прежнюю роль (справка 9, пункт 2).
     *
     * Отличие от `markSub()` и `markSid()` — именно в этом, и сводить три
     * отметки в один механизм нельзя: последствия у них разные.
     */
    public function markRights(string $sub, CarbonImmutable $at): void
    {
        $this->put('mark:rights:'.$sub, $at);
    }

    public function rightsMark(string $sub): CarbonImmutable|null
    {
        return $this->read('mark:rights:'.$sub);
    }

    /**
     * Отметка состава навигации установки.
     *
     * `navigation.changed` — единственный тип без `sub` и `sid`: состав
     * меняется для всех сразу, и адресовать его некому (справка 8.1).
     */
    public function markProduct(CarbonImmutable $at): void
    {
        $this->put('mark:navigation', $at);
    }

    public function productMark(): CarbonImmutable|null
    {
        return $this->read('mark:navigation');
    }

    /**
     * Сессия установлена раньше отметки — значит, отменена.
     */
    public function isStale(string $sub, string|null $sid, CarbonImmutable $authenticatedAt): bool
    {
        $subMark = $this->read('mark:sub:'.$sub);

        if ($subMark !== null && $authenticatedAt->lessThan($subMark)) {
            return true;
        }

        if ($sid === null) {
            return false;
        }

        $sidMark = $this->read('mark:sid:'.$sid);

        return $sidMark !== null && $authenticatedAt->lessThan($sidMark);
    }

    private function put(string $key, CarbonImmutable $at): void
    {
        $existing = $this->read($key);

        // Более поздняя отметка не заменяется более ранней: порядок доставки
        // не гарантирован (справка 8.1).
        if ($existing !== null && $existing->greaterThanOrEqualTo($at)) {
            return;
        }

        $this->cache->put($key, $at->toIso8601String(), $this->config->sessionTtlSeconds());
    }

    private function read(string $key): CarbonImmutable|null
    {
        $value = $this->cache->get($key);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}

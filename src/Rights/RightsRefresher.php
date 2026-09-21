<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Rights;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Exceptions\IdentityException;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Tokens\TokenManager;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Обмен токена перед отказом по недостаточным правам.
 *
 * Установка требует: получив отказ по правам, продукт обменивает токен
 * и повторяет проверку один раз — новая роль применяется сразу, а не через
 * минуты (справка 9, «Дополнительно»).
 *
 * **Почему этого мало — отметки прав.** Отметка по `user.rights.changed`
 * закрывает случай точно и без лишних обменов, но бессильна, когда
 * потребитель сообщений не поднят или брокер недоступен. Повтор перед
 * отказом не зависит ни от чего и потому работает всегда.
 *
 * **Почему под дросселем.** Честный отказ — случай обычный: рядовой
 * сотрудник открывает административный экран. Без дросселя каждая такая
 * попытка звала бы установку. Промежуток задаёт `rights_probe_ttl`.
 *
 * Правило живёт здесь, а не в посредниках: их два, и разойтись двум копиям
 * ничто не мешает.
 */
final class RightsRefresher
{
    public function __construct(
        private readonly IdentitySession $session,
        private readonly TokenManager $tokens,
        private readonly TokenStore $store,
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
    ) {}

    /**
     * Обменять токен, если это уместно, и сообщить, стоит ли перечитать права.
     *
     * Истина означает: обмен состоялся, утверждения токена изменились,
     * и право следует проверить ещё раз. Ложь — обменивать было нечего
     * либо незачем; отказ остаётся отказом.
     *
     * `SessionExpiredException` наружу не перехватывается: ответ на неё —
     * перенаправление на вход, а запроса и ответа у этого класса нет.
     */
    public function refreshOnce(): bool
    {
        $sid = $this->session->sid();

        if ($sid === null) {
            return false;
        }

        if ($this->throttled($sid)) {
            return false;
        }

        $set = $this->store->get($sid);

        /*
         * Набора нет либо в нём нет refresh-токена — обменивать нечем.
         *
         * Это не мелочь: обмен без refresh-токена даёт
         * `SessionExpiredException`, и честный отказ рядовому сотруднику
         * обернулся бы выходом из продукта.
         */
        if ($set === null || $set->refreshToken === null) {
            return false;
        }

        $this->throttle($sid);

        Log::debug('[RightsRefresher.refreshOnce] rights re-checked', ['sid' => $sid]);

        try {
            $this->tokens->refreshNow($sid, CarbonImmutable::now());
        } catch (SessionExpiredException $exception) {
            /*
             * Установка отвергла обмен: сессии больше нет, и отвечать на это
             * отказом по правам неверно. Решение принимает посредник —
             * у него есть запрос и ответ.
             */
            throw $exception;
        } catch (IdentityException $exception) {
            /*
             * Установка недоступна — а страница обязана работать и без неё
             * (`DESCRIPTION.md`). Отказ остаётся отказом: прав у человека
             * по имеющемуся токену действительно нет, и превращать сбой сети
             * в `500` незачем.
             */
            Log::warning('[RightsRefresher.refreshOnce] identity unavailable', [
                'sid' => $sid,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Обменивали ли токен этой сессии недавно.
     *
     * Срок меньше единицы означает выключенный дроссель, и записи тогда
     * не делается вовсе: нулевой срок каждый драйвер кэша трактует по-своему,
     * и выключение вышло бы случайностью реализации, а не объявленным
     * поведением.
     */
    private function throttled(string $sid): bool
    {
        if ($this->config->rightsProbeTtl() < 1) {
            return false;
        }

        if ($this->cache->get($this->throttleKey($sid)) === null) {
            return false;
        }

        Log::debug('[RightsRefresher.refreshOnce] throttled', [
            'sid' => $sid,
            'ttl' => $this->config->rightsProbeTtl(),
        ]);

        return true;
    }

    /**
     * Запись дросселя ставится **до** обмена: иначе отказ установки оставлял
     * бы продукт без защиты от повторных попыток.
     */
    private function throttle(string $sid): void
    {
        $ttl = $this->config->rightsProbeTtl();

        if ($ttl < 1) {
            return;
        }

        $this->cache->put($this->throttleKey($sid), true, $ttl);
    }

    private function throttleKey(string $sid): string
    {
        return 'rights-probe:'.$sid;
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tokens;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Выдача действующего токена доступа сессии входа.
 *
 * Обмен идёт **под блокировкой с ключом по `sid`** и с повторной проверкой
 * состояния после её получения (справка 9, пункты 3–4). Без блокировки
 * параллельные запросы Inertia предъявят прежний refresh-токен, установка
 * распознает это как кражу и отзовёт всё семейство (критерий 86).
 */
final class TokenManager
{
    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(
        private readonly TokenStore $store,
        private readonly TokenExchanger $exchanger,
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
    ) {}

    public function accessTokenFor(string $sid): string
    {
        return $this->resolve(
            $sid,
            fn (TokenSet $set): bool => $this->needsRefresh($set),
            'accessTokenFor',
        );
    }

    /**
     * Обмен по поводу, не связанному со сроком: права устарели.
     *
     * Обменивает токен, **выданный раньше** `$staleBefore`, и отдаёт
     * действующий токен доступа. Поводов два: отметка изменения прав
     * из сообщения установки и повтор перед отказом по недостаточным правам
     * (справка 9, «Дополнительно»).
     *
     * **Момент, а не признак «обменять во что бы то ни стало».** Два
     * параллельных запроса, получивших отказ одновременно, берут отметку
     * времени до блокировки; второй, дождавшись её, видит токен, выданный
     * уже после своей отметки, и второго обмена не делает. Признак дал бы
     * два обмена подряд, а с ними — предъявление погашенного refresh-токена
     * и отзыв семейства (критерий 86).
     */
    public function refreshNow(string $sid, CarbonImmutable $staleBefore): string
    {
        return $this->resolve(
            $sid,
            static fn (TokenSet $set): bool => $set->issuedAt->lessThan($staleBefore),
            'refreshNow',
        );
    }

    /**
     * Отдать токен доступа, обменяв набор, если он признан устаревшим.
     *
     * Порядок один на все поводы обмена: проверка до блокировки, взятие
     * блокировки по `sid`, **повторная** проверка после её получения, обмен,
     * сохранение. Разные поводы различаются только правилом `$stale`,
     * поэтому и живёт этот порядок в одном месте: разойдясь, две его копии
     * дали бы обмен без блокировки, а это отзыв семейства.
     *
     * @param  \Closure(TokenSet): bool  $stale  Правило «набор пора обменять»
     * @param  string  $caller  Метод для записей журнала
     */
    private function resolve(string $sid, \Closure $stale, string $caller): string
    {
        $set = $this->store->get($sid);

        if ($set === null) {
            throw SessionExpiredException::forSid($sid, 'токенов нет в хранилище');
        }

        if (! $stale($set)) {
            return $set->accessToken;
        }

        $lock = $this->cache->lock('refresh:'.$sid, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            /*
             * Гонка не разрешилась за отведённое время. Обменивать токен
             * без блокировки нельзя — это ровно тот случай, ради которого
             * она заведена. Остаётся отдать имеющийся токен, если он ещё жив.
             */
            Log::warning('[TokenManager.'.$caller.'] lock timeout', ['sid' => $sid]);

            $fresh = $this->store->get($sid);

            if ($fresh !== null && $fresh->expiresAt->isFuture()) {
                return $fresh->accessToken;
            }

            throw SessionExpiredException::forSid($sid, 'обмен токена не состоялся');
        }

        try {
            // Повторная проверка обязательна: пока ждали блокировку,
            // соседний запрос мог обменять токен (справка 9.4).
            $current = $this->store->get($sid);

            if ($current === null) {
                throw SessionExpiredException::forSid($sid, 'токены удалены во время ожидания');
            }

            if (! $stale($current)) {
                return $current->accessToken;
            }

            Log::debug('[TokenManager.'.$caller.'] refreshing', [
                'sid' => $sid,
                'ttl' => CarbonImmutable::now()->diffInSeconds($current->expiresAt, absolute: false),
            ]);

            try {
                $new = $this->exchanger->refresh($current);
            } catch (SessionExpiredException $exception) {
                Log::warning('[TokenManager.'.$caller.'] refresh rejected, destroying session', [
                    'sid' => $sid,
                ]);

                $this->store->forget($sid);

                throw $exception;
            }

            $this->store->put($sid, $new);

            return $new->accessToken;
        } finally {
            $lock->release();
        }
    }

    /**
     * Обмен начинается, когда до истечения осталось менее трети срока.
     *
     * Доля, а не число секунд: срок жизни токена задаёт администратор
     * установки в пределах от 1 до 60 минут (справка 11), и предполагать
     * конкретное значение нельзя.
     */
    public function needsRefresh(TokenSet $set): bool
    {
        $threshold = (int) round($set->lifetimeSeconds() * $this->config->refreshAheadRatio());

        return CarbonImmutable::now()
            ->greaterThanOrEqualTo($set->expiresAt->subSeconds($threshold));
    }
}

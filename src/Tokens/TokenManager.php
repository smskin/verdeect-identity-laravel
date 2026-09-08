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
        $set = $this->store->get($sid);

        if ($set === null) {
            throw SessionExpiredException::forSid($sid, 'токенов нет в хранилище');
        }

        if (! $this->needsRefresh($set)) {
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
            Log::warning('[TokenManager.accessTokenFor] lock timeout', ['sid' => $sid]);

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

            if (! $this->needsRefresh($current)) {
                return $current->accessToken;
            }

            Log::debug('[TokenManager.accessTokenFor] refreshing ahead', [
                'sid' => $sid,
                'ttl' => CarbonImmutable::now()->diffInSeconds($current->expiresAt, absolute: false),
            ]);

            try {
                $new = $this->exchanger->refresh($current);
            } catch (SessionExpiredException $exception) {
                Log::warning('[TokenManager.accessTokenFor] refresh rejected, destroying session', [
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

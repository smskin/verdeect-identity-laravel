<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Verdeect\IdentityIntegration\Profile\ProfileProvider;
use Verdeect\IdentityIntegration\Support\IdentityCache;

/**
 * Сброс кэшей, относящихся к одному сотруднику.
 *
 * Вызывается обработчиком сообщений (задача 18). Обработчик **ничего
 * не запрашивает у установки** — он только помечает и сбрасывает своё
 * (критерий 73).
 */
final class CacheInvalidator
{
    public function __construct(
        private readonly ProfileProvider $profiles,
        private readonly NavigationClient $navigation,
        private readonly IdentityCache $cache,
    ) {}

    public function forgetProfile(string $sub): void
    {
        $this->profiles->forget($sub);

        // Разрешённое имя того же человека тоже устарело.
        $this->cache->forget('user:'.$sub);
    }

    public function forgetServices(string $sub): void
    {
        $this->navigation->forget($sub);
    }
}

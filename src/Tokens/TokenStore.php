<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Tokens;

use Verdeect\IdentityIntegration\Support\IdentityCache;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Хранилище токенов, ключ — `sid`.
 *
 * Ключом служит идентификатор **сессии входа**, а не идентификатор сессии
 * Laravel: один человек в двух браузерах даёт две сессии входа и два `sid`,
 * и общий ключ заставил бы их затирать refresh-токен друг друга. Следующее
 * предъявление прежнего значения установка распознала бы как кражу
 * и отозвала всё семейство (справка 9, пункт 3; раздел 10).
 */
final class TokenStore
{
    public function __construct(
        private readonly IdentityCache $cache,
        private readonly IdentityConfig $config,
    ) {}

    public function put(string $sid, TokenSet $set): void
    {
        $this->cache->put(
            $this->key($sid),
            $set->toArray(),
            $this->config->sessionTtlSeconds(),
        );
    }

    public function get(string $sid): TokenSet|null
    {
        $payload = $this->cache->get($this->key($sid));

        if (! is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return TokenSet::fromArray($payload);
    }

    public function forget(string $sid): void
    {
        $this->cache->forget($this->key($sid));
    }

    private function key(string $sid): string
    {
        return 'tokens:'.$sid;
    }
}

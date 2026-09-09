<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Support;

use Verdeect\IdentityIntegration\Exceptions\ConfigurationException;

/**
 * Чтение настроек с внятным отказом на незаполненном значении.
 *
 * Пустой `client_id` без этой проверки уводил бы на `/authorize` с пустым
 * параметром, и отказ приходил бы от установки в виде `unauthorized_client` —
 * далеко от настоящей причины.
 */
final class IdentityConfig
{
    public function baseUrl(): string
    {
        return rtrim($this->required('identity.base_url'), '/');
    }

    public function webClientId(): string
    {
        return $this->required('identity.web.client_id');
    }

    public function webClientSecret(): string
    {
        return $this->required('identity.web.client_secret');
    }

    public function redirectUri(): string
    {
        return $this->required('identity.web.redirect_uri');
    }

    public function postLogoutRedirectUri(): string
    {
        return $this->required('identity.web.post_logout_redirect_uri');
    }

    public function serviceClientId(): string
    {
        return $this->required('identity.service.client_id');
    }

    public function serviceClientSecret(): string
    {
        return $this->required('identity.service.client_secret');
    }

    /**
     * @return list<string>
     */
    public function webScopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = config('identity.web.scopes', ['openid', 'profile', 'email']);

        return $scopes;
    }

    /**
     * Области служебного токена.
     *
     * Их две: `users:read` открывает разрешение чужих имён, `navigation:read` —
     * обе операции навигации. Умолчание повторяет обе, потому что продукт
     * без опубликованной настройки обязан работать целиком, а не наполовину.
     *
     * @return list<string>
     */
    public function serviceScopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = config('identity.service.scopes', ['users:read', 'navigation:read']);

        return $scopes;
    }

    /**
     * Срок кэша рейла вошедшего.
     *
     * Верхнюю границу задаёт не настройка, а срок подписи ссылок на иконки,
     * который знает только установка (см. `NavItem`).
     */
    public function navigationTtl(): int
    {
        return (int) config('identity.cache.navigation_ttl', 3600);
    }

    /**
     * Срок кэша гостевого рейла.
     *
     * Запись одна на установку, и та же верхняя граница действует сильнее:
     * протухшая подпись бьёт по каждой странице без сессии сразу.
     */
    public function navigationGuestTtl(): int
    {
        return (int) config('identity.cache.navigation_guest_ttl', 3600);
    }

    public function refreshAheadRatio(): float
    {
        return (float) config('identity.refresh_ahead_ratio', 1 / 3);
    }

    public function sessionTtlSeconds(): int
    {
        return (int) config('identity.session_ttl_days', 90) * 86400;
    }

    public function httpTimeout(): int
    {
        return (int) config('identity.http.timeout', 10);
    }

    /**
     * Записи подмены узла при соединении, вида `узел:порт:адрес`.
     *
     * @return list<string>
     */
    public function httpResolve(): array
    {
        $value = config('identity.http.resolve', '');

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $value))));
    }

    /**
     * @return int<1, max>
     */
    public function resolveBatchLimit(): int
    {
        return max(1, (int) config('identity.resolve_batch_limit', 100));
    }

    private function required(string $key): string
    {
        $value = config($key);

        if (! is_string($value) || $value === '') {
            throw ConfigurationException::missing($key);
        }

        return $value;
    }
}

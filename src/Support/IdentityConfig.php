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
     * @return list<string>
     */
    public function serviceScopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = config('identity.service.scopes', ['users:read']);

        return $scopes;
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

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Flow;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Support\IdentityConfig;

/**
 * Начало потока кода авторизации.
 *
 * Вход полностью серверный (PRD 12.3): `state`, `nonce` и проверочный код
 * живут только в серверной сессии, в браузер не попадают и проверяются
 * на возврате.
 */
final class AuthorizationFlow
{
    public const SESSION_KEY = 'identity.flow';

    public function __construct(
        private readonly IDiscoveryClient $discovery,
        private readonly IdentityConfig $config,
    ) {}

    /**
     * Готовит поток и возвращает адрес `/authorize` установки.
     *
     * @param  string|null  $intendedUrl  адрес, куда вернуть после входа
     */
    public function start(string|null $intendedUrl = null): string
    {
        $state = $this->randomValue();
        $nonce = $this->randomValue();
        $pkce = PkcePair::generate();

        Session::put(self::SESSION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $pkce->verifier,
            'intended_url' => $intendedUrl,
        ]);

        $document = $this->discovery->fetch();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config->webClientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => implode(' ', $this->config->webScopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => 'S256',
            'ui_locales' => 'ru',
        ]);

        /*
         * Целиком `state` не журналируется: он предъявляется на возврате,
         * и запись в журнале сделала бы его доступным читателю журнала.
         */
        Log::debug('[AuthorizationFlow.start] flow started', [
            'state' => substr($state, 0, 8),
            'intended_url' => $intendedUrl,
        ]);

        return $document->authorizationEndpoint.'?'.$query;
    }

    /**
     * Сохранённые значения потока.
     *
     * @return array{state: string, nonce: string, verifier: string, intended_url: string|null}|null
     */
    public function pending(): array|null
    {
        $flow = Session::get(self::SESSION_KEY);

        if (! is_array($flow)) {
            return null;
        }

        foreach (['state', 'nonce', 'verifier'] as $field) {
            if (! isset($flow[$field]) || ! is_string($flow[$field])) {
                return null;
            }
        }

        /** @var array{state: string, nonce: string, verifier: string, intended_url: string|null} $flow */
        return $flow;
    }

    public function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }

    private function randomValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}

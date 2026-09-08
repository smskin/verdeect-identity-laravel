<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Http\ExternalRedirect;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Support\IdentityConfig;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Выход.
 *
 * Через `/end-session`, а не через `/revoke`: сотрудник, вышедший на общем
 * компьютере, должен выйти отовсюду. `/revoke` освобождает токены одного
 * продукта, не выводя человека из системы, и возврат в сервис происходил бы
 * одним кликом без пароля (PRD 12.5, справка 4.4–4.5).
 */
final readonly class LogoutController
{
    public function __construct(
        private IDiscoveryClient $discovery,
        private TokenStore $store,
        private IdentitySession $session,
        private IdentityConfig $config,
    ) {}

    public function __invoke(Request $request): Response
    {
        $sid = $this->session->sid();
        $set = $sid === null ? null : $this->store->get($sid);
        $idToken = $set?->idToken;

        $endSession = $this->endSessionEndpoint();

        /*
         * Порядок обязателен: локальная сессия и токены уничтожаются
         * **до** перенаправления (критерий 76). Иначе отказ установки
         * оставил бы пользователя вошедшим в продукт.
         */
        if ($sid !== null) {
            $this->store->forget($sid);
        }

        $this->session->forget();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::debug('[LogoutController] local session destroyed', ['sid' => $sid]);

        /*
         * Перенаправление возможно только при пригодной подсказке
         * (справка 4.5). Её нет — выход уже выполнен локально, остаётся
         * вернуть человека на главную.
         */
        if ($endSession === null || $idToken === null) {
            return redirect()->to('/');
        }

        $query = http_build_query([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => $this->config->postLogoutRedirectUri(),
            'state' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='),
        ]);

        return ExternalRedirect::to($request, $endSession.'?'.$query);
    }

    private function endSessionEndpoint(): string|null
    {
        try {
            return $this->discovery->fetch()->endSessionEndpoint;
        } catch (Throwable $exception) {
            Log::warning('[LogoutController] discovery unavailable, local logout only', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}

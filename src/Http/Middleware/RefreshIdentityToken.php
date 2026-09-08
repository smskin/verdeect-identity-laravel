<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Tokens\TokenManager;

/**
 * Поддержание токена доступа свежим.
 *
 * Обмен выполняется в начале запроса: иначе первое обращение к прикладному
 * интерфейсу происходило бы с истекающим токеном, и отказ приходил бы
 * из середины отрисовки страницы.
 */
final class RefreshIdentityToken
{
    public function __construct(
        private readonly IdentitySession $session,
        private readonly TokenManager $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sid = $this->session->sid();

        if ($sid === null) {
            return $next($request);
        }

        try {
            $this->tokens->accessTokenFor($sid);
        } catch (SessionExpiredException) {
            /*
             * Сессия уничтожается **штатным механизмом Laravel**: прямого
             * доступа к хранилищу сессий у пакета нет (PRD 12.1).
             */
            $this->session->forget();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return RedirectToLogin::respond($request);
        }

        return $next($request);
    }
}

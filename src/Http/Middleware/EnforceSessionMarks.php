<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

/**
 * Применение отметок гашения.
 *
 * Обработчик очереди только помечает; сессию гасит **ближайший запрос
 * самого пользователя** — по значениям `sub` и `sid` из его собственной
 * сессии (справка 8.1, критерий 74). Обратный индекс «пользователь →
 * сессии» поэтому не нужен.
 */
final class EnforceSessionMarks
{
    public function __construct(
        private readonly IdentitySession $session,
        private readonly SessionMarks $marks,
        private readonly TokenStore $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sub = $this->session->sub();
        $sid = $this->session->sid();
        $authenticatedAt = $this->session->authenticatedAt();

        if ($sub === null || $authenticatedAt === null) {
            return $next($request);
        }

        if (! $this->marks->isStale($sub, $sid, $authenticatedAt)) {
            return $next($request);
        }

        Log::debug('[EnforceSessionMarks.handle] stale session destroyed', [
            'sub' => $sub,
            'sid' => $sid,
        ]);

        if ($sid !== null) {
            $this->store->forget($sid);
        }

        $this->session->forget();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return RedirectToLogin::respond($request);
    }
}

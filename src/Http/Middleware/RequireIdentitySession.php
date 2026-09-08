<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Session\IdentitySession;

/**
 * Требование аутентифицированной сессии.
 *
 * Все экраны сервиса живут за входом (PRD 12.3). Запросы Inertia не умеют
 * следовать за перенаправлением на чужой домен: им отдаётся `409`
 * с заголовком `X-Inertia-Location`, по которому клиент выполняет полный
 * переход (PRD 12.3, пункт 6).
 */
final class RequireIdentitySession
{
    public function __construct(private readonly IdentitySession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->session->isAuthenticated()) {
            return $next($request);
        }

        $request->session()->put('identity.intended_url', $request->fullUrl());

        return RedirectToLogin::respond($request);
    }
}

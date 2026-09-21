<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Http\Middleware\RedirectToLogin;
use Verdeect\IdentityIntegration\Session\IdentitySession;

/**
 * Завершение сессии входа и переход на форму входа.
 *
 * Правило одно на четыре повода: обмен токена не удался, сессия помечена
 * погашенной из установки, и оба посредника прав, встретившие
 * `SessionExpiredException` при повторе перед отказом. Разойдясь, копии
 * оставили бы один из поводов с живой сессией и мёртвыми токенами.
 *
 * **Сессия уничтожается штатным механизмом Laravel** — `invalidate()`
 * и `regenerateToken()`. Прямого доступа к хранилищу сессий у пакета нет:
 * это привязало бы его к драйверу (PRD 12.1).
 */
trait EndsIdentitySession
{
    abstract protected function identitySession(): IdentitySession;

    protected function endSession(Request $request): Response
    {
        $this->identitySession()->forget();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return RedirectToLogin::respond($request);
    }
}

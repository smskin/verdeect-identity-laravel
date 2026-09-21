<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Http\EndsIdentitySession;
use Verdeect\IdentityIntegration\Session\IdentitySession;
use Verdeect\IdentityIntegration\Tokens\TokenManager;

/**
 * Поддержание токена доступа свежим.
 *
 * Обмен выполняется в начале запроса: иначе первое обращение к прикладному
 * интерфейсу происходило бы с истекающим токеном, и отказ приходил бы
 * из середины отрисовки страницы.
 *
 * **Поводов обмена два.** Первый — упреждение по сроку. Второй — отметка
 * изменившихся прав: установка сказала `user.rights.changed`, права живут
 * в токене доступа, и до обмена продукт видел бы прежнюю роль (справка 9,
 * пункт 2). Второй повод закрывает повышение прав без нового входа и без
 * ожидания истечения токена.
 */
final class RefreshIdentityToken
{
    use EndsIdentitySession;

    public function __construct(
        private readonly IdentitySession $session,
        private readonly TokenManager $tokens,
        private readonly SessionMarks $marks,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $sid = $this->session->sid();

        if ($sid === null) {
            return $next($request);
        }

        try {
            $this->tokens->accessTokenFor($sid);
            $this->applyRightsMark($sid);
        } catch (SessionExpiredException) {
            return $this->endSession($request);
        }

        return $next($request);
    }

    protected function identitySession(): IdentitySession
    {
        return $this->session;
    }

    /**
     * Обменять токен, выданный раньше отметки изменения прав.
     *
     * **Отметка не снимается после обмена.** Она поставлена по `sub`
     * и общая для всех сессий входа человека; снявший её первый браузер
     * оставил бы остальные со старыми правами. Повторных обменов это
     * не вызывает: `refreshNow()` сравнивает отметку со временем выдачи
     * набора, и обменянный токен уже новее её.
     */
    private function applyRightsMark(string $sid): void
    {
        $sub = $this->session->sub();

        if ($sub === null) {
            return;
        }

        $mark = $this->marks->rightsMark($sub);

        if (! $mark instanceof CarbonImmutable) {
            return;
        }

        Log::debug('[RefreshIdentityToken.handle] rights mark applied', [
            'sub' => $sub,
            'sid' => $sid,
        ]);

        $this->tokens->refreshNow($sid, $mark);
    }
}

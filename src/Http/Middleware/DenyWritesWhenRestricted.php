<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Rights\CurrentIdentity;

/**
 * Запрет записи при ограничении учётной записи.
 *
 * Правило эффективных прав проверяется **в одном месте пакета**, а не
 * воспроизводится каждым продуктом (справка 4.7.3): ошибка в нём не проявляется
 * сбоем — она даёт прав больше положенного, и обнаруживают её не тестами.
 *
 * ```php
 * Route::middleware('identity.writable')->group(function (): void {
 *     Route::post('/reports', StoreReportController::class);
 * });
 * ```
 *
 * **Вешается на маршруты записи, а не на всю группу.** Ограничение закрывает
 * запись, а не чтение: человек в режиме «только чтение» обязан видеть данные
 * продукта. Белый список маршрутов чтения пакет не ведёт — какие из маршрутов
 * продукта меняют состояние, знает только продукт.
 *
 * **Решение принимает `CurrentIdentity::allowsWrites()`**, и посредник его
 * не повторяет: запись разрешена только при пустом перечне ограничений, и
 * ограничение, о котором продукт ещё не знает, закрывает её само.
 *
 * Продукт, не поддерживающий режим ограниченной работы, посредник просто
 * не упоминает. Это его решение, а не умолчание пакета.
 */
final class DenyWritesWhenRestricted
{
    public function __construct(private readonly CurrentIdentity $identity) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->identity->allowsWrites()) {
            return $next($request);
        }

        /*
         * Перечень ограничений в журнал не пишется: он сведения об учётной
         * записи, а для разбора отказа довольно самого факта.
         */
        Log::debug('[DenyWritesWhenRestricted] write denied');

        abort(403);
    }
}

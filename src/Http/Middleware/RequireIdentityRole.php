<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Rights\CurrentIdentity;

/**
 * Требование роли на маршруте.
 *
 * Роль называет **продукт** в самом маршруте, а не пакет в имени класса:
 * смысл ролей в пакет не выносится (справка 9.3). Пакет отвечает лишь за то,
 * откуда роль взялась и что она свежая.
 *
 * ```php
 * Route::middleware(['identity', 'identity.role:admin'])->group(...);
 * ```
 *
 * Несколько ролей перечисляются через запятую и означают «любая из»:
 * `identity.role:admin,supervisor`. Пересечение («и та, и другая») не
 * поддерживается намеренно — ролей в установке две, и случая для него нет.
 *
 * **Ставится после требования входа.** У гостя перечень ролей пуст, и без
 * `RequireIdentitySession` впереди он получил бы `403` вместо перехода
 * на форму входа — то есть отказ вместо предложения войти.
 */
final class RequireIdentityRole
{
    public function __construct(private readonly CurrentIdentity $identity) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        foreach ($roles as $role) {
            if ($this->identity->hasRole($role)) {
                return $next($request);
            }
        }

        /*
         * Роли вошедшего в журнал не пишутся: они сведения об учётной записи,
         * а для разбора отказа довольно того, какая роль требовалась.
         */
        Log::debug('[RequireIdentityRole] role required', ['roles' => $roles]);

        abort(403);
    }
}

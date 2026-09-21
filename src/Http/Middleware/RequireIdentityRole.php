<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Exceptions\SessionExpiredException;
use Verdeect\IdentityIntegration\Http\EndsIdentitySession;
use Verdeect\IdentityIntegration\Rights\CurrentIdentity;
use Verdeect\IdentityIntegration\Rights\RightsRefresher;
use Verdeect\IdentityIntegration\Session\IdentitySession;

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
 *
 * **Перед отказом роль перепроверяется один раз** после принудительного
 * обмена токена (справка 9, «Дополнительно»): повышение роли доезжает
 * до продукта сообщением либо истечением токена, и до тех пор человек видел
 * бы отказ на действии, которое ему уже разрешено. Повторяется **проверка**,
 * а не операция: посредник стоит до обработчика, и тело запроса
 * не выполняется ни разу.
 */
final class RequireIdentityRole
{
    use EndsIdentitySession;

    public function __construct(
        private readonly CurrentIdentity $identity,
        private readonly RightsRefresher $refresher,
        private readonly IdentitySession $session,
    ) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($this->hasAnyRole($roles)) {
            return $next($request);
        }

        try {
            $refreshed = $this->refresher->refreshOnce();
        } catch (SessionExpiredException) {
            /*
             * Обмен отвергнут установкой: человек не «без прав», а больше
             * не вошёл, и отказ сообщал бы о другом.
             */
            return $this->endSession($request);
        }

        if ($refreshed && $this->hasAnyRole($roles)) {
            return $next($request);
        }

        /*
         * Роли вошедшего в журнал не пишутся: они сведения об учётной записи,
         * а для разбора отказа довольно того, какая роль требовалась и был ли
         * перед отказом обмен.
         */
        Log::debug('[RequireIdentityRole.handle] role required', [
            'roles' => $roles,
            'refreshed' => $refreshed,
        ]);

        abort(403);
    }

    /**
     * Есть ли у вошедшего хоть одна из названных маршрутом ролей.
     *
     * Ключи перечня не важны и не нормализуются: роли приходят переменным
     * числом параметров посредника, и при вызове с именованными аргументами
     * ключи оказываются строковыми.
     *
     * Ответ меняется в пределах запроса вслед за обменом токена — по доводу
     * `CurrentIdentity::hasRole()`.
     *
     * @param  array<array-key, string>  $roles
     *
     * @phpstan-impure
     */
    private function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->identity->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    protected function identitySession(): IdentitySession
    {
        return $this->session;
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Verdeect\IdentityIntegration\Http\ExternalRedirect;

/**
 * Отправка на вход.
 *
 * Отдельный класс, потому что отправляют на вход три разных middleware,
 * и правило должно быть описано один раз.
 *
 * Адрес входа принадлежит самому продукту, но переход к нему обязан быть
 * полным, а не запросом Inertia: следом идёт переход на установку, и
 * начатая XHR цепочка оборвалась бы на чужом источнике.
 */
final class RedirectToLogin
{
    public static function respond(Request $request): Response
    {
        return ExternalRedirect::to($request, route('identity.login'));
    }
}

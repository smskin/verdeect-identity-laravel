<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Переход на чужой источник.
 *
 * Запрос Inertia — это XHR, и обычное перенаправление он пытается пройти
 * сам. Цепочка, уходящая на другой источник, обрывается правилом общего
 * происхождения: обмен не состоится, а страница останется прежней, будто
 * ничего не произошло. Поэтому таким запросам отдаётся `409` с заголовком
 * `X-Inertia-Location`, по которому клиент выполняет полный переход
 * (PRD 12.3, пункт 6).
 *
 * Класс живёт в пакете и об Inertia знает ровно один заголовок: заводить
 * зависимость от адаптера ради него незачем.
 */
final class ExternalRedirect
{
    public static function to(Request $request, string $url): Response
    {
        if ($request->header('X-Inertia')) {
            return response('', 409)->header('X-Inertia-Location', $url);
        }

        return redirect()->away($url);
    }
}

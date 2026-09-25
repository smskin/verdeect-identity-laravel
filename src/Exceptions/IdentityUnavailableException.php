<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Установка не ответила: соединение не открылось либо ответ не пришёл
 * за отведённое время.
 *
 * Отдельно от отказа обмена (`SessionExpiredException`): установка ничего
 * не решала, и повтор через минуту может пройти. Уничтожать сессию или
 * показывать «вход отклонён» по такому поводу — неверная причина.
 */
final class IdentityUnavailableException extends IdentityException
{
    public static function tokenEndpoint(string $reason): self
    {
        return new self("Эндпоинт токенов установки не ответил: {$reason}");
    }
}

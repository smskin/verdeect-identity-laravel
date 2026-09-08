<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Сессия входа больше не действует.
 *
 * Поднимается, когда токенов нет, обмен отклонён либо истёк предельный срок
 * сессии установки. Обработчик — middleware: он уничтожает локальную сессию
 * штатным механизмом Laravel и отправляет на вход.
 */
final class SessionExpiredException extends IdentityException
{
    public static function forSid(string|null $sid, string $reason): self
    {
        return new self("Сессия входа {$sid} недействительна: {$reason}");
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Ключа подписи нет в наборе даже после перечитывания.
 *
 * Одно перечитывание при неизвестном `kid` — штатная реакция на ротацию
 * (критерий 71). Второй промах означает, что токен подписан не этой
 * установкой.
 */
final class UnknownKeyException extends IdentityException
{
    public static function forKid(string $kid): self
    {
        return new self("Ключ подписи {$kid} отсутствует в наборе после перечитывания");
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Документ обнаружения недоступен или неполон.
 *
 * Отсутствие обязательного поля — отказ, а не `null`: адреса эндпоинтов
 * в код не зашиваются (PRD 12.2), и пустое значение проявилось бы обращением
 * по пустому адресу далеко от причины.
 */
final class DiscoveryException extends IdentityException
{
    public static function unreachable(string $baseUrl, string $reason): self
    {
        return new self("Документ обнаружения {$baseUrl} недоступен: {$reason}");
    }

    public static function incomplete(string $field): self
    {
        return new self("В документе обнаружения нет обязательного поля {$field}");
    }
}

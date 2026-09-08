<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Настройка интеграции неполна.
 *
 * Отдельный класс нужен, чтобы незаполненное окружение давало внятный текст,
 * а не перенаправление на битый адрес и не отказ с чужой формулировкой.
 */
final class ConfigurationException extends IdentityException
{
    public static function missing(string $key): self
    {
        return new self(
            "Настройка интеграции с identity не задана: {$key}. ".
            'Заполните переменную окружения; образец — .env.example.',
        );
    }
}

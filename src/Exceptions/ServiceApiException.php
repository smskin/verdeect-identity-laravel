<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * Отказ прикладного интерфейса identity.
 *
 * Код `error` сохраняется отдельно от текста: справка 7.4 требует различать
 * ошибки по коду, а не по формулировке.
 */
final class ServiceApiException extends IdentityException
{
    private function __construct(
        public readonly string $error,
        public readonly int $status,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function of(string $error, int $status, string $operation): self
    {
        return new self(
            $error,
            $status,
            "Операция {$operation} прикладного интерфейса identity отклонена: {$error} ({$status})",
        );
    }
}

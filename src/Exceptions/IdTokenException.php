<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Exceptions;

/**
 * ID-токен не прошёл проверку.
 *
 * Причина хранится кодом, а не текстом: по ней ветвится журналирование
 * и различаются критерии приёмки 69 и 70.
 */
final class IdTokenException extends IdentityException
{
    public const REASON_MALFORMED = 'malformed';

    public const REASON_SIGNATURE = 'signature';

    public const REASON_ISSUER = 'issuer';

    public const REASON_AUDIENCE = 'audience';

    public const REASON_EXPIRED = 'expired';

    public const REASON_NONCE = 'nonce';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}

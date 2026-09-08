<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Session;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;

/**
 * Сессия входа глазами продукта.
 *
 * Фасад над серверной сессией. В ней лежат ровно три значения — `sid`, `sub`
 * и момент установления сессии. **Ролей и ограничений здесь нет намеренно:**
 * сессия живёт дольше токена, и скопированная роль не понизилась бы
 * до нового входа (справка 13).
 */
final class IdentitySession
{
    private const SID = 'identity.sid';

    private const SUB = 'identity.sub';

    private const AUTHENTICATED_AT = 'identity.authenticated_at';

    public function establish(string $sid, string $sub): void
    {
        Session::put(self::SID, $sid);
        Session::put(self::SUB, $sub);
        Session::put(self::AUTHENTICATED_AT, CarbonImmutable::now()->toIso8601String());
    }

    public function sid(): string|null
    {
        $value = Session::get(self::SID);

        return is_string($value) ? $value : null;
    }

    public function sub(): string|null
    {
        $value = Session::get(self::SUB);

        return is_string($value) ? $value : null;
    }

    public function authenticatedAt(): CarbonImmutable|null
    {
        $value = Session::get(self::AUTHENTICATED_AT);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }

    public function isAuthenticated(): bool
    {
        return $this->sid() !== null && $this->sub() !== null;
    }

    public function forget(): void
    {
        Session::forget([self::SID, self::SUB, self::AUTHENTICATED_AT]);
    }
}

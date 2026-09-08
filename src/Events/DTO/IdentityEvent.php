<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events\DTO;

use Carbon\CarbonImmutable;

/**
 * Сообщение установки.
 *
 * **Состояния не несёт** — ни роли, ни имени: его смысл в том, что сведения
 * устарели либо сессия прекращена (справка 8.1). Поэтому идемпотентность
 * по `id`, версионирование и таблица обработанных сообщений не нужны:
 * повторная доставка даёт тот же результат.
 */
final readonly class IdentityEvent
{
    public function __construct(
        public string $id,
        public string $type,
        public CarbonImmutable $occurredAt,
        public string|null $sub,
        public string|null $sid,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = $payload['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new \InvalidArgumentException('В сообщении нет поля type');
        }

        $occurredAt = $payload['occurred_at'] ?? null;

        return new self(
            id: is_string($payload['id'] ?? null) ? $payload['id'] : '',
            type: $type,
            occurredAt: is_string($occurredAt)
                ? CarbonImmutable::parse($occurredAt)
                : CarbonImmutable::now(),
            sub: is_string($payload['sub'] ?? null) ? $payload['sub'] : null,
            sid: is_string($payload['sid'] ?? null) ? $payload['sid'] : null,
        );
    }
}

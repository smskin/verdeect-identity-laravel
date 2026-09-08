<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

use Verdeect\IdentityIntegration\Profile\NameForms;

/**
 * Разрешённое имя сотрудника.
 *
 * Идентификатор, которому в установке ничего не соответствует, отказом
 * не является: он выводится как есть (критерий 85, справка 7.1).
 */
final readonly class ResolvedUser
{
    public function __construct(
        public string $sub,
        public string $name,
        public string $shortName,
        public string $initials,
        public bool $isResolved,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromResponse(array $row): self
    {
        $sub = is_string($row['sub'] ?? null) ? $row['sub'] : '';
        $firstName = is_string($row['first_name'] ?? null) ? $row['first_name'] : '';
        $lastName = is_string($row['last_name'] ?? null) ? $row['last_name'] : '';
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';

        return new self(
            sub: $sub,
            name: $name !== '' ? $name : $sub,
            shortName: $firstName !== '' || $lastName !== ''
                ? NameForms::short($firstName, $lastName)
                : $sub,
            initials: $firstName !== '' && $lastName !== ''
                ? NameForms::initials($firstName, $lastName)
                : '',
            isResolved: true,
        );
    }

    public static function unresolved(string $sub): self
    {
        return new self(
            sub: $sub,
            name: $sub,
            shortName: $sub,
            initials: '',
            isResolved: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sub' => $this->sub,
            'name' => $this->name,
            'shortName' => $this->shortName,
            'initials' => $this->initials,
            'isResolved' => $this->isResolved,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            sub: (string) ($payload['sub'] ?? ''),
            name: (string) ($payload['name'] ?? ''),
            shortName: (string) ($payload['shortName'] ?? ''),
            initials: (string) ($payload['initials'] ?? ''),
            isResolved: (bool) ($payload['isResolved'] ?? false),
        );
    }
}

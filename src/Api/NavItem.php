<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

/**
 * Пункт рейла кросс-сервисной навигации.
 *
 * Имя приходит объектом по языкам: выбор языка — дело того, кто отрисовывает
 * (справка 7.3). Иконка — идентификатор набора PrimeIcons; неизвестный
 * идентификатор нормален и заменяется первой буквой имени самим компонентом.
 */
final readonly class NavItem
{
    /**
     * @param  array<string, string>  $name
     */
    public function __construct(
        public string $id,
        public array $name,
        public string $url,
        public string|null $icon,
        public int $order,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromResponse(array $row): self
    {
        /** @var array<string, string> $name */
        $name = is_array($row['name'] ?? null)
            ? array_filter($row['name'], is_string(...))
            : [];

        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            name: $name,
            url: is_string($row['url'] ?? null) ? $row['url'] : '',
            icon: is_string($row['icon'] ?? null) ? $row['icon'] : null,
            order: is_int($row['order'] ?? null) ? $row['order'] : 0,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'icon' => $this->icon,
            'order' => $this->order,
        ];
    }
}

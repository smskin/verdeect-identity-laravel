<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Profile;

/**
 * Профиль текущего пользователя.
 *
 * Собирается из `/userinfo`, а не из ID-токена: тот фиксирует момент входа
 * и свежим не поддерживается (справка 5.2, прямой запрет PRD 12.3).
 */
final readonly class UserProfile
{
    public function __construct(
        public string $sub,
        public string $name,
        public string $givenName,
        public string $familyName,
        public string $middleName,
        public string $locale,
        public string $shortName,
        public string $initials,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromUserInfo(array $claims): self
    {
        $sub = self::string($claims, 'sub');
        $givenName = self::string($claims, 'given_name');
        $familyName = self::string($claims, 'family_name');
        $name = self::string($claims, 'name');

        return new self(
            sub: $sub,
            name: $name !== '' ? $name : $sub,
            givenName: $givenName,
            familyName: $familyName,
            middleName: self::string($claims, 'middle_name'),
            locale: self::string($claims, 'locale') !== '' ? self::string($claims, 'locale') : 'ru',
            shortName: $givenName !== '' || $familyName !== ''
                ? NameForms::short($givenName, $familyName)
                : $sub,
            initials: $givenName !== '' && $familyName !== ''
                ? NameForms::initials($givenName, $familyName)
                : '',
        );
    }

    /**
     * Запасной профиль на случай недоступности установки.
     *
     * Страница обязана отрисовываться и без identity: вместо имени
     * показывается идентификатор (справка 9, пункт 8; PRD 12.11).
     */
    public static function unresolved(string $sub): self
    {
        return new self(
            sub: $sub,
            name: $sub,
            givenName: '',
            familyName: '',
            middleName: '',
            locale: 'ru',
            shortName: $sub,
            initials: '',
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
            'givenName' => $this->givenName,
            'familyName' => $this->familyName,
            'middleName' => $this->middleName,
            'locale' => $this->locale,
            'shortName' => $this->shortName,
            'initials' => $this->initials,
        ];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private static function string(array $claims, string $field): string
    {
        $value = $claims[$field] ?? null;

        return is_string($value) ? $value : '';
    }
}

<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Profile;

/**
 * Формы имени.
 *
 * Сервис готовыми их не отдаёт: `/userinfo` даёт `name`, `given_name`
 * и `family_name`. Собираются они по таблице справки 9.1, чтобы у всех
 * продуктов установки выглядели одинаково.
 */
final class NameForms
{
    /**
     * Краткая форма: имя, пробел, фамилия.
     */
    public static function short(string $givenName, string $familyName): string
    {
        return trim($givenName.' '.$familyName);
    }

    /**
     * Инициалы: первые буквы имени и фамилии заглавными.
     *
     * Срез **многобайтный**: однобайтный `substr` вернул бы половину символа
     * кириллицы, и на месте инициалов оказался бы мусор (критерий 96).
     * Отчество в инициалах не участвует.
     */
    public static function initials(string $givenName, string $familyName): string
    {
        return mb_strtoupper(
            mb_substr($givenName, 0, 1).mb_substr($familyName, 0, 1),
        );
    }
}

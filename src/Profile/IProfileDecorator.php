<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Profile;

/**
 * Точка расширения профиля.
 *
 * Продукт установки, которому нужны собственные поля рядом с профилем,
 * подменяет привязку у себя — и пакет при этом не правит. Сервис уборки
 * своей реализации не заводит: ролей у него нет (PRD 12.1).
 */
interface IProfileDecorator
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function decorate(array $profile): array;
}

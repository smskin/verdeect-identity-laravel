<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Api;

interface IUserResolver
{
    /**
     * Разрешение идентификаторов в имена.
     *
     * Операция **пакетная**: обращение в цикле по строкам списка — ошибка
     * реализации (справка 7.1, критерий 84).
     *
     * @param  list<string>  $subs
     * @return array<string, ResolvedUser>
     */
    public function resolve(array $subs): array;
}

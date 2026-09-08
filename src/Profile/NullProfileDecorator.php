<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Profile;

/**
 * Пустая реализация точки расширения профиля.
 */
final class NullProfileDecorator implements IProfileDecorator
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function decorate(array $profile): array
    {
        return $profile;
    }
}

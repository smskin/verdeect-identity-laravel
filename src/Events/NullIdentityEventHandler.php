<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events;

use Verdeect\IdentityIntegration\Events\DTO\IdentityEvent;

/**
 * Пустая реализация точек расширения.
 */
final class NullIdentityEventHandler implements IIdentityEventHandler
{
    public function onProfileChanged(IdentityEvent $event): void {}

    public function onRightsChanged(IdentityEvent $event): void {}

    public function onSessionTerminated(IdentityEvent $event): void {}

    public function onNavigationChanged(IdentityEvent $event): void {}
}

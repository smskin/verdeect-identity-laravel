<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events;

use Verdeect\IdentityIntegration\Events\DTO\IdentityEvent;

/**
 * Точки расширения обработчика сообщений.
 *
 * Пакет предметной области не знает (PRD 12.1): всё, что продукту нужно
 * сделать сверх сброса кэшей и отметок, он делает своей реализацией.
 * Сервис уборки своей **не заводит** — ролей у него нет.
 */
interface IIdentityEventHandler
{
    public function onProfileChanged(IdentityEvent $event): void;

    public function onRightsChanged(IdentityEvent $event): void;

    public function onSessionTerminated(IdentityEvent $event): void;

    public function onNavigationChanged(IdentityEvent $event): void;
}

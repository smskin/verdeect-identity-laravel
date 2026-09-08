<?php

declare(strict_types=1);

namespace Verdeect\IdentityIntegration\Events;

use Illuminate\Support\Facades\Log;
use Verdeect\IdentityIntegration\Api\CacheInvalidator;
use Verdeect\IdentityIntegration\Events\DTO\IdentityEvent;

/**
 * Применение сообщения установки.
 *
 * Вынесено из консольной команды, чтобы поведение проверялось без брокера:
 * тесты подают сюда разобранное сообщение.
 *
 * **Ничего не запрашивает у установки и не трогает чужие сессии**
 * (критерий 73): ставит отметки и сбрасывает свои кэши. Гашение выполняет
 * ближайший запрос самого пользователя.
 */
final class IdentityEventDispatcher
{
    public const TYPE_PROFILE_CHANGED = 'user.profile.changed';

    public const TYPE_RIGHTS_CHANGED = 'user.rights.changed';

    public const TYPE_SESSION_TERMINATED = 'user.session.terminated';

    public const TYPE_NAVIGATION_CHANGED = 'navigation.changed';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_PROFILE_CHANGED,
        self::TYPE_RIGHTS_CHANGED,
        self::TYPE_SESSION_TERMINATED,
        self::TYPE_NAVIGATION_CHANGED,
    ];

    public function __construct(
        private readonly SessionMarks $marks,
        private readonly CacheInvalidator $caches,
        private readonly IIdentityEventHandler $handler,
    ) {}

    /**
     * @return bool сообщение распознано
     */
    public function dispatch(IdentityEvent $event): bool
    {
        switch ($event->type) {
            case self::TYPE_PROFILE_CHANGED:
                if ($event->sub !== null) {
                    $this->caches->forgetProfile($event->sub);
                }

                $this->handler->onProfileChanged($event);

                break;

            case self::TYPE_RIGHTS_CHANGED:
                if ($event->sub !== null) {
                    /*
                     * Состав пунктов рейла зависит от роли, поэтому его кэш
                     * сбрасывается вместе с правами (справка 9.1,
                     * критерий 87). Токен пометки требующим обмена
                     * не требует: права читаются из него при каждом запросе,
                     * а не копируются в сессию.
                     */
                    $this->caches->forgetServices($event->sub);
                }

                $this->handler->onRightsChanged($event);

                break;

            case self::TYPE_SESSION_TERMINATED:
                /*
                 * Сообщение **с `sid`** гасит одну названную сессию,
                 * **без `sid`** — все сессии `sub`. Различие обеспечивается
                 * ключом отметки, а не веткой логики (справка 8.1).
                 */
                if ($event->sid !== null) {
                    $this->marks->markSid($event->sid, $event->occurredAt);
                } elseif ($event->sub !== null) {
                    $this->marks->markSub($event->sub, $event->occurredAt);
                }

                $this->handler->onSessionTerminated($event);

                break;

            case self::TYPE_NAVIGATION_CHANGED:
                $this->marks->markProduct($event->occurredAt);
                $this->handler->onNavigationChanged($event);

                break;

            default:
                /*
                 * Неизвестный тип подтверждается и не копится в очереди:
                 * установка вправе завести новый тип, и продукт, встающий
                 * на нём колом, остановил бы обработку остальных.
                 */
                Log::warning('[IdentityEventDispatcher.dispatch] unknown type', [
                    'type' => $event->type,
                    'id' => $event->id,
                ]);

                return false;
        }

        Log::debug('[IdentityEventDispatcher.dispatch] message handled', [
            'type' => $event->type,
            'sub' => $event->sub,
            'sid' => $event->sid,
            'id' => $event->id,
        ]);

        return true;
    }
}

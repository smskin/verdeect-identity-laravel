<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Api\NavigationClient;
use Verdeect\IdentityIntegration\Events\DTO\IdentityEvent;
use Illuminate\Support\Facades\Route;
use Verdeect\IdentityIntegration\Events\IdentityEventDispatcher;
use Verdeect\IdentityIntegration\Http\Middleware\EnforceSessionMarks;
use Verdeect\IdentityIntegration\Http\Middleware\RequireIdentitySession;
use Verdeect\IdentityIntegration\Events\SessionMarks;
use Verdeect\IdentityIntegration\Profile\ProfileProvider;
use Verdeect\IdentityIntegration\Tokens\TokenStore;

function identityEvent(string $type, array $overrides = []): IdentityEvent
{
    return IdentityEvent::fromArray([
        'id' => 'msg-1',
        'type' => $type,
        'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        ...$overrides,
    ]);
}

/**
 * Критерий 73: обработчик **ничего не запрашивает у установки**.
 *
 * Обращение из обработчика очереди означало бы, что недоступность установки
 * останавливает разбор очереди, а всплеск сообщений превращается во всплеск
 * запросов к ней.
 */
it('only marks, never calls identity', function (): void {
    Http::fake();

    $dispatcher = app(IdentityEventDispatcher::class);

    foreach (IdentityEventDispatcher::TYPES as $type) {
        $dispatcher->dispatch(identityEvent($type, ['sub' => 'sub-1', 'sid' => 'sid-1']));
    }

    Http::assertNothingSent();
});

/**
 * Критерий 73 (вторая половина): обработчик не трогает чужие сессии.
 *
 * Токены остаются на месте — их удаляет запрос самого пользователя,
 * увидевший отметку.
 */
it('does not read or write other sessions', function (): void {
    Http::fake();
    identityAuthenticate('sid-1', 'sub-1');

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_SESSION_TERMINATED, ['sub' => 'sub-1']),
    );

    expect(app(TokenStore::class)->get('sid-1'))->not->toBeNull();
});

/**
 * Критерий 75: отметка — время, а не снимаемый признак.
 *
 * Признак, удаляемый при чтении, забрало бы первое же устройство,
 * и вторая сессия того же человека продолжила бы работать.
 */
it('keeps the mark after reading', function (): void {
    Http::fake();

    $authenticatedAt = CarbonImmutable::now()->subMinute();

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_SESSION_TERMINATED, ['sub' => 'sub-1']),
    );

    $marks = app(SessionMarks::class);

    expect($marks->isStale('sub-1', 'sid-first', $authenticatedAt))->toBeTrue()
        ->and($marks->isStale('sub-1', 'sid-second', $authenticatedAt))->toBeTrue();
});

it('marks only the named session when sid is present', function (): void {
    Http::fake();

    $authenticatedAt = CarbonImmutable::now()->subMinute();

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_SESSION_TERMINATED, [
            'sub' => 'sub-1',
            'sid' => 'sid-named',
        ]),
    );

    $marks = app(SessionMarks::class);

    expect($marks->isStale('sub-1', 'sid-named', $authenticatedAt))->toBeTrue()
        ->and($marks->isStale('sub-1', 'sid-other', $authenticatedAt))->toBeFalse();
});

/**
 * Критерий 74: сессия гасится ближайшим запросом пользователя.
 */
it('destroys a stale session on the next request', function (): void {
    identityFakeHttp();
    identityAuthenticate('sid-1', 'sub-1');

    // Сессия установлена «минуту назад», отметка — сейчас.
    session(['identity.authenticated_at' => CarbonImmutable::now()->subMinute()->toIso8601String()]);

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_SESSION_TERMINATED, ['sub' => 'sub-1']),
    );

    /*
     * Маршрут объявляется здесь, а не берётся у приложения: собственных
     * экранов у пакета нет, а проверяется именно связка middleware —
     * сверка отметок гашения и требование живой сессии.
     */
    Route::middleware(['web', EnforceSessionMarks::class, RequireIdentitySession::class])
        ->get('/probe', static fn (): string => 'ok');

    $this->get('/probe')->assertRedirect(route('identity.login'));

    expect(app(TokenStore::class)->get('sid-1'))->toBeNull()
        ->and(session()->has('identity.sid'))->toBeFalse();
});

/**
 * Дубли возможны (гарантия «хотя бы один раз»), но состояния сообщение
 * не несёт: повторная доставка даёт тот же результат. Таблицы обработанных
 * сообщений поэтому нет.
 */
it('is idempotent on duplicates', function (): void {
    Http::fake();

    $event = identityEvent(IdentityEventDispatcher::TYPE_SESSION_TERMINATED, ['sub' => 'sub-1']);

    $dispatcher = app(IdentityEventDispatcher::class);
    $dispatcher->dispatch($event);
    $dispatcher->dispatch($event);

    expect(app(SessionMarks::class)->isStale('sub-1', null, CarbonImmutable::now()->subMinute()))
        ->toBeTrue();
});

/**
 * Критерий 88: `navigation.changed` — единственный тип без `sub` и `sid`;
 * отметка одна на продукт, ставится по `occurred_at` самого сообщения.
 */
it('marks the product once for navigation changed', function (): void {
    Http::fake();

    $occurredAt = CarbonImmutable::now()->addMinutes(5);

    app(IdentityEventDispatcher::class)->dispatch(IdentityEvent::fromArray([
        'id' => 'msg-nav',
        'type' => IdentityEventDispatcher::TYPE_NAVIGATION_CHANGED,
        'occurred_at' => $occurredAt->toIso8601String(),
    ]));

    expect(app(SessionMarks::class)->productMark()?->toIso8601String())
        ->toBe($occurredAt->toIso8601String());
});

/**
 * Критерий 87: смена прав сбрасывает кэш рейла — состав пунктов зависит
 * от роли, и смена обязана доходить до рейла без нового входа.
 */
it('invalidates rail cache on rights change', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);
    $client->for('sub-1');

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_RIGHTS_CHANGED, ['sub' => 'sub-1']),
    );

    $client->for('sub-1');

    expect(identityNavigationRequests())->toBe(2);
});

/**
 * Смена состава навигации обесценивает **обе** записи сразу.
 *
 * Отметка `navigation.changed` одна на установку и адресату не принадлежит:
 * состав меняется для всех, включая тех, кто не входил. Гостевая запись
 * без этого держалась бы до истечения срока, и новый пункт увидели бы
 * только вошедшие.
 */
it('invalidates both the guest and the user rail on navigation change', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);
    $client->forGuest();
    $client->for('sub-1');

    app(IdentityEventDispatcher::class)->dispatch(IdentityEvent::fromArray([
        'id' => 'msg-nav-both',
        'type' => IdentityEventDispatcher::TYPE_NAVIGATION_CHANGED,
        'occurred_at' => CarbonImmutable::now()->addMinute()->toIso8601String(),
    ]));

    $client->forGuest();
    $client->for('sub-1');

    expect(identityGuestNavigationRequests())->toBe(2)
        ->and(identityNavigationRequests())->toBe(2);
});

/**
 * Смена прав одного человека гостевой записи **не** касается.
 *
 * Гостевой набор от роли не зависит — роли у гостя нет, — и сброс общей записи
 * на каждое `user.rights.changed` означал бы поход к установке за одним и тем
 * же ответом на каждое изменение прав любого сотрудника.
 */
it('leaves the guest rail alone on rights change', function (): void {
    identityFakeNavigation();

    $client = app(NavigationClient::class);
    $client->forGuest();

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_RIGHTS_CHANGED, ['sub' => 'sub-1']),
    );

    $client->forGuest();

    expect(identityGuestNavigationRequests())->toBe(1);
});

/**
 * Критерий 77: показанное имя обновляется после смены профиля.
 */
it('refreshes displayed name after profile change', function (): void {
    identityFakeHttp([
        identityBaseUrl().'/userinfo' => Http::sequence()
            ->push([
                'sub' => 'sub-1',
                'name' => 'Михайлов Сергей Петрович',
                'given_name' => 'Сергей',
                'family_name' => 'Михайлов',
            ])
            ->push([
                'sub' => 'sub-1',
                'name' => 'Петрова Мария Ивановна',
                'given_name' => 'Мария',
                'family_name' => 'Петрова',
            ]),
    ]);

    identityAuthenticate('sid-1', 'sub-1');

    expect(app(ProfileProvider::class)->current()->name)->toBe('Михайлов Сергей Петрович');

    app(IdentityEventDispatcher::class)->dispatch(
        identityEvent(IdentityEventDispatcher::TYPE_PROFILE_CHANGED, ['sub' => 'sub-1']),
    );

    expect(app(ProfileProvider::class)->current()->name)->toBe('Петрова Мария Ивановна');
});

it('acknowledges an unknown type without acting', function (): void {
    Http::fake();

    $handled = app(IdentityEventDispatcher::class)->dispatch(
        identityEvent('user.unknown.thing', ['sub' => 'sub-1']),
    );

    expect($handled)->toBeFalse();
});

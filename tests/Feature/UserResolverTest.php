<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Api\IUserResolver;

/**
 * Критерий 84: предел пакета — 100, превышение установка **отклоняет,
 * а не усекает** (справка 7.1), поэтому разбиение выполняется у нас.
 */
it('splits long lists into batches of 100', function (): void {
    identityFakeResolve();

    $subs = array_map(static fn (int $index): string => 'sub-'.$index, range(1, 250));

    $resolved = app(IUserResolver::class)->resolve($subs);

    expect($resolved)->toHaveCount(250)
        ->and(identityResolveRequests())->toBe(3);
});

it('never truncates', function (): void {
    identityFakeResolve();

    $subs = array_map(static fn (int $index): string => 'sub-'.$index, range(1, 250));

    $resolved = app(IUserResolver::class)->resolve($subs);

    $unresolved = array_keys(array_filter(
        $resolved,
        static fn ($user): bool => ! $user->isResolved,
    ));

    expect(array_keys($resolved))->toBe($subs)
        ->and($unresolved)->toBe([]);
});

/**
 * Критерий 85: идентификатор, которому ничего не соответствует, выводится
 * как есть. Установка отвечает на такой запрос `200` с пустым списком,
 * а не отказом (проверено 04.09.2026), и отличать это от ошибки обязательно.
 */
it('returns unresolved subs as is', function (): void {
    identityFakeResolve(Http::response(['users' => []]));

    $resolved = app(IUserResolver::class)->resolve(['sub-unknown']);

    expect($resolved['sub-unknown']->isResolved)->toBeFalse()
        ->and($resolved['sub-unknown']->name)->toBe('sub-unknown');
});

it('serves repeated calls from cache', function (): void {
    identityFakeResolve();

    $resolver = app(IUserResolver::class);

    $resolver->resolve(['sub-1', 'sub-2']);
    $resolver->resolve(['sub-1', 'sub-2']);

    expect(identityResolveRequests())->toBe(1);
});

it('deduplicates and preserves request order', function (): void {
    identityFakeResolve();

    $resolved = app(IUserResolver::class)->resolve(['sub-2', 'sub-1', 'sub-2']);

    expect(array_keys($resolved))->toBe(['sub-2', 'sub-1']);
});

/**
 * Недоступность установки не прекращает отрисовку страницы: список выводится
 * с идентификаторами вместо имён (справка 9, пункт 8).
 */
it('degrades when identity is down', function (): void {
    identityFakeResolve(Http::response(['error' => 'rate_limit_exceeded'], 429));

    $resolved = app(IUserResolver::class)->resolve(['sub-1', 'sub-2']);

    expect($resolved)->toHaveCount(2)
        ->and($resolved['sub-1']->isResolved)->toBeFalse()
        ->and($resolved['sub-1']->name)->toBe('sub-1');
});

it('builds name forms from resolved rows', function (): void {
    identityFakeResolve();

    $resolved = app(IUserResolver::class)->resolve(['sub-1']);

    expect($resolved['sub-1']->name)->toBe('Михайлов Сергей Петрович')
        ->and($resolved['sub-1']->shortName)->toBe('Сергей Михайлов')
        ->and($resolved['sub-1']->initials)->toBe('СМ');
});

<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Support\IdentityOrigin;

/**
 * Origin установки для политики содержимого продукта.
 */
it('resolves scheme, host and port', function (): void {
    config(['identity.base_url' => 'https://id.example.test:8443/some/path']);

    expect(app(IdentityOrigin::class)->resolve())
        ->toBe('https://id.example.test:8443');
});

it('omits the port when the address has none', function (): void {
    config(['identity.base_url' => 'https://id.example.test']);

    expect(app(IdentityOrigin::class)->resolve())->toBe('https://id.example.test');
});

/**
 * Пустой адрес — не отказ: политика содержимого обязана строиться
 * и на неподнятом окружении, иначе сборка конфигурации валилась бы
 * на пустом `.env`.
 */
it('returns an empty origin when the address is not set', function (): void {
    config(['identity.base_url' => null]);

    expect(app(IdentityOrigin::class)->resolve())->toBe('');
});

/**
 * Заданный, но неразбираемый адрес тоже не роняет политику — но остаётся
 * в журнале предупреждением: это ошибка настройки.
 */
it('returns an empty origin when the address is not parsable', function (): void {
    config(['identity.base_url' => 'не адрес вовсе']);

    expect(app(IdentityOrigin::class)->resolve())->toBe('');
});

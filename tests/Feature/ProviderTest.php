<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Verdeect\IdentityIntegration\Api\IUserResolver;
use Verdeect\IdentityIntegration\Discovery\IDiscoveryClient;
use Verdeect\IdentityIntegration\Discovery\IJwksProvider;
use Verdeect\IdentityIntegration\IdentityServiceProvider;

it('merges package config', function (): void {
    expect(config('identity.base_url'))->toBe('https://id.example.test')
        ->and(config('identity.web.scopes'))->toBe(['openid', 'profile'])
        ->and(config('identity.service.scopes'))->toBe(['users:read']);
});

/**
 * Публикуется **только настройка**.
 *
 * Компоненты навигации этот пакет не публикует: они приходят продукту
 * npm-пакетом `@verdeect/identity-nav-vue`. Опубликованная копия
 * расходилась бы с эталоном на первой же правке — ради этого компоненты
 * и вынесены отдельно.
 */
it('publishes the config and no component copies', function (): void {
    $groups = ServiceProvider::publishableGroups();

    expect($groups)->toContain('identity-config')
        ->and($groups)->not->toContain('identity-components');
});

it('binds package interfaces', function (): void {
    expect(app(IDiscoveryClient::class))->toBeInstanceOf(IDiscoveryClient::class)
        ->and(app(IJwksProvider::class))->toBeInstanceOf(IJwksProvider::class)
        ->and(app(IUserResolver::class))->toBeInstanceOf(IUserResolver::class);
});

it('registers the package provider', function (): void {
    expect(app()->getLoadedProviders())->toHaveKey(IdentityServiceProvider::class);
});

/**
 * Граница держится с обеих сторон.
 *
 * Раньше набор проверял её со стороны продукта: в `app/` не должно быть
 * ни одного класса, выполняющего OAuth, чтение JWKS, хранение токенов или
 * подписку на очередь. После выемки пакета в отдельный репозиторий проверять
 * там нечего — приложения рядом нет, — и условие переворачивается: **ни один
 * класс пакета не ссылается на пространство имён приложения**.
 *
 * Проверка не косметическая: ссылка на `App\` привязала бы библиотеку
 * к одному продукту и обнаружилась бы только при установке во второй.
 */
it('keeps the application namespace out of the package', function (): void {
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            dirname(__DIR__, 2).'/src',
            FilesystemIterator::SKIP_DOTS,
        ),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        if (preg_match('~\bApp\\\\[A-Z]~', $code) === 1) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

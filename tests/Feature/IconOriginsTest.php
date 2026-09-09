<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Verdeect\IdentityIntegration\Support\IconOrigins;

/**
 * Origin'ы хранилища иконок для политики содержимого продукта.
 *
 * Обязанность продукта — пропустить их в `img-src`, — существовала
 * с выпуска 0.2, но выполнить её было нечем: адрес хранилища не приходил
 * ни полем ответа, ни настройкой. Продукт, обновившийся на 0.2, показывал
 * заглушки вместо иконок, и причина была видна только в консоли обозревателя.
 * Обнаружено на продукте установки 09.09.2026.
 */
it('resolves the icon storage origin from the rail', function (): void {
    identityFakeServices();
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())
        ->toBe(['https://storage.identity.test']);
});

/**
 * Хранилище отвечает по своему адресу, установка — по своему: в разработке
 * это разные порты одного узла, в эксплуатации — разные узлы. Origin
 * установки политике всё так же нужен (логотип), но подменить им origin
 * хранилища нельзя.
 */
it('does not confuse the storage origin with the installation origin', function (): void {
    identityFakeServices();
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())
        ->not->toContain(identityBaseUrl());
});

/**
 * Порт — часть origin: `http://localhost:59000` и `http://localhost:81`
 * для политики содержимого разные источники.
 */
it('keeps the port as part of the origin', function (): void {
    identityFakeServices(Http::response(identityServicesResponseWithIcon(
        'http://localhost:59000/identity/navigation/icons/item.svg?signature=stub',
    )));
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())
        ->toBe(['http://localhost:59000']);
});

/**
 * Повторов нет: пунктов в рейле десятки, ведро у них общее, и перечислять
 * один и тот же источник по разу на пункт незачем.
 */
it('lists an origin once', function (): void {
    identityFakeServices();
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())->toHaveCount(1);
});

/**
 * Пустая ссылка законна и означает «ссылки не пришло»: рейл показывает такой
 * пункт первой буквой имени, и разрешать в политике нечего.
 */
it('ignores an empty icon url', function (): void {
    identityFakeServices(Http::response(identityServicesResponseWithIcon('')));
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())->toBe([]);
});

/**
 * Без сессии рейла нет, а значит нет и изображений из хранилища: разрешать
 * origin на такой странице не за чем.
 */
it('returns nothing without a session', function (): void {
    identityFakeHttp();

    expect(app(IconOrigins::class)->forSession())->toBe([]);
});

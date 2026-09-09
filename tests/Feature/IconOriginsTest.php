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
    identityFakeNavigation();
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
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())
        ->not->toContain(identityBaseUrl());
});

/**
 * Порт — часть origin: `http://localhost:59000` и `http://localhost:81`
 * для политики содержимого разные источники.
 */
it('keeps the port as part of the origin', function (): void {
    identityFakeNavigation(Http::response(identityNavigationResponseWithIcon(
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
    identityFakeNavigation();
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())->toHaveCount(1);
});

/**
 * Пустая ссылка законна и означает «ссылки не пришло»: рейл показывает такой
 * пункт первой буквой имени, и разрешать в политике нечего.
 */
it('ignores an empty icon url', function (): void {
    identityFakeNavigation(Http::response(identityNavigationResponseWithIcon('')));
    identityAuthenticate('sid-1', 'sub-1');

    expect(app(IconOrigins::class)->forSession())->toBe([]);
});

/**
 * **На гостевой странице origin'ы не пусты.**
 *
 * Прежде список без сессии был пуст, и посылка была верна: рейла нет — значит,
 * нет и изображений из хранилища. С гостевым рейлом посылка перестала быть
 * верной: изображения есть, а пустой список означал бы, что обозреватель
 * заблокирует **все** иконки на каждой странице без входа, показав причину
 * только в своей консоли.
 */
it('resolves the icon storage origin for a guest', function (): void {
    identityFakeNavigation();

    expect(app(IconOrigins::class)->forSession())
        ->toBe(['https://storage.identity.test']);
});

/**
 * Недоступность установки политику содержимого не ломает: разрешать нечего,
 * потому что и рисовать нечего.
 */
it('returns nothing when the guest rail is unavailable', function (): void {
    identityFakeNavigation(null, Http::response(['error' => 'server_error'], 503));

    expect(app(IconOrigins::class)->forSession())->toBe([]);
});

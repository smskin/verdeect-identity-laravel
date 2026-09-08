<?php

declare(strict_types=1);

use Verdeect\IdentityIntegration\Profile\NameForms;

it('builds short name', function (): void {
    expect(NameForms::short('Сергей', 'Михайлов'))->toBe('Сергей Михайлов');
});

/**
 * Критерий 96: срез первой буквы **многобайтный**.
 *
 * Однобайтный `substr` вернул бы половину символа кириллицы, и на месте
 * инициалов оказался бы мусор. Проверяется длина в символах, а не в байтах:
 * в байтах верный ответ и неверный различаются не всегда.
 */
it('builds multibyte initials', function (): void {
    $initials = NameForms::initials('Сергей', 'Михайлов');

    expect($initials)->toBe('СМ')
        ->and(mb_strlen($initials))->toBe(2);
});

it('ignores middle name in initials', function (): void {
    // Отчество в форму не передаётся вовсе: подписи из трёх букв
    // у продуктов установки нет (справка 9.1).
    expect(NameForms::initials('Иван', 'Иванов'))->toBe('ИИ');
});

it('uppercases lowercase names', function (): void {
    expect(NameForms::initials('сергей', 'михайлов'))->toBe('СМ');
});

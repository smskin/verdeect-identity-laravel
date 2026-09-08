<?php

declare(strict_types=1);

/**
 * Настройки интеграции с identity.
 *
 * Адреса эндпоинтов здесь **отсутствуют намеренно**: они читаются из документа
 * обнаружения установки (справка, раздел 4; PRD 12.2, критерий 72). Исключение
 * одно — пути прикладного интерфейса, в документ обнаружения не входящие.
 *
 * Секреты значений по умолчанию не имеют: пустой секрет обязан приводить
 * к внятному отказу, а не к молчаливой работе с чужими учётными данными.
 */
return [
    'base_url' => env('IDENTITY_BASE_URL'),

    'discovery_ttl' => (int) env('IDENTITY_DISCOVERY_TTL', 3600),
    'jwks_ttl' => (int) env('IDENTITY_JWKS_TTL', 3600),

    /*
     * Веб-клиент: поток кода авторизации с PKCE. Области — ровно
     * `openid profile`; `email` не запрашивается, адрес сервису не нужен.
     */
    'web' => [
        'client_id' => env('IDENTITY_WEB_CLIENT_ID'),
        'client_secret' => env('IDENTITY_WEB_CLIENT_SECRET'),
        'scopes' => ['openid', 'profile'],
        'redirect_uri' => env('IDENTITY_REDIRECT_URI'),
        'post_logout_redirect_uri' => env('IDENTITY_POST_LOGOUT_REDIRECT_URI'),
    ],

    /*
     * Служебный клиент: учётные данные клиента. Ресурсом выступает сам
     * identity (справка 7), поэтому `resource` равен `base_url`.
     */
    'service' => [
        'client_id' => env('IDENTITY_SERVICE_CLIENT_ID'),
        'client_secret' => env('IDENTITY_SERVICE_CLIENT_SECRET'),
        'scopes' => ['users:read'],
    ],

    'cache' => [
        'store' => env('IDENTITY_CACHE_STORE', 'redis'),
        'profile_ttl' => (int) env('IDENTITY_PROFILE_TTL', 300),
        'resolve_ttl' => (int) env('IDENTITY_RESOLVE_TTL', 3600),
        'services_ttl' => (int) env('IDENTITY_SERVICES_TTL', 3600),
    ],

    /*
     * Предел пакета разрешения имён. Превышение установка отклоняет,
     * а не усекает (справка 7.1), поэтому длинные списки разбиваются
     * на стороне пакета.
     */
    'resolve_batch_limit' => (int) env('IDENTITY_RESOLVE_BATCH_LIMIT', 100),

    /*
     * Доля срока жизни токена, на которой начинается упреждающий обмен
     * (справка 9, пункт 5). Конкретных чисел не предполагать: `expires_in`
     * читается из ответа, администратор вправе задать от 1 до 60 минут.
     */
    'refresh_ahead_ratio' => 1 / 3,

    /*
     * Предельный срок сессии входа установки — верхняя граница хранения
     * токенов и отметок гашения (справка 11).
     */
    'session_ttl_days' => (int) env('IDENTITY_SESSION_TTL_DAYS', 90),

    /*
     * Брокер установки.
     *
     * Брокер общий на установку: его поднимает состав identity-service
     * и обслуживает виртуальный хост организации. Продукт установки только
     * потребляет из него сообщения identity и своего экземпляра не поднимает.
     *
     * Имена переменных совпадают с identity-service (`BROKER_*`) намеренно,
     * чтобы одинаково читались в обеих установках.
     */
    'broker' => [
        'host' => env('BROKER_HOST', '127.0.0.1'),
        'port' => (int) env('BROKER_PORT', 5672),
        'user' => env('BROKER_USER'),
        'password' => env('BROKER_PASSWORD'),
        'vhost' => env('BROKER_VHOST', '/'),
        'exchange' => env('BROKER_EXCHANGE', 'identity.events'),

        /*
         * Имя очереди принадлежит продукту, и умолчания у него нет: значение
         * из библиотеки увело бы продукт на очередь соседа молча. Пустое имя
         * обязано приводить к внятному отказу при запуске потребителя.
         */
        'queue' => env('BROKER_QUEUE'),

        /*
         * Метка потребителя видна в панели брокера и нужна, чтобы отличать
         * продукты друг от друга. По умолчанию выводится из имени очереди:
         * второго места, где повторять имя продукта, заводить незачем.
         */
        'consumer_tag' => env('BROKER_CONSUMER_TAG'),
    ],

    'http' => [
        'timeout' => (int) env('IDENTITY_HTTP_TIMEOUT', 10),

        /*
         * Подмена узла **при соединении** — то же, что `--resolve` у curl.
         * Перечень записей `узел:порт:адрес` через запятую; цель может быть
         * именем.
         *
         * Нужна только в разработке: установка объявляет себя по адресу
         * `http://localhost:81`, а внутри контейнера `localhost` — сам
         * контейнер. Переписывать узел в адресе нельзя (PRD 12.2), поэтому
         * меняется лишь то, куда открывается соединение.
         *
         * В эксплуатации значение пусто: там работает обычный DNS.
         */
        'resolve' => env('IDENTITY_HTTP_RESOLVE', ''),
    ],
];

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Verdeect\IdentityIntegration\Http\Controllers\CallbackController;
use Verdeect\IdentityIntegration\Http\Controllers\LoginController;
use Verdeect\IdentityIntegration\Http\Controllers\LogoutController;

/*
 * Маршруты входа и выхода.
 *
 * Группа `web` обязательна: поток кода хранит `state`, `nonce` и проверочный
 * код в серверной сессии, а выход требует проверки CSRF.
 */
Route::middleware('web')->group(function (): void {
    Route::get('/auth/login', LoginController::class)->name('identity.login');
    Route::get('/auth/callback', CallbackController::class)->name('identity.callback');

    /*
     * Выход — `POST`, а не `GET`: по ссылке из письма или чужой страницы
     * выход вызвать нельзя.
     */
    Route::post('/auth/logout', LogoutController::class)->name('identity.logout');
});

<?php

use App\Http\Controllers\Auth\WorkOsAuthController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MapAdminController;
use App\Http\Controllers\MapReadController;
use App\Http\Controllers\NativeDeviceController;
use App\Http\Controllers\PaneAdminInvitationController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReleaseMetadataController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TermsAcceptanceController;
use App\Http\Controllers\UserAnonymisationController;
use App\Http\Controllers\UserInvitationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// index
Route::get('/', [Controller::class, 'index']);
Route::get('/docs', [Controller::class, 'docs'])->name('docs');
Route::get('/docs/openapi.json', [Controller::class, 'openApi'])->name('docs.openapi');
Route::get('/status', [Controller::class, 'status'])->name('status');

// Versioned browser session API used by Latte.
Route::prefix('/api/v1')->name('api.v1.')->group(function () {
    Route::get('/release', [ReleaseMetadataController::class, 'show'])->name('release.show');
    Route::post('/csrf-cookie', [WorkOsAuthController::class, 'csrfCookie'])->name('csrf-cookie');
    Route::post('/auth/login-intents', [WorkOsAuthController::class, 'loginIntent'])->name('auth.login-intents');
    Route::post('/auth/callback', [WorkOsAuthController::class, 'completeV1Callback'])
        ->block(10, 10)
        ->name('auth.callback');

    // Native clients (Pane#130): no cookie, Origin or CSRF; the code verifier or refresh token is the proof.
    Route::post('/auth/native/exchange', [WorkOsAuthController::class, 'exchangeNative'])
        ->middleware('throttle:20,1')
        ->name('auth.native.exchange');
    Route::post('/auth/native/refresh', [NativeDeviceController::class, 'refresh'])
        ->middleware('throttle:60,1')
        ->name('auth.native.refresh');

    // `device` first: a request with a valid bearer token is a native client; otherwise the cookie session applies.
    Route::middleware('auth:device,web')->group(function () {
        Route::get('/session', [WorkOsAuthController::class, 'session'])->name('session.show');
        Route::delete('/session', [WorkOsAuthController::class, 'destroySession'])->name('session.destroy');
        Route::get('/session/devices', [NativeDeviceController::class, 'list'])->name('session.devices.index');
        Route::delete('/session/devices/{deviceId}', [NativeDeviceController::class, 'destroy'])->name('session.devices.destroy');
        Route::put('/session/terms', [TermsAcceptanceController::class, 'update'])->name('session.terms.update');
        Route::get('/installation/pane-admin-invitations', [PaneAdminInvitationController::class, 'list'])
            ->name('installation.pane-admin-invitations.index');
        Route::post('/installation/pane-admin-invitations', [PaneAdminInvitationController::class, 'store'])
            ->name('installation.pane-admin-invitations.store');
        Route::delete('/installation/pane-admin-invitations/{invitationId}', [PaneAdminInvitationController::class, 'destroy'])
            ->name('installation.pane-admin-invitations.destroy');
        Route::get('/installation/user-invitations', [UserInvitationController::class, 'list'])
            ->name('installation.user-invitations.index');
        Route::post('/installation/user-invitations', [UserInvitationController::class, 'store'])
            ->name('installation.user-invitations.store');
        Route::post('/installation/user-invitations/{invitationId}/resend', [UserInvitationController::class, 'resend'])
            ->name('installation.user-invitations.resend');
        Route::delete('/installation/user-invitations/{invitationId}', [UserInvitationController::class, 'destroy'])
            ->name('installation.user-invitations.destroy');
        Route::delete('/users/{userId}', [UserAnonymisationController::class, 'destroy'])
            ->name('users.anonymise');
        Route::get('/push', [PushSubscriptionController::class, 'show'])->name('push.config');
        Route::get('/push/subscriptions', [PushSubscriptionController::class, 'list'])->name('push.subscriptions.index');
        Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->name('push.subscriptions.store');
        Route::delete('/push/subscriptions/{subscriptionId}', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');
        Route::get('/maps', [MapReadController::class, 'show'])->name('maps.show');
        // Maps admin API (Pane#157). The fixed `menu` and `functions` routes come before `{source}/{table}`.
        Route::get('/maps/menu', [MapAdminController::class, 'menu'])->name('maps.menu.show');
        Route::put('/maps/menu', [MapAdminController::class, 'saveMenu'])->name('maps.menu.save');
        Route::get('/maps/menu/versions', [MapAdminController::class, 'menuVersions'])->name('maps.menu.versions');
        Route::post('/maps/menu/versions/{version}/restore', [MapAdminController::class, 'restoreMenu'])->name('maps.menu.restore');
        Route::patch('/maps/functions/{key}', [MapAdminController::class, 'switchFunction'])->name('maps.functions.switch');
        Route::put('/maps/{source}/{table}', [MapAdminController::class, 'saveTable'])->name('maps.table.save');
        Route::get('/maps/{source}/{table}/versions', [MapAdminController::class, 'tableVersions'])->name('maps.table.versions');
        Route::post('/maps/{source}/{table}/versions/{version}/restore', [MapAdminController::class, 'restoreTable'])->name('maps.table.restore');
        Route::get('/maps/{source}/{table}', [MapReadController::class, 'table'])->name('maps.table');
        // App settings (Pane#158); the public ones are served inside GET /maps.
        Route::get('/settings', [SettingsController::class, 'list'])->name('settings.index');
        Route::get('/settings/{key}', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings/{key}', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/{key}/upload', [SettingsController::class, 'upload'])->name('settings.upload');
    });
});

// WorkOS auth
Route::get('/auth/login-url', [WorkOsAuthController::class, 'loginUrl'])->name('auth.login-url');
Route::post('/auth/callback', [WorkOsAuthController::class, 'completeCallback'])
    ->block(10, 10)
    ->name('auth.callback.complete');
Route::get('/auth/login', [WorkOsAuthController::class, 'login'])->name('login');
Route::get('/auth/callback', [WorkOsAuthController::class, 'callback'])->name('auth.callback');
Route::get('/auth/user', [WorkOsAuthController::class, 'user'])->middleware('auth')->name('auth.user');

// all stories (crud)
Route::middleware('auth:device,web')->group(function () {
    Route::match(['get', 'post'], '/{story}/{subject}', [Controller::class, 'runStory']);
    Route::match(['get', 'put', 'delete'], '/{story}/{subject}/{key}', [Controller::class, 'runStory']);
});

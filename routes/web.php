<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\Auth\WorkOsAuthController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OrganizationInvitationController;
use App\Http\Controllers\OrganizationMembershipController;
use App\Http\Controllers\PaneAdminInvitationController;
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

// Versioned browser session API used by Latte.
Route::prefix('/api/v1')->name('api.v1.')->group(function () {
    Route::post('/csrf-cookie', [WorkOsAuthController::class, 'csrfCookie'])->name('csrf-cookie');
    Route::post('/auth/login-intents', [WorkOsAuthController::class, 'loginIntent'])->name('auth.login-intents');
    Route::post('/auth/callback', [WorkOsAuthController::class, 'completeV1Callback'])
        ->block(10, 10)
        ->name('auth.callback');

    Route::middleware('auth')->group(function () {
        Route::get('/session', [WorkOsAuthController::class, 'session'])->name('session.show');
        Route::delete('/session', [WorkOsAuthController::class, 'destroySession'])->name('session.destroy');
        Route::get('/installation/applications', [ApplicationController::class, 'list'])
            ->name('installation.applications.index');
        Route::post('/installation/applications', [ApplicationController::class, 'store'])
            ->name('installation.applications.store');
        Route::get('/installation/applications/{applicationId}', [ApplicationController::class, 'show'])
            ->name('installation.applications.show');
        Route::patch('/installation/applications/{applicationId}', [ApplicationController::class, 'update'])
            ->name('installation.applications.update');
        Route::delete('/installation/applications/{applicationId}', [ApplicationController::class, 'destroy'])
            ->name('installation.applications.destroy');
        Route::get('/installation/pane-admin-invitations', [PaneAdminInvitationController::class, 'list'])
            ->name('installation.pane-admin-invitations.index');
        Route::post('/installation/pane-admin-invitations', [PaneAdminInvitationController::class, 'store'])
            ->name('installation.pane-admin-invitations.store');
        Route::delete('/installation/pane-admin-invitations/{invitationId}', [PaneAdminInvitationController::class, 'destroy'])
            ->name('installation.pane-admin-invitations.destroy');
        Route::get('/organizations/{organizationId}/memberships', [OrganizationMembershipController::class, 'list'])
            ->name('organizations.memberships.index');
        Route::get('/organizations/{organizationId}/memberships/{membershipId}', [OrganizationMembershipController::class, 'show'])
            ->name('organizations.memberships.show');
        Route::patch('/organizations/{organizationId}/memberships/{membershipId}', [OrganizationMembershipController::class, 'update'])
            ->name('organizations.memberships.update');
        Route::get('/organizations/{organizationId}/invitations', [OrganizationInvitationController::class, 'list'])
            ->name('organizations.invitations.index');
        Route::post('/organizations/{organizationId}/invitations', [OrganizationInvitationController::class, 'store'])
            ->name('organizations.invitations.store');
        Route::get('/organizations/{organizationId}/invitations/{invitationId}', [OrganizationInvitationController::class, 'show'])
            ->name('organizations.invitations.show');
        Route::post('/organizations/{organizationId}/invitations/{invitationId}/resends', [OrganizationInvitationController::class, 'resend'])
            ->name('organizations.invitations.resend');
        Route::delete('/organizations/{organizationId}/invitations/{invitationId}', [OrganizationInvitationController::class, 'destroy'])
            ->name('organizations.invitations.destroy');
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
Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/{story}/{subject}', [Controller::class, 'runStory']);
    Route::match(['get', 'put', 'delete'], '/{story}/{subject}/{key}', [Controller::class, 'runStory']);
});

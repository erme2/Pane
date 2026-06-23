<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Auth\WorkOsAuthController;

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

// WorkOS auth
Route::get('/auth/login', [WorkOsAuthController::class, 'login'])->name('login');
Route::get('/auth/callback', [WorkOsAuthController::class, 'callback'])->name('auth.callback');
Route::post('/auth/logout', [WorkOsAuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/auth/user', [WorkOsAuthController::class, 'user'])->middleware('auth')->name('auth.user');

// all stories (crud)
Route::match(['get', 'post'], '/{story}/{subject}', [Controller::class, 'runStory']);
Route::match(['get', 'put', 'delete'], '/{story}/{subject}/{key}', [Controller::class, 'runStory']);

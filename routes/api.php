<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController;

Route::post('/register', [AuthController::class, 'register']);
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verify'])
    ->name('verification.verify');
Route::post('/login',[AuthController::class, 'login']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
Route::post('/auth/social', [AuthController::class, 'socialLogin']);
Route::post('/auth/link-social', [AuthController::class, 'linkSocial']);
// Wallet and Protected User routes
Route::middleware(['custom.auth'])->group(function () {
    // Wallet and Protected User routes
    Route::get('/user/profile', [AuthController::class, 'getProfile']);
    Route::post('/user/avatar', [AuthController::class, 'updateAvatar']);
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets', [WalletController::class, 'store']);
    Route::post('/wallets/{id}', [WalletController::class, 'update']);
    Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);

    //Logut and logout all
    Route::post('/logout',[AuthController::class, 'logout']);
    Route::post('/logout-all',[AuthController::class, 'logoutAllDevices']);
});
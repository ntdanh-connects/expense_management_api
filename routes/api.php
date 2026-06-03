<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CategoryController;

Route::post('/register', [AuthController::class, 'register']);
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verify'])
    ->name('verification.verify');
Route::post('/login',[AuthController::class, 'login']);
Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
Route::post('/auth/social', [AuthController::class, 'socialLogin']);
Route::post('/auth/link-social', [AuthController::class, 'linkSocial']);
Route::post('/auth/forgot-password', [AuthController::class, 'sendResetLinkEmail']);
// Wallet and Protected User routes
Route::middleware(['custom.auth'])->group(function () {
    // Wallet and Protected User routes
    Route::get('/user/profile', [AuthController::class, 'getProfile']);
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/user/avatar', [AuthController::class, 'updateAvatar']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);
    Route::delete('/user', [AuthController::class, 'deleteAccount']);
    
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets', [WalletController::class, 'store']);
    Route::post('/wallets/{id}', [WalletController::class, 'update']);
    Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);
    Route::post('/wallets/transfer', [WalletController::class, 'transfer']);
    Route::get('/wallets/{id}/transactions', [WalletController::class, 'transactions']);

    // Categories routes
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/icons', [CategoryController::class, 'getIcons']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    Route::post('/categories/merge', [CategoryController::class, 'merge']);

    //Logut and logout all
    Route::post('/logout',[AuthController::class, 'logout']);
    Route::post('/logout-all',[AuthController::class, 'logoutAllDevices']);
});
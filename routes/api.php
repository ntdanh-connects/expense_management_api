<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\RecurringRuleController;
use App\Http\Controllers\PreferenceOptionsController;
use App\Http\Controllers\BudgetController;

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
    // Quản lý giao dịch
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);

    // Quản lý quy tắc định kỳ
    Route::get('/recurring-rules', [RecurringRuleController::class, 'index']);
    Route::post('/recurring-rules', [RecurringRuleController::class, 'store']);
    Route::post('/recurring-rules/{id}', [RecurringRuleController::class, 'update']);
    Route::delete('/recurring-rules/{id}', [RecurringRuleController::class, 'destroy']);
    Route::post('/recurring-rules/{id}/toggle', [RecurringRuleController::class, 'toggle']);
    // Wallet and Protected User routes
    Route::get('/user/profile', [AuthController::class, 'getProfile']);
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::get('/user/preferences/options', [PreferenceOptionsController::class, 'getOptions']);
    Route::get('/exchange-rates', [PreferenceOptionsController::class, 'getRates']);
    Route::post('/user/avatar', [AuthController::class, 'updateAvatar']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);
    Route::delete('/user', [AuthController::class, 'deleteAccount']);
    
    Route::get('/wallets', [WalletController::class, 'index']);
    Route::post('/wallets', [WalletController::class, 'store']);
    Route::post('/wallets/transfer', [WalletController::class, 'transfer']);
    Route::get('/wallets/transfers', [WalletController::class, 'getTransfers']);
    Route::post('/wallets/{id}', [WalletController::class, 'update']);
    Route::delete('/wallets/{id}', [WalletController::class, 'destroy']);
    Route::get('/wallets/{id}/transactions', [WalletController::class, 'transactions']);

    // Categories routes
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::get('/categories/icons', [CategoryController::class, 'getIcons']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/merge', [CategoryController::class, 'merge']);
    Route::post('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Budget routes
    Route::get('/budgets', [BudgetController::class, 'index']);
    Route::post('/budgets', [BudgetController::class, 'store']);
    Route::delete('/budgets/{id}', [BudgetController::class, 'destroy']);
    Route::post('/budgets/copy', [BudgetController::class, 'copy']);

    //Logut and logout all
    Route::post('/logout',[AuthController::class, 'logout']);
    Route::post('/logout-all',[AuthController::class, 'logoutAllDevices']);
});
<?php

namespace App\Providers;

use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\UserRepository;
use App\Repositories\Contracts\WalletRepositoryInterface;
use App\Repositories\Eloquent\WalletRepository;
use App\Repositories\Contracts\CategoryRepositoryInterface;
use App\Repositories\Eloquent\CategoryRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(WalletRepositoryInterface::class, WalletRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(\App\Repositories\Contracts\TransactionRepositoryInterface::class, \App\Repositories\Eloquent\TransactionRepository::class);
        $this->app->bind(\App\Repositories\Contracts\RecurringRuleRepositoryInterface::class, \App\Repositories\Eloquent\RecurringRuleRepository::class);
        $this->app->bind(\App\Repositories\Contracts\BudgetRepositoryInterface::class, \App\Repositories\Eloquent\BudgetRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

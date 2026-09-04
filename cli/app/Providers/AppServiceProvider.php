<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Vaults\Auth\TokenStore;
use Vaults\VaultsClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenStore::class, fn (): TokenStore => new TokenStore);

        $this->app->bind(VaultsClient::class, function (): VaultsClient {
            return new VaultsClient($this->app->make(TokenStore::class)->token());
        });
    }
}

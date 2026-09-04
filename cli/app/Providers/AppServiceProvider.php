<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\LaravelSleeper;
use Illuminate\Support\ServiceProvider;
use Vaults\Auth\DeviceFlow;
use Vaults\Auth\TokenStore;
use Vaults\Support\Clock;
use Vaults\Support\Sleeper;
use Vaults\Support\SystemClock;
use Vaults\VaultsClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenStore::class, fn (): TokenStore => new TokenStore);

        $this->app->bind(VaultsClient::class, function (): VaultsClient {
            return new VaultsClient($this->app->make(TokenStore::class)->token());
        });

        $this->app->bind(Sleeper::class, LaravelSleeper::class);
        $this->app->bind(Clock::class, SystemClock::class);

        $this->app->bind(DeviceFlow::class, fn (): DeviceFlow => new DeviceFlow(
            $this->app->make(VaultsClient::class),
            $this->app->make(Sleeper::class),
            $this->app->make(Clock::class),
        ));
    }
}

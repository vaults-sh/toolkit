<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\LaravelSleeper;
use App\Updater\ReleaseLocator;
use App\Updater\UpdateNotifier;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use LaravelZero\Framework\Providers\Build\Build;
use Vaults\Auth\CredentialResolver;
use Vaults\Auth\DeviceFlow;
use Vaults\Auth\TokenStore;
use Vaults\Support\Clock;
use Vaults\Support\Sleeper;
use Vaults\Support\SystemClock;
use Vaults\VaultsClient;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! $this->app->make(Build::class)->isRunning() || $event->command === 'self-update' || ! stream_isatty(STDOUT)) {
                return;
            }

            $message = $this->app->make(UpdateNotifier::class)->message((string) config('app.version'));

            if ($message !== null) {
                $event->output->writeln('<comment>'.$message.'</comment>');
            }
        });
    }

    public function register(): void
    {
        $this->app->bind(ReleaseLocator::class, fn (): ReleaseLocator => new ReleaseLocator(timeout: 2.0));
        $this->app->bind(UpdateNotifier::class, fn (): UpdateNotifier => new UpdateNotifier($this->app->make(ReleaseLocator::class)));

        $this->app->singleton(TokenStore::class, fn (): TokenStore => new TokenStore);

        $this->app->bind(CredentialResolver::class, fn (): CredentialResolver => new CredentialResolver($this->app->make(TokenStore::class)));

        $this->app->bind(VaultsClient::class, function (): VaultsClient {
            $credentials = $this->app->make(CredentialResolver::class)->resolve((string) getcwd());

            return new VaultsClient($credentials?->token);
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

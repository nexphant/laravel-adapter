<?php

namespace Nexphant\LaravelAdapter;

use Illuminate\Support\ServiceProvider;
use Nexphant\LaravelAdapter\Console\NexphantInstallCommand;
use Nexphant\LaravelAdapter\Console\NexphantStartCommand;

class NexphantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                NexphantInstallCommand::class,
                NexphantStartCommand::class,
            ]);
        }
    }
}

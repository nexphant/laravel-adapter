<?php

namespace Nexph\LaravelAdapter;

use Illuminate\Support\ServiceProvider;
use Nexph\LaravelAdapter\Console\NexphInstallCommand;
use Nexph\LaravelAdapter\Console\NexphStartCommand;

class NexphServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                NexphInstallCommand::class,
                NexphStartCommand::class,
            ]);
        }
    }
}

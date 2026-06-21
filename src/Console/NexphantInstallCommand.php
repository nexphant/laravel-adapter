<?php

namespace Nexphant\LaravelAdapter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class NexphantInstallCommand extends Command
{
    protected $signature = 'nexphant:install {--force}';
    protected $description = 'Install Nexphant adapter';

    public function handle()
    {
        $this->info('Installing Nexphant adapter...');
        $this->publishConfiguration();
        $this->info('Nexphant adapter installed successfully.');
        $this->comment('Start the server: php artisan nexphant:start');
    }

    protected function publishConfiguration()
    {
        $force = $this->option('force');
        $configPath = config_path('nexphant.php');
        if (File::exists($configPath) && !$force) {
            if (!$this->confirm('nexphant.php already exists. Overwrite?')) {
                return;
            }
        }
        File::copy(
            __DIR__.'/../../config/nexphant.php',
            $configPath
        );
        $this->info('Published: config/nexphant.php');
    }
}

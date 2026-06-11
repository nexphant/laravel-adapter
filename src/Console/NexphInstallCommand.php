<?php

namespace Nexph\LaravelAdapter\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class NexphInstallCommand extends Command
{
    protected $signature = 'nexph:install {--force}';
    protected $description = 'Install Nexph adapter';

    public function handle()
    {
        $this->info('Installing Nexph adapter...');
        $this->publishConfiguration();
        $this->info('Nexph adapter installed successfully.');
        $this->comment('Start the server: php artisan nexph:start');
    }

    protected function publishConfiguration()
    {
        $force = $this->option('force');
        $configPath = config_path('nexph.php');
        if (File::exists($configPath) && !$force) {
            if (!$this->confirm('nexph.php already exists. Overwrite?')) {
                return;
            }
        }
        File::copy(
            __DIR__.'/../../config/nexph.php',
            $configPath
        );
        $this->info('Published: config/nexph.php');
    }
}

<?php

namespace Nexph\LaravelAdapter\Console;

use Illuminate\Console\Command;
use Nexph\LaravelAdapter\LaravelHttpAdapter;
use Nexph\Server\HttpServer;

class NexphStartCommand extends Command
{
    protected $signature = 'nexph:start {--host=} {--port=}';
    protected $description = 'Start Nexph server';

    public function handle()
    {
        $config = config('nexph', []);
        $config['host'] = $this->option('host') ?: ($config['host'] ?? '0.0.0.0');
        $config['port'] = (int) ($this->option('port') ?: ($config['port'] ?? 8000));

        $adapter = LaravelHttpAdapter::bootstrap(base_path('bootstrap/app.php'));
        $server = new HttpServer($config);
        $server->onRequest(fn($req, $res) => $adapter->handle($req, $res));
        $this->info("Nexph server running on http://{$config['host']}:{$config['port']}");
        $server->start();
    }
}

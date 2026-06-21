<?php

namespace Nexphant\LaravelAdapter\Console;

use Illuminate\Console\Command;
use Nexphant\LaravelAdapter\LaravelHttpAdapter;
use Nexph\Server\HttpServer;

class NexphantStartCommand extends Command
{
    protected $signature = 'nexphant:start {--host=} {--port=}';
    protected $description = 'Start Nexphant server';

    public function handle()
    {
        $config = config('nexphant', []);
        $config['host'] = $this->option('host') ?: ($config['host'] ?? '0.0.0.0');
        $config['port'] = (int) ($this->option('port') ?: ($config['port'] ?? 8000));

        $adapter = LaravelHttpAdapter::bootstrap(base_path('bootstrap/app.php'));
        $server = new HttpServer($config);
        $server->onRequest(fn($req, $res) => $adapter->handle($req, $res));
        $this->info("Nexphant server running on http://{$config['host']}:{$config['port']}");
        $server->start();
    }
}

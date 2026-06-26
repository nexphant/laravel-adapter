<?php

namespace Nexphant\LaravelAdapter\Console;

use Illuminate\Console\Command;
use Nexphant\LaravelAdapter\LaravelHttpAdapter;
use Nexph\Server\HttpServer;

class NexphantStartCommand extends Command
{
    protected $signature = 'nexphant:start
                            {--host=           : Bind address (overrides config)}
                            {--port=           : Bind port (overrides config)}
                            {--workers=        : Number of worker processes (default: CPU count)}';

    protected $description = 'Start the Nexphant HTTP server';

    public function handle(): int
    {
        $config = config('nexphant', []);

        $config['host'] = $this->option('host') ?: ($config['host'] ?? '0.0.0.0');
        $config['port'] = (int) ($this->option('port') ?: ($config['port'] ?? 8000));

        $workers = (int) ($this->option('workers')
            ?: ($config['workers'] ?? $this->cpuCount()));

        $workers = max(1, $workers);
        $config['worker_count'] = $workers;

        $this->info("Nexphant server starting on http://{$config['host']}:{$config['port']} with {$workers} worker(s)");

        if ($workers === 1) {
            $this->runWorker($config, 1);
            return 0;
        }

        // Multi-worker: fork one process per worker.
        // The parent just waits and restarts crashed children.
        if (!function_exists('pcntl_fork')) {
            $this->warn('pcntl extension not available — falling back to single worker.');
            $this->runWorker($config, 1);
            return 0;
        }

        $pids = [];

        for ($id = 1; $id <= $workers; $id++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->error("pcntl_fork() failed for worker {$id}");
                continue;
            }

            if ($pid === 0) {
                // Child process — run the server loop and never return.
                $this->runWorker($config, $id);
                exit(0);
            }

            $pids[$pid] = $id;
        }

        // Parent: watch children and restart on unexpected exit.
        pcntl_async_signals(true);

        $running = true;
        pcntl_signal(SIGINT,  function () use (&$running, $pids): void {
            $running = false;
            foreach (array_keys($pids) as $pid) {
                posix_kill($pid, SIGTERM);
            }
        });
        pcntl_signal(SIGTERM, function () use (&$running, $pids): void {
            $running = false;
            foreach (array_keys($pids) as $pid) {
                posix_kill($pid, SIGTERM);
            }
        });

        while ($running && !empty($pids)) {
            $exitedPid = pcntl_wait($status);

            if ($exitedPid <= 0) {
                continue;
            }

            $workerId = $pids[$exitedPid] ?? null;
            unset($pids[$exitedPid]);

            if (!$running || $workerId === null) {
                continue;
            }

            // Restart crashed worker.
            $this->warn("Worker {$workerId} (pid {$exitedPid}) exited — restarting.");

            $pid = pcntl_fork();
            if ($pid === 0) {
                $this->runWorker($config, $workerId);
                exit(0);
            }
            if ($pid > 0) {
                $pids[$pid] = $workerId;
            }
        }

        return 0;
    }

    private function runWorker(array $config, int $workerId): void
    {
        $config['worker_id'] = $workerId;

        // Each worker bootstraps its own Laravel application so that the
        // container state is fully isolated across processes.
        $adapter = LaravelHttpAdapter::bootstrap(base_path('bootstrap/app.php'));

        $server = new HttpServer($config);
        $server->onRequest(fn($req, $res) => $adapter->handle($req, $res));
        $server->start();
    }

    private function cpuCount(): int
    {
        // Try nproc (Linux), sysctl (macOS), fall back to 1.
        if (PHP_OS_FAMILY === 'Linux') {
            $n = (int) @shell_exec('nproc --all 2>/dev/null');
            if ($n > 0) return $n;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $n = (int) @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
            if ($n > 0) return $n;
        }

        return 1;
    }
}

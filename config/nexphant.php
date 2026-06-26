<?php

return [
    'host' => env('NEXPHANT_HOST', '0.0.0.0'),
    'port' => env('NEXPHANT_PORT', 8000),
    'quiet' => env('NEXPHANT_QUIET', true),
    'performance_mode' => env('NEXPHANT_PERFORMANCE_MODE', true),
    'runtime_safety' => env('NEXPHANT_RUNTIME_SAFETY', false),
    'event_loop' => env('NEXPHANT_LOOP', 'auto'),
    'socket_driver' => env('NEXPHANT_SOCKET', 'auto'),
    // ---- Connection limits ------------------------------------------------
    // 10k RPS needs headroom for concurrent keep-alive connections.
    // Rule of thumb: max_connections ≥ (target_rps × avg_latency_seconds).
    'max_connections'       => env('NEXPHANT_MAX_CONNECTIONS', 16384),
    'backlog'               => env('NEXPHANT_BACKLOG', 16384),
    // Accept up to 256 sockets per event-loop tick to drain the kernel queue
    // fast under burst traffic.
    'max_accept_per_tick'   => env('NEXPHANT_MAX_ACCEPT_PER_TICK', 256),
    // Short keep-alive: releases idle connections quickly under high load.
    'keep_alive_timeout'    => env('NEXPHANT_KEEP_ALIVE_TIMEOUT', 5),
    // Max requests per connection before graceful close (prevents head-of-line
    // blocking on long-lived connections).
    'max_requests'          => env('NEXPHANT_MAX_REQUESTS', 2000),
    'max_request_size'      => env('NEXPHANT_MAX_REQUEST_SIZE', 2 * 1024 * 1024),
    // Larger write buffer allows batching more response data per syscall.
    'max_write_buffer_size' => env('NEXPHANT_MAX_WRITE_BUFFER_SIZE', 4 * 1024 * 1024),
    // ---- Memory ------------------------------------------------------------
    'memory_limit'                    => env('NEXPHANT_MEMORY_LIMIT', 512 * 1024 * 1024),
    'memory_pressure_threshold'       => env('NEXPHANT_MEMORY_PRESSURE_THRESHOLD', 0.85),
    'memory_hard_pressure_threshold'  => env('NEXPHANT_MEMORY_HARD_PRESSURE_THRESHOLD', 0.95),
    // ---- Event-loop fairness -----------------------------------------------
    // Higher per-tick limits reduce latency spikes under sustained load.
    'max_deferred'                  => env('NEXPHANT_MAX_DEFERRED', 200000),
    'max_read_callbacks_per_tick'   => env('NEXPHANT_MAX_READ_CALLBACKS_PER_TICK', 2048),
    'max_write_callbacks_per_tick'  => env('NEXPHANT_MAX_WRITE_CALLBACKS_PER_TICK', 2048),
    'max_deferred_per_tick'         => env('NEXPHANT_MAX_DEFERRED_PER_TICK', 2048),
    // ---- Object pools ------------------------------------------------------
    // Larger pools amortise allocation cost across bursts.
    'response_pool_size' => env('NEXPHANT_RESPONSE_POOL_SIZE', 8192),
    'request_pool_size'  => env('NEXPHANT_REQUEST_POOL_SIZE', 8192),
    'buffer_pool_size'   => env('NEXPHANT_BUFFER_POOL_SIZE', 16384),
    'metrics_sample_rate' => env('NEXPHANT_METRICS_SAMPLE_RATE', 100),
    'route_latency' => env('NEXPHANT_ROUTE_LATENCY', false),
    'histogram' => env('NEXPHANT_HISTOGRAM', false),
    'object_tracking' => env('NEXPHANT_OBJECT_TRACKING', false),
    'pool_safety' => env('NEXPHANT_POOL_SAFETY', false),
];

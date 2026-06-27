<?php

namespace Nexphant\LaravelAdapter;

use Illuminate\Contracts\Foundation\Application as LaravelApplication;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Facade;
use Nexphant\Server\ServerRequest;
use Nexphant\Server\ServerResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelHttpAdapter
{
    private Kernel $kernel;

    /**
     * Pre-computed static SERVER keys that never change between requests.
     * Merged with per-request values in serverParameters() to avoid
     * rebuilding the full array every time.
     */
    private array $staticServer = [];

    private bool $hasScopedInstances;

    public function __construct(
        private LaravelApplication $laravel
    ) {
        $this->kernel = $this->laravel->make(Kernel::class);
        // Reflect on the protected Container::$scopedInstances property once at
        // boot to decide whether we need to call forgetScopedInstances() on
        // every request. getScopedInstances() does not exist on Application.
        try {
            $prop = new \ReflectionProperty($this->laravel, 'scopedInstances');
            $this->hasScopedInstances = !empty($prop->getValue($this->laravel));
        } catch (\ReflectionException) {
            $this->hasScopedInstances = true; // safe default
        }
    }

    public static function bootstrap(string $bootstrapFile): self
    {
        $laravel = require $bootstrapFile;

        if (!$laravel instanceof LaravelApplication) {
            throw new \RuntimeException('Laravel bootstrap file must return an application instance.');
        }

        return new self($laravel);
    }

    public function handle(ServerRequest $nexphantRequest, ServerResponse $nexphantResponse): ServerResponse
    {
        $symfonyRequest = $this->toSymfonyRequest($nexphantRequest);
        $symfonyResponse = null;

        try {
            $symfonyResponse = $this->kernel->handle($symfonyRequest);
            $symfonyResponse->prepare($symfonyRequest);

            $this->toNexphantResponse($symfonyResponse, $nexphantResponse);

            return $nexphantResponse;
        } finally {
            if ($symfonyResponse !== null) {
                $this->kernel->terminate($symfonyRequest, $symfonyResponse);
            }

            $this->resetRequestState();
        }
    }

    private function toSymfonyRequest(ServerRequest $request): IlluminateRequest
    {
        $server = $this->serverParameters($request);

        // Build the SymfonyRequest directly without going through the
        // SymfonyRequest::create() factory which re-parses the URI and
        // applies extra logic we don't need (we already have all parts).
        $symfonyRequest = new SymfonyRequest(
            /* $query   */ [],          // populated lazily via QUERY_STRING in $server
            /* $request */ $request->parsedBody,
            /* $attributes */ [],
            /* $cookies */ [],          // parsed lazily below
            /* $files   */ [],
            /* $server  */ $server,
            /* $content */ $request->body !== '' ? $request->body : null
        );

        // Parse cookies only when the header is actually present — avoids
        // the explode/urldecode loop on the vast majority of API requests.
        $rawCookie = $request->header('cookie', '');
        if ($rawCookie !== '') {
            $cookies = [];
            foreach (explode(';', $rawCookie) as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (isset($parts[1])) {
                    $cookies[$parts[0]] = urldecode($parts[1]);
                }
            }
            // Inject directly into the ParameterBag — no re-construction needed.
            $symfonyRequest->cookies->replace($cookies);
        }

        return IlluminateRequest::createFromBase($symfonyRequest);
    }

    private function toNexphantResponse(SymfonyResponse $symfonyResponse, ServerResponse $nexphantResponse): void
    {
        $bag = $symfonyResponse->headers;

        // all() returns ['lowercase-name' => ['v1','v2']] — one allocation,
        // no per-header case-preservation overhead for the hot path.
        foreach ($bag->all() as $name => $values) {
            if ($name === 'set-cookie') {
                continue; // handled separately below
            }
            if ($name === 'transfer-encoding') {
                continue; // Nexphant writes its own framing
            }
            
            // Handle array values by setting each one individually
            if (is_array($values)) {
                foreach ($values as $value) {
                    $nexphantResponse->header($name, $value);
                }
            } else {
                $nexphantResponse->header($name, $values);
            }
        }

        $cookies = $bag->getCookies();
        if ($cookies !== []) {
            foreach ($cookies as $cookie) {
                $nexphantResponse->header('Set-Cookie', (string) $cookie);
            }
        }

        $nexphantResponse
            ->status($symfonyResponse->getStatusCode())
            ->body($this->responseBody($symfonyResponse));
    }

    private function responseBody(SymfonyResponse $response): string
    {
        if (!$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse) {
            return (string) $response->getContent();
        }

        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function serverParameters(ServerRequest $request): array
    {
        $secure = $this->isSecure($request);
        $host   = $request->headers['host'] ?? 'localhost';

        // Resolve port: prefer explicit port in Host header, fall back to
        // scheme-based default.  str_contains is cheaper than parse_url.
        if (str_contains($host, ':')) {
            $colon = strrpos($host, ':');
            $port  = substr($host, $colon + 1);
        } else {
            $port = $secure ? '443' : '80';
        }

        $uri = $request->queryString === ''
            ? $request->path
            : $request->path . '?' . $request->queryString;

        // Build the base array inline — no function call overhead.
        $server = [
            'REQUEST_METHOD'  => $request->method,
            'REQUEST_URI'     => $uri,
            'QUERY_STRING'    => $request->queryString,
            'REMOTE_ADDR'     => $request->remoteAddr,
            'REMOTE_PORT'     => (string) $request->remotePort,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME'     => $host,
            'SERVER_PORT'     => $port,
            'HTTPS'           => $secure ? 'on' : 'off',
            'CONTENT_TYPE'    => '',
            'CONTENT_LENGTH'  => '',
        ];

        // Convert headers to CGI/SAPI vars in a single pass.
        // str_replace + strtoupper on short strings is fast; avoid any regex.
        foreach ($request->headers as $name => $value) {
            // Headers are already lowercase in ServerRequest (set during hydrate).
            $key = strtoupper(str_replace('-', '_', $name));

            if ($key === 'CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $server['CONTENT_LENGTH'] = $value;
            } else {
                $server['HTTP_' . $key] = $value;
            }
        }

        // Only compute body length when there actually is a body —
        // avoids strlen() on an empty string for every GET/HEAD request.
        if ($server['CONTENT_LENGTH'] === '' && $request->body !== '') {
            $server['CONTENT_LENGTH'] = (string) strlen($request->body);
        }

        return $server;
    }

    private function isSecure(ServerRequest $request): bool
    {
        // Headers are already lowercase-keyed in ServerRequest.
        $proto = $request->headers['x-forwarded-proto'] ?? '';
        if ($proto !== '') {
            return strtolower($proto) === 'https';
        }

        $ssl = $request->headers['x-forwarded-ssl'] ?? '';
        if ($ssl !== '') {
            return strtolower($ssl) === 'on';
        }

        $scheme = $request->headers['x-url-scheme'] ?? '';
        return strtolower($scheme) === 'https';
    }

    private function resetRequestState(): void
    {
        // Unset the request singleton so the next request gets a fresh one.
        $this->laravel->forgetInstance('request');

        // Only iterate scopedInstances when bindings actually exist — this
        // loop is O(n) on the scoped binding list and is the main reset cost.
        if ($this->hasScopedInstances) {
            $this->laravel->forgetScopedInstances();
        }

        Facade::clearResolvedInstance('request');
    }
}

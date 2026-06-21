<?php

namespace Nexphant\LaravelAdapter;

use Illuminate\Contracts\Foundation\Application as LaravelApplication;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Support\Facades\Facade;
use Nexph\Server\ServerRequest;
use Nexph\Server\ServerResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LaravelHttpAdapter
{
    private Kernel $kernel;

    public function __construct(
        private LaravelApplication $laravel
    ) {
        $this->kernel = $this->laravel->make(Kernel::class);
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
            if ($symfonyResponse instanceof SymfonyResponse) {
                $this->kernel->terminate($symfonyRequest, $symfonyResponse);
            }

            $this->resetRequestState();
        }
    }

    private function toSymfonyRequest(ServerRequest $request): IlluminateRequest
    {
        $query = [];
        if ($request->queryString !== '') {
            parse_str($request->queryString, $query);
        }

        $cookies = [];
        $cookieHeader = $request->header('cookie', '');
        if ($cookieHeader !== '') {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if (count($parts) === 2) {
                    $cookies[$parts[0]] = urldecode($parts[1]);
                }
            }
        }

        $server = $this->serverParameters($request);

        $symfonyRequest = SymfonyRequest::create(
            $this->requestUri($request),
            $request->method,
            $request->parsedBody,
            $cookies,
            [],
            $server,
            $request->body
        );

        return IlluminateRequest::createFromBase($symfonyRequest);
    }

    private function toNexphantResponse(SymfonyResponse $symfonyResponse, ServerResponse $nexphantResponse): void
    {
        $headers = [];
        foreach ($symfonyResponse->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $headers[$name] = count($values) === 1 ? (string) $values[0] : array_map('strval', $values);
        }

        $cookies = [];
        foreach ($symfonyResponse->headers->getCookies() as $cookie) {
            $cookies[] = (string) $cookie;
        }
        if ($cookies !== []) {
            $headers['Set-Cookie'] = $cookies;
        }

        unset($headers['Transfer-Encoding']);

        $nexphantResponse
            ->status($symfonyResponse->getStatusCode())
            ->headers($headers)
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
        $server = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $this->requestUri($request),
            'QUERY_STRING' => $request->queryString,
            'REMOTE_ADDR' => $request->remoteAddr,
            'REMOTE_PORT' => (string) $request->remotePort,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => $request->header('host', 'localhost'),
            'SERVER_PORT' => $this->serverPort($request),
            'HTTPS' => $this->isSecure($request) ? 'on' : 'off',
            'CONTENT_LENGTH' => (string) strlen($request->body),
        ];

        foreach ($request->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            if ($key === 'CONTENT_TYPE') {
                $server['CONTENT_TYPE'] = $value;
                continue;
            }
            if ($key === 'CONTENT_LENGTH') {
                $server['CONTENT_LENGTH'] = $value;
                continue;
            }
            $server['HTTP_' . $key] = $value;
        }

        return $server;
    }

    private function requestUri(ServerRequest $request): string
    {
        return $request->queryString === ''
            ? $request->path
            : $request->path . '?' . $request->queryString;
    }

    private function serverPort(ServerRequest $request): string
    {
        $host = $request->header('host', '');
        if (str_contains($host, ':')) {
            return (string) parse_url('http://' . $host, PHP_URL_PORT);
        }

        return $this->isSecure($request) ? '443' : '80';
    }

    private function isSecure(ServerRequest $request): bool
    {
        return strtolower($request->header('x-forwarded-proto', '')) === 'https'
            || strtolower($request->header('x-forwarded-ssl', '')) === 'on'
            || strtolower($request->header('x-url-scheme', '')) === 'https';
    }

    private function resetRequestState(): void
    {
        $this->laravel->forgetInstance('request');
        $this->laravel->forgetScopedInstances();
        Facade::clearResolvedInstance('request');
    }
}

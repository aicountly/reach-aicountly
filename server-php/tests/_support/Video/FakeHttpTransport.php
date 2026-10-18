<?php

declare(strict_types=1);

namespace Tests\Support\Video;

use App\Libraries\Video\Automation\Http\HttpResponse;
use App\Libraries\Video\Automation\Http\HttpTransport;

/**
 * Scripted HTTP for provider-client tests. Each route is a method + URL
 * regex answered by a fixed HttpResponse or a callable(method, url, headers,
 * body): HttpResponse. Unmatched requests fail the test loudly (status 599)
 * so nothing silently reaches the network. Every request is recorded.
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, pattern: string, answer: HttpResponse|callable, times: ?int}> */
    private array $routes = [];

    public function on(string $method, string $urlPattern, HttpResponse|callable $answer, ?int $times = null): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $urlPattern, 'answer' => $answer, 'times' => $times];
        return $this;
    }

    public static function json(int $status, array $body, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, json_encode($body), array_change_key_case($headers, CASE_LOWER));
    }

    /**
     * Honours the streaming options like CurlTransport: `body_file` is read as
     * the request body (recorded in `body`), and a 2xx answer to a request
     * with `sink_file` is written to that file and returned with an empty body.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): HttpResponse
    {
        $method = strtoupper($method);
        if ($body === null && isset($options['body_file']) && is_file((string) $options['body_file'])) {
            $body = (string) file_get_contents((string) $options['body_file']);
        }
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $res = $this->answer($method, $url, $headers, $body);
        if (isset($options['sink_file']) && $res->ok()) {
            file_put_contents((string) $options['sink_file'], $res->body);
            return new HttpResponse($res->status, '', $res->headers, $res->transportError);
        }
        return $res;
    }

    private function answer(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        foreach ($this->routes as $i => $route) {
            if ($route['method'] !== $method || ! preg_match($route['pattern'], $url)) {
                continue;
            }
            if ($route['times'] !== null) {
                if ($route['times'] <= 0) {
                    continue;
                }
                $this->routes[$i]['times']--;
            }
            $answer = $route['answer'];
            return $answer instanceof HttpResponse ? $answer : $answer($method, $url, $headers, $body);
        }
        return new HttpResponse(599, '{"error":"unexpected request in test: ' . $method . ' ' . addslashes($url) . '"}');
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public function to(string $urlPattern, ?string $method = null): array
    {
        return array_values(array_filter($this->requests, static fn ($r) => preg_match($urlPattern, $r['url']) && ($method === null || $r['method'] === strtoupper($method))));
    }
}

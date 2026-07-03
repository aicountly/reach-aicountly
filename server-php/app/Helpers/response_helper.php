<?php

/**
 * Consistent API response envelope for the Reach portal.
 *
 * Success:  { "ok": true,  "data": ... }
 * Failure:  { "ok": false, "error": "...", "details"?: ... }
 */

if (! function_exists('json_ok')) {
    function json_ok(mixed $data = [], int $status = 200): \CodeIgniter\HTTP\ResponseInterface
    {
        return service('response')
            ->setStatusCode($status)
            ->setJSON(['ok' => true, 'data' => $data]);
    }
}

if (! function_exists('json_fail')) {
    function json_fail(string $message, int $status = 400, mixed $details = null): \CodeIgniter\HTTP\ResponseInterface
    {
        $body = ['ok' => false, 'error' => $message];
        if ($details !== null) {
            $body['details'] = $details;
        }
        return service('response')->setStatusCode($status)->setJSON($body);
    }
}

if (! function_exists('json_unauthorized')) {
    function json_unauthorized(string $message = 'Not authenticated.'): \CodeIgniter\HTTP\ResponseInterface
    {
        return json_fail($message, 401);
    }
}

if (! function_exists('json_forbidden')) {
    function json_forbidden(string $message = 'Forbidden.'): \CodeIgniter\HTTP\ResponseInterface
    {
        return json_fail($message, 403);
    }
}

if (! function_exists('json_not_found')) {
    function json_not_found(string $message = 'Not found.'): \CodeIgniter\HTTP\ResponseInterface
    {
        return json_fail($message, 404);
    }
}

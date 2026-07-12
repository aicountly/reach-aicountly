<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Reject JSON request bodies that exceed a per-route byte limit.
 *
 * The default is 1 MiB. Routes may override with `throttle-body:<bytes>` in
 * filter arguments (e.g. `body-size:65536` for 64 KiB).
 *
 * We inspect Content-Length first and fall back to `strlen($request->getBody())`
 * so chunked / missing Content-Length uploads are still bounded. Non-JSON
 * requests are ignored — file uploads and form posts are governed by PHP's
 * `post_max_size` / `upload_max_filesize` and are outside the scope of this
 * filter.
 */
class JsonBodySizeFilter implements FilterInterface
{
    private const DEFAULT_LIMIT = 1048576;

    public function before(RequestInterface $request, $arguments = null)
    {
        $method = strtoupper($request->getMethod());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        $contentType = strtolower((string) $request->getHeaderLine('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'json')) {
            return null;
        }

        $limit = self::DEFAULT_LIMIT;
        if (is_array($arguments) && isset($arguments[0]) && is_numeric($arguments[0])) {
            $limit = max(1024, (int) $arguments[0]);
        }

        $declared = (int) $request->getHeaderLine('Content-Length');
        $body     = $request->getBody() ?? '';
        $actual   = strlen($body);
        $size     = max($declared, $actual);

        if ($size > $limit) {
            $response = Services::response()
                ->setStatusCode(413)
                ->setJSON([
                    'ok'      => false,
                    'error'   => 'Request body too large.',
                    'max'     => $limit,
                    'actual'  => $size,
                ]);
            return $response;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}

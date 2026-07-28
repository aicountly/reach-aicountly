<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController as CoreBaseApiController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Extended helpers used by Phase 4+ controllers (Publishing, etc.).
 */
abstract class BaseApiController extends CoreBaseApiController
{
    /**
     * @param mixed                     $data
     * @param int|array<string, mixed>  $statusOrMeta  HTTP status, or meta bag merged into data
     */
    protected function ok(mixed $data = [], int|array $statusOrMeta = 200): ResponseInterface
    {
        if (is_array($statusOrMeta)) {
            if (is_array($data)) {
                $data = array_merge(['items' => $data], $statusOrMeta);
            } else {
                $data = array_merge(['data' => $data], $statusOrMeta);
            }
            return parent::ok($data, 200);
        }

        return parent::ok($data, $statusOrMeta);
    }

    protected function notFound(string $message = 'Not found.'): ResponseInterface
    {
        return $this->fail($message, 404);
    }

    protected function error(string $message, int $status = 400, mixed $details = null): ResponseInterface
    {
        return $this->fail($message, $status, $details);
    }
}

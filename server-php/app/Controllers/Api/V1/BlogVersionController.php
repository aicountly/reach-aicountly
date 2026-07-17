<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BlogPostModel;
use App\Models\BlogVersionModel;
use CodeIgniter\HTTP\ResponseInterface;

class BlogVersionController extends BaseApiController
{
    public function index(int $blogPostId): ResponseInterface
    {
        if ((new BlogPostModel())->find($blogPostId) === null) {
            return $this->fail('Blog post not found.', 404);
        }

        $rows = (new BlogVersionModel())->forPost($blogPostId);

        return $this->ok(['items' => $rows]);
    }

    public function show(int $blogPostId, int $version): ResponseInterface
    {
        if ((new BlogPostModel())->find($blogPostId) === null) {
            return $this->fail('Blog post not found.', 404);
        }

        $row = (new BlogVersionModel())->findForPost($blogPostId, $version);
        if ($row === null) {
            return $this->fail('Version not found.', 404);
        }

        return $this->ok($row);
    }
}

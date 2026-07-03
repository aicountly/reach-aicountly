<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\BlogVersionModel;

class BlogVersionController extends BaseApiController
{
    public function index(int $blogPostId)
    {
        $rows = (new BlogVersionModel())
            ->where('blog_post_id', $blogPostId)
            ->orderBy('version', 'DESC')
            ->findAll();
        return $this->ok(['items' => $rows]);
    }

    public function show(int $blogPostId, int $version)
    {
        $row = (new BlogVersionModel())
            ->where('blog_post_id', $blogPostId)
            ->where('version', $version)
            ->first();
        if (! $row) {
            return $this->fail('Version not found.', 404);
        }
        return $this->ok($row);
    }
}

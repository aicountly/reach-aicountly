<?php

namespace Tests\Feature\Content;

use Tests\Support\ApiTestCase;

/**
 * Content validation feature tests.
 */
final class ContentValidationTest extends ApiTestCase
{
    public function testStoreAndListValidation(): void
    {
        $headers = $this->authAs('super_admin');

        $res = $this->withHeaders($headers)->call('POST', 'v1/content/items', [
            'title'        => 'Validation test',
            'content_type' => 'blog',
        ]);
        $id = json_decode((string) $res->getJSON(), true)['data']['id'];

        $v = $this->withHeaders($headers)->call('POST', "v1/content/items/{$id}/validations", [
            'validation_type'   => 'seo',
            'validation_status' => 'passed',
            'score'             => 92,
            'message'           => 'SEO looks good.',
        ]);
        $this->assertSame(200, $v->response()->getStatusCode());

        $list = $this->withHeaders($headers)->call('GET', "v1/content/items/{$id}/validations");
        $this->assertSame(200, $list->response()->getStatusCode());
    }
}


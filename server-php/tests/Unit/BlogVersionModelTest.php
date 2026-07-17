<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\BlogVersionModel;
use CodeIgniter\Test\CIUnitTestCase;

final class BlogVersionModelTest extends CIUnitTestCase
{
    public function testDecodeSnapshotAcceptsArray(): void
    {
        $input = ['title' => 'Hello', 'slug' => 'hello'];

        $this->assertSame($input, BlogVersionModel::decodeSnapshot($input));
    }

    public function testDecodeSnapshotDecodesJsonObjectString(): void
    {
        $decoded = BlogVersionModel::decodeSnapshot('{"title":"Hello","slug":"hello"}');

        $this->assertSame(['title' => 'Hello', 'slug' => 'hello'], $decoded);
    }

    public function testDecodeSnapshotRepairsLegacyDoubleEncodedJson(): void
    {
        $inner = json_encode(['title' => 'Legacy'], JSON_UNESCAPED_SLASHES);
        $outer = json_encode($inner, JSON_UNESCAPED_SLASHES);

        $decoded = BlogVersionModel::decodeSnapshot($outer);

        $this->assertSame(['title' => 'Legacy'], $decoded);
    }
}

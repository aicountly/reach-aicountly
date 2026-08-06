<?php

namespace Tests\Unit\Deploy;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The deploy rsyncs the API directory with --delete. Runtime data survives
 * only because of the protect rules in scripts/cpanel-rsync-api.filters.
 *
 * rsync matches every path it is about to delete against those rules
 * individually, so "writable/uploads/" protects the directory entry and
 * nothing else — writable/uploads/media/covers/2026/08/x.webp does not match
 * it and was deleted on every release, leaving gallery rows pointing at
 * binaries that no longer existed. "dir/***" is the idiom that protects a
 * directory and its contents.
 *
 * This test pins the idiom so the trailing stars are not "tidied" away.
 */
final class RsyncFilterProtectionTest extends CIUnitTestCase
{
    /** Directories whose entire contents must survive a --delete deploy. */
    private const PROTECTED_TREES = [
        'writable/uploads',
        'writable/cache',
        'writable/logs',
        'writable/session',
    ];

    private function filters(): string
    {
        $path = dirname(ROOTPATH, 1) . '/scripts/cpanel-rsync-api.filters';
        $this->assertFileExists($path, 'The deploy references this filter file by path; losing it breaks every deploy.');

        return (string) file_get_contents($path);
    }

    public function testRuntimeTreesAreProtectedWithTheirContents(): void
    {
        $filters = $this->filters();

        foreach (self::PROTECTED_TREES as $tree) {
            $this->assertMatchesRegularExpression(
                '/^P\s+' . preg_quote($tree, '/') . '\/\*\*\*$/m',
                $filters,
                "{$tree} must be protected as a tree (P {$tree}/***). A bare trailing slash "
                . 'protects only the directory entry and deletes everything inside it.',
            );
        }
    }

    public function testTheServerEnvIsNeverUploadedOrDeleted(): void
    {
        $filters = $this->filters();

        $this->assertMatchesRegularExpression('/^P\s+\.env$/m', $filters, 'The server .env must never be deleted by --delete.');
        $this->assertMatchesRegularExpression('/^-\s+\.env$/m', $filters, 'CI must never upload a .env over the server one.');
    }
}

<?php

declare(strict_types=1);

namespace App\Libraries\Automation\Routine;

/**
 * The apps AICOUNTLY launches with — the routines write about these first.
 * Names, KB product slugs, source repositories and public pages only; what an
 * app does must come from its repository, never from this list.
 */
final class LaunchApps
{
    /** @var list<array{name: string, product_slug: string, repo: string, page: ?string}> */
    public const APPS = [
        ['name' => 'Smart Books', 'product_slug' => 'books',     'repo' => 'aicountly/books-react-app',   'page' => '/smart-books'],
        ['name' => 'Billing',     'product_slug' => 'billing',   'repo' => 'aicountly/billing-aicountly',  'page' => '/billing'],
        ['name' => 'Inventory',   'product_slug' => 'inventory', 'repo' => 'aicountly/Inventory-aicountly', 'page' => '/inventory'],
        ['name' => 'Notes',       'product_slug' => 'notes',     'repo' => 'aicountly/notes-aicountly',    'page' => '/notes'],
        ['name' => 'Drive',       'product_slug' => 'drive',     'repo' => 'aicountly/drive-react-app',    'page' => null],
        ['name' => 'Manage',      'product_slug' => 'manage',    'repo' => 'aicountly/manage-aicountly',   'page' => '/manage'],
    ];

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::APPS, 'product_slug');
    }

    public static function nameFor(string $productSlug): ?string
    {
        foreach (self::APPS as $app) {
            if ($app['product_slug'] === $productSlug) {
                return $app['name'];
            }
        }

        return null;
    }

    /** @return list<array<string, ?string>> with absolute page URLs */
    public static function forRoutine(string $siteBase): array
    {
        return array_map(static fn (array $a): array => [
            'name'         => $a['name'],
            'product_slug' => $a['product_slug'],
            'repo'         => $a['repo'],
            'page_url'     => $a['page'] !== null ? rtrim($siteBase, '/') . $a['page'] : null,
        ], self::APPS);
    }
}

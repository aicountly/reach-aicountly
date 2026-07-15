<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddRefreshPermissions extends Migration
{
    public function up(): void
    {
        // Permission slugs are managed in app/Config/Permissions.php.
        // This migration serves as a schema-version marker for Phase 9 permission additions.
        // No schema changes required — permissions are enforced via PermissionService.
    }

    public function down(): void
    {
        // Nothing to revert — permissions are code-managed.
    }
}

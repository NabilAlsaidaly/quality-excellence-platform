<?php

namespace App\Support;

use Illuminate\Database\Migrations\Migration;

/** Cache stateless guards, never a connection or a decision, across migration calls. */
final class ClosureMigrationGuards
{
    private static ?Migration $foundation = null;

    private static ?Migration $closure = null;

    public static function assertTarget(): void
    {
        self::$foundation ??= require database_path('migrations/2026_11_13_000001_add_reassessment_parent_key.php');
        self::$foundation->assertTarget(); // Re-evaluate the actual PDO/environment on every invocation.
    }

    public static function assertRollbackSafe(): void
    {
        self::$closure ??= require database_path('migrations/2026_11_14_000001_add_document_public_scope_identity.php');
        self::$closure->assertRollbackSafe(); // Journal, data and dependencies are never cached.
    }
}

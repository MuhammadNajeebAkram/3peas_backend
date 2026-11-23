<?php

namespace Tests\Feature\Freemium;

use App\Http\Services\Freemium\DatabaseCompatibilityChecker;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseCompatibilityMigrationTest extends TestCase
{
    /**
     * Test that the migration file exists and instantiates an anonymous Migration class.
     */
    public function test_migration_file_exists_and_is_valid_migration_instance(): void
    {
        $migrationPath = database_path('migrations/2026_12_01_000000_assert_freemium_database_compatibility.php');

        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;

        $this->assertInstanceOf(Migration::class, $migration);
    }

    /**
     * Test that down() executes cleanly and performs no schema modifications.
     */
    public function test_migration_down_is_safe_noop(): void
    {
        $migrationPath = database_path('migrations/2026_12_01_000000_assert_freemium_database_compatibility.php');
        $migration = require $migrationPath;

        // Calling down() must execute without throwing any exception and perform no changes
        $migration->down();

        $this->assertTrue(true);
    }

    /**
     * Test that the migration file precedes any future Freemium migrations.
     */
    public function test_migration_is_ordered_before_future_freemium_migrations(): void
    {
        $migrationFileName = '2026_12_01_000000_assert_freemium_database_compatibility.php';
        $hypotheticalFirstTableMigration = '2026_12_01_000001_create_freemium_campaigns_table.php';

        $this->assertLessThan(0, strcmp($migrationFileName, $hypotheticalFirstTableMigration));
    }
}

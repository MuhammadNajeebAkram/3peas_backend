<?php

namespace Tests\Unit\Freemium;

use App\Http\Services\Freemium\DatabaseCompatibilityChecker;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DatabaseCompatibilityCheckerTest extends TestCase
{
    /**
     * Test MySQL 8.0.16 is accepted.
     */
    public function test_mysql_8_0_16_is_accepted(): void
    {
        $result = DatabaseCompatibilityChecker::evaluateVersion('8.0.16');

        $this->assertTrue($result['supported']);
        $this->assertSame(DatabaseCompatibilityChecker::ENGINE_MYSQL, $result['engine']);
        $this->assertSame('8.0.16', $result['version']);
    }

    /**
     * Test MySQL 8.0.15 is rejected fail-closed.
     */
    public function test_mysql_8_0_15_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNSUPPORTED DATABASE VERSION: MySQL 8\.0\.15.*requires MySQL >= 8\.0\.16/i');

        DatabaseCompatibilityChecker::evaluateVersion('8.0.15');
    }

    /**
     * Test legacy MySQL 5.7 is rejected fail-closed.
     */
    public function test_mysql_5_7_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNSUPPORTED DATABASE VERSION: MySQL 5\.7\.44.*requires MySQL >= 8\.0\.16/i');

        DatabaseCompatibilityChecker::evaluateVersion('5.7.44-0ubuntu0.18.04.1');
    }

    /**
     * Test modern MySQL 8.4.x and 9.x are accepted.
     */
    public function test_mysql_8_4_x_and_higher_are_accepted(): void
    {
        $result84 = DatabaseCompatibilityChecker::evaluateVersion('8.4.0');
        $this->assertTrue($result84['supported']);
        $this->assertSame('8.4.0', $result84['version']);

        $result84Lts = DatabaseCompatibilityChecker::evaluateVersion('8.4.1-lts');
        $this->assertTrue($result84Lts['supported']);
        $this->assertSame('8.4.1', $result84Lts['version']);

        $result90 = DatabaseCompatibilityChecker::evaluateVersion('9.0.1');
        $this->assertTrue($result90['supported']);
        $this->assertSame('9.0.1', $result90['version']);
    }

    /**
     * Test MariaDB 10.2.1 is accepted.
     */
    public function test_mariadb_10_2_1_is_accepted(): void
    {
        $result = DatabaseCompatibilityChecker::evaluateVersion('10.2.1-MariaDB');

        $this->assertTrue($result['supported']);
        $this->assertSame(DatabaseCompatibilityChecker::ENGINE_MARIADB, $result['engine']);
        $this->assertSame('10.2.1', $result['version']);
    }

    /**
     * Test MariaDB 10.2.0 is rejected fail-closed.
     */
    public function test_mariadb_10_2_0_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNSUPPORTED DATABASE VERSION: MariaDB 10\.2\.0-MariaDB.*requires MariaDB >= 10\.2\.1/i');

        DatabaseCompatibilityChecker::evaluateVersion('10.2.0-MariaDB');
    }

    /**
     * Test MariaDB compatibility-prefixed version strings (e.g. 5.5.5-10.x.x-MariaDB)
     * are correctly detected, stripped, and accepted when >= 10.2.1.
     */
    public function test_mariadb_compatibility_prefixed_version_strings_detected_and_accepted(): void
    {
        $raw = '5.5.5-10.6.12-MariaDB-1:10.6.12+maria~ubu2004';
        $result = DatabaseCompatibilityChecker::evaluateVersion($raw);

        $this->assertTrue($result['supported']);
        $this->assertSame(DatabaseCompatibilityChecker::ENGINE_MARIADB, $result['engine']);
        $this->assertSame('10.6.12', $result['version']);
        $this->assertSame($raw, $result['raw_version']);

        $exactThreshold = '5.5.5-10.2.1-MariaDB';
        $resultThreshold = DatabaseCompatibilityChecker::evaluateVersion($exactThreshold);
        $this->assertTrue($resultThreshold['supported']);
        $this->assertSame('10.2.1', $resultThreshold['version']);
    }

    /**
     * Test MariaDB compatibility-prefixed version strings are rejected when below 10.2.1.
     */
    public function test_mariadb_compatibility_prefixed_version_strings_rejected_when_below_minimum(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNSUPPORTED DATABASE VERSION: MariaDB 5\.5\.5-10\.2\.0-MariaDB.*detected version 10\.2\.0.*requires MariaDB >= 10\.2\.1/i');

        DatabaseCompatibilityChecker::evaluateVersion('5.5.5-10.2.0-MariaDB');
    }

    /**
     * Test MariaDB is detected via version comment when raw version string lacks "MariaDB".
     */
    public function test_mariadb_detected_via_version_comment(): void
    {
        $result = DatabaseCompatibilityChecker::evaluateVersion('10.5.12', 'mysql', 'MariaDB Server');

        $this->assertTrue($result['supported']);
        $this->assertSame(DatabaseCompatibilityChecker::ENGINE_MARIADB, $result['engine']);
        $this->assertSame('10.5.12', $result['version']);
    }

    /**
     * Test unknown or unsupported database drivers and engines are rejected.
     */
    public function test_unknown_database_engines_are_rejected(): void
    {
        // PostgreSQL driver
        try {
            DatabaseCompatibilityChecker::evaluateVersion('PostgreSQL 15.3', 'pgsql');
            $this->fail('Expected RuntimeException for PostgreSQL was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Connection driver 'pgsql' is not supported", $e->getMessage());
        }

        // SQLite driver
        try {
            DatabaseCompatibilityChecker::evaluateVersion('3.39.2', 'sqlite');
            $this->fail('Expected RuntimeException for SQLite was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Connection driver 'sqlite' is not supported", $e->getMessage());
        }

        // SQL Server
        try {
            DatabaseCompatibilityChecker::evaluateVersion('15.0.2000.5', 'sqlsrv');
            $this->fail('Expected RuntimeException for SQL Server was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Connection driver 'sqlsrv' is not supported", $e->getMessage());
        }

        // Non-MySQL engine signature inside version string
        try {
            DatabaseCompatibilityChecker::evaluateVersion('Oracle Database 19c Enterprise Edition', 'mysql');
            $this->fail('Expected RuntimeException for Oracle signature was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Non-MySQL engine detected', $e->getMessage());
        }
    }

    /**
     * Test malformed or empty version strings are rejected.
     */
    public function test_malformed_or_unparseable_versions_are_rejected(): void
    {
        // Empty string
        try {
            DatabaseCompatibilityChecker::evaluateVersion('');
            $this->fail('Expected RuntimeException for empty version string was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('version string is empty or unreadable', $e->getMessage());
        }

        // Null
        try {
            DatabaseCompatibilityChecker::evaluateVersion(null);
            $this->fail('Expected RuntimeException for null version string was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('version string is empty or unreadable', $e->getMessage());
        }

        // Unparseable characters
        try {
            DatabaseCompatibilityChecker::evaluateVersion('invalid_mysql_string');
            $this->fail('Expected RuntimeException for non-numeric version was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not parse semantic version digits', $e->getMessage());
        }
    }
}

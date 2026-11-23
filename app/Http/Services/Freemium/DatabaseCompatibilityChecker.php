<?php

namespace App\Http\Services\Freemium;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validates active database engine compatibility for the Freemium Access Management System.
 *
 * Enforces fail-closed protection against unsupported database engines that lack native
 * CHECK constraint enforcement or stored generated column support.
 *
 * Approved Minimum Versions:
 * - MySQL: >= 8.0.16 (enforced CHECK constraints introduced April 2019)
 * - MariaDB: >= 10.2.1 (enforced CHECK constraints introduced April 2017)
 */
class DatabaseCompatibilityChecker
{
    public const MIN_MYSQL_VERSION = '8.0.16';
    public const MIN_MARIADB_VERSION = '10.2.1';

    public const ENGINE_MYSQL = 'MySQL';
    public const ENGINE_MARIADB = 'MariaDB';
    public const ENGINE_UNKNOWN = 'Unknown';

    /**
     * Assert that the active database connection satisfies minimum engine requirements.
     *
     * @param ConnectionInterface|null $connection
     * @return array{supported: bool, engine: string, version: string, raw_version: string}
     *
     * @throws RuntimeException if the database engine or version is unsupported or malformed.
     */
    public static function assertSupportedDatabaseEngine(?ConnectionInterface $connection = null): array
    {
        $connection ??= DB::connection();

        $driver = (string) $connection->getDriverName();
        $rawVersion = self::detectRawServerVersion($connection);
        $comment = self::detectServerVersionComment($connection);

        return self::evaluateVersion($rawVersion, $driver, $comment);
    }

    /**
     * Query the server version string through multiple database inspection vectors.
     */
    public static function detectRawServerVersion(ConnectionInterface $connection): string
    {
        // Vector 1: Standard SQL query SELECT VERSION()
        try {
            $row = $connection->selectOne('SELECT VERSION() AS v');
            if (!empty($row?->v)) {
                return (string) $row->v;
            }
        } catch (\Throwable) {
            // Fall through to secondary vectors
        }

        // Vector 2: PDO attribute ATTR_SERVER_VERSION
        try {
            $pdo = $connection->getPdo();
            $pdoVersion = $pdo?->getAttribute(\PDO::ATTR_SERVER_VERSION);
            if (!empty($pdoVersion)) {
                return (string) $pdoVersion;
            }
        } catch (\Throwable) {
            // Fall through
        }

        // Vector 3: Global variable @@version
        try {
            $row = $connection->selectOne('SELECT @@version AS v');
            if (!empty($row?->v)) {
                return (string) $row->v;
            }
        } catch (\Throwable) {
            // Exhausted
        }

        throw new RuntimeException(
            'DATABASE COMPATIBILITY CHECK FAILED: Unable to detect database server version across all inspection vectors.'
        );
    }

    /**
     * Detect the server version comment (e.g. "MariaDB Server" or "MySQL Community Server").
     */
    public static function detectServerVersionComment(ConnectionInterface $connection): string
    {
        try {
            $row = $connection->selectOne('SELECT @@version_comment AS c');
            return (string) ($row?->c ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Evaluate database engine and version string against approved Freemium thresholds.
     * Pure and testable without requiring live database connections.
     *
     * @param string|null $rawVersion
     * @param string $driver
     * @param string $comment
     * @return array{supported: bool, engine: string, version: string, raw_version: string}
     *
     * @throws RuntimeException
     */
    public static function evaluateVersion(?string $rawVersion, string $driver = 'mysql', string $comment = ''): array
    {
        if ($rawVersion === null || trim($rawVersion) === '') {
            throw new RuntimeException(
                'DATABASE COMPATIBILITY CHECK FAILED: Database version string is empty or unreadable.'
            );
        }

        $trimmed = trim($rawVersion);
        $lowerVersion = strtolower($trimmed);
        $lowerDriver = strtolower(trim($driver));
        $lowerComment = strtolower(trim($comment));

        // 1. Validate connection driver: must be mysql or mariadb
        if ($lowerDriver !== '' && !in_array($lowerDriver, ['mysql', 'mariadb'], true)) {
            throw new RuntimeException(
                "UNSUPPORTED DATABASE ENGINE: Connection driver '{$driver}' is not supported. " .
                'The Freemium Access Management System requires MySQL >= ' . self::MIN_MYSQL_VERSION .
                ' or MariaDB >= ' . self::MIN_MARIADB_VERSION . '.'
            );
        }

        // 2. Reject obvious non-MySQL / non-MariaDB signatures
        foreach (['sqlite', 'postgres', 'pgsql', 'microsoft', 'sqlsrv', 'oracle'] as $foreign) {
            if (str_contains($lowerVersion, $foreign) || str_contains($lowerComment, $foreign)) {
                throw new RuntimeException(
                    "UNSUPPORTED DATABASE ENGINE: Non-MySQL engine detected ('{$trimmed}'). " .
                    'The Freemium Access Management System requires MySQL >= ' . self::MIN_MYSQL_VERSION .
                    ' or MariaDB >= ' . self::MIN_MARIADB_VERSION . '.'
                );
            }
        }

        // 3. Determine if the engine is MariaDB
        $isMariaDb = str_contains($lowerVersion, 'mariadb')
            || str_contains($lowerComment, 'mariadb')
            || $lowerDriver === 'mariadb';

        if ($isMariaDb) {
            return self::evaluateMariaDbVersion($trimmed);
        }

        // 4. Otherwise evaluate as MySQL
        return self::evaluateMySqlVersion($trimmed);
    }

    /**
     * Parse and validate MariaDB version string.
     */
    protected static function evaluateMariaDbVersion(string $rawVersion): array
    {
        $clean = $rawVersion;

        // Strip MariaDB compatibility prefix: e.g. "5.5.5-10.6.12-MariaDB..." -> "10.6.12-MariaDB..."
        if (str_starts_with($clean, '5.5.5-')) {
            $clean = substr($clean, 6);
        }

        // Extract semantic version digits: e.g. "10.6.12-MariaDB" -> "10.6.12"
        if (!preg_match('/^(\d+(?:\.\d+)+)/', $clean, $matches)) {
            throw new RuntimeException(
                "MALFORMED DATABASE VERSION: Could not parse semantic version digits from MariaDB string: '{$rawVersion}'."
            );
        }

        $parsedVersion = $matches[1];

        if (version_compare($parsedVersion, self::MIN_MARIADB_VERSION, '<')) {
            throw new RuntimeException(
                "UNSUPPORTED DATABASE VERSION: MariaDB {$rawVersion} (detected version {$parsedVersion}) is not supported. " .
                'The Freemium Access Management System requires MariaDB >= ' . self::MIN_MARIADB_VERSION .
                ' to guarantee enforced CHECK constraints and generated column indexes. Migration aborted.'
            );
        }

        return [
            'supported'   => true,
            'engine'      => self::ENGINE_MARIADB,
            'version'     => $parsedVersion,
            'raw_version' => $rawVersion,
        ];
    }

    /**
     * Parse and validate MySQL version string.
     */
    protected static function evaluateMySqlVersion(string $rawVersion): array
    {
        // Extract semantic version digits: e.g. "8.0.35-0ubuntu0.22.04.1" -> "8.0.35"
        if (!preg_match('/^(\d+(?:\.\d+)+)/', $rawVersion, $matches)) {
            throw new RuntimeException(
                "MALFORMED DATABASE VERSION: Could not parse semantic version digits from database string: '{$rawVersion}'."
            );
        }

        $parsedVersion = $matches[1];

        if (version_compare($parsedVersion, self::MIN_MYSQL_VERSION, '<')) {
            throw new RuntimeException(
                "UNSUPPORTED DATABASE VERSION: MySQL {$rawVersion} (detected version {$parsedVersion}) is not supported. " .
                'The Freemium Access Management System requires MySQL >= ' . self::MIN_MYSQL_VERSION .
                ' to guarantee enforced CHECK constraints. MySQL versions prior to 8.0.16 silently ignore ' .
                'CHECK constraints, which would compromise ledger integrity. Migration aborted.'
            );
        }

        return [
            'supported'   => true,
            'engine'      => self::ENGINE_MYSQL,
            'version'     => $parsedVersion,
            'raw_version' => $rawVersion,
        ];
    }
}

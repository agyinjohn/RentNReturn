<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Versioned database migrations.
 *  - Empty database: builds it from storage/schema.sql (which records the migrations it already contains).
 *  - Existing database: applies each storage/migrations/*.sql not yet listed in schema_migrations, in filename order.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    public function __construct(private PDO $pdo, private string $storagePath, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    /** @return array<string, bool> migration version => applied? */
    public function status(): array
    {
        $applied = $this->appliedVersions();
        $status = [];
        foreach ($this->migrationFiles() as $version => $file) {
            $status[$version] = in_array($version, $applied, true);
        }
        return $status;
    }

    /** @return string[] versions applied by this run */
    public function migrate(): array
    {
        $ran = [];
        if (!$this->tableExists('users')) {
            ($this->output)('Empty database: creating tables from storage/schema.sql');
            $this->runFile($this->storagePath . '/schema.sql', true);
            $ran[] = 'schema.sql';
        }
        $applied = $this->appliedVersions();
        foreach ($this->migrationFiles() as $version => $file) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            ($this->output)("Applying {$version}");
            $this->runFile($file, false);
            $this->pdo->prepare('INSERT IGNORE INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $ran[] = $version;
        }
        return $ran;
    }

    /** @return array<string, string> version => path */
    private function migrationFiles(): array
    {
        $files = glob($this->storagePath . '/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.sql')] = $file;
        }
        return $out;
    }

    /** @return string[] */
    private function appliedVersions(): array
    {
        if (!$this->tableExists('schema_migrations')) {
            return [];
        }
        return $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function runFile(string $file, bool $isSchema): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read {$file}");
        }
        foreach (SqlScript::split($sql) as $n => $statement) {
            // schema.sql names a database for manual imports; the runner uses the configured one instead.
            if ($isSchema && preg_match('/^(CREATE\s+DATABASE|USE)\s/i', $statement)) {
                continue;
            }
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new \RuntimeException(sprintf(
                    "%s failed at statement %d: %s\n--- statement ---\n%s",
                    basename($file),
                    $n + 1,
                    $e->getMessage(),
                    $statement
                ), 0, $e);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\SqlScript;
use PHPUnit\Framework\TestCase;

final class SqlScriptTest extends TestCase
{
    public function testSplitsStatementsAndIgnoresComments(): void
    {
        $sql = "-- a comment; with a semicolon\nCREATE TABLE a (id INT);\n# hash comment;\n/* block; comment */ INSERT INTO a VALUES (1);\n\nSELECT 1";
        $this->assertSame(['CREATE TABLE a (id INT)', 'INSERT INTO a VALUES (1)', 'SELECT 1'], SqlScript::split($sql));
    }

    public function testSemicolonsInsideQuotesDoNotSplit(): void
    {
        $sql = "SET @sql = IF(1, 'ALTER TABLE x ADD y INT; -- not a comment', 'DO 0'); PREPARE s FROM @sql; SELECT \"a;b\", `we;ird`, 'it''s; fine', 'esc\\'; still'";
        $this->assertSame([
            "SET @sql = IF(1, 'ALTER TABLE x ADD y INT; -- not a comment', 'DO 0')",
            'PREPARE s FROM @sql',
            "SELECT \"a;b\", `we;ird`, 'it''s; fine', 'esc\\'; still'",
        ], SqlScript::split($sql));
    }

    public function testRealMigrationFilesSplitCleanly(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/storage/{schema.sql,migrations/*.sql}', GLOB_BRACE) as $file) {
            $statements = SqlScript::split((string) file_get_contents($file));
            $this->assertNotEmpty($statements, basename($file));
            foreach ($statements as $s) {
                $this->assertDoesNotMatchRegularExpression('/^\s*$/', $s);
            }
        }
    }
}

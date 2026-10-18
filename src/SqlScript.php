<?php

declare(strict_types=1);

namespace App;

/**
 * Splits a .sql file into individual statements so each one can be executed (and its error reported)
 * on its own. Handles quoted strings, backtick identifiers and -- / # / block comments.
 * DELIMITER blocks (stored procedures) are not supported — migrations should not need them.
 */
final class SqlScript
{
    /** @return string[] */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) { // doubled quote escape
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
            } elseif (($c === '-' && $next === '-' && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
            } elseif ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $current .= ' ';
            } elseif ($c === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}

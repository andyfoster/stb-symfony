<?php

namespace App\Legacy;

/**
 * Reads table rows out of a mysqldump/mariadb-dump file without a database:
 * column names from each `CREATE TABLE`, rows from its `INSERT INTO ... VALUES
 * (...),(...);` lines. Strings use MySQL's backslash escapes.
 *
 * The old site's tables are latin1 but its pages sent UTF-8, so most non-ASCII
 * text was stored as UTF-8 bytes and comes out of a UTF-8 dump encoded twice
 * ("MÄori" for "Māori"); repairEncoding() undoes that.
 */
final class MysqlDumpReader
{
    private const array ESCAPES = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A"];

    public function __construct(private readonly string $file) {}

    /**
     * @return \Generator<int, array<string, string|null>> each row keyed by column name
     */
    public function rows(string $table): \Generator
    {
        $handle = is_readable($this->file) ? fopen($this->file, 'r') : false;
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Can’t read %s.', $this->file));
        }

        try {
            $columns = null;
            $prefix  = \sprintf('INSERT INTO `%s` VALUES ', $table);
            while (false !== $line = fgets($handle)) {
                if (str_starts_with($line, \sprintf('CREATE TABLE `%s` (', $table))) {
                    $columns = $this->readColumns($handle);
                } elseif (str_starts_with($line, $prefix)) {
                    if ($columns === null) {
                        throw new \RuntimeException(\sprintf('Rows for %s come before its CREATE TABLE.', $table));
                    }
                    foreach ($this->parseValues($line, \strlen($prefix)) as $values) {
                        if (\count($values) !== \count($columns)) {
                            throw new \RuntimeException(\sprintf('A %s row has %d values for %d columns.', $table, \count($values), \count($columns)));
                        }
                        yield array_combine($columns, $values);
                    }
                }
            }
            if ($columns === null) {
                throw new \RuntimeException(\sprintf('There’s no %s table in %s.', $table, $this->file));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Turns UTF-8 that was read as latin1 and encoded again back into what was
     * typed. Text that was stored properly (or isn't UTF-8 underneath) is kept;
     * bytes that aren't UTF-8 at all are read as Windows-1252.
     */
    public static function repairEncoding(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        if (!preg_match('/[\x80-\xff]/', $value)) {
            return $value;
        }

        // MySQL's latin1 is Windows-1252, with its five unused bytes as U+0081 and so on.
        $bytes = '';
        foreach (mb_str_split($value) as $char) {
            $code = mb_ord($char);
            if ($code < 0x100) {
                $bytes .= \chr($code);
                continue;
            }
            $byte = mb_convert_encoding($char, 'Windows-1252', 'UTF-8');
            if (\strlen($byte) !== 1 || $byte === '?') {
                return $value;
            }
            $bytes .= $byte;
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $value;
    }

    /**
     * @param resource $handle positioned just after a CREATE TABLE line
     *
     * @return list<string>
     */
    private function readColumns($handle): array
    {
        $columns = [];
        while (false !== $line = fgets($handle)) {
            if (preg_match('/^\s+`([^`]+)`/', $line, $match)) {
                $columns[] = $match[1];
            } elseif (!str_starts_with(ltrim($line), 'PRIMARY') && !preg_match('/^\s+(UNIQUE |FULLTEXT )?KEY/', $line)) {
                break;
            }
        }

        return $columns;
    }

    /**
     * @return \Generator<int, list<string|null>>
     */
    private function parseValues(string $line, int $offset): \Generator
    {
        $length = \strlen($line);
        $i      = $offset;
        while ($i < $length && $line[$i] === '(') {
            ++$i;
            $row = [];
            while (true) {
                if ($line[$i] === "'") {
                    $value = '';
                    ++$i;
                    while (true) {
                        $end = strcspn($line, "'\\", $i);
                        $value .= substr($line, $i, $end);
                        $i += $end;
                        if ($i >= $length) {
                            throw new \RuntimeException('A string runs past the end of its line.');
                        }
                        if ($line[$i] === '\\') {
                            $value .= self::ESCAPES[$line[$i + 1]] ?? $line[$i + 1];
                            $i += 2;
                        } elseif (($line[$i + 1] ?? '') === "'") {
                            $value .= "'";
                            $i += 2;
                        } else {
                            ++$i;
                            break;
                        }
                    }
                    $row[] = self::repairEncoding($value);
                } else {
                    $end   = strcspn($line, ',)', $i);
                    $token = substr($line, $i, $end);
                    $i += $end;
                    $row[] = $token === 'NULL' ? null : $token;
                }

                if ($line[$i] === ',') {
                    ++$i;
                    continue;
                }
                if ($line[$i] !== ')') {
                    throw new \RuntimeException(\sprintf('Unexpected "%s" at column %d.', $line[$i], $i));
                }
                ++$i;
                break;
            }
            yield $row;

            if (($line[$i] ?? '') === ',') {
                ++$i;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Spamtroll\Tests\Support;

/**
 * A $wpdb that records instead of connecting.
 *
 * Only the handful of methods the plugin actually calls are here. prepare()
 * returns the SQL with the arguments interpolated so a test can assert on
 * the statement that would have run — which is how the `?paged=0` offset
 * regression is caught without a database.
 */
final class FakeWpdb
{
    public string $prefix = 'wp_';

    public string $options = 'wp_options';

    /** @var list<string> */
    public array $queries = [];

    /** @var list<array{table: string, where: array<string, mixed>, data: array<string, mixed>}> */
    public array $updates = [];

    /** @var list<array{table: string, where: array<string, mixed>}> */
    public array $deletes = [];

    /**
     * Rows get_results() hands back, and get_var()'s answer.
     *
     * @var list<array<string, mixed>>
     */
    public array $rows = [];

    /** @var mixed */
    public $var = 0;

    public int $update_result = 1;

    public int $delete_result = 1;

    public function __construct(private readonly WpEnv $env)
    {
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * @param string|array<int, mixed> $args
     */
    public function prepare(string $sql, $args = [], ...$rest): string
    {
        $values = is_array($args) ? $args : array_merge([ $args ], $rest);
        foreach ($values as $value) {
            $replacement = is_numeric($value) ? (string) $value : "'" . (string) $value . "'";
            $sql = preg_replace('/%[dfs]/', $replacement, $sql, 1) ?? $sql;
        }
        return $sql;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $formats
     */
    public function insert(string $table, array $data, array $formats = []): int
    {
        unset($formats);
        $this->env->inserts[] = [ 'table' => $table ] + $data;
        return 1;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @param list<string> $data_formats
     * @param list<string> $where_formats
     */
    public function update(string $table, array $data, array $where, array $data_formats = [], array $where_formats = []): int
    {
        unset($data_formats, $where_formats);
        $this->updates[] = [ 'table' => $table, 'where' => $where, 'data' => $data ];
        return $this->update_result;
    }

    /**
     * @param array<string, mixed> $where
     * @param list<string> $formats
     */
    public function delete(string $table, array $where, array $formats = []): int
    {
        unset($formats);
        $this->deletes[] = [ 'table' => $table, 'where' => $where ];
        return $this->delete_result;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        return 0;
    }

    /**
     * @return mixed
     */
    public function get_var(string $sql)
    {
        $this->queries[] = $sql;
        return $this->var;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        unset($output);
        $this->queries[] = $sql;
        return $this->rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        unset($output);
        $this->queries[] = $sql;
        return $this->rows[0] ?? null;
    }
}

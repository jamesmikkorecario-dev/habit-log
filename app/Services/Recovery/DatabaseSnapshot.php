<?php

namespace App\Services\Recovery;

use App\Actions\Accounts\SessionSecurity;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

final class DatabaseSnapshot
{
    // Parent-before-child order keeps foreign-key enforcement enabled on import.
    public const TABLES = ['users', 'categories', 'category_aliases', 'habits', 'habit_checkpoints',
        'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_entries', 'habit_checkpoint_entries',
        'habit_suspensions', 'journals', 'follows', 'pending_email_changes', 'sessions',
        'session_revocations', 'password_reset_tokens'];

    public function connection(): Connection
    {
        $connection = DB::connection();
        $passwords = config('auth.passwords.'.config('fortify.passwords'));
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== ''
            || $connection->transactionLevel() !== 0 || ! SessionSecurity::supported()
            || ($passwords['driver'] ?? 'database') !== 'database'
            || ($passwords['table'] ?? null) !== 'password_reset_tokens'
            || ($passwords['connection'] ?? config('database.default')) !== config('database.default')) {
            throw new RecoveryFailure;
        }

        return $connection;
    }

    public function schema(Connection $connection): array
    {
        $database = $connection->getDatabaseName();
        $tables = $connection->select('SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_TYPE AS kind, AUTO_INCREMENT AS next_id FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$database]);
        $expected = [...self::TABLES, 'migrations', 'recovery_state'];
        sort($expected);
        if (array_column($tables, 'name') !== $expected) {
            throw new RecoveryFailure;
        }
        foreach ($tables as $table) {
            if ($table->engine !== 'InnoDB' || $table->kind !== 'BASE TABLE') {
                throw new RecoveryFailure;
            }
        }
        foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $column) {
            if ($connection->table('information_schema.'.$table)->where($column, $database)->exists()) {
                throw new RecoveryFailure;
            }
        }
        $definitions = [];
        $columns = [];
        $sequences = [];
        foreach ($tables as $table) {
            $definition = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table->name.'`');
            $definitions[$table->name] = preg_replace('/ AUTO_INCREMENT=\d+/', '', $definition['Create Table']);
            $columns[$table->name] = array_column($connection->select('SHOW COLUMNS FROM `'.$table->name.'`'), 'Field');
            $sequences[$table->name] = $table->next_id;
        }
        $migrations = $connection->table('migrations')->orderBy('migration')->pluck('migration')->all();

        return ['hash' => hash('sha256', json_encode([$definitions, $migrations], JSON_THROW_ON_ERROR)),
            'columns' => $columns, 'sequences' => $sequences];
    }

    public function export(array $header): Generator
    {
        // A separate unbuffered connection gives a bounded-memory, consistent
        // snapshot without changing the web connection's buffering semantics.
        $source = $this->connection();
        $connection = DB::build($source->getConfig());
        $connection->getPdo()->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $connection->beginTransaction();
        try {
            // Establish the snapshot before reading metadata/rows. Schema
            // changes must be stopped by the operator for the entire command.
            $connection->table('users')->count();
            $schema = $this->schema($connection);
            yield ['type' => 'header', ...$header, 'schema' => $schema['hash'],
                'sequences' => $schema['sequences'], 'source_database' => $source->getDatabaseName()];
            foreach (self::TABLES as $table) {
                yield ['type' => 'table', 'name' => $table];
                $count = 0;
                foreach ($connection->table($table)->cursor() as $row) {
                    yield ['type' => 'row', 'values' => (array) $row];
                    $count++;
                }
                yield ['type' => 'end', 'count' => $count];
            }
            if ($this->schema($connection)['hash'] !== $schema['hash']) {
                throw new RecoveryFailure;
            }
            $connection->commit();
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->disconnect();
        }
    }

    public function assertEmpty(Connection $connection): void
    {
        foreach ([...self::TABLES, 'recovery_state'] as $table) {
            if ($connection->table($table)->exists()) {
                throw new RecoveryFailure;
            }
        }
    }

    public function import(iterable $records, Connection $connection, array $schema): array
    {
        $header = null;
        $current = null;
        $index = 0;
        $count = 0;
        foreach ($records as $record) {
            if ($header === null) {
                if (($record['type'] ?? null) !== 'header' || ($record['schema'] ?? null) !== $schema['hash']) {
                    throw new RecoveryFailure;
                }
                $header = $record;

                continue;
            }
            if (($record['type'] ?? null) === 'table' && $current === null && $index < count(self::TABLES)
                && ($record['name'] ?? null) === self::TABLES[$index]) {
                $current = $record['name'];
                $count = 0;
            } elseif (($record['type'] ?? null) === 'row' && $current !== null) {
                if (! is_array($record['values'] ?? null) || array_keys($record['values']) !== $schema['columns'][$current]) {
                    throw new RecoveryFailure;
                }
                $connection->table($current)->insert($record['values']);
                $count++;
            } elseif (($record['type'] ?? null) === 'end' && $current !== null && ($record['count'] ?? null) === $count) {
                $current = null;
                $index++;
            } else {
                throw new RecoveryFailure;
            }
        }
        if ($header === null || $current !== null || $index !== count(self::TABLES)) {
            throw new RecoveryFailure;
        }

        return $header;
    }
}

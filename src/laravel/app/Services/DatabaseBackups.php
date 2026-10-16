<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackups
{
    private function run(string $binary, array $arguments, array $connection, ?string $input = null): string
    {
        $env = ['PGHOST' => (string) $connection['host'], 'PGPORT' => (string) $connection['port'],
            'PGUSER' => (string) $connection['username'], 'PGPASSWORD' => (string) ($connection['password'] ?? ''),
            'PGDATABASE' => (string) $connection['database'], 'PGSSLMODE' => $connection['sslmode'] ?? 'prefer',
            'PGCONNECT_TIMEOUT' => '15', 'PGSERVICE' => false, 'PGSERVICEFILE' => false];
        $process = new Process([$binary, ...$arguments], null, $env, $input, 600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(basename($binary).' failed. Check the PostgreSQL client version, connection, and database permissions.');
        }

        return $process->getOutput();
    }

    private function counts(Connection $db): array
    {
        $counts = [];
        foreach ($db->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
            $counts[$table->tablename] = $db->table($table->tablename)->count();
        }

        return $counts;
    }

    public function create(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Backups require PostgreSQL.');
        }
        $id = (string) Str::uuid();
        DB::table('backup_runs')->insert(['id' => $id, 'status' => 'running', 'created_at' => now()]);
        try {
            [$dump, $counts] = DB::transaction(function () {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $snapshot = DB::selectOne('SELECT pg_export_snapshot() AS snapshot')->snapshot;
                $counts = $this->counts(DB::connection());
                $dump = $this->run(config('backups.pg_dump'), ['--format=custom', '--no-owner', '--no-acl', '--no-password', '--snapshot='.$snapshot], DB::connection()->getConfig());

                return [$dump, $counts];
            });
            $metadata = ['format' => 1, 'id' => $id, 'created_at' => now()->toIso8601String(), 'counts' => $counts, 'dump_sha256' => hash('sha256', $dump)];
            $encrypted = Crypt::encryptString(json_encode([...$metadata, 'dump' => base64_encode($dump)], JSON_THROW_ON_ERROR));
            $path = 'database/'.now()->format('Ymd_His').'_'.$id.'.enc';
            $disk = Storage::disk('backups');
            $disk->put($path, $encrypted, ['visibility' => 'private']);
            $hash = hash('sha256', $encrypted);
            if (! hash_equals($hash, hash('sha256', $disk->get($path)))) {
                throw new RuntimeException('Backup read-back checksum failed.');
            }
            DB::table('backup_runs')->where('id', $id)->update(['path' => $path, 'sha256' => $hash, 'bytes' => strlen($encrypted), 'counts' => json_encode($counts)]);
            $mirror = config('backups.mirror_disk');
            if ($mirror) {
                if (in_array($mirror, ['public', 'local', 'backups'], true)) {
                    throw new RuntimeException('Choose a separate private mirror disk.');
                }
                Storage::disk($mirror)->put($path, $encrypted, ['visibility' => 'private']);
                if (! hash_equals($hash, hash('sha256', Storage::disk($mirror)->get($path)))) {
                    throw new RuntimeException('Mirror read-back checksum failed.');
                }
            }
            DB::table('backup_runs')->where('id', $id)->update(['status' => 'ready', 'mirrored' => (bool) $mirror, 'finished_at' => now()]);

            return [...$metadata, 'path' => $path, 'bytes' => strlen($encrypted), 'mirrored' => (bool) $mirror];
        } catch (\Throwable $e) {
            DB::table('backup_runs')->where('id', $id)->update(['status' => 'failed', 'error' => 'Backup failed; check storage, PostgreSQL client, and connection configuration.', 'finished_at' => now()]);
            throw new RuntimeException('Backup failed. See Head Chef backup status and check the configured destinations.', previous: $e);
        }
    }

    public static function assertTarget(string $name, string $source): void
    {
        if (! preg_match('/^sushi_rehearsal_[a-z0-9_]{8,40}$/', $name) || $name === $source) {
            throw new RuntimeException('Rehearsals require a new sushi_rehearsal_ database and cannot target the live database.');
        }
    }

    public function rehearse(string $id): array
    {
        $run = DB::table('backup_runs')->where('id', $id)->where('status', 'ready')->first();
        if (! $run) {
            throw new RuntimeException('Choose a completed backup ID.');
        }
        $connection = config('backups.restore');
        if (empty($connection['host']) || empty($connection['username'])) {
            throw new RuntimeException('Configure a dedicated rehearsal PostgreSQL connection first.');
        }
        if (app()->environment('production') && $connection['host'] === DB::connection()->getConfig('host')) {
            throw new RuntimeException('Production rehearsals require a separate PostgreSQL server.');
        }
        $encrypted = Storage::disk('backups')->get($run->path);
        if (! hash_equals($run->sha256, hash('sha256', $encrypted))) {
            throw new RuntimeException('Backup checksum mismatch.');
        }
        $bundle = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        $dump = base64_decode($bundle['dump'], true);
        if ($dump === false || ! hash_equals($bundle['dump_sha256'], hash('sha256', $dump))) {
            throw new RuntimeException('Archive checksum mismatch.');
        }
        $target = 'sushi_rehearsal_'.now()->format('Ymd_His').'_'.strtolower(Str::random(8));
        self::assertTarget($target, DB::connection()->getDatabaseName());
        config(['database.connections.sushi_restore_admin' => [...$connection, 'driver' => 'pgsql', 'database' => 'postgres', 'search_path' => 'public']]);
        DB::purge('sushi_restore_admin');
        $admin = DB::connection('sushi_restore_admin');
        if ($admin->selectOne('SELECT 1 AS found FROM pg_database WHERE datname = ?', [$target])) {
            throw new RuntimeException('The rehearsal database already exists; refusing to overwrite it.');
        }
        $admin->statement('CREATE DATABASE "'.$target.'" TEMPLATE template0');
        try {
            $this->run(config('backups.pg_restore'), ['--no-owner', '--no-acl', '--exit-on-error', '--single-transaction', '--no-password', '--dbname='.$target], [...$connection, 'database' => $target], $dump);
            config(['database.connections.sushi_restore_check' => [...$connection, 'driver' => 'pgsql', 'database' => $target, 'search_path' => 'public']]);
            DB::purge('sushi_restore_check');
            $restored = $this->counts(DB::connection('sushi_restore_check'));
            if ($restored !== $bundle['counts']) {
                throw new RuntimeException('Restored table counts do not match the snapshot.');
            }
            DB::disconnect('sushi_restore_check');
            // This database was created by this invocation and is never an existing app database.
            $admin->statement('DROP DATABASE "'.$target.'"');
            DB::table('backup_runs')->where('id', $id)->update(['verified_at' => now(), 'restore_database' => $target]);

            return ['backup_id' => $id, 'tables_verified' => count($restored), 'counts' => $restored, 'rehearsal_database' => $target, 'temporary_database_removed' => true];
        } catch (\Throwable $e) {
            DB::disconnect('sushi_restore_check');
            throw new RuntimeException('Restore rehearsal failed. The isolated database '.$target.' was retained for inspection.', previous: $e);
        }
    }
}

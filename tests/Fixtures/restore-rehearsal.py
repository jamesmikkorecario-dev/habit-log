"""Local Compose restore drill. Only synthetic habit_log_test may be exported."""
import fcntl
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
os.chdir(ROOT)
if os.environ.get('HABIT_LOG_RESTORE_REHEARSAL') != '1':
    raise SystemExit('Set HABIT_LOG_RESTORE_REHEARSAL=1 for the isolated test-data drill.')
lock = open('storage/framework/testing.lock', 'a')
fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
source = 'habit_log_test'
target = 'habit_log_restore_' + secrets.token_hex(4)
evidence = ROOT / 'storage/app/phase7-checks'
evidence.mkdir(parents=True, exist_ok=True)


def mysql(sql, database=None):
    command = 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot --batch --skip-column-names'
    if database:
        assert re.fullmatch(r'habit_log_(test|restore_[0-9a-f]{8})', database)
        command += ' ' + database
    return subprocess.run(['docker', 'compose', 'exec', '-T', 'mysql', 'sh', '-c', command],
                          input=sql, text=True, capture_output=True, check=True).stdout.strip()


def checksum(database):
    tables = mysql('SHOW TABLES', database).splitlines()
    result = {}
    for table in tables:
        assert re.fullmatch(r'[a-z_]+', table)
        result[table] = {'rows': int(mysql(f'SELECT COUNT(*) FROM `{table}`', database)),
                         'checksum': mysql(f'CHECKSUM TABLE `{table}`', database).split('\t')[-1]}
    return result


def application_check(database):
    # Production-mode, uncached CLI read of the restored models and policies.
    code = r'''<?php
    try {
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        if ($app->configurationIsCached() || !preg_match('/^habit_log_(test|restore_[0-9a-f]{8})$/', config('database.connections.mysql.database'))
            || Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS name')->name !== config('database.connections.mysql.database')) exit(2);
        $journal = App\Models\Journal::firstOrFail();
        $owner = App\Models\User::findOrFail($journal->user_id);
        $other = App\Models\User::where('id', '!=', $owner->id)->firstOrFail();
        $report = app(App\Services\Analytics\ActivityAnalytics::class)->report($owner, (int)substr($owner->localToday(),0,4));
        if (!Illuminate\Support\Facades\Gate::forUser($owner)->allows('view', $journal)
            || Illuminate\Support\Facades\Gate::forUser($other)->allows('view', $journal)) exit(3);
        echo json_encode(['report_sha256'=>hash('sha256',json_encode($report)), 'journal_policy'=>true]);
    } catch (Throwable $e) { fwrite(STDERR, 'Restore application check failed: '.$e::class); exit(1); }
    '''
    env = dict(os.environ, APP_ENV='production', APP_DEBUG='false', DB_CONNECTION='mysql',
               DB_HOST='127.0.0.1', DB_PORT='3306', DB_DATABASE=database, DB_USERNAME='habit_log',
               DB_PASSWORD='habit_log_local', DB_URL='', MAIL_MAILER='array')
    result = subprocess.run(['php'], input=code, text=True, capture_output=True, check=True, env=env)
    return json.loads(result.stdout)


assert mysql('SELECT DATABASE()', source) == source
# Ensure every drill includes journal/mood and a relationship, even after E2E cleanup.
mysql("INSERT IGNORE INTO journals (user_id,journal_date,content,mood,revision,created_at,updated_at) SELECT id,'2026-10-05','Synthetic restore drill',3,1,NOW(),NOW() FROM users ORDER BY id LIMIT 1", source)
mysql("INSERT IGNORE INTO follows (follower_id,followed_id,status,requested_at,accepted_at,created_at,updated_at) SELECT a.id,b.id,'accepted',NOW(),NOW(),NOW(),NOW() FROM users a JOIN users b ON a.id < b.id ORDER BY a.id,b.id LIMIT 1", source)
before = checksum(source)
for table in ['users', 'habits', 'habit_entries', 'habit_tracking_versions', 'habit_tracking_checkpoints', 'habit_checkpoint_entries', 'journals', 'follows']:
    assert before[table]['rows'] > 0, f'Populated {table} required for a meaningful restore drill.'
source_app = application_check(source)
created = False
try:
    with tempfile.TemporaryDirectory(prefix='habit-restore-') as scratch_name:
        scratch = Path(scratch_name)
        key = scratch / 'passphrase'
        key.write_text(secrets.token_urlsafe(48))
        key.chmod(0o600)
        plain = scratch / 'backup.sql'
        os.umask(0o077)
        start = time.monotonic()
        with plain.open('wb') as output:
            subprocess.run(['docker', 'compose', 'exec', '-T', 'mysql', 'sh', '-c',
                'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF --routines --triggers --events habit_log_test'], stdout=output, stderr=subprocess.PIPE, check=True)
        dump_seconds = time.monotonic() - start
        dump_bytes = plain.stat().st_size
        gpg_env = dict(os.environ, GNUPGHOME=str(scratch / 'gnupg'))
        (scratch / 'gnupg').mkdir(mode=0o700)
        encrypted = evidence / 'restore-backup.gpg'
        gpg = ['gpg', '--batch', '--yes', '--pinentry-mode', 'loopback', '--passphrase-file', str(key)]
        subprocess.run(gpg + ['--cipher-algo', 'AES256', '--output', str(encrypted), '--symmetric', str(plain)], env=gpg_env, capture_output=True, check=True)
        plain.unlink()
        subprocess.run(gpg + ['--output', str(plain), '--decrypt', str(encrypted)], env=gpg_env, capture_output=True, check=True)
        mysql(f'CREATE DATABASE `{target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
        created = True
        mysql(f"GRANT SELECT ON `{target}`.* TO 'habit_log'@'%'")
        start = time.monotonic()
        with plain.open('rb') as data:
            subprocess.run(['docker', 'compose', 'exec', '-T', 'mysql', 'sh', '-c',
                f'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot {target}'], stdin=data, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, check=True)
        restore_seconds = time.monotonic() - start
        plain.unlink()
        after = checksum(target)
        assert before == after, 'Restored table counts/checksums differ.'
        restored_app = application_check(target)
        assert source_app == restored_app, 'Restored analytics/policy results differ.'
        result = {'source': source, 'target': target, 'tables': before, 'dump_bytes': dump_bytes,
                  'encrypted_bytes': encrypted.stat().st_size, 'dump_seconds': round(dump_seconds, 2),
                  'restore_seconds': round(restore_seconds, 2), 'all_checksums_match': True,
                  'application_check': restored_app, 'temporary_key_destroyed_after_drill': True}
        (evidence / 'restore.json').write_text(json.dumps(result, indent=2))
        print(json.dumps({k: v for k, v in result.items() if k not in ['tables', 'application_check']}))
finally:
    if created:
        mysql(f"REVOKE SELECT ON `{target}`.* FROM 'habit_log'@'%'")
        mysql(f'DROP DATABASE `{target}`')

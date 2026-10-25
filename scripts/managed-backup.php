<?php
declare(strict_types=1);

// Local/host CLI maintenance only. Never publish this script or its output.
if (PHP_SAPI !== 'cli' || !in_array($argc, [1, 2], true) || ($argc === 2 && $argv[1] !== '--prune')) {
    fwrite(STDERR, "Usage: php scripts/managed-backup.php [--prune]\n");
    exit(2);
}
$public = getenv('AOV_PUBLIC_DIR') ?: dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $public;
require rtrim($public, '/\\') . '/bootstrap.php';

$private = aov_private_dir();
$directory = $private . DIRECTORY_SEPARATOR . 'managed-backups';
if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) throw new RuntimeException('Cannot create private backup directory');
@chmod($directory, 0700);
$stamp = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
$base = $directory . DIRECTORY_SEPARATOR . 'aov-managed-' . $stamp;
$sqlite = $base . '.sqlite';
$sql = $base . '.sql';
$manifest = $base . '.json';
$config = aov_config()['chat_mysql'] ?? null;
if (!is_array($config) || empty($config['name']) || empty($config['user']) || !isset($config['password'])) throw new RuntimeException('Chat backup configuration is missing');
if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string) $config['host']) || !preg_match('/^[a-zA-Z0-9_]+$/', (string) $config['name'])) throw new RuntimeException('Invalid chat host or database name');
try {
    $db = aov_db();
    $db->exec('VACUUM INTO ' . $db->quote($sqlite));
    @chmod($sqlite, 0600);
    $check = new PDO('sqlite:' . $sqlite, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('SQLite backup failed integrity check');
    if (!$check->query("SELECT 1 FROM sqlite_master WHERE name='users'")->fetchColumn()) throw new RuntimeException('SQLite backup has no user table');
    unset($check);
    $mysql = new PDO('mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4', $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $mysql->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables || !in_array('ajax_chat_messages', $tables, true)) throw new RuntimeException('Chat database tables are missing');
    $file = fopen($sql, 'wb');
    if ($file === false) throw new RuntimeException('Cannot create chat export');
    try {
        fwrite($file, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n");
        $mysql->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) throw new RuntimeException('Unexpected chat table name');
            $name = '`' . $table . '`';
            $definition = $mysql->query('SHOW CREATE TABLE ' . $name)->fetch(PDO::FETCH_NUM);
            if (!is_array($definition) || !isset($definition[1])) throw new RuntimeException('Cannot read chat table schema');
            fwrite($file, "DROP TABLE IF EXISTS $name;\n" . $definition[1] . ";\n");
            $rows = $mysql->query('SELECT * FROM ' . $name);
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $columns = implode(',', array_map(static fn(string $key): string => '`' . $key . '`', array_keys($row)));
                $values = implode(',', array_map(static fn($value): string => $value === null ? 'NULL' : $mysql->quote((string) $value), array_values($row)));
                if (fwrite($file, "INSERT INTO $name ($columns) VALUES ($values);\n") === false) throw new RuntimeException('Cannot write chat export');
            }
        }
        $mysql->commit();
        fwrite($file, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($file);
    }
    @chmod($sql, 0600);
    $contents = file_get_contents($sql);
    if ($contents === false || !str_contains($contents, 'CREATE TABLE') || !str_contains($contents, 'ajax_chat_messages')) throw new RuntimeException('Chat export is incomplete');
    $record = ['created_at' => gmdate('c'), 'sqlite_sha256' => hash_file('sha256', $sqlite), 'chat_sha256' => hash_file('sha256', $sql), 'chat_database' => $config['name']];
    if (file_put_contents($manifest, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('Cannot write backup manifest');
    @chmod($manifest, 0600);
    echo "Verified AOV-managed backup: $stamp\n";
    if ($argc === 2) {
        $cutoff = time() - 30 * 86400;
        $removed = 0;
        foreach (new DirectoryIterator($directory) as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            if (!preg_match('/^aov-managed-\d{8}-\d{6}-[a-f0-9]{8}\.(?:sqlite|sql|json)$/', $file->getFilename())) continue;
            if ($file->getMTime() >= $cutoff) continue;
            if (!unlink($file->getPathname())) throw new RuntimeException('Could not prune an expired managed backup');
            $removed++;
        }
        echo "Expired managed files removed: $removed\n";
    }
} catch (Throwable $error) {
    foreach ([$sqlite, $sql, $manifest] as $path) if (is_file($path)) unlink($path);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3 || !ctype_digit($argv[1])) {
    fwrite(STDERR, "Run locally: php scripts/grant-admin.php USER_ID EXACT_SCREEN_NAME\n");
    exit(2);
}

require dirname(__DIR__) . '/bootstrap.php';

$id = (int) $argv[1];
$name = $argv[2];
$db = aov_db();
$query = $db->prepare('SELECT username FROM users WHERE id=?');
$query->execute([$id]);
if ($id < 1 || $query->fetchColumn() !== $name) {
    fwrite(STDERR, "The ID and screen name do not match an account.\n");
    exit(1);
}
$backup = aov_private_dir() . '/aov-before-admin-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
$db->exec('VACUUM INTO ' . $db->quote($backup));
(new Delight\Auth\Administration($db))->addRoleForUserById($id, Delight\Auth\Role::ADMIN);
echo "Administrator role assigned to {$name}. A private SQLite backup was created.\n";

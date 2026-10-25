<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Delight\Auth\Auth;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('BEGIN IMMEDIATE');
$db->exec(file_get_contents(dirname(__DIR__) . '/vendor/delight-im/auth/Database/SQLite.sql'));
$db->exec('CREATE UNIQUE INDEX aov_username_unique ON users(username COLLATE NOCASE) WHERE username IS NOT NULL');
$db->exec('COMMIT');
$db->exec('PRAGMA foreign_keys = ON');
$auth = new Auth($db, '127.0.0.1', null, false);
$name = 'LifecycleTest';
$email = 'lifecycle@example.com';
$firstPassword = 'one-strong-test-passphrase';
$secondPassword = 'second-strong-test-passphrase';
$auth->registerWithUniqueUsername($email, $firstPassword, $name);
try {
    $auth->registerWithUniqueUsername('different@example.com', $firstPassword, strtoupper($name));
    throw new RuntimeException('Case-insensitive duplicate screen name was accepted');
} catch (\Delight\Auth\DuplicateUsernameException $expectedDuplicate) {
    // Expected: screen names are unique regardless of case.
}
$hash = $db->query('SELECT password FROM users')->fetchColumn();
$expected = in_array(PASSWORD_ARGON2ID, password_algos(), true) ? 'argon2id' : 'bcrypt';
if (password_get_info((string) $hash)['algoName'] !== $expected) throw new RuntimeException('Unexpected password algorithm');
$pair = [];
$auth->forgotPassword($email, static function ($selector, $token) use (&$pair): void { $pair = [$selector, $token]; }, 3600);
if (count($pair) !== 2 || !$auth->canResetPassword($pair[0], $pair[1])) throw new RuntimeException('Reset token unavailable');
$auth->resetPassword($pair[0], $pair[1], $secondPassword);
if ($auth->canResetPassword($pair[0], $pair[1])) throw new RuntimeException('Reset token was reused');
$auth->loginWithUsername(strtoupper($name), $secondPassword);
if (!$auth->isLoggedIn() || $auth->getUsername() !== $name) throw new RuntimeException('Case-insensitive login failed');
echo "Authentication lifecycle passed\n";

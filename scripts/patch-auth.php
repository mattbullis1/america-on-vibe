<?php
declare(strict_types=1);

// MIT-licensed delight-im/auth v9.0.0 uses PASSWORD_DEFAULT (bcrypt on PHP 8.2).
// This small, reproducible local patch selects Argon2id when the host supports it.
$file = dirname(__DIR__) . '/vendor/delight-im/auth/src/PasswordHash.php';
$source = file_get_contents($file);
if ($source === false) throw new RuntimeException('Missing PHP-Auth dependency');
if (str_contains($source, 'private static function algorithm()')) exit(0);
$old = "\tconst HASH_ALGORITHM_IDENTIFIER = \\PASSWORD_DEFAULT;";
$new = "\tprivate static function algorithm() {\n\t\treturn \\defined('PASSWORD_ARGON2ID') && \\in_array(\\PASSWORD_ARGON2ID, \\password_algos(), true) ? \\PASSWORD_ARGON2ID : \\PASSWORD_BCRYPT;\n\t}";
if (!str_contains($source, $old)) throw new RuntimeException('PHP-Auth hash implementation changed; review patch');
$source = str_replace($old, $new, $source);
$source = str_replace('self::HASH_ALGORITHM_IDENTIFIER', 'self::algorithm()', $source);
file_put_contents($file, $source);

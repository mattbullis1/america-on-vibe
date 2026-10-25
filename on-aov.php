<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bulletins.php';

$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
header('Cache-Control: private, no-store');
try {
    aov_json(['posts' => aov_bulletins_public()]);
} catch (Throwable $error) {
    error_log('AOV posts error: ' . get_class($error));
    aov_json(['error' => 'AOV posts are temporarily unavailable'], 503);
}

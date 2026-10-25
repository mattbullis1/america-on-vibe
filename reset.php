<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth = aov_bootstrap();
$selector = (string) ($_GET['selector'] ?? '');
$token = (string) ($_GET['token'] ?? '');
try {
    $valid = strlen($selector) <= 24 && strlen($token) <= 128 && $selector !== '' && $token !== '' && $auth->canResetPassword($selector, $token);
} catch (Throwable $error) {
    $valid = false;
}
if ($valid) {
    $_SESSION['aov_reset'] = ['selector' => $selector, 'token' => $token];
    header('Location: ./?reset=1', true, 303);
} else {
    header('Location: ./?reset=invalid', true, 303);
}
exit;

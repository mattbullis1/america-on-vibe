<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/browser.php';

use Delight\Auth\AuthException;
use PHPMailer\PHPMailer\PHPMailer;

$auth = aov_bootstrap();
$action = (string) ($_GET['action'] ?? '');
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    aov_touch($auth);
    aov_json(aov_state($auth));
}
if ($action === 'browser_state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
    aov_touch($auth);
    aov_json(aov_browser_state((int) $auth->getUserId()));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') aov_json(['error' => 'Method not allowed'], 405);
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) aov_json(['error' => 'Invalid request'], 400);
if (!hash_equals(aov_csrf(), (string) ($_SERVER['HTTP_X_AOV_CSRF'] ?? ''))) aov_json(['error' => 'Please reload and try again'], 403);

function field(array $input, string $name, int $max = 2048): string {
    $value = $input[$name] ?? '';
    if (!is_string($value) || strlen($value) > $max) throw new InvalidArgumentException('Invalid form value');
    return $value;
}

function valid_name(string $name): bool {
    static $reserved = ['admin', 'administrator', 'aov', 'aol', 'system', 'support', 'moderator', 'mod', 'staff', 'root', 'help', 'postmaster'];
    return (bool) preg_match('/^[A-Za-z][A-Za-z0-9_]{2,23}$/D', $name) && !in_array(strtolower($name), $reserved, true);
}

function valid_password(string $password): bool {
    return strlen($password) >= 12 && strlen($password) <= 2048;
}

function valid_zip(string $zip): bool {
    return (bool) preg_match('/^[0-9]{5}$/D', $zip);
}

try {
    switch ($action) {
        case 'register':
            if (field($input, 'website', 200) !== '') aov_json(['error' => 'Registration could not be completed'], 400);
            $name = trim(field($input, 'name', 24));
            $email = trim(field($input, 'email', 249));
            $zip = trim(field($input, 'zipCode', 5));
            $password = field($input, 'password');
            if (!valid_name($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !valid_zip($zip) || !valid_password($password) ||
                !hash_equals($password, field($input, 'confirm')) || ($input['terms'] ?? false) !== true) {
                aov_json(['error' => 'Check the screen name, email, five-digit ZIP code, password, and terms'], 400);
            }
            require_once __DIR__ . '/local-data.php';
            if (aov_zip_location($zip) === null) aov_json(['error' => 'Enter a valid US ZIP code'], 400);
            $id = $auth->registerWithUniqueUsername($email, $password, $name);
            $stmt = aov_db()->prepare('INSERT INTO aov_profiles (user_id, terms_accepted_at, zip_code) VALUES (?, ?, ?)');
            $stmt->execute([$id, time(), $zip]);
            aov_event($id, 'register');
            $auth->loginWithUsername($name, $password);
            session_regenerate_id(true);
            $_SESSION['aov_last_activity'] = time();
            $_SESSION['aov_csrf'] = bin2hex(random_bytes(32));
            aov_json(aov_state($auth));

        case 'login':
            $name = trim(field($input, 'name', 24));
            $password = field($input, 'password');
            if (!valid_name($name) || $password === '') aov_json(['error' => 'Screen name or password is incorrect'], 401);
            $auth->loginWithUsername($name, $password);
            session_regenerate_id(true);
            $_SESSION['aov_last_activity'] = time();
            $_SESSION['aov_csrf'] = bin2hex(random_bytes(32));
            aov_event((int) $auth->getUserId(), 'login');
            aov_json(aov_state($auth));

        case 'logout':
            if ($auth->isLoggedIn()) {
                aov_event((int) $auth->getUserId(), 'logout');
                $auth->logOut();
            }
            unset($_SESSION['aov_last_activity']);
            session_regenerate_id(true);
            $_SESSION['aov_csrf'] = bin2hex(random_bytes(32));
            aov_json(aov_state($auth));

        case 'recovery':
            $mailConfig = aov_config();
            if (empty($mailConfig['smtp']['host']) || empty($mailConfig['smtp']['from']) || empty($mailConfig['base_url'])) {
                aov_json(['error' => 'Password recovery email is not configured yet'], 503);
            }
            $email = trim(field($input, 'email', 249));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try {
                    $smtp = aov_config()['smtp'];
                    $smtp['recipient'] = $email;
                    $auth->forgotPassword($email, function ($selector, $token) use ($smtp): void {
                        send_recovery_to($smtp, $selector, $token);
                    }, 3600);
                } catch (AuthException $ignored) {
                    // Identical response for unknown addresses and other account states.
                } catch (Throwable $error) {
                    error_log('AOV recovery delivery failed: ' . get_class($error));
                }
            }
            aov_json(['message' => 'If that address belongs to an AOV account, a recovery link will be sent.']);

        case 'reset':
            if (!isset($_SESSION['aov_reset']) || !is_array($_SESSION['aov_reset'])) aov_json(['error' => 'Open a fresh recovery link'], 400);
            $password = field($input, 'password');
            if (!valid_password($password) || !hash_equals($password, field($input, 'confirm'))) aov_json(['error' => 'Use a matching password of at least 12 characters'], 400);
            $reset = $_SESSION['aov_reset'];
            $auth->resetPassword((string) $reset['selector'], (string) $reset['token'], $password);
            unset($_SESSION['aov_reset']);
            session_regenerate_id(true);
            $_SESSION['aov_csrf'] = bin2hex(random_bytes(32));
            aov_json(['message' => 'Password changed. Please sign on.', 'csrf' => aov_csrf()]);

        case 'change_password':
            if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
            $current = field($input, 'current');
            $new = field($input, 'password');
            if (!valid_password($new) || !hash_equals($new, field($input, 'confirm'))) aov_json(['error' => 'Use a matching password of at least 12 characters'], 400);
            $auth->changePassword($current, $new);
            aov_event((int) $auth->getUserId(), 'password.change');
            aov_touch($auth);
            aov_json(['message' => 'Password changed']);

        case 'change_name':
            if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
            $name = trim(field($input, 'name', 24));
            if (!valid_name($name)) aov_json(['error' => 'Choose a different screen name'], 400);
            if (!$auth->reconfirmPassword(field($input, 'current'))) aov_json(['error' => 'Password is incorrect'], 401);
            $auth->changeUsername($name, true);
            aov_event((int) $auth->getUserId(), 'name.change');
            aov_touch($auth);
            aov_json(aov_state($auth));

        case 'change_zip':
            if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
            $zip = trim(field($input, 'zipCode', 5));
            if (!valid_zip($zip)) aov_json(['error' => 'Enter a five-digit US ZIP code'], 400);
            require_once __DIR__ . '/local-data.php';
            if (aov_zip_location($zip) === null) aov_json(['error' => 'Enter a valid US ZIP code'], 400);
            $stmt = aov_db()->prepare('UPDATE aov_profiles SET zip_code = ? WHERE user_id = ?');
            $stmt->execute([$zip, (int) $auth->getUserId()]);
            aov_event((int) $auth->getUserId(), 'location.change');
            aov_touch($auth);
            aov_json(aov_state($auth));

        case 'browser_visit':
        case 'browser_bookmark_add':
        case 'browser_bookmark_remove':
        case 'browser_home_set':
        case 'browser_history_clear':
            if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
            $result = aov_browser_action($action, $input, (int) $auth->getUserId());
            aov_touch($auth);
            aov_json($result);

        default:
            aov_json(['error' => 'Unknown action'], 404);
    }
} catch (AuthException $e) {
    $message = $action === 'register' ? 'That screen name or email is unavailable, or registration is temporarily limited' :
        ($action === 'reset' ? 'Recovery link is invalid or expired' : 'Screen name or password is incorrect, or too many attempts were made');
    aov_json(['error' => $message], 400);
} catch (InvalidArgumentException $e) {
    aov_json(['error' => str_starts_with($action, 'browser_') ? $e->getMessage() : 'Invalid form value'], 400);
} catch (Throwable $e) {
    error_log('AOV account error: ' . get_class($e) . ' ' . $e->getMessage());
    aov_json(['error' => 'Account service is temporarily unavailable'], 503);
}

function send_recovery_to(array $smtp, string $selector, string $token): void {
    // Address comes only from the validated recovery request; credentials stay in private config.
    $config = aov_config();
    if (empty($config['base_url'])) throw new RuntimeException('Recovery base URL is not configured');
    $link = rtrim((string) $config['base_url'], '/') . '/reset.php?selector=' . rawurlencode($selector) . '&token=' . rawurlencode($token);
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $smtp['host'];
    $mail->Port = (int) ($smtp['port'] ?? 587);
    $mail->SMTPAuth = !empty($smtp['username']);
    $mail->Username = (string) ($smtp['username'] ?? '');
    $mail->Password = (string) ($smtp['password'] ?? '');
    $mail->SMTPSecure = ($smtp['security'] ?? 'starttls') === 'smtps' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $smtp['from'], 'AOV Account Recovery');
    $mail->addAddress((string) $smtp['recipient']);
    $mail->Subject = 'Reset your AOV password';
    $mail->Body = "A password reset was requested for your AOV account.\n\nOpen this link within one hour:\n{$link}\n\nIf you did not request this, ignore this message.";
    $mail->send();
}

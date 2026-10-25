<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/bulletins.php';

use Delight\Auth\Role;
use Delight\Auth\Status;

$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
if (!$auth->hasRole(Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN)) aov_json(['error' => 'Forbidden'], 403);
$me = (int) $auth->getUserId();

function aov_admin_state(): array {
    $db = aov_db();
    $channels = json_decode((string) file_get_contents(__DIR__ . '/channels.json'), true);
    return [
        'users' => $db->query('SELECT u.id,u.username,u.email,u.status,u.roles_mask,u.registered,u.last_login,COALESCE(p.recovery_email_verified,0) AS recovery_email_verified FROM users u LEFT JOIN aov_profiles p ON p.user_id=u.id ORDER BY u.id DESC LIMIT 100')->fetchAll(),
        'rooms' => $db->query('SELECT id,name,category,enabled FROM aov_chat_rooms ORDER BY id LIMIT 50')->fetchAll(),
        'events' => $db->query('SELECT e.id,e.event,e.at,u.username FROM aov_security_events e LEFT JOIN users u ON u.id=e.user_id ORDER BY e.id DESC LIMIT 100')->fetchAll(),
        'mailReports' => $db->query("SELECT r.id,r.reason,r.created_at,m.subject,m.body,s.username AS sender,t.username AS reporter FROM aov_mail_reports r JOIN aov_mail m ON m.id=r.message_id JOIN users s ON s.id=m.sender_id JOIN users t ON t.id=r.reporter_id WHERE r.status='open' ORDER BY r.id DESC LIMIT 50")->fetchAll(),
        'channels' => is_array($channels) ? $channels : [],
        'bulletins' => aov_bulletins_read(),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') aov_json(aov_admin_state());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') aov_json(['error' => 'Method not allowed'], 405);
if (!hash_equals(aov_csrf(), (string) ($_SERVER['HTTP_X_AOV_CSRF'] ?? ''))) aov_json(['error' => 'Please reload and try again'], 403);
$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) aov_json(['error' => 'Invalid request'], 400);
$action = (string) ($_GET['action'] ?? '');

try {
    if ($action === 'mail_report_close') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) aov_json(['error' => 'Invalid report'], 400);
        $stmt = aov_db()->prepare("UPDATE aov_mail_reports SET status='closed',closed_at=? WHERE id=? AND status='open'");
        $stmt->execute([time(), $id]);
        if (!$stmt->rowCount()) aov_json(['error' => 'Report not found'], 404);
        aov_event($me, 'admin_mail_report_close');
    } elseif (in_array($action, ['suspend', 'restore', 'rename'], true)) {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || $id === $me) aov_json(['error' => 'Choose another account'], 400);
        $stmt = aov_db()->prepare('SELECT username,status,roles_mask FROM users WHERE id=?');
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) aov_json(['error' => 'Account not found'], 404);
        $roles = (int) $target['roles_mask'];
        if (($roles & Role::SUPER_ADMIN) || (($roles & Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN))) {
            aov_json(['error' => 'This account requires a super administrator'], 403);
        }
        if ($action === 'suspend') {
            $stmt = aov_db()->prepare('UPDATE users SET status=?,force_logout=force_logout+1 WHERE id=? AND status=?');
            $stmt->execute([Status::SUSPENDED, $id, Status::NORMAL]);
        } elseif ($action === 'restore') {
            $stmt = aov_db()->prepare('UPDATE users SET status=?,force_logout=force_logout+1 WHERE id=? AND status=?');
            $stmt->execute([Status::NORMAL, $id, Status::SUSPENDED]);
        } else {
            $name = trim((string) ($input['name'] ?? ''));
            $reserved = ['admin','administrator','aov','aol','system','support','moderator','mod','staff','root','help','postmaster'];
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,23}$/D', $name) || in_array(strtolower($name), $reserved, true)) {
                aov_json(['error' => 'Invalid screen name'], 400);
            }
            $stmt = aov_db()->prepare('UPDATE users SET username=?,force_logout=force_logout+1 WHERE id=?');
            $stmt->execute([$name, $id]);
        }
        aov_event($me, 'admin_' . $action);
    } elseif ($action === 'room_add') {
        $name = trim((string) ($input['name'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'General'));
        if (!preg_match('/^[\pL\pN][\pL\pN \x27-]{2,39}$/uD', $name)) aov_json(['error' => 'Use a room name of 3 to 40 characters'], 400);
        if (!preg_match('/^[\pL\pN][\pL\pN -]{1,23}$/uD', $category)) aov_json(['error' => 'Use a category of 2 to 24 characters'], 400);
        $id = (int) aov_db()->query('SELECT COALESCE(MAX(id),2)+1 FROM aov_chat_rooms')->fetchColumn();
        if ($id > 49) aov_json(['error' => 'Room limit reached'], 400);
        $stmt = aov_db()->prepare('INSERT INTO aov_chat_rooms (id,name,category,enabled) VALUES (?,?,?,1)');
        $stmt->execute([$id, $name, $category]);
        aov_event($me, 'admin_room_add');
    } elseif ($action === 'room_toggle') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 49]]);
        if (!$id) aov_json(['error' => 'Town Square must remain available'], 400);
        $stmt = aov_db()->prepare('UPDATE aov_chat_rooms SET enabled=1-enabled WHERE id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) aov_json(['error' => 'Room not found'], 404);
        aov_event($me, 'admin_room_toggle');
    } elseif ($action === 'channel_set') {
        $id = $input['id'] ?? null;
        $url = $input['url'] ?? null;
        if (!is_string($id) || !is_string($url) || strlen($url) > 2048 || !preg_match('/^[a-z][a-z0-9]*$/D', $id)) aov_json(['error' => 'Invalid channel destination'], 400);
        $file = __DIR__ . '/channels.json';
        $channels = json_decode((string) file_get_contents($file), true);
        if (!is_array($channels) || !array_key_exists($id, $channels)) aov_json(['error' => 'Unknown channel'], 404);
        $url = trim($url);
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http','https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) aov_json(['error' => 'Enter a full HTTP or HTTPS address'], 400);
        $channels[$id] = $url;
        $json = json_encode($channels, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new RuntimeException('Channel configuration is unavailable');
        $backup = aov_private_dir() . '/channels-' . gmdate('Ymd-His') . '.json';
        if (!copy($file, $backup)) throw new RuntimeException('Could not back up channel configuration');
        $temporary = tempnam(__DIR__, 'aov-channels-');
        if ($temporary === false) throw new RuntimeException('Channel configuration is not writable');
        try {
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $file)) throw new RuntimeException('Could not save channel configuration');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
        aov_event($me, 'admin_channel_set');
    } elseif ($action === 'bulletin_save') {
        $id = $input['id'] ?? '';
        $title = $input['title'] ?? null;
        $date = $input['date'] ?? null;
        $summary = $input['summary'] ?? null;
        $body = $input['body'] ?? null;
        $image = $input['image'] ?? null;
        $status = $input['status'] ?? null;
        if (!is_string($id) || !is_string($title) || !is_string($date) || !is_string($summary)
            || !is_string($body) || !is_string($image) || !is_string($status)) aov_json(['error' => 'Invalid post'], 400);
        $title = trim($title); $summary = trim($summary); $body = trim($body);
        $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($title === '' || mb_strlen($title) > 120 || $summary === '' || mb_strlen($summary) > 240
            || $body === '' || mb_strlen($body) > 5000 || !$validDate || $validDate->format('Y-m-d') !== $date
            || !in_array($image, ['', 'assets/aov-logo.png', 'assets/aov-icon.jpg', 'assets/news-channel-logo.png'], true)
            || !in_array($status, ['draft', 'published'], true)) aov_json(['error' => 'Check the post title, date, summary, body, image, and status'], 400);
        $posts = aov_bulletins_read();
        if ($id === '') {
            if (count($posts) >= 30) aov_json(['error' => 'The 30-post limit has been reached'], 400);
            $id = bin2hex(random_bytes(8));
            $index = null;
        } else {
            $index = null;
            foreach ($posts as $position => $post) if (($post['id'] ?? null) === $id) { $index = $position; break; }
            if ($index === null) aov_json(['error' => 'Post not found'], 404);
        }
        $post = compact('id', 'title', 'date', 'summary', 'body', 'image', 'status');
        if ($index === null) $posts[] = $post;
        else $posts[$index] = $post;
        aov_bulletins_save($posts);
        aov_event($me, 'admin_bulletin_save');
    } else {
        aov_json(['error' => 'Unknown action'], 404);
    }
    aov_touch($auth);
    aov_json(aov_admin_state());
} catch (PDOException $error) {
    if ($error->getCode() === '23000') aov_json(['error' => 'That name is already in use'], 409);
    error_log('AOV administration database error: ' . $error->getCode());
    aov_json(['error' => 'Administration is temporarily unavailable'], 503);
} catch (Throwable $error) {
    error_log('AOV administration error: ' . get_class($error));
    aov_json(['error' => 'Administration is temporarily unavailable'], 503);
}

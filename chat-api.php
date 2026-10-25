<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Delight\Auth\Role;

$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
$me = (int) $auth->getUserId();
$action = (string) ($_GET['action'] ?? 'state');

function aov_chat_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $config = aov_config()['chat_mysql'] ?? null;
    if (!is_array($config) || empty($config['host']) || empty($config['name']) || empty($config['user'])) {
        throw new RuntimeException('Chat database is not configured');
    }
    $db = new PDO(
        'mysql:host=' . $config['host'] . ';dbname=' . $config['name'] . ';charset=utf8mb4',
        (string) $config['user'], (string) ($config['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    return $db;
}

function aov_chat_target(string $name): ?array {
    $stmt = aov_db()->prepare('SELECT id, username, roles_mask FROM users WHERE username = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function aov_chat_body(): array {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') aov_json(['error' => 'Method not allowed'], 405);
    if (!hash_equals(aov_csrf(), (string) ($_SERVER['HTTP_X_AOV_CSRF'] ?? ''))) aov_json(['error' => 'Please reload and try again'], 403);
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) aov_json(['error' => 'Invalid request'], 400);
    return $body;
}

try {
    if ($action === 'state' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $buddyQuery = aov_db()->prepare("SELECT u.id, u.username, b.group_name, COALESCE(p.is_away,0) AS is_away, COALESCE(p.away_message,'') AS away_message FROM aov_buddies b JOIN users u ON u.id=b.buddy_id LEFT JOIN aov_presence p ON p.user_id=u.id WHERE b.user_id=? ORDER BY b.group_name COLLATE NOCASE,u.username COLLATE NOCASE");
        $buddyQuery->execute([$me]);
        $buddies = $buddyQuery->fetchAll();
        $blocksQuery = aov_db()->prepare('SELECT b.blocked_id AS id,u.username FROM aov_chat_blocks b JOIN users u ON u.id=b.blocked_id WHERE b.user_id=? ORDER BY u.username COLLATE NOCASE');
        $blocksQuery->execute([$me]);
        $blockedUsers = $blocksQuery->fetchAll();
        $blocks = array_map(fn($row) => (int) $row['id'], $blockedUsers);
        $online = aov_chat_db()->query('SELECT userID AS id, userName AS name FROM ajax_chat_online WHERE dateTime >= DATE_SUB(NOW(), INTERVAL 3 MINUTE) ORDER BY userName LIMIT 200')->fetchAll();
        $inviteQuery = aov_chat_db()->prepare('SELECT channel FROM ajax_chat_invitations WHERE userID=? AND channel >= 500000000 AND channel < 1000000000 AND dateTime >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 50');
        $inviteQuery->execute([$me]);
        $rooms = aov_db()->query('SELECT id,name,category FROM aov_chat_rooms WHERE enabled=1 ORDER BY category COLLATE NOCASE,name COLLATE NOCASE')->fetchAll();
        $rooms[] = ['id' => 500000000 + $me, 'name' => 'My Private Room', 'category' => 'Private Rooms'];
        foreach ($inviteQuery->fetchAll(PDO::FETCH_COLUMN) as $channel) {
            $owner = aov_db()->prepare('SELECT username FROM users WHERE id=?');
            $owner->execute([(int) $channel - 500000000]);
            $ownerName = $owner->fetchColumn();
            if ($ownerName !== false) $rooms[] = ['id' => (int) $channel, 'name' => $ownerName . "'s Room", 'category' => 'Private Rooms'];
        }
        $presence = aov_db()->prepare('SELECT is_away,away_message FROM aov_presence WHERE user_id=?');
        $presence->execute([$me]);
        aov_json(['userId' => $me, 'buddies' => $buddies, 'blockedIds' => $blocks, 'blockedUsers' => $blockedUsers, 'online' => $online,
            'presence' => $presence->fetch() ?: ['is_away' => 0, 'away_message' => ''],
            'isAdmin' => $auth->hasRole(Role::ADMIN) || $auth->hasRole(Role::SUPER_ADMIN),
            'rooms' => $rooms]);
    }
    $body = aov_chat_body();
    if ($action === 'away_set') {
        $away = $body['away'] ?? null;
        $message = $body['message'] ?? '';
        if (!is_bool($away) || !is_string($message) || mb_strlen($message) > 160 || preg_match('/[\x00-\x1F\x7F]/', $message)) aov_json(['error' => 'Invalid away message'], 400);
        $stmt = aov_db()->prepare('INSERT INTO aov_presence(user_id,is_away,away_message,updated_at) VALUES(?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET is_away=excluded.is_away,away_message=excluded.away_message,updated_at=excluded.updated_at');
        $stmt->execute([$me, (int) $away, trim($message), time()]);
        aov_touch($auth);
        aov_json(['ok' => true]);
    }
    if (in_array($action, ['buddy_add', 'buddy_remove', 'buddy_group_set', 'block', 'unblock'], true)) {
        $name = trim((string) ($body['name'] ?? ''));
        if (strlen($name) > 24 || $name === '') aov_json(['error' => 'Enter a screen name'], 400);
        $target = aov_chat_target($name);
        if (!$target || (int) $target['id'] === $me) aov_json(['error' => 'Screen name is unavailable'], 404);
        $other = (int) $target['id'];
        if ($action === 'buddy_add') {
            $count = aov_db()->prepare('SELECT COUNT(*) FROM aov_buddies WHERE user_id=?');
            $count->execute([$me]);
            if ((int) $count->fetchColumn() >= 100) aov_json(['error' => 'Buddy list is full'], 400);
            $stmt = aov_db()->prepare('INSERT OR IGNORE INTO aov_buddies (user_id,buddy_id,created_at) VALUES (?,?,?)');
            $stmt->execute([$me, $other, time()]);
        } elseif ($action === 'buddy_remove') {
            $stmt = aov_db()->prepare('DELETE FROM aov_buddies WHERE user_id=? AND buddy_id=?');
            $stmt->execute([$me, $other]);
        } elseif ($action === 'buddy_group_set') {
            $group = trim((string) ($body['group'] ?? ''));
            if (!preg_match('/^[\pL\pN _-]{1,24}$/uD', $group)) aov_json(['error' => 'Use a group name of 1 to 24 letters or numbers'], 400);
            $stmt = aov_db()->prepare('UPDATE aov_buddies SET group_name=? WHERE user_id=? AND buddy_id=?');
            $stmt->execute([$group, $me, $other]);
            if (!$stmt->rowCount()) aov_json(['error' => 'Buddy is unavailable'], 404);
        } elseif ($action === 'block') {
            $stmt = aov_db()->prepare('INSERT OR IGNORE INTO aov_chat_blocks (user_id,blocked_id,created_at) VALUES (?,?,?)');
            $stmt->execute([$me, $other, time()]);
        } else {
            $stmt = aov_db()->prepare('DELETE FROM aov_chat_blocks WHERE user_id=? AND blocked_id=?');
            $stmt->execute([$me, $other]);
        }
        aov_touch($auth);
        aov_json(['ok' => true]);
    }
    if ($action === 'report') {
        $messageId = filter_var($body['messageId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$messageId) aov_json(['error' => 'Choose a message to report'], 400);
        $count = aov_db()->prepare('SELECT COUNT(*) FROM aov_chat_reports WHERE reporter_id=? AND created_at>?');
        $count->execute([$me, time() - 3600]);
        if ((int) $count->fetchColumn() >= 5) aov_json(['error' => 'Please wait before sending another report'], 429);
        $msg = aov_chat_db()->prepare('SELECT userID,channel,text FROM ajax_chat_messages WHERE id=?');
        $msg->execute([$messageId]);
        $row = $msg->fetch();
        if (!$row || (int) $row['userID'] === $me || (int) $row['userID'] >= 500000000) aov_json(['error' => 'Message cannot be reported'], 400);
        $channel = (int) $row['channel'];
        $visible = false;
        if ($channel >= 1000000000 && $channel < 1500000000) {
            $visible = $channel === 1000000000 + $me;
        } elseif ($channel >= 500000000 && $channel < 1000000000) {
            $visible = $channel === 500000000 + $me;
            if (!$visible) {
                $invited = aov_chat_db()->prepare('SELECT 1 FROM ajax_chat_invitations WHERE userID=? AND channel=? AND dateTime >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1');
                $invited->execute([$me, $channel]);
                $visible = (bool) $invited->fetchColumn();
            }
        } elseif ($channel >= 0 && $channel < 500000000) {
            $room = aov_db()->prepare('SELECT 1 FROM aov_chat_rooms WHERE id=? AND enabled=1');
            $room->execute([$channel]);
            $visible = (bool) $room->fetchColumn();
        }
        if (!$visible) aov_json(['error' => 'Message cannot be reported'], 404);
        $stmt = aov_db()->prepare('INSERT INTO aov_chat_reports (reporter_id,target_id,message,created_at) VALUES (?,?,?,?)');
        $stmt->execute([$me, (int) $row['userID'], '#' . $messageId . ' ' . mb_substr((string) $row['text'], 0, 500), time()]);
        aov_event($me, 'chat_report');
        aov_touch($auth);
        aov_json(['ok' => true]);
    }
    if ($action === 'admin_reports') {
        if (!$auth->hasRole(Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN)) aov_json(['error' => 'Forbidden'], 403);
        $reports = aov_db()->query("SELECT r.id, r.message, r.created_at, reporter.username AS reporter, target.username AS target FROM aov_chat_reports r JOIN users reporter ON reporter.id=r.reporter_id JOIN users target ON target.id=r.target_id WHERE r.status='open' ORDER BY r.created_at DESC LIMIT 50")->fetchAll();
        aov_json(['reports' => $reports]);
    }
    if (in_array($action, ['admin_filter_terms', 'admin_filter_add', 'admin_filter_remove', 'admin_guard_unmute'], true)) {
        if (!$auth->hasRole(Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN)) aov_json(['error' => 'Forbidden'], 403);
        if ($action === 'admin_filter_add') {
            $term = trim((string) ($body['term'] ?? ''));
            if (mb_strlen($term) < 2 || mb_strlen($term) > 40 || preg_match('/[\x00-\x1F\x7F]/', $term)) aov_json(['error' => 'Use a term of 2 to 40 characters'], 400);
            $count = (int) aov_db()->query('SELECT COUNT(*) FROM aov_chat_filter_terms')->fetchColumn();
            if ($count >= 100) aov_json(['error' => 'Filter list is full'], 400);
            $stmt = aov_db()->prepare('INSERT OR IGNORE INTO aov_chat_filter_terms (term,created_at) VALUES (?,?)');
            $stmt->execute([$term, time()]);
            aov_event($me, 'chat_filter_add');
        } elseif ($action === 'admin_filter_remove') {
            $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) aov_json(['error' => 'Invalid filter term'], 400);
            $stmt = aov_db()->prepare('DELETE FROM aov_chat_filter_terms WHERE id=?');
            $stmt->execute([$id]);
            aov_event($me, 'chat_filter_remove');
        } elseif ($action === 'admin_guard_unmute') {
            $name = trim((string) ($body['name'] ?? ''));
            $target = strlen($name) <= 24 ? aov_chat_target($name) : null;
            if (!$target) aov_json(['error' => 'Screen name is unavailable'], 404);
            $stmt = aov_db()->prepare('UPDATE aov_chat_guard SET muted_until=0,strikes=0 WHERE user_id=?');
            $stmt->execute([(int) $target['id']]);
            aov_event($me, 'chat_guard_unmute');
        }
        $terms = aov_db()->query('SELECT id,term FROM aov_chat_filter_terms ORDER BY term COLLATE NOCASE LIMIT 100')->fetchAll();
        $events = aov_db()->query("SELECT e.event,e.at,u.username FROM aov_security_events e LEFT JOIN users u ON u.id=e.user_id WHERE e.event IN ('chat_message_rejected','chat_auto_mute') ORDER BY e.id DESC LIMIT 30")->fetchAll();
        aov_json(['terms' => $terms, 'events' => $events]);
    }
    if (in_array($action, ['admin_ban', 'admin_unban', 'admin_report_close'], true)) {
        if (!$auth->hasRole(Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN)) aov_json(['error' => 'Forbidden'], 403);
        if ($action === 'admin_report_close') {
            $reportId = filter_var($body['reportId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$reportId) aov_json(['error' => 'Invalid report'], 400);
            $stmt = aov_db()->prepare("UPDATE aov_chat_reports SET status='closed',closed_at=? WHERE id=? AND status='open'");
            $stmt->execute([time(), $reportId]);
            aov_event($me, 'chat_report_closed');
            aov_json(['ok' => true]);
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || strlen($name) > 24) aov_json(['error' => 'Enter a screen name'], 400);
        $target = aov_chat_target($name);
        if (!$target || (int) $target['id'] === $me) aov_json(['error' => 'Screen name is unavailable'], 404);
        if (((int) $target['roles_mask'] & (Role::ADMIN | Role::SUPER_ADMIN)) !== 0 && !$auth->hasRole(Role::SUPER_ADMIN)) {
            aov_json(['error' => 'This account requires a super administrator'], 403);
        }
        $targetId = (int) $target['id'];
        if ($action === 'admin_ban') {
            $minutes = filter_var($body['minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
            if (!$minutes) aov_json(['error' => 'Choose 1 to 1440 minutes'], 400);
            $stmt = aov_chat_db()->prepare("INSERT INTO ajax_chat_bans (userID,userName,dateTime,ip) VALUES (?,?,DATE_ADD(NOW(), INTERVAL ? MINUTE),INET6_ATON('0.0.0.0')) ON DUPLICATE KEY UPDATE userName=VALUES(userName),dateTime=VALUES(dateTime),ip=VALUES(ip)");
            $stmt->execute([$targetId, $target['username'], $minutes]);
            $stmt = aov_chat_db()->prepare('DELETE FROM ajax_chat_online WHERE userID=?');
            $stmt->execute([$targetId]);
            aov_event($me, 'chat_ban');
        } else {
            $stmt = aov_chat_db()->prepare('DELETE FROM ajax_chat_bans WHERE userID=?');
            $stmt->execute([$targetId]);
            aov_event($me, 'chat_unban');
        }
        aov_json(['ok' => true]);
    }
    aov_json(['error' => 'Unknown action'], 404);
} catch (Throwable $error) {
    error_log('AOV chat error: ' . get_class($error) . ' ' . $error->getMessage());
    aov_json(['error' => 'Chat is temporarily unavailable'], 503);
}

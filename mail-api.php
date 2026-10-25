<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
$me = (int) $auth->getUserId();
$db = aov_db();
$action = (string) ($_GET['action'] ?? '');

function aov_mail_unread(PDO $db, int $me): int {
    $q = $db->prepare('SELECT COUNT(*) FROM aov_mail WHERE recipient_id=? AND recipient_archived=0 AND read_at IS NULL');
    $q->execute([$me]);
    return (int) $q->fetchColumn();
}

function aov_mail_list(PDO $db, int $me, string $folder, int $page): array {
    $sent = $folder === 'sent';
    $column = $sent ? 'sender_id' : 'recipient_id';
    $archived = $sent ? 'sender_archived' : 'recipient_archived';
    $other = $sent ? 'recipient_id' : 'sender_id';
    $q = $db->prepare("SELECT m.id,m.subject,m.created_at,m.read_at,u.username AS other_name FROM aov_mail m JOIN users u ON u.id=m.$other WHERE m.$column=? AND m.$archived=0 ORDER BY m.id DESC LIMIT 21 OFFSET ?");
    $q->bindValue(1, $me, PDO::PARAM_INT);
    $q->bindValue(2, $page * 20, PDO::PARAM_INT);
    $q->execute();
    $rows = $q->fetchAll();
    return ['folder' => $folder, 'page' => $page, 'more' => count($rows) > 20, 'messages' => array_slice($rows, 0, 20), 'unread' => aov_mail_unread($db, $me)];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            $folder = (string) ($_GET['folder'] ?? 'inbox');
            $page = filter_var($_GET['page'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
            if (!in_array($folder, ['inbox', 'sent'], true) || $page === false) aov_json(['error' => 'Invalid folder'], 400);
            aov_touch($auth);
            aov_json(aov_mail_list($db, $me, $folder, (int) $page));
        }
        if ($action === 'directory') {
            $q = trim((string) ($_GET['q'] ?? ''));
            if (strlen($q) > 24) aov_json(['error' => 'Search is too long'], 400);
            if ($q !== '' && !preg_match('/^[A-Za-z0-9_]{1,24}$/D', $q)) aov_json(['error' => 'Invalid search'], 400);
            $stmt = $db->prepare('SELECT u.username FROM users u WHERE u.id<>? AND u.status=0 AND substr(lower(u.username),1,length(?))=lower(?) ORDER BY u.username COLLATE NOCASE LIMIT 25');
            $stmt->execute([$me, $q, $q]);
            aov_touch($auth);
            aov_json(['members' => $stmt->fetchAll(PDO::FETCH_COLUMN)]);
        }
        if ($action === 'unread') aov_json(['unread' => aov_mail_unread($db, $me)]);
        aov_json(['error' => 'Unknown action'], 404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') aov_json(['error' => 'Method not allowed'], 405);
    if (!hash_equals(aov_csrf(), (string) ($_SERVER['HTTP_X_AOV_CSRF'] ?? ''))) aov_json(['error' => 'Please reload and try again'], 403);
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) aov_json(['error' => 'Invalid request'], 400);
    if ($action === 'send') {
        $to = $input['to'] ?? null;
        $subject = $input['subject'] ?? null;
        $body = $input['body'] ?? null;
        $reply = $input['replyTo'] ?? null;
        if (!is_string($to) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{2,23}$/D', $to) || !is_string($subject) || !is_string($body)) aov_json(['error' => 'Check recipient and message'], 400);
        $subject = trim($subject); $body = trim($body);
        if ($subject === '' || mb_strlen($subject) > 120 || $body === '' || mb_strlen($body) > 10000 || strlen($body) > 40000) aov_json(['error' => 'Subject or message is too long or empty'], 400);
        $recipient = $db->prepare('SELECT id FROM users WHERE username=? COLLATE NOCASE AND status=0');
        $recipient->execute([$to]);
        $recipientId = (int) $recipient->fetchColumn();
        if (!$recipientId || $recipientId === $me) aov_json(['error' => 'Recipient is unavailable'], 400);
        $blocked = $db->prepare('SELECT 1 FROM aov_chat_blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?) LIMIT 1');
        $blocked->execute([$me, $recipientId, $recipientId, $me]);
        if ($blocked->fetchColumn()) aov_json(['error' => 'Recipient is unavailable'], 400);
        $replyId = null;
        if ($reply !== null) {
            $replyId = filter_var($reply, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($replyId === false) aov_json(['error' => 'Invalid reply'], 400);
            $check = $db->prepare('SELECT 1 FROM aov_mail WHERE id=? AND recipient_id=? AND sender_id=? AND recipient_archived=0');
            $check->execute([$replyId, $me, $recipientId]);
            if (!$check->fetchColumn()) aov_json(['error' => 'Original message is unavailable'], 403);
        }
        $db->exec('BEGIN IMMEDIATE');
        try {
            $now = time();
            $rate = $db->prepare('SELECT window_at,sent_count FROM aov_mail_rate WHERE user_id=?');
            $rate->execute([$me]);
            $state = $rate->fetch();
            $count = $state && $now - (int) $state['window_at'] < 3600 ? (int) $state['sent_count'] : 0;
            if ($count >= 20) { $db->exec('ROLLBACK'); aov_json(['error' => 'Hourly mail limit reached. Try again later.'], 429); }
            $rate = $db->prepare('INSERT INTO aov_mail_rate(user_id,window_at,sent_count) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET window_at=excluded.window_at,sent_count=excluded.sent_count');
            $rate->execute([$me, $count ? (int) $state['window_at'] : $now, $count + 1]);
            $insert = $db->prepare('INSERT INTO aov_mail(sender_id,recipient_id,subject,body,created_at,reply_to_id) VALUES(?,?,?,?,?,?)');
            $insert->execute([$me, $recipientId, $subject, $body, $now, $replyId]);
            $db->exec('COMMIT');
        } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
        aov_touch($auth);
        aov_json(['message' => 'Message sent', 'unread' => aov_mail_unread($db, $me)]);
    }
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) aov_json(['error' => 'Invalid message'], 400);
    if ($action === 'read') {
        $q = $db->prepare('SELECT m.id,m.sender_id,m.recipient_id,m.subject,m.body,m.created_at,m.read_at,m.recipient_archived,m.sender_archived,s.username AS sender,r.username AS recipient FROM aov_mail m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.recipient_id WHERE m.id=? AND ((m.recipient_id=? AND m.recipient_archived=0) OR (m.sender_id=? AND m.sender_archived=0))');
        $q->execute([$id, $me, $me]); $message = $q->fetch();
        if (!$message) aov_json(['error' => 'Message not found'], 404);
        if ((int) $message['recipient_id'] === $me && $message['read_at'] === null) {
            $update = $db->prepare('UPDATE aov_mail SET read_at=? WHERE id=? AND recipient_id=? AND read_at IS NULL');
            $update->execute([time(), $id, $me]);
        }
        aov_touch($auth);
        aov_json(['message' => $message, 'unread' => aov_mail_unread($db, $me)]);
    }
    if ($action === 'archive') {
        $q = $db->prepare('UPDATE aov_mail SET recipient_archived=CASE WHEN recipient_id=? THEN 1 ELSE recipient_archived END,sender_archived=CASE WHEN sender_id=? THEN 1 ELSE sender_archived END WHERE id=? AND (recipient_id=? OR sender_id=?)');
        $q->execute([$me, $me, $id, $me, $me]);
        if (!$q->rowCount()) aov_json(['error' => 'Message not found'], 404);
        if (!empty(aov_config()['retention_enabled'])) aov_retention_delete_archived_mail($db, $id);
        aov_touch($auth);
        aov_json(['message' => 'Message archived', 'unread' => aov_mail_unread($db, $me)]);
    }
    if ($action === 'report') {
        $reason = $input['reason'] ?? null;
        if (!is_string($reason) || !in_array($reason, ['Harassment', 'Spam', 'Threat', 'Other'], true)) aov_json(['error' => 'Choose a report reason'], 400);
        $check = $db->prepare('SELECT 1 FROM aov_mail WHERE id=? AND recipient_id=? AND recipient_archived=0');
        $check->execute([$id, $me]);
        if (!$check->fetchColumn()) aov_json(['error' => 'Message not found'], 404);
        $report = $db->prepare('INSERT OR IGNORE INTO aov_mail_reports(message_id,reporter_id,reason,created_at) VALUES(?,?,?,?)');
        $report->execute([$id, $me, $reason, time()]);
        aov_event($me, 'mail_report');
        aov_touch($auth);
        aov_json(['message' => 'Report sent to AOV administrators']);
    }
    aov_json(['error' => 'Unknown action'], 404);
} catch (Throwable $e) {
    error_log('AOV mail error: ' . get_class($e) . ' ' . $e->getMessage());
    aov_json(['error' => 'Mail is temporarily unavailable'], 503);
}

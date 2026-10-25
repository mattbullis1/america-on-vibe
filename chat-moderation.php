<?php
declare(strict_types=1);

/** A shared-hosting-safe message guard, keyed by the authenticated AOV user ID. */
function aov_chat_guard_message(int $userId, string $message): ?string {
    $now = time();
    $text = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    $hash = hash('sha256', mb_strtolower($text, 'UTF-8'));
    $db = aov_db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $query = $db->prepare('SELECT * FROM aov_chat_guard WHERE user_id=?');
        $query->execute([$userId]);
        $previous = $query->fetch() ?: null;
        $mutedUntil = (int) ($previous['muted_until'] ?? 0);
        if ($mutedUntil > $now) {
            $db->exec('COMMIT');
            return 'Chat is temporarily muted. Try again later.';
        }
        $windowStarted = (int) ($previous['window_started'] ?? $now);
        $windowCount = (int) ($previous['window_count'] ?? 0);
        if ($now - $windowStarted >= 10) { $windowStarted = $now; $windowCount = 0; }
        $windowCount++;
        $lastAt = (int) ($previous['last_at'] ?? 0);
        $strikes = $now - $lastAt > 60 ? 0 : (int) ($previous['strikes'] ?? 0);
        $reason = null;
        if ($windowCount > 4) $reason = 'Slow down before posting again.';
        elseif ($previous && $previous['last_hash'] === $hash && $now - $lastAt < 90) $reason = 'Please avoid repeating the same message.';
        else {
            $terms = $db->query('SELECT term FROM aov_chat_filter_terms ORDER BY id LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($terms as $term) {
                if (preg_match('/(?<![\pL\pN])' . preg_quote((string) $term, '/') . '(?![\pL\pN])/iu', $text)) {
                    $reason = 'That message contains a blocked term.';
                    break;
                }
            }
        }
        if ($reason !== null) $strikes++;
        else $strikes = 0;
        $mutedUntil = $strikes >= 3 ? $now + 300 : 0;
        if ($mutedUntil) $reason = 'Chat is temporarily muted for repeated blocked messages.';
        $update = $db->prepare('INSERT INTO aov_chat_guard (user_id,window_started,window_count,last_hash,last_at,strikes,muted_until) VALUES (?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET window_started=excluded.window_started,window_count=excluded.window_count,last_hash=excluded.last_hash,last_at=excluded.last_at,strikes=excluded.strikes,muted_until=excluded.muted_until');
        $update->execute([$userId, $windowStarted, $windowCount, $reason === null ? $hash : (string) ($previous['last_hash'] ?? ''), $now, $strikes, $mutedUntil]);
        $db->exec('COMMIT');
        if ($reason !== null) aov_event($userId, $mutedUntil ? 'chat_auto_mute' : 'chat_message_rejected');
        return $reason;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

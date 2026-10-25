<?php
declare(strict_types=1);

function aov_browser_url(mixed $value, bool $allowEmpty = false): string {
    if (!is_string($value)) throw new InvalidArgumentException('Enter a valid web address');
    $value = trim($value);
    if ($allowEmpty && $value === '') return '';
    if ($value === '' || strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Enter a valid web address');
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Enter an HTTP or HTTPS address without credentials');
    }
    return $value;
}

function aov_browser_title(mixed $value, string $default): string {
    if (!is_string($value)) return $default;
    $title = trim($value);
    return $title === '' ? $default : (function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120));
}

function aov_browser_state(int $userId): array {
    $db = aov_db();
    $home = $db->prepare('SELECT home_url FROM aov_browser_settings WHERE user_id = ?');
    $home->execute([$userId]);
    $bookmarks = $db->prepare('SELECT id, url, title FROM aov_bookmarks WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
    $bookmarks->execute([$userId]);
    $history = $db->prepare('SELECT id, url, title, visited_at FROM aov_browser_history WHERE user_id = ? ORDER BY id DESC LIMIT 50');
    $history->execute([$userId]);
    return [
        'homeUrl' => (string) ($home->fetchColumn() ?: ''),
        'bookmarks' => $bookmarks->fetchAll(),
        'history' => $history->fetchAll(),
    ];
}

function aov_browser_action(string $action, array $input, int $userId): array {
    $db = aov_db();
    switch ($action) {
        case 'browser_visit':
            $url = aov_browser_url($input['url'] ?? null);
            $title = aov_browser_title($input['title'] ?? null, (string) parse_url($url, PHP_URL_HOST));
            $db->prepare('INSERT INTO aov_browser_history (user_id, url, title, visited_at) VALUES (?, ?, ?, ?)')
                ->execute([$userId, $url, $title, time()]);
            $db->prepare('DELETE FROM aov_browser_history WHERE user_id = ? AND id NOT IN (SELECT id FROM aov_browser_history WHERE user_id = ? ORDER BY id DESC LIMIT 200)')
                ->execute([$userId, $userId]);
            return aov_browser_state($userId);

        case 'browser_bookmark_add':
            $url = aov_browser_url($input['url'] ?? null);
            $title = aov_browser_title($input['title'] ?? null, (string) parse_url($url, PHP_URL_HOST));
            $count = $db->prepare('SELECT COUNT(*) FROM aov_bookmarks WHERE user_id = ? AND url <> ?');
            $count->execute([$userId, $url]);
            if ((int) $count->fetchColumn() >= 100) throw new InvalidArgumentException('Bookmark limit reached');
            $db->prepare('INSERT INTO aov_bookmarks (user_id, url, title, created_at) VALUES (?, ?, ?, ?) ON CONFLICT(user_id, url) DO UPDATE SET title = excluded.title')
                ->execute([$userId, $url, $title, time()]);
            return aov_browser_state($userId);

        case 'browser_bookmark_remove':
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new InvalidArgumentException('Invalid bookmark');
            $db->prepare('DELETE FROM aov_bookmarks WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            return aov_browser_state($userId);

        case 'browser_home_set':
            $url = aov_browser_url($input['url'] ?? null, true);
            $db->prepare('INSERT INTO aov_browser_settings (user_id, home_url) VALUES (?, ?) ON CONFLICT(user_id) DO UPDATE SET home_url = excluded.home_url')
                ->execute([$userId, $url]);
            return aov_browser_state($userId);

        case 'browser_history_clear':
            $db->prepare('DELETE FROM aov_browser_history WHERE user_id = ?')->execute([$userId]);
            return aov_browser_state($userId);
    }
    throw new InvalidArgumentException('Unknown browser action');
}

<?php
declare(strict_types=1);

use Delight\Auth\Auth;
use Delight\Auth\Role;

require_once __DIR__ . '/channel-feed-images.php';

const AOV_IDLE_SECONDS = 1800;

function aov_private_dir(): string {
    $override = getenv('AOV_PRIVATE_DIR');
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
    $publicHtml = null;
    for ($candidate = $documentRoot; dirname($candidate) !== $candidate; $candidate = dirname($candidate)) {
        if (strtolower(basename($candidate)) === 'public_html') { $publicHtml = $candidate; break; }
    }
    $root = $override !== false && $override !== ''
        ? $override
        : dirname($publicHtml ?? $documentRoot) . DIRECTORY_SEPARATOR . 'aov-private';
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Private storage is unavailable');
    }
    $resolved = realpath($root);
    $public = realpath(__DIR__);
    $exposedRoot = $publicHtml ?? $documentRoot;
    $unsafe = static fn(string $path, string $base): bool => strcasecmp($path, $base) === 0 || str_starts_with(strtolower($path), strtolower($base . DIRECTORY_SEPARATOR));
    if ($resolved === false || ($public !== false && $unsafe($resolved, $public)) || $unsafe($resolved, $exposedRoot)) {
        throw new RuntimeException('Private storage must be outside the public application');
    }
    return $resolved;
}

function aov_config(): array {
    static $config;
    if ($config !== null) return $config;
    $path = aov_private_dir() . DIRECTORY_SEPARATOR . 'config.php';
    $config = is_file($path) ? require $path : [];
    if (!is_array($config)) throw new RuntimeException('Invalid private configuration');
    if (in_array(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), ['americaonvibe.com', 'www.americaonvibe.com'], true)) {
        $config['base_url'] = 'https://americaonvibe.com/';
    }
    return $config;
}

require aov_private_dir() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . '/retention.php';

function aov_db(): PDO {
    static $db;
    if ($db !== null) return $db;
    $path = aov_private_dir() . DIRECTORY_SEPARATOR . 'aov.sqlite';
    $db = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('BEGIN IMMEDIATE');
    $installed = (bool) $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$installed) {
        $db->exec(file_get_contents(aov_private_dir() . '/vendor/delight-im/auth/Database/SQLite.sql'));
        @chmod($path, 0600);
    }
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS aov_username_unique ON users(username COLLATE NOCASE) WHERE username IS NOT NULL');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_profiles (user_id INTEGER PRIMARY KEY, terms_accepted_at INTEGER NOT NULL, recovery_email_verified INTEGER NOT NULL DEFAULT 0, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_security_events (id INTEGER PRIMARY KEY, user_id INTEGER, event TEXT NOT NULL, at INTEGER NOT NULL, ip TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_security_events_at ON aov_security_events(at,id)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_browser_settings (user_id INTEGER PRIMARY KEY, home_url TEXT NOT NULL DEFAULT \'\', FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_bookmarks (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, url TEXT NOT NULL, title TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE(user_id, url), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_bookmarks_user_order ON aov_bookmarks(user_id, created_at DESC)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_channel_favorites (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, channel_id TEXT NOT NULL, channel_path TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE(user_id, channel_id, channel_path), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_channel_favorites_user_order ON aov_channel_favorites(user_id, created_at DESC)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_browser_history (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, url TEXT NOT NULL, title TEXT NOT NULL, visited_at INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_browser_history_user_order ON aov_browser_history(user_id, id DESC)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_buddies (user_id INTEGER NOT NULL, buddy_id INTEGER NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(user_id,buddy_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(buddy_id) REFERENCES users(id) ON DELETE CASCADE)');
    $buddyColumns = $db->query('PRAGMA table_info(aov_buddies)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('group_name', $buddyColumns, true)) $db->exec("ALTER TABLE aov_buddies ADD COLUMN group_name TEXT NOT NULL DEFAULT 'Buddies'");
    $db->exec('CREATE INDEX IF NOT EXISTS aov_buddies_buddy ON aov_buddies(buddy_id)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_presence (user_id INTEGER PRIMARY KEY, is_away INTEGER NOT NULL DEFAULT 0, away_message TEXT NOT NULL DEFAULT \'\', updated_at INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_chat_blocks (user_id INTEGER NOT NULL, blocked_id INTEGER NOT NULL, created_at INTEGER NOT NULL, PRIMARY KEY(user_id,blocked_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(blocked_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_chat_reports (id INTEGER PRIMARY KEY, reporter_id INTEGER NOT NULL, target_id INTEGER NOT NULL, message TEXT NOT NULL, created_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'open\', FOREIGN KEY(reporter_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(target_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_chat_reports_status ON aov_chat_reports(status,created_at DESC)');
    if (!in_array('closed_at', $db->query('PRAGMA table_info(aov_chat_reports)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) $db->exec('ALTER TABLE aov_chat_reports ADD COLUMN closed_at INTEGER');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_chat_reports_retention ON aov_chat_reports(status,closed_at,id)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_chat_guard (user_id INTEGER PRIMARY KEY, window_started INTEGER NOT NULL, window_count INTEGER NOT NULL, last_hash TEXT NOT NULL, last_at INTEGER NOT NULL, strikes INTEGER NOT NULL, muted_until INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_chat_filter_terms (id INTEGER PRIMARY KEY, term TEXT NOT NULL COLLATE NOCASE UNIQUE, created_at INTEGER NOT NULL)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_chat_rooms (id INTEGER PRIMARY KEY, name TEXT NOT NULL COLLATE NOCASE UNIQUE, enabled INTEGER NOT NULL DEFAULT 1)');
    $roomColumns = $db->query('PRAGMA table_info(aov_chat_rooms)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('category', $roomColumns, true)) $db->exec("ALTER TABLE aov_chat_rooms ADD COLUMN category TEXT NOT NULL DEFAULT 'General'");
    $db->exec("UPDATE aov_chat_rooms SET category='Computing' WHERE id=1 AND category='General'");
    $db->exec("UPDATE aov_chat_rooms SET category='Music' WHERE id=2 AND category='General'");
    $db->exec('CREATE TABLE IF NOT EXISTS aov_mail (id INTEGER PRIMARY KEY, sender_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, subject TEXT NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL, read_at INTEGER, sender_archived INTEGER NOT NULL DEFAULT 0, recipient_archived INTEGER NOT NULL DEFAULT 0, reply_to_id INTEGER, FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_mail_inbox ON aov_mail(recipient_id,recipient_archived,id DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_mail_sent ON aov_mail(sender_id,sender_archived,id DESC)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_mail_rate (user_id INTEGER PRIMARY KEY, window_at INTEGER NOT NULL, sent_count INTEGER NOT NULL, FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_mail_reports (id INTEGER PRIMARY KEY, message_id INTEGER NOT NULL, reporter_id INTEGER NOT NULL, reason TEXT NOT NULL, created_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT \'open\', UNIQUE(message_id,reporter_id), FOREIGN KEY(message_id) REFERENCES aov_mail(id) ON DELETE CASCADE, FOREIGN KEY(reporter_id) REFERENCES users(id) ON DELETE CASCADE)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_mail_reports_status ON aov_mail_reports(status,id DESC)');
    if (!in_array('closed_at', $db->query('PRAGMA table_info(aov_mail_reports)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) $db->exec('ALTER TABLE aov_mail_reports ADD COLUMN closed_at INTEGER');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_mail_reports_retention ON aov_mail_reports(status,closed_at,id)');
    $db->exec('CREATE INDEX IF NOT EXISTS aov_mail_retention ON aov_mail(sender_archived,recipient_archived,id)');
    $db->exec('CREATE TABLE IF NOT EXISTS aov_maintenance (name TEXT PRIMARY KEY, last_run INTEGER NOT NULL)');
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (id,name,enabled) VALUES (0,'Town Square',1),(1,'Retro Computing',1),(2,'Music Lounge',1)");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Sports Talk',1,'Sports')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Laugh Lounge',1,'Social')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Influence Talk',1,'Politics')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Travel Talk',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('International Talk',1,'News')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Money Talk',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Career Corner',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Study Hall',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Screen Talk',1,'Entertainment')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Hobby House',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Life & Style',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Health Talk',1,'Interests')");
    $db->exec("INSERT OR IGNORE INTO aov_chat_rooms (name,enabled,category) VALUES ('Family Circle',1,'Interests')");
    $profileColumns = $db->query('PRAGMA table_info(aov_profiles)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('recovery_email_verified', $profileColumns, true)) {
        $db->exec('ALTER TABLE aov_profiles ADD COLUMN recovery_email_verified INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('zip_code', $profileColumns, true)) {
        $db->exec("ALTER TABLE aov_profiles ADD COLUMN zip_code TEXT NOT NULL DEFAULT ''");
    }
    $db->exec('COMMIT');
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function aov_bootstrap(): Auth {
    $config = aov_config();
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (($config['require_https'] ?? false) && !$https) {
        http_response_code(403);
        exit('HTTPS is required');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, private');
    header('X-Frame-Options: SAMEORIGIN');
    $imageOrigins = implode(' ', array_map(static fn(string $host): string => 'https://' . $host, aov_feed_image_hosts()));
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: {$imageOrigins}; media-src 'self' https://kexp.streamguys1.com https://stream0.wfmu.org; connect-src 'self' https://archive.org; frame-src 'self' https://www.npr.org https://web.archive.org https://wiby.me https://www.openstreetmap.org; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    if (session_status() === PHP_SESSION_NONE) {
        $sessionDir = aov_private_dir() . DIRECTORY_SEPARATOR . 'sessions';
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) throw new RuntimeException('Session storage is unavailable');
        session_save_path($sessionDir);
        session_name('AOVSESSID');
        $cookiePath = (string) (parse_url((string) ($config['base_url'] ?? ''), PHP_URL_PATH) ?: '/aov/');
        $cookiePath = rtrim($cookiePath, '/') . '/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }
    $auth = new Auth(aov_db());
    if (!empty($config['retention_enabled'])) aov_retention_daily(aov_db(), time());
    if ($auth->isLoggedIn()) {
        $status = aov_db()->prepare('SELECT status FROM users WHERE id=?');
        $status->execute([(int) $auth->getUserId()]);
        $currentStatus = $status->fetchColumn();
        if ($currentStatus === false || (int) $currentStatus !== 0) {
            $auth->logOut();
            unset($_SESSION['aov_last_activity']);
            session_regenerate_id(true);
        }
    }
    if ($auth->isLoggedIn() && isset($_SESSION['aov_last_activity']) && time() - (int) $_SESSION['aov_last_activity'] >= AOV_IDLE_SECONDS) {
        $auth->logOut();
        unset($_SESSION['aov_last_activity']);
        session_regenerate_id(true);
    }
    $_SESSION['aov_csrf'] ??= bin2hex(random_bytes(32));
    return $auth;
}

function aov_csrf(): string {
    return (string) $_SESSION['aov_csrf'];
}

function aov_touch(Auth $auth): void {
    if ($auth->isLoggedIn()) $_SESSION['aov_last_activity'] = time();
}

function aov_state(Auth $auth): array {
    $zip = '';
    if ($auth->isLoggedIn()) {
        $statement = aov_db()->prepare('SELECT zip_code FROM aov_profiles WHERE user_id = ?');
        $statement->execute([(int) $auth->getUserId()]);
        $zip = (string) ($statement->fetchColumn() ?: '');
    }
    return [
        'loggedIn' => $auth->isLoggedIn(),
        'name' => $auth->isLoggedIn() ? $auth->getUsername() : null,
        'email' => $auth->isLoggedIn() ? $auth->getEmail() : null,
        'zipCode' => $zip,
        'csrf' => aov_csrf(),
        'recoveryAvailable' => !empty(aov_config()['smtp']['host']),
        'resetReady' => isset($_SESSION['aov_reset']),
        'isAdmin' => $auth->isLoggedIn() && ($auth->hasRole(Role::ADMIN) || $auth->hasRole(Role::SUPER_ADMIN)),
    ];
}

function aov_json(array $body, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function aov_event(?int $userId, string $event): void {
    $statement = aov_db()->prepare('INSERT INTO aov_security_events (user_id,event,at,ip) VALUES (?,?,?,?)');
    $statement->execute([$userId, $event, time(), substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 49)]);
}

<?php
declare(strict_types=1);

function aov_channel_favorites(int $userId): array {
    $stmt = aov_db()->prepare('SELECT id, channel_id AS channel, channel_path AS path, title FROM aov_channel_favorites WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
    $stmt->execute([$userId]);
    return ['favorites' => $stmt->fetchAll()];
}

function aov_channel_favorite_action(string $action, array $input, int $userId): array {
    $db = aov_db();
    if ($action === 'channel_favorite_remove') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) throw new InvalidArgumentException('Invalid favorite');
        $db->prepare('DELETE FROM aov_channel_favorites WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        return aov_channel_favorites($userId);
    }
    if ($action !== 'channel_favorite_add') throw new InvalidArgumentException('Unknown favorite action');
    $channel = $input['channel'] ?? null;
    $path = $input['path'] ?? null;
    if (!is_string($channel) || !preg_match('/^[a-z][a-z0-9-]*$/D', $channel)
        || !is_string($path) || ($path !== '' && !preg_match('/^[a-z][a-z0-9-]*(\/[a-z][a-z0-9-]*){0,7}$/D', $path))) {
        throw new InvalidArgumentException('Invalid channel');
    }
    $configPath = __DIR__ . '/channel-configs/' . $channel . '.json';
    if (!is_file($configPath)) throw new InvalidArgumentException('Channel is unavailable');
    $config = json_decode((string) file_get_contents($configPath), true);
    if (!is_array($config) || ($config['id'] ?? null) !== $channel) throw new InvalidArgumentException('Channel is unavailable');
    $node = $config;
    if ($channel === 'local' && str_starts_with($path, 'digital-cities')) {
        $directory = json_decode((string) file_get_contents(__DIR__ . '/channel-configs/digital-cities.json'), true);
        $parts = explode('/', $path);
        if (count($parts) > 3 || !is_array($directory)) throw new InvalidArgumentException('Channel is unavailable');
        $node = ['title' => 'Digital Cities'];
        if (count($parts) >= 2) {
            $group = null;
            foreach ($directory['groups'] ?? [] as $entry) if (($entry['id'] ?? null) === $parts[1]) $group = $entry;
            if ($group === null) throw new InvalidArgumentException('Channel is unavailable');
            $node = $group;
            if (count($parts) === 3) {
                $city = null;
                foreach ($group['cities'] ?? [] as $entry) if (($entry['id'] ?? null) === $parts[2]) $city = $entry;
                if ($city === null) throw new InvalidArgumentException('Channel is unavailable');
                $node = $city;
            }
        }
    } elseif ($path !== '') {
        foreach (explode('/', $path) as $slug) {
            $child = null;
            foreach ($node['children'] ?? [] as $entry) if (($entry['id'] ?? null) === $slug) $child = $entry;
            if ($child === null) throw new InvalidArgumentException('Channel is unavailable');
            $node = $child;
        }
    }
    $title = trim((string) ($node['title'] ?? $config['title'] ?? $channel));
    if ($title === '') $title = $channel;
    $count = $db->prepare('SELECT COUNT(*) FROM aov_channel_favorites WHERE user_id = ? AND NOT (channel_id = ? AND channel_path = ?)');
    $count->execute([$userId, $channel, $path]);
    if ((int) $count->fetchColumn() >= 100) throw new InvalidArgumentException('Favorites limit reached');
    $db->prepare('INSERT INTO aov_channel_favorites (user_id, channel_id, channel_path, title, created_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(user_id, channel_id, channel_path) DO UPDATE SET title = excluded.title')
        ->execute([$userId, $channel, $path, $title, time()]);
    return aov_channel_favorites($userId);
}

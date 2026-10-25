<?php
declare(strict_types=1);

/** The seed contains published starter posts; edits and drafts stay outside the web root. */
function aov_bulletins_read(): array {
    $private = aov_private_dir() . DIRECTORY_SEPARATOR . 'on-aov-posts.json';
    $source = is_file($private) ? $private : __DIR__ . '/on-aov-seed.json';
    $data = json_decode((string) file_get_contents($source), true);
    if (!is_array($data) || !isset($data['posts']) || !is_array($data['posts'])) {
        throw new RuntimeException('AOV posts are unavailable');
    }
    return $data['posts'];
}

function aov_bulletins_save(array $posts): void {
    $directory = aov_private_dir();
    $file = $directory . DIRECTORY_SEPARATOR . 'on-aov-posts.json';
    $json = json_encode(['posts' => $posts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Could not encode AOV posts');
    if (is_file($file)) {
        $backup = $directory . DIRECTORY_SEPARATOR . 'on-aov-posts-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
        if (!copy($file, $backup)) throw new RuntimeException('Could not back up AOV posts');
    }
    $temporary = tempnam($directory, 'aov-posts-');
    if ($temporary === false) throw new RuntimeException('AOV post storage is unavailable');
    try {
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) throw new RuntimeException('Could not save AOV posts');
        @chmod($temporary, 0600);
        if (!rename($temporary, $file)) throw new RuntimeException('Could not replace AOV posts');
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function aov_bulletins_public(): array {
    $posts = array_values(array_filter(aov_bulletins_read(), static fn($post): bool => is_array($post) && ($post['status'] ?? '') === 'published'));
    usort($posts, static fn(array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
    return $posts;
}

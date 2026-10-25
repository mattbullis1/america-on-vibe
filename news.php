<?php
declare(strict_types=1);

// One fixed RSS source for the Phase 1A welcome screen. No user-supplied URLs.
const AOV_NEWS_FEED = 'https://feeds.npr.org/1001/rss.xml';
const AOV_NEWS_CACHE_SECONDS = 900;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aov-news-' . substr(hash('sha256', __FILE__ . AOV_NEWS_FEED), 0, 16) . '.json';
$cached = @file_get_contents($cacheFile);
if ($cached !== false && @filemtime($cacheFile) >= time() - AOV_NEWS_CACHE_SECONDS) {
    echo $cached;
    exit;
}

$curl = curl_init(AOV_NEWS_FEED);
if ($curl === false) {
    unavailable();
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'AOV-Phase1A-News/1.0',
]);
$xmlText = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if (!is_string($xmlText) || $httpCode !== 200 || strlen($xmlText) > 524288 || stripos($xmlText, '<!DOCTYPE') !== false) {
    unavailable();
}

libxml_use_internal_errors(true);
$feed = simplexml_load_string($xmlText, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
libxml_clear_errors();
if ($feed === false || !isset($feed->channel->item)) {
    unavailable();
}

$items = [];
foreach ($feed->channel->item as $item) {
    $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $link = trim((string) $item->link);
    $host = strtolower((string) parse_url($link, PHP_URL_HOST));
    $published = strtotime((string) $item->pubDate);
    if ($title === '' || $host !== 'www.npr.org' || !str_starts_with($link, 'https://') || $published === false || $published < time() - 3 * 86400) {
        continue;
    }
    $items[] = [
        'title' => mb_substr($title, 0, 180),
        'url' => $link,
        'published' => gmdate(DATE_ATOM, $published),
    ];
    if (count($items) === 5) {
        break;
    }
}
if ($items === []) {
    unavailable();
}

$json = json_encode([
    'source' => 'NPR Top Stories RSS',
    'fetchedAt' => gmdate(DATE_ATOM),
    'items' => $items,
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) {
    unavailable();
}
@file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;

function unavailable(): void
{
    http_response_code(503);
    echo json_encode(['source' => 'NPR Top Stories RSS', 'items' => [], 'error' => 'News is temporarily unavailable.']);
    exit;
}

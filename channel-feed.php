<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/channel-feed-images.php';
$auth = aov_bootstrap();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
if (!$auth->isLoggedIn()) { http_response_code(401); exit('{"error":"Please sign on again"}'); }

$channel = (string) ($_GET['channel'] ?? '');
$feedId = (string) ($_GET['feed'] ?? '');
if (!preg_match('/^[a-z][a-z0-9]*$/D', $channel) || !preg_match('/^[a-z][a-z0-9-]*$/D', $feedId)) {
    http_response_code(400); exit('{"error":"Invalid feed"}');
}
$configPath = __DIR__ . '/channel-configs/' . $channel . '.json';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
$feed = is_array($config) ? findFeed($config, $feedId) : null;
$url = $feed['url'] ?? null;
if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
    || !in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['rss.upi.com', 'feeds.skynews.com', 'www.christianitytoday.com', 'meh.com', '9to5toys.com', 'itch.io', 'feeds.npr.org', 'www.pbs.org', 'abcnews.com', 'www.cbsnews.com', 'www.theguardian.com', 'lemmy.world', 'www.cbssports.com', 'www.runnersworld.com', 'www.espn.com', 'www.goodnewsnetwork.org', 'www.positive.news', 'www.optimistdaily.com', 'www.cjr.org', 'www.cntraveler.com', 'www.nps.gov', 'feeds.bbci.co.uk', 'www.cbc.ca', 'globalnews.ca', 'www.whitehouse.gov', 'www.lemonde.fr', 'www.aljazeera.com', 'www.nerdwallet.com', 'www.cnbc.com', 'www.consumerfinance.gov', 'www.fastcompany.com', 'www.inc.com', 'www.entrepreneur.com', 'arstechnica.com', 'www.bleepingcomputer.com', 'www.howtogeek.com', 'www.makeuseof.com', 'www.theverge.com', 'hackaday.com', 'www.raspberrypi.com', 'www.smithsonianmag.com', 'www.sciencedaily.com', 'daily.jstor.org', 'blogs.loc.gov', 'www.nasa.gov', 'www.scifinow.co.uk', 'variety.com', 'www.hollywoodreporter.com', 'www.indiewire.com', 'www.billboard.com', 'pitchfork.com', 'www.bonappetit.com', 'www.thekitchn.com', 'www.apartmenttherapy.com', 'www.gardenersworld.com', 'www.akc.org', 'bookriot.com', 'lithub.com', 'makezine.com', 'www.vogue.com', 'www.allure.com', 'www.theminimalists.com', 'tinybuddha.com', 'www.self.com', 'www.oprahdaily.com', 'medlineplus.gov', 'www.mother.ly', 'www.readbrightly.com', 'www.parentmap.com'], true)) {
    http_response_code(404); exit('{"error":"Feed is not configured"}');
}

$cache = sys_get_temp_dir() . '/aov-channel-feed-' . hash('sha256', 'published-v1:' . $url) . '.json';
$cached = @file_get_contents($cache);
if ($cached !== false && @filemtime($cache) >= time() - 900) { echo $cached; exit; }

$curl = curl_init($url);
if ($curl === false) { unavailable(); }
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'AOV-Channel-Feeds/1.0',
    CURLOPT_MAXFILESIZE => 524288,
]);
$body = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
if (!is_string($body) || $status !== 200 || strlen($body) > 524288 || stripos($body, '<!DOCTYPE') !== false) {
    if ($cached !== false) { echo $cached; exit; }
    unavailable();
}
libxml_use_internal_errors(true);
$xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
libxml_clear_errors();
if ($xml === false) { if ($cached !== false) { echo $cached; exit; } unavailable(); }
$entries = isset($xml->channel->item) ? $xml->channel->item : $xml->entry;
$items = [];
foreach ($entries as $entry) {
    $rawTitle = $channel === 'games' && trim((string) $entry->plainTitle) !== ''
        ? (string) $entry->plainTitle : (string) $entry->title;
    $title = trim(html_entity_decode(strip_tags($rawTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $link = isset($xml->channel->item) ? trim((string) $entry->link) : trim((string) $entry->link['href']);
    if ($title === '' || !filter_var($link, FILTER_VALIDATE_URL) || !in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) continue;
    $description = html_entity_decode((string) $entry->description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $summary = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
    $rawDate = trim((string) $entry->pubDate);
    if ($rawDate === '') $rawDate = trim((string) $entry->children('http://purl.org/dc/elements/1.1/')->date);
    if ($rawDate === '') $rawDate = trim((string) ($entry->published ?: $entry->updated));
    $published = $rawDate === '' ? false : strtotime($rawDate);
    $items[] = [
        'title' => mb_substr($title, 0, 180),
        'url' => $link,
        'summary' => mb_substr($summary, 0, 190),
        'image' => aov_feed_image($entry),
        'publishedAt' => $published === false ? null : gmdate('c', $published),
    ];
    if (count($items) === (in_array($channel, ['games', 'shopping'], true) ? 8 : 5)) break;
}
if ($items === []) { if ($cached !== false) { echo $cached; exit; } unavailable(); }
$json = json_encode(['source' => $feed['title'] ?? $feedId, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) unavailable();
@file_put_contents($cache, $json, LOCK_EX);
echo $json;

function unavailable(): never {
    http_response_code(503);
    exit('{"error":"Headlines are temporarily unavailable"}');
}

function findFeed(array $node, string $id, int $depth = 0): ?array {
    foreach (($node['sections'] ?? []) as $section) {
        if (!in_array(($section['type'] ?? ''), ['feeds', 'game-cards', 'deal-cards'], true)) continue;
        foreach (($section['items'] ?? []) as $item) {
            if (($item['id'] ?? '') === $id && is_array($item)) return $item;
        }
    }
    if ($depth >= 8) return null;
    foreach (($node['children'] ?? []) as $child) {
        if (!is_array($child)) continue;
        $found = findFeed($child, $id, $depth + 1);
        if ($found !== null) return $found;
    }
    return null;
}

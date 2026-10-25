<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/local-data.php';
$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) aov_json(['error' => 'Please sign on again'], 401);
$cityId = (string) ($_GET['city'] ?? '');
$cityEntry = null;
if ($cityId !== '') {
    if (!preg_match('/^[a-z][a-z0-9-]*$/D', $cityId)) aov_json(['error' => 'Unknown city'], 404);
    $directory = json_decode((string) file_get_contents(__DIR__ . '/channel-configs/digital-cities.json'), true);
    foreach ($directory['groups'] ?? [] as $group) {
        foreach ($group['cities'] ?? [] as $city) {
            if (($city['id'] ?? '') === $cityId) { $cityEntry = $city; break 2; }
        }
    }
    if ($cityEntry === null) aov_json(['error' => 'Unknown city'], 404);
    $location = ['name' => $cityEntry['city'], 'admin1' => $cityEntry['region'],
        'latitude' => $cityEntry['latitude'], 'longitude' => $cityEntry['longitude'],
        'lookupSource' => 'AOV Digital Cities directory'];
    $zip = '';
} else {
    $stmt = aov_db()->prepare('SELECT zip_code FROM aov_profiles WHERE user_id = ?');
    $stmt->execute([(int) $auth->getUserId()]);
    $zip = (string) ($stmt->fetchColumn() ?: '');
    if ($zip === '') aov_json(['needsZip' => true, 'items' => []]);
    $location = aov_zip_location($zip);
}
if ($location === null) aov_json(['error' => 'Location is temporarily unavailable', 'items' => []], 503);
$cache = aov_private_dir() . DIRECTORY_SEPARATOR . ($cityId === '' ? 'local-' . $zip : 'local-city-' . $cityId) . '.json';
if (is_file($cache) && filemtime($cache) > time() - 900
    && isset((json_decode((string) file_get_contents($cache), true) ?: [])['latitude'])) {
    header('Content-Type: application/json; charset=utf-8');
    readfile($cache);
    exit;
}
$weather = aov_weather($location);
// Search RSS is used only for local headlines; links open at the publisher/aggregator.
$query = $location['name'] . ' ' . $location['admin1'] . ' local news when:7d';
$country = $cityEntry['country'] ?? 'US';
$locale = $country === 'CA' ? 'CA' : 'US';
$url = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=en-' . $locale . '&gl=' . $locale . '&ceid=' . $locale . ':en';
$curl = curl_init($url);
$items = [];
if ($curl !== false) {
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'AOV local headlines/1.0']);
    $xml = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (is_string($xml) && $status === 200 && strlen($xml) < 524288 && stripos($xml, '<!DOCTYPE') === false) {
        libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if ($feed !== false) foreach ($feed->channel->item ?? [] as $item) {
            $title = trim(html_entity_decode(strip_tags((string) $item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $link = (string) $item->link;
            if ($title === '' || !str_starts_with($link, 'https://news.google.com/')) continue;
            $items[] = ['title' => mb_substr($title, 0, 180), 'url' => $link];
            if (count($items) >= 5) break;
        }
    }
}
$result = ['city' => $location['name'], 'region' => $location['admin1'], 'zipCode' => $zip,
    'locationSource' => $location['lookupSource'] ?? 'Open-Meteo',
    'latitude' => $location['latitude'], 'longitude' => $location['longitude'],
    'placeQuery' => $location['name'] . ', ' . $location['admin1'],
    'forecastUrl' => $country === 'CA'
        ? 'https://weather.gc.ca/en/location/index.html?coords=' . round($location['latitude'], 3) . ',' . round($location['longitude'], 3)
        : ($cityId === '' ? 'https://forecast.weather.gov/zipcity.php?inputstring=' . rawurlencode($zip)
            : 'https://forecast.weather.gov/MapClick.php?lat=' . round($location['latitude'], 5) . '&lon=' . round($location['longitude'], 5)),
    'weather' => $weather, 'items' => $items, 'source' => 'Google News search RSS',
    'newsUrl' => 'https://news.google.com/search?q=' . rawurlencode($location['name'] . ' ' . $location['admin1'])];
$json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
if ($json === false) aov_json(['error' => 'Local content is unavailable'], 503);
file_put_contents($cache, $json, LOCK_EX);
header('Content-Type: application/json; charset=utf-8');
echo $json;

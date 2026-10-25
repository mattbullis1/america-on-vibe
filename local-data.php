<?php
declare(strict_types=1);

// Fixed upstream hosts and a five-digit ZIP prevent user-controlled outbound URLs.
function aov_remote_json(string $url): ?array {
    $curl = curl_init($url);
    if ($curl === false) return null;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 7, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'AOV local weather/1.0']);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status !== 200 || strlen($body) > 262144) return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

function aov_zip_location(string $zip): ?array {
    if (!preg_match('/^[0-9]{5}$/D', $zip)) return null;
    $path = aov_private_dir() . DIRECTORY_SEPARATOR . 'geo-' . $zip . '.json';
    if (is_file($path) && filemtime($path) > time() - 30 * 86400) {
        $cached = json_decode((string) file_get_contents($path), true);
        if (is_array($cached) && isset($cached['latitude'], $cached['longitude'], $cached['name'], $cached['admin1'])) return $cached;
    }
    $data = aov_remote_json('https://geocoding-api.open-meteo.com/v1/search?name=' . $zip . '&count=10&language=en&format=json&countryCode=US');
    foreach ($data['results'] ?? [] as $result) {
        if (($result['country_code'] ?? '') !== 'US' || !in_array($zip, $result['postcodes'] ?? [], true)) continue;
        if (!is_numeric($result['latitude'] ?? null) || !is_numeric($result['longitude'] ?? null)) continue;
        $location = ['name' => (string) $result['name'], 'admin1' => (string) ($result['admin1'] ?? ''),
            'latitude' => (float) $result['latitude'], 'longitude' => (float) $result['longitude'],
            'lookupSource' => 'Open-Meteo'];
        file_put_contents($path, json_encode($location), LOCK_EX);
        return $location;
    }
    // Open-Meteo's place index misses some rural US ZIP codes.
    $postal = aov_remote_json('https://api.zippopotam.us/us/' . $zip);
    foreach ($postal['places'] ?? [] as $place) {
        if (!is_numeric($place['latitude'] ?? null) || !is_numeric($place['longitude'] ?? null)) continue;
        $location = ['name' => (string) ($place['place name'] ?? ''),
            'admin1' => (string) ($place['state abbreviation'] ?? ''),
            'latitude' => (float) $place['latitude'], 'longitude' => (float) $place['longitude'],
            'lookupSource' => 'Zippopotam.us'];
        if ($location['name'] === '' || $location['admin1'] === '') continue;
        file_put_contents($path, json_encode($location), LOCK_EX);
        return $location;
    }
    if ($data === null && $postal === null) throw new RuntimeException('ZIP lookup is temporarily unavailable');
    return null;
}

function aov_weather(array $location): ?array {
    $lat = round($location['latitude'], 3);
    $lon = round($location['longitude'], 3);
    $data = aov_remote_json('https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon . '&current=temperature_2m,weather_code&temperature_unit=fahrenheit&timezone=auto');
    $current = $data['current'] ?? null;
    if (!is_array($current) || !is_numeric($current['temperature_2m'] ?? null)) return null;
    $code = (int) ($current['weather_code'] ?? -1);
    $condition = match (true) {
        $code === 0 => 'Clear', $code <= 3 => 'Cloudy', $code <= 48 => 'Foggy',
        $code <= 67 => 'Rain', $code <= 77 => 'Snow', $code <= 82 => 'Showers',
        $code <= 86 => 'Snow showers', $code <= 99 => 'Thunderstorms', default => 'Conditions unavailable',
    };
    return ['temperatureF' => (int) round((float) $current['temperature_2m']), 'condition' => $condition];
}

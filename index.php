<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth = aov_bootstrap();
$state = aov_state($auth);
$channelFile = file_get_contents(__DIR__ . '/channels.json');
$channelSettings = $channelFile === false ? null : json_decode($channelFile, true);
$state['channels'] = [];
if (is_array($channelSettings)) {
    foreach ($channelSettings as $id => $address) {
        if (!is_string($id) || !is_string($address)) continue;
        $url = filter_var($address, FILTER_VALIDATE_URL);
        if ($url === false) continue;
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) continue;
        $state['channels'][$id] = $url;
    }
}
$json = json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
$html = file_get_contents(__DIR__ . '/view.tpl');
if ($html === false) {
    http_response_code(500);
    exit('AOV view is unavailable');
}
$html = str_replace('styles.css"', 'styles.css?v=' . filemtime(__DIR__ . '/styles.css') . '"', $html);
$html = str_replace('app.js"', 'app.js?v=' . filemtime(__DIR__ . '/app.js') . '"', $html);
echo str_replace('<!-- AOV_STATE -->', '<script type="application/json" id="aov-state">' . $json . '</script>', $html);

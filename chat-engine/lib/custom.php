<?php
/*
 * @package AJAX_Chat
 * @author Sebastian Tschan
 * @copyright (c) Sebastian Tschan
 * @license Modified MIT License
 * @link https://blueimp.net/ajax/
 */

// Use the existing AOV account session for every chat request.
require_once dirname(__DIR__, 2) . '/bootstrap.php';
$GLOBALS['aovChatAuth'] = aov_bootstrap();
if (!$GLOBALS['aovChatAuth']->isLoggedIn()) {
    http_response_code(401);
    exit('Please sign on to AOV');
}
if (!isset($_REQUEST['ajax']) || !hash_equals(aov_csrf(), (string) ($_SERVER['HTTP_X_AOV_CSRF'] ?? ''))) {
    http_response_code(403);
    exit('Invalid chat request');
}
if (isset($_REQUEST['text']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (isset($_POST['text'])) aov_touch($GLOBALS['aovChatAuth']);

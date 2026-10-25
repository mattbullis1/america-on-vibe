<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Delight\Auth\Role;

$auth = aov_bootstrap();
if (!$auth->isLoggedIn()) { header('Location: ./', true, 303); exit; }
if (!$auth->hasRole(Role::ADMIN) && !$auth->hasRole(Role::SUPER_ADMIN)) { http_response_code(403); exit('Forbidden'); }
aov_touch($auth);
$state = json_encode(['csrf' => aov_csrf(), 'name' => $auth->getUsername()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>AOV Administration</title><link rel="icon" href="assets/aov-icon.jpg"><link rel="stylesheet" href="admin.css?v=<?= filemtime(__DIR__ . '/admin.css') ?>"></head>
<body><header><img src="assets/aov-icon.jpg" alt="" width="40" height="40"><div><strong>AOV Administration</strong><small>Signed in as <?= htmlspecialchars((string) $auth->getUsername(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small></div><a href="./">Return to AOV</a></header>
<main>
<p id="admin-message" role="status"></p>
<section><h1>Members</h1><p>Suspend or restore accounts and correct screen names. Changes sign out the affected member.</p><div id="admin-users" class="rows"></div></section>
<section><h2>On AOV posts</h2><p>Write official AOV updates. Drafts stay private; published posts appear in the News channel. Body text is plain text.</p>
<form id="bulletin-form" class="bulletin-editor">
<input id="bulletin-id" type="hidden">
<label>Title<input id="bulletin-title" maxlength="120" required></label>
<label>Date<input id="bulletin-date" type="date" required></label>
<label>Summary<textarea id="bulletin-summary" maxlength="240" rows="2" required></textarea></label>
<label>Body<textarea id="bulletin-body" maxlength="5000" rows="6" required></textarea></label>
<label>Picture<select id="bulletin-image"><option value="">No picture</option><option value="assets/aov-logo.png">AOV logo</option><option value="assets/aov-icon.jpg">AOV icon</option><option value="assets/news-channel-logo.png">AOV News logo</option></select></label>
<label>Status<select id="bulletin-status"><option value="draft">Draft</option><option value="published">Published</option></select></label>
<div class="bulletin-actions"><button type="submit">Save post</button><button type="button" id="bulletin-cancel">New post / Cancel edit</button></div>
</form><div id="admin-bulletins" class="rows"></div></section>
<section><h2>Mail reports</h2><p>Only messages reported by recipients appear here.</p><div id="admin-mail-reports" class="rows"></div></section>
<section><h2>Chat rooms</h2><form id="room-form"><label for="room-name">New room name</label><input id="room-name" maxlength="40" required><label for="room-category">Interest category</label><input id="room-category" maxlength="24" value="General" required><button type="submit">Add room</button></form><div id="admin-rooms" class="rows"></div></section>
<section><h2>Channel destinations</h2><p>These addresses are saved to <code>channels.json</code> with a private backup. The file remains directly editable.</p><div id="admin-channels" class="rows"></div></section>
<section><h2>Security events</h2><div id="admin-events" class="rows"></div></section>
</main>
<script id="admin-state" type="application/json"><?= $state ?></script><script src="admin.js?v=<?= filemtime(__DIR__ . '/admin.js') ?>" defer></script></body></html>

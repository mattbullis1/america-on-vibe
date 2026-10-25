<?php
// Copy this file to the private aov-private/config.php directory OUTSIDE public_html.
// Never put real SMTP credentials in the public application or Git repository.
return [
    // For the planned subdomain, point this at its root. Keep the trailing slash.
    'base_url' => 'https://americaonvibe.com/',
    'require_https' => true,
    // Activate only after verifying a recoverable private backup on this host.
    'retention_enabled' => false,
    // Live chat requires a dedicated least-privilege MariaDB user.
    'chat_mysql' => ['host' => '127.0.0.1', 'name' => 'aov_chat', 'user' => '', 'password' => ''],
    'smtp' => [
        'host' => '',
        'port' => 587,
        'security' => 'starttls', // or 'smtps' for port 465
        'username' => '',
        'password' => '',
        'from' => '',
    ],
];

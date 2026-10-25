<?php
declare(strict_types=1);
require dirname(__DIR__) . '/retention.php';

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE aov_security_events(id INTEGER PRIMARY KEY,at INTEGER)');
$db->exec('CREATE TABLE aov_chat_reports(id INTEGER PRIMARY KEY,status TEXT,closed_at INTEGER)');
$db->exec('CREATE TABLE aov_mail(id INTEGER PRIMARY KEY,sender_archived INTEGER,recipient_archived INTEGER)');
$db->exec('CREATE TABLE aov_mail_reports(id INTEGER PRIMARY KEY,message_id INTEGER,status TEXT,closed_at INTEGER,FOREIGN KEY(message_id) REFERENCES aov_mail(id) ON DELETE CASCADE)');
$db->exec('CREATE TABLE aov_maintenance(name TEXT PRIMARY KEY,last_run INTEGER)');
$now = 2000000000;
$cutoff = $now - 90 * 86400;
$db->exec('INSERT INTO aov_security_events VALUES (1,' . ($cutoff - 1) . '),(2,' . $cutoff . ')');
$db->exec("INSERT INTO aov_chat_reports VALUES (1,'open'," . ($cutoff - 1) . "),(2,'closed',NULL),(3,'closed'," . ($cutoff - 1) . "),(4,'closed',$cutoff)");
$db->exec('INSERT INTO aov_mail VALUES (1,1,0),(2,1,1),(3,1,1),(4,1,1),(5,1,1)');
$db->exec("INSERT INTO aov_mail_reports VALUES (1,3,'open',NULL),(2,4,'closed'," . ($cutoff - 1) . "),(3,5,'closed',NULL)");
function assertIds(PDO $db, string $table, string $expected): void {
    $actual = implode(',', $db->query("SELECT id FROM $table ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    if ($actual !== $expected) throw new RuntimeException("$table: expected $expected, got $actual");
}
if (aov_retention_delete_archived_mail($db, 3) !== 0) throw new RuntimeException('Reported mail deleted prematurely');
aov_retention_daily($db, $now);
assertIds($db, 'aov_security_events', '2');
assertIds($db, 'aov_chat_reports', '1,2,4');
assertIds($db, 'aov_mail_reports', '1,3');
assertIds($db, 'aov_mail', '1,3,5');
$db->exec('INSERT INTO aov_security_events VALUES (3,' . ($cutoff - 2) . ')');
aov_retention_daily($db, $now + 1);
assertIds($db, 'aov_security_events', '2,3');
aov_retention_daily($db, $now + 86400);
assertIds($db, 'aov_security_events', '');
echo "Retention boundaries, open reports, legacy closure dates, archive states, and daily scheduling passed.\n";

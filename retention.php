<?php
declare(strict_types=1);

const AOV_RETENTION_DAYS = 90;

/** Remove a fully archived message only after all related reports are gone. */
function aov_retention_delete_archived_mail(PDO $db, int $messageId): int {
    $stmt = $db->prepare('DELETE FROM aov_mail WHERE id=? AND sender_archived=1 AND recipient_archived=1 AND NOT EXISTS (SELECT 1 FROM aov_mail_reports r WHERE r.message_id=aov_mail.id)');
    $stmt->execute([$messageId]);
    return $stmt->rowCount();
}

/** Bounded SQLite cleanup; closed reports without a known closure date are preserved. */
function aov_retention_batch(PDO $db, int $now, int $limit = 100): array {
    if ($limit < 1 || $limit > 500) throw new InvalidArgumentException('Invalid retention batch size');
    $cutoff = $now - AOV_RETENTION_DAYS * 86400;
    $counts = [];
    $db->beginTransaction();
    try {
        foreach ([
            'events' => 'DELETE FROM aov_security_events WHERE id IN (SELECT id FROM aov_security_events WHERE at < ? ORDER BY at,id LIMIT ?)',
            'chat_reports' => "DELETE FROM aov_chat_reports WHERE id IN (SELECT id FROM aov_chat_reports WHERE status='closed' AND closed_at IS NOT NULL AND closed_at < ? ORDER BY closed_at,id LIMIT ?)",
            'mail_reports' => "DELETE FROM aov_mail_reports WHERE id IN (SELECT id FROM aov_mail_reports WHERE status='closed' AND closed_at IS NOT NULL AND closed_at < ? ORDER BY closed_at,id LIMIT ?)",
        ] as $key => $sql) {
            $stmt = $db->prepare($sql);
            $stmt->bindValue(1, $cutoff, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->execute();
            $counts[$key] = $stmt->rowCount();
        }
        $stmt = $db->prepare('DELETE FROM aov_mail WHERE id IN (SELECT m.id FROM aov_mail m WHERE m.sender_archived=1 AND m.recipient_archived=1 AND NOT EXISTS (SELECT 1 FROM aov_mail_reports r WHERE r.message_id=m.id) ORDER BY m.id LIMIT ?)');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $counts['mail'] = $stmt->rowCount();
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    return $counts;
}

/** At most one batch daily on a shared-hosting web request. */
function aov_retention_daily(PDO $db, int $now): void {
    $stmt = $db->prepare("SELECT last_run FROM aov_maintenance WHERE name='retention'");
    $stmt->execute();
    $last = $stmt->fetchColumn();
    if ($last !== false && $now - (int) $last < 86400) return;
    aov_retention_batch($db, $now);
    $stmt = $db->prepare("INSERT INTO aov_maintenance(name,last_run) VALUES('retention',?) ON CONFLICT(name) DO UPDATE SET last_run=excluded.last_run");
    $stmt->execute([$now]);
}

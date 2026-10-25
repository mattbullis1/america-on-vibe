<?php
declare(strict_types=1);

use Delight\Auth\Role;
require_once dirname(__DIR__, 3) . '/chat-moderation.php';

/** Frug AJAX-Chat adapter: AOV is the sole source of account identity. */
class CustomAJAXChat extends AJAXChat {
    function onNewMessage($text) {
        $reason = aov_chat_guard_message((int) $this->getUserID(), (string) $text);
        if ($reason === null) return true;
        header('X-AOV-Chat-Notice: ' . $reason);
        return false;
    }
    function insertParsedMessagePrivMsg($textParts) {
        if (isset($textParts[1])) {
            $target = aov_db()->prepare('SELECT id FROM users WHERE username = ? COLLATE NOCASE LIMIT 1');
            $target->execute([(string) $textParts[1]]);
            $targetId = (int) $target->fetchColumn();
            if ($targetId > 0) {
                $blocked = aov_db()->prepare('SELECT 1 FROM aov_chat_blocks WHERE (user_id=? AND blocked_id=?) OR (user_id=? AND blocked_id=?) LIMIT 1');
                $blocked->execute([(int) $this->getUserID(), $targetId, $targetId, (int) $this->getUserID()]);
                if ($blocked->fetchColumn()) return;
            }
        }
        parent::insertParsedMessagePrivMsg($textParts);
    }
    function getValidLoginUserData() {
        $auth = $GLOBALS['aovChatAuth'];
        if (!$auth->isLoggedIn()) return null;
        $userId = (int) $auth->getUserId();
        $name = (string) $auth->getUsername();
        if ($userId < 1 || $userId >= (int) $this->getConfig('privateChannelDiff') || $name === '') return null;
        $role = $auth->hasRole(Role::ADMIN) || $auth->hasRole(Role::SUPER_ADMIN)
            ? AJAX_CHAT_ADMIN : AJAX_CHAT_USER;
        return ['userID' => $userId, 'userName' => $name, 'userRole' => $role];
    }

    function revalidateUserID() {
        $auth = $GLOBALS['aovChatAuth'];
        return $auth->isLoggedIn()
            && (int) $auth->getUserId() === (int) $this->getUserID()
            && (string) $auth->getUsername() === (string) $this->getLoginUserName();
    }

    function &getAllChannels() {
        if ($this->_allChannels === null) {
            $this->_allChannels = [];
            foreach (aov_db()->query('SELECT id,name FROM aov_chat_rooms WHERE enabled=1 ORDER BY id') as $room) {
                $this->_allChannels[(string) $room['name']] = (int) $room['id'];
            }
        }
        return $this->_allChannels;
    }

    function &getChannels() {
        if ($this->_channels === null) $this->_channels = $this->getAllChannels();
        return $this->_channels;
    }

    function getMessageFilter() {
        $filter = parent::getMessageFilter();
        $query = aov_db()->prepare('SELECT blocked_id FROM aov_chat_blocks WHERE user_id=?');
        $query->execute([(int) $this->getUserID()]);
        $blocked = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
        if ($blocked) $filter .= ' AND userID NOT IN (' . implode(',', $blocked) . ')';
        return $filter;
    }
}

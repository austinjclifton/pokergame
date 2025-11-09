<?php
// backend/app/services/LobbyService.php
// Business logic for lobby-related operations (presence + chat)

declare(strict_types=1);

require_once __DIR__ . '/../db/presence.php';
require_once __DIR__ . '/../db/chat_messages.php';
require_once __DIR__ . '/../db/users.php';
require_once __DIR__ . '/../../lib/session.php';
require_once __DIR__ . '/../../lib/security.php';

/**
 * Fetch all currently online players in the lobby.
 * Only callable by an authenticated user.
 */
function lobby_get_online_players(PDO $pdo): array {
    $user = requireSession($pdo);
    if (!$user) {
        throw new RuntimeException('UNAUTHORIZED');
    }

    $players = db_get_online_users($pdo);

    // Escape usernames for XSS prevention
    $players = array_map(function($player) {
        $player['user_username'] = escape_html($player['user_username']);
        return $player;
    }, $players);

    return [
        'ok' => true,
        'players' => $players,
    ];
}

/**
 * Retrieve the most recent chat messages from the lobby.
 * Returns an array of messages with sender usernames and timestamps.
 */
function lobby_get_recent_messages(PDO $pdo, int $limit = 20): array {
    $rows = db_get_recent_chat_messages($pdo, 'lobby', 0, $limit);

    // Normalize field names for frontend consistency (escape for XSS prevention)
    $messages = array_map(static function ($row) {
        return [
            'from' => escape_html($row['sender_username'] ?? ''),
            'msg' => escape_html($row['body'] ?? ''),
            'time' => !empty($row['created_at']) ? date('H:i:s', strtotime($row['created_at'])) : '',
        ];
    }, $rows);

    return $messages;
}

/**
 * Record a new lobby chat message in the database.
 * Returns the stored message row (with sender info and timestamp).
 */
function lobby_record_message(PDO $pdo, int $userId, string $text): array {
    $text = trim($text);
    if ($text === '') {
        throw new InvalidArgumentException('Empty message');
    }

    // Get username for the message
    $username = db_get_username_by_id($pdo, $userId) ?? "User#$userId";

    // Insert message into chat_messages
    $msgId = db_insert_chat_message($pdo, 'lobby', 0, $userId, $text, null, $username);
    $rows = db_get_recent_chat_messages($pdo, 'lobby', 0, 1);

    return $rows[0] ?? [];
}

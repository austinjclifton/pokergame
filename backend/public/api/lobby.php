<?php
// backend/public/api/lobby.php
// -----------------------------------------------------------------------------
// HTTP controller: Returns all users currently online in the lobby.
//
// Layers:
//  - API: handles headers, JSON, HTTP codes
//  - Service: LobbyService::lobby_get_online_players()
//  - DB: presence.php for SQL access
// -----------------------------------------------------------------------------

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Set allowed methods for this endpoint
setAllowedMethods('GET, OPTIONS');

require_once __DIR__ . '/../../app/services/LobbyService.php';

// Apply rate limiting (100 requests/minute per IP)
apply_rate_limiting(null, 100, 200, 60);

try {
    $result = lobby_get_online_players($pdo);
    echo json_encode($result);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'UNAUTHORIZED') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Not authenticated']);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Bad request']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error']);
}

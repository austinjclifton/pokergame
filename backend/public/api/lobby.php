<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('GET, OPTIONS');
apply_rate_limiting(null, 100, 200, 60);

try {
	echo json_encode(lobby_get_online_players($pdo));
} catch (RuntimeException $e) {
	if ($e->getMessage() === 'UNAUTHORIZED') {
		http_response_code(401);
		echo json_encode(['ok' => false, 'message' => 'Not authenticated']);
		return;
	}

	http_response_code(400);
	echo json_encode(['ok' => false, 'message' => 'Bad request']);
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['ok' => false, 'message' => 'Server error']);
}

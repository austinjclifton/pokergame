<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('GET, OPTIONS');
apply_rate_limiting(null, 100, 200, 60);

try {
	$purpose = trim((string) ($_GET['purpose'] ?? ''));
	if (!in_array($purpose, ['register', 'logout', 'challenge', 'challenge_accept', 'challenge_response'], true)) {
		http_response_code(400);
		echo json_encode(['ok' => false, 'message' => 'Invalid nonce purpose']);
		exit;
	}
	echo json_encode(['ok' => true] + nonce_issue($pdo, 15, $purpose));
} catch (Throwable $e) {
	error_log('[nonce.php] ' . $e->getMessage());
	http_response_code(500);
	echo json_encode(['ok' => false, 'message' => 'Server error']);
}

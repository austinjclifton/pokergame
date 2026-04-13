<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

setAllowedMethods('GET, OPTIONS');
apply_rate_limiting(null, 100, 200, 60);

try {
	echo json_encode(['ok' => true] + nonce_issue($pdo));
} catch (Throwable $e) {
	error_log('[nonce.php] ' . $e->getMessage());
	http_response_code(500);
	$response = ['ok' => false, 'message' => 'Server error'];
	if (debug_enabled()) {
		$response['detail'] = $e->getMessage();
	}
	echo json_encode($response);
}

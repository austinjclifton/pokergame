<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once BASE_PATH . '/app/db/challenges.php';
require_once BASE_PATH . '/app/db/users.php';

apply_rate_limiting(null, 100, 200, 60);

try {
	$user = auth_require_session($pdo);
	apply_rate_limiting((int) $user['id'], 100, 200, 60);

	$userId = (int) $user['id'];
	$pending = db_get_pending_challenges_by_user($pdo, $userId);

	$outgoing = [];
	$incoming = [];

	foreach ($pending as $challenge) {
		$row = [
			'id' => $challenge['id'],
			'opponent' => escape_html(
				$challenge['from_user_id'] == $userId
					? $challenge['to_username']
					: $challenge['from_username']
			),
			'opponent_id' => $challenge['from_user_id'] == $userId
				? (int) $challenge['to_user_id']
				: (int) $challenge['from_user_id'],
			'created_at' => $challenge['created_at'],
		];

		if ($challenge['from_user_id'] == $userId) {
			$outgoing[] = $row;
			continue;
		}

		$incoming[] = $row;
	}

	echo json_encode([
		'ok' => true,
		'outgoing' => $outgoing,
		'incoming' => $incoming,
	]);
} catch (Throwable $e) {
	error_log('[pending.php] ' . $e->getMessage());
	http_response_code(500);
	echo json_encode([
		'ok' => false,
		'error' => 'Failed to fetch pending challenges',
		'debug' => debug_enabled() ? $e->getMessage() : null,
	]);
}

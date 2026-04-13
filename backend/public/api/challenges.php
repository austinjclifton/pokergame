<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

apply_rate_limiting(null, 100, 200, 60);

try {
	$user = auth_require_session($pdo);
	apply_rate_limiting((int) $user['id'], 100, 200, 60);
	$userId = (int) $user['id'];

	$stmt = $pdo->prepare(
		'SELECT gc.id,
				gc.from_user_id,
				gc.to_user_id,
				gc.status,
				gc.created_at,
				from_user.username AS from_username,
				to_user.username AS to_username
		 FROM game_challenges gc
		 JOIN users from_user ON from_user.id = gc.from_user_id
		 JOIN users to_user ON to_user.id = gc.to_user_id
		 WHERE (gc.from_user_id = ? OR gc.to_user_id = ?)
		   AND gc.status = ?
		 ORDER BY gc.created_at DESC'
	);
	$stmt->execute([$userId, $userId, 'pending']);

	$challenges = $stmt->fetchAll(PDO::FETCH_ASSOC);
	$formattedChallenges = array_map(static function (array $challenge) use ($userId): array {
		return [
			'id' => (int) $challenge['id'],
			'from_user_id' => (int) $challenge['from_user_id'],
			'to_user_id' => (int) $challenge['to_user_id'],
			'from_username' => escape_html((string) $challenge['from_username']),
			'to_username' => escape_html((string) $challenge['to_username']),
			'status' => $challenge['status'],
			'created_at' => $challenge['created_at'],
			'is_from_me' => (int) $challenge['from_user_id'] === $userId,
			'is_to_me' => (int) $challenge['to_user_id'] === $userId,
		];
	}, $challenges);

	echo json_encode([
		'ok' => true,
		'challenges' => $formattedChallenges,
	]);
} catch (Exception $e) {
	error_log('[challenges.php] ' . $e->getMessage());
	http_response_code(500);
	$response = ['ok' => false, 'error' => 'Failed to fetch challenges'];
	if (debug_enabled()) {
		$response['message'] = $e->getMessage();
	}
	echo json_encode($response);
}

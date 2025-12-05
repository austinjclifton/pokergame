<?php
// backend/config/db.php
// Centralized PDO connection setup for PokerGame backend.

declare(strict_types=1);

// ---------------------------------------------------------------
// Detect LOCAL vs VM environment
// ---------------------------------------------------------------

// True when running from command-line (WS server, CLI tests, etc.)
$runningInCli = php_sapi_name() === 'cli';

// Detect if we are on the VM by hostname.
// Your VM hostname is "student-virtual-machine".
$hostname = gethostname();
$isVm = str_contains($hostname, 'student-virtual-machine');

// Local environment rules:
// - CLI only counts as LOCAL when NOT on the VM
// - PHP dev server counts as LOCAL
// - localhost / 127.0.0.1 via HTTP counts as LOCAL
$IS_LOCAL =
    (!$isVm && $runningInCli) ||
    php_sapi_name() === 'cli-server' ||
    str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost') ||
    str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');

// ---------------------------------------------------------------
// Assign DB credentials
// ---------------------------------------------------------------
if ($IS_LOCAL) {
    // LOCAL (MacBook)
    $DB_HOST = '127.0.0.1';
    $DB_NAME = 'pokergame';
    $DB_USER = 'root';
    $DB_PASS = '';  // no password locally
} else {
    // VM (Apache + WebSocket server)
    $DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
    $DB_NAME = getenv('DB_NAME') ?: 'pokergame';
    $DB_USER = getenv('DB_USER') ?: 'root';
    $DB_PASS = getenv('DB_PASS') ?: 'student'; // required on VM
}

// ---------------------------------------------------------------
// Construct PDO
// ---------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]
    );
} catch (PDOException $e) {

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    error_log('[db.php] Database connection failed: ' . $e->getMessage());

    $isDebug = getenv('DEBUG') === '1' || getenv('APP_ENV') === 'development';

    $response = ['ok' => false, 'error' => 'Database connection failed'];
    if ($isDebug) {
        $response['details'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

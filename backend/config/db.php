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

$explicitValues = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
    $value = getenv($key);
    if ($value !== false) {
        $explicitValues[$key] = $value;
    }
}
$hasAnyExplicitDatabaseSetting = $explicitValues !== [] || getenv('DB_PORT') !== false;
$hasCompleteExplicitDatabaseSettings = count($explicitValues) === 4;

if ($hasAnyExplicitDatabaseSetting && !$hasCompleteExplicitDatabaseSettings) {
    throw new RuntimeException('Incomplete database configuration: DB_HOST, DB_NAME, DB_USER, and DB_PASS must be provided together');
}

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
if ($hasCompleteExplicitDatabaseSettings) {
    $DB_HOST = $explicitValues['DB_HOST'];
    $DB_NAME = $explicitValues['DB_NAME'];
    $DB_USER = $explicitValues['DB_USER'];
    $DB_PASS = $explicitValues['DB_PASS'];
} elseif ($IS_LOCAL) {
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

$DB_PORT = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 3306;

if (getenv('APP_ENV') === 'testing' && !preg_match('/^[A-Za-z0-9_]+_test$/', $DB_NAME)) {
    throw new RuntimeException('Testing requires a database name ending in _test');
}

// ---------------------------------------------------------------
// Construct PDO
// ---------------------------------------------------------------
try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
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

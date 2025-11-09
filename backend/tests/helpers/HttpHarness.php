<?php
declare(strict_types=1);

/**
 * HTTP harness for testing API endpoints
 * 
 * Executes actual endpoint files and captures HTTP headers and response body.
 * This allows testing the full HTTP contract (status codes, headers, JSON schema).
 */

/**
 * Run an endpoint file and capture its output
 * 
 * @param string $endpointPath Path to endpoint file (e.g., __DIR__ . '/../../../public/api/me.php')
 * @param array $server $_SERVER superglobal values
 * @param array $get $_GET superglobal values
 * @param array $post $_POST superglobal values
 * @param array $cookie $_COOKIE superglobal values
 * @param string|null $input Raw request body (for POST/PUT with JSON)
 * @return array{status: int, headers: array<string>, body: string, headers_map: array<string, string>}
 */
function run_endpoint(
    string $endpointPath,
    array $server = [],
    array $get = [],
    array $post = [],
    array $cookie = [],
    ?string $input = null
): array {
    // Set up superglobals
    $_SERVER = array_merge([
        'REQUEST_METHOD' => 'GET',
        'SERVER_NAME' => 'localhost',
        'SERVER_PORT' => '80',
        'REQUEST_URI' => '/',
        'SCRIPT_NAME' => '/index.php',
        'QUERY_STRING' => '',
    ], $server);
    
    $_GET = $get;
    $_POST = $post;
    $_COOKIE = $cookie;
    
    // Set up input stream if provided
    if ($input !== null) {
        // Create a temporary file for php://input simulation
        $tempFile = tmpfile();
        fwrite($tempFile, $input);
        rewind($tempFile);
        // Note: We can't actually override php://input, but endpoints should use file_get_contents('php://input')
        // For testing, we'll need to mock this or use a different approach
        // For now, we'll rely on endpoints reading from a global or we'll patch file_get_contents
    }
    
    // Capture headers
    $headers = [];
    $headersMap = [];
    
    // Override header() function to capture headers
    if (!function_exists('test_header_override')) {
        // We'll use output buffering and parse headers from response
        // For now, capture via header_list() if available
    }
    
    // Capture original response code (may be false if not set)
    $originalHttpResponseCode = function_exists('http_response_code') ? http_response_code() : 200;
    
    // Ensure global $pdo is available (endpoints expect it from config/db.php)
    global $pdo;
    if (!isset($pdo) && isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }
    
    // Use process isolation to handle exit() calls
    // exit() terminates the entire PHP process, so we need to run endpoints in a separate process
    return run_endpoint_via_process($endpointPath, $server, $get, $post, $cookie, $input);
    
    // Get status code (PHP 5.4+)
    // http_response_code() returns false if no code was set, default to 200
    // Capture after endpoint execution
    if (function_exists('http_response_code')) {
        $statusCode = http_response_code();
        if ($statusCode === false) {
            $statusCode = 200; // Default status if none was set
        }
    } else {
        $statusCode = 200;
    }
    
    // Get headers (PHP 5.4+ with xdebug or custom header tracking)
    if (function_exists('xdebug_get_headers')) {
        $rawHeaders = xdebug_get_headers();
        foreach ($rawHeaders as $header) {
            $headers[] = $header;
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $headersMap[trim($name)] = trim($value);
            }
        }
    } elseif (function_exists('headers_list')) {
        // Fallback: use headers_list() if available
        $rawHeaders = headers_list();
        foreach ($rawHeaders as $header) {
            $headers[] = $header;
            if (strpos($header, ':') !== false) {
                [$name, $value] = explode(':', $header, 2);
                $headersMap[trim($name)] = trim($value);
            }
        }
    }
    
    // Clear headers for next test
    if (function_exists('header_remove')) {
        // Clear all headers
        foreach ($headersMap as $name => $value) {
            header_remove($name);
        }
    }
    
    // Restore original response code (only if it was set)
    if (function_exists('http_response_code') && $originalHttpResponseCode !== false) {
        http_response_code($originalHttpResponseCode);
    }
    
    return [
        'status' => $statusCode,
        'headers' => $headers,
        'headers_map' => $headersMap,
        'body' => $body,
    ];
}

/**
 * Run endpoint via separate PHP process to handle exit() calls
 * 
 * @param string $endpointPath Path to endpoint file
 * @param array $server $_SERVER values
 * @param array $get $_GET values
 * @param array $post $_POST values
 * @param array $cookie $_COOKIE values
 * @param string|null $input Raw request body
 * @return array{status: int, headers: array<string>, body: string, headers_map: array<string, string>}
 */
function run_endpoint_via_process(
    string $endpointPath,
    array $server = [],
    array $get = [],
    array $post = [],
    array $cookie = [],
    ?string $input = null
): array {
    // Get the base directory (backend/)
    // HttpHarness.php is in tests/helpers/, so go up 2 levels to get backend/
    $baseDir = realpath(__DIR__ . '/../..');
    $bootstrapPath = realpath(__DIR__ . '/../bootstrap.php');
    $endpointRealPath = realpath($endpointPath);
    
    if (!$endpointRealPath) {
        return [
            'status' => 500,
            'headers' => [],
            'headers_map' => [],
            'body' => json_encode(['ok' => false, 'error' => 'Endpoint file not found: ' . $endpointPath]),
        ];
    }
    
    if (!$bootstrapPath) {
        return [
            'status' => 500,
            'headers' => [],
            'headers_map' => [],
            'body' => json_encode(['ok' => false, 'error' => 'Bootstrap file not found']),
        ];
    }
    
    // Create a temporary script that runs the endpoint and captures output
    // We override exit() to capture status code before termination
    $script = <<<PHP
<?php
// Suppress warnings
ini_set('display_errors', '0');
error_reporting(0);

// Change to backend directory
chdir('{$baseDir}');

// Capture output and status
ob_start();
\$statusCode = 200;
\$headers = [];
\$body = '';

// Override exit() to capture status code before termination
\$originalExit = null;
if (function_exists('exit')) {
    // We'll capture status in a shutdown handler instead
}

// Track if endpoint already output JSON
\$endpointOutputted = false;

// Register shutdown handler to capture status code even if exit() is called
register_shutdown_function(function() use (&\$statusCode, &\$body, &\$headers, &\$endpointOutputted) {
    // Capture status code
    if (function_exists('http_response_code')) {
        \$code = http_response_code();
        if (\$code !== false) {
            \$statusCode = \$code;
        }
    }
    
    // Capture any remaining output
    if (ob_get_level() > 0) {
        \$remaining = ob_get_contents();
        if (!empty(\$remaining)) {
            \$body = \$remaining;
            // Check if body looks like JSON (starts with { or [)
            if (preg_match('/^[\s]*[{\[]/', \$body)) {
                \$endpointOutputted = true;
            }
        }
        ob_end_clean();
    }
    
    // Get headers
    if (function_exists('xdebug_get_headers')) {
        \$headers = xdebug_get_headers();
    } elseif (function_exists('headers_list')) {
        \$headers = headers_list();
    }
    
    // Only output wrapper JSON if endpoint didn't already output JSON
    // Otherwise, the endpoint output is already in \$body
    if (!\$endpointOutputted) {
        // Output JSON result wrapper
        echo json_encode([
            'status' => \$statusCode,
            'body' => \$body,
            'headers' => \$headers,
        ]);
    } else {
        // Endpoint already output JSON, wrap it
        echo json_encode([
            'status' => \$statusCode,
            'body' => \$body,
            'headers' => \$headers,
        ]);
    }
});

// Set up superglobals from environment
\$_SERVER = json_decode(getenv('TEST_SERVER'), true) ?: [];
\$_GET = json_decode(getenv('TEST_GET'), true) ?: [];
\$_POST = json_decode(getenv('TEST_POST'), true) ?: [];
\$_COOKIE = json_decode(getenv('TEST_COOKIE'), true) ?: [];

// Set up php://input simulation using a global variable
// Endpoints should check for \$GLOBALS['_TEST_INPUT'] before reading php://input
\$testInput = getenv('TEST_INPUT');
if (!empty(\$testInput)) {
    \$GLOBALS['_TEST_INPUT'] = \$testInput;
}

// Set up global \$pdo from bootstrap (suppress warnings)
@require '{$bootstrapPath}';
global \$pdo;

// Include the endpoint (may call exit())
if (file_exists('{$endpointRealPath}')) {
    @include '{$endpointRealPath}';
}

// If we get here, endpoint didn't call exit - capture output normally
\$body = ob_get_clean();
\$statusCode = function_exists('http_response_code') ? http_response_code() : 200;
if (\$statusCode === false) {
    \$statusCode = 200;
}

// Get headers
if (function_exists('xdebug_get_headers')) {
    \$headers = xdebug_get_headers();
} elseif (function_exists('headers_list')) {
    \$headers = headers_list();
}

// Output JSON result
echo json_encode([
    'status' => \$statusCode,
    'body' => \$body,
    'headers' => \$headers,
]);
PHP;
    
    $tempScript = tempnam(sys_get_temp_dir(), 'phpunit_endpoint_');
    file_put_contents($tempScript, $script);
    
    // Set environment variables
    $env = [
        'TEST_ENDPOINT_PATH' => $endpointRealPath,
        'TEST_SERVER' => json_encode(array_merge([
            'REQUEST_METHOD' => 'GET',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'QUERY_STRING' => '',
        ], $server)),
        'TEST_GET' => json_encode($get),
        'TEST_POST' => json_encode($post),
        'TEST_COOKIE' => json_encode($cookie),
        'TEST_INPUT' => $input ?? '',
    ];
    
    // Build command with environment variables
    $envString = '';
    foreach ($env as $key => $value) {
        $envString .= $key . '=' . escapeshellarg($value) . ' ';
    }
    
    // Execute and capture output (redirect stderr to /dev/null to suppress warnings)
    $cmd = $envString . 'php ' . escapeshellarg($tempScript) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    @unlink($tempScript);
    
    if (empty($output)) {
        return [
            'status' => 500,
            'headers' => [],
            'headers_map' => [],
            'body' => json_encode(['ok' => false, 'error' => 'Endpoint execution failed - no output']),
        ];
    }
    
    // Try to find the last complete JSON object in output
    // The shutdown handler outputs JSON, so we want the last one
    $jsonObjects = [];
    $depth = 0;
    $start = -1;
    for ($i = 0; $i < strlen($output); $i++) {
        if ($output[$i] === '{') {
            if ($depth === 0) {
                $start = $i;
            }
            $depth++;
        } elseif ($output[$i] === '}') {
            $depth--;
            if ($depth === 0 && $start !== -1) {
                $jsonObjects[] = substr($output, $start, $i - $start + 1);
                $start = -1;
            }
        }
    }
    
    // Use the last JSON object (from shutdown handler)
    if (!empty($jsonObjects)) {
        $output = end($jsonObjects);
    } else {
        // Fallback: try to find any JSON
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }
    }
    
    $result = json_decode($output, true);
    if (!is_array($result)) {
        return [
            'status' => 500,
            'headers' => [],
            'headers_map' => [],
            'body' => json_encode(['ok' => false, 'error' => 'Invalid response from endpoint', 'raw_output' => substr($output, 0, 500)]),
        ];
    }
    
    // Parse headers
    $headersMap = [];
    foreach ($result['headers'] ?? [] as $header) {
        if (strpos($header, ':') !== false) {
            [$name, $value] = explode(':', $header, 2);
            $headersMap[trim($name)] = trim($value);
        }
    }
    
    return [
        'status' => (int)($result['status'] ?? 200),
        'headers' => $result['headers'] ?? [],
        'headers_map' => $headersMap,
        'body' => $result['body'] ?? '',
    ];
}

/**
 * Assert JSON response has required keys
 * 
 * @param array $data Decoded JSON data
 * @param array<string> $requiredKeys Required keys
 * @param string $message Optional assertion message
 */
function assertJsonHasKeys(array $data, array $requiredKeys, string $message = ''): void {
    foreach ($requiredKeys as $key) {
        PHPUnit\Framework\TestCase::assertArrayHasKey(
            $key,
            $data,
            $message ?: "Response must have key: {$key}"
        );
    }
}

/**
 * Assert JSON response matches expected schema
 * 
 * @param array $data Decoded JSON data
 * @param array<string, string> $schema Key => type mapping (e.g., ['ok' => 'boolean', 'user' => 'array'])
 * @param string $message Optional assertion message
 */
function assertJsonSchema(array $data, array $schema, string $message = ''): void {
    foreach ($schema as $key => $expectedType) {
        PHPUnit\Framework\TestCase::assertArrayHasKey($key, $data, "Missing key: {$key}");
        
        $actualType = gettype($data[$key]);
        if ($expectedType === 'array' && $actualType === 'array') {
            // OK
        } elseif ($expectedType === 'int' && $actualType === 'integer') {
            // OK
        } elseif ($expectedType === 'float' && ($actualType === 'double' || $actualType === 'integer')) {
            // OK
        } else {
            PHPUnit\Framework\TestCase::assertEquals(
                $expectedType,
                $actualType,
                $message ?: "Key '{$key}' must be type '{$expectedType}', got '{$actualType}'"
            );
        }
    }
}


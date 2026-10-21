<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support/TestDatabase.php';

$testDatabaseConfig = TestDatabaseConfig::fromEnvironment();
TestDatabaseConfig::publishForApplication($testDatabaseConfig);

$pdo = TestDatabaseConfig::connect($testDatabaseConfig);
$GLOBALS['pdo'] = $pdo;

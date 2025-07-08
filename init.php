<?php
/**
 * Application Bootstrap File
 *
 * This file is the single entry point for setting up the entire application environment.
 * It handles loading dependencies, configuration, and setting up core services. 
 * This file is activated by /scheduler/api_daemon.php
 *
 * @version 1.0
 * @author Peter Aarts
 */

// --- 1. Define Root Path & Error Reporting ---
// Use the __DIR__ magic constant to get the absolute path to this directory (the project root).
$abs_us_root = __DIR__;

// Set error reporting for development. In a production environment, you would
// want to log errors to a file instead of displaying them.
ini_set('display_errors', 1);
error_reporting(E_ALL);


// --- 2. Load Composer's Autoloader ---
// This handles the automatic loading of all your vendor packages (like mPDF, JPGraph)
// and your own namespaced classes (like ApiDaemon\DB).
if (file_exists($abs_us_root . '/vendor/autoload.php')) {
    require_once $abs_us_root . '/vendor/autoload.php';
} else {
    die('Error: Please run "composer install" to install dependencies.');
}

// --- 3. Load Environment Variables ---
// This securely loads your configuration from the .env file using the phpdotenv library.
// This keeps your secrets (like database passwords) out of version control.
try {
    $dotenv = Dotenv\Dotenv::createImmutable($abs_us_root);
    $dotenv->load();
} catch (\Dotenv\Exception\InvalidPathException $e) {
    die('Error: The .env file is missing. Please copy .env.example to .env and fill in your configuration.');
}


// --- 4. Set up Global Configuration ---
// This global variable makes your configuration accessible to legacy parts of the
// application or to classes like your DB class.
$GLOBALS['config'] = [
    // Default 'mysql' connection
    'mysql' => [
        'host'      => $_ENV['DB_HOST'],
        'username'  => $_ENV['DB_USER'],
        'password'  => $_ENV['DB_PASS'],
        'db'        => $_ENV['DB_NAME'],
    ],
];


// --- 5. Configure PHP Environment ---
// Set the default timezone for all date/time functions.
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'UTC');

// You can define other global constants or variables here if needed.
$copyright_message = $_ENV['APP_COPYRIGHT'] ?? 'Your Company';

// --- 6. Configure Logging ---
// This is where the logger is created and made available to the application.
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use ApiDaemon\Log;

$logger = new Logger('API_DAEMON');
$logger->pushHandler(new StreamHandler($abs_us_root . '/logs/app.log', Logger::DEBUG));

// This line is crucial: it passes the created logger into our helper class.
Log::setLogger($logger);

// --- Application is now Initialized ---
// Now it is safe to use the logger.
Log::info('Application Initialized Successfully.');


?>
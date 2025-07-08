<?php
// FILE: scheduler/API_daemon.php (The New Dispatcher)
// PURPOSE: The main entry point. Fetches tasks and dispatches them to the correct handlers.

require_once __DIR__ . '/../init.php';

use ApiDaemon\Log;
use ApiDaemon\Auth\Authenticator; // Or a future factory class
use ApiDaemon\ApiClient;

// --- Daemon Startup & Logging ---
$waitcycle = $_ENV['DAEMON_WAIT_CYCLE_SECONDS'] ?? 10;
$daemonid  = $_ENV['DAEMON_ID'] ?? 'daemon_' . uniqid();
Log::info("API DAEMON STARTING UP", ['daemon_id' => $daemonid, 'php_version' => PHP_VERSION]);
// ... your other startup echo/log messages ...

// --- Main Execution Loop ---
while (true) {
    $scheduledTasks = GetApiScheduler(); // This function must now also return the auth_type

    if (!empty($scheduledTasks)) {
        foreach ($scheduledTasks as $task) {
            Log::info("Processing Task: " . $task->name_EndPoint, ['task_id' => $task->id]);

            // Add a check for the required auth_type property
            if (!isset($task->auth_type)) {
                Log::error("Task is missing 'auth_type'. Skipping.", ['task_id' => $task->id]);
                continue;
            }

            try {
                // 1. Use the Factory to create the correct Authenticator
                $authenticator = AuthFactory::create($task->auth_type, (int) $task->api_id);

                // 2. Create an ApiClient and inject the authenticator
                $apiClient = new ApiClient($authenticator);

                // 3. Build the protocol handler class name
                $protocolClassName = 'ApiDaemon\\Protocols\\' . ucfirst($task->protocol);
                $methodName = $task->script;

                if (class_exists($protocolClassName)) {
                    // 4. Inject the configured ApiClient into the protocol handler
                    $handler = new $protocolClassName($apiClient);

                    if (method_exists($handler, $methodName)) {
                        $taskDetails = load_scheduler_task($task->id);
                        $handler->$methodName($taskDetails);
                        Log::info("Task executed successfully.", ['handler' => $protocolClassName, 'method' => $methodName]);
                    } else {
                        Log::error("Method not found in handler.", ['handler' => $protocolClassName, 'method' => $methodName]);
                    }
                } else {
                    Log::error("Protocol handler class not found.", ['handler_class' => $protocolClassName]);
                }

            } catch (Exception $e) {
                Log::error("A critical error occurred while setting up or running task.", ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }
        }
    } else {
        Log::info("No tasks to process. Waiting...");
    }
    sleep($waitcycle);
}
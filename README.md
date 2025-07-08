# API Daemon Framework - Installation Guide

This guide provides step-by-step instructions for setting up and running the API Daemon framework on a server.

---

## 1. Framework Capabilities

The API Daemon is a highly extensible and robust PHP framework designed to automate data collection from various external APIs. It runs as a continuous background service, managed by a database-driven schedule.

Its core capabilities include:

* **Multi-API & Multi-Protocol**: The framework is not tied to a single API. You can create different "Protocol Handlers" to define the business logic for fetching and processing data from any number of different APIs (e.g., rFMS, RDW, etc.).
* **Pluggable Authentication**: Supports multiple authentication methods out of the box (e.g., OAuth 2.0 Client Credentials, Scania's Challenge-Response). The included `AuthFactory` makes it simple to add new authentication schemes without changing existing code.
* **Database-Driven Scheduler**: All tasks are defined and scheduled in the `api_scheduler` table. The daemon periodically checks this table and executes any due tasks, making it easy to manage a complex set of data collection jobs.
* **Automated & Resilient**: Designed to be run as a background service using tools like `supervisor` or `systemd`, ensuring it's always running. It includes robust logging and error handling.
* **Modern & Maintainable**: Built on modern PHP 8+ principles with object-oriented design, PSR-4 autoloading via Composer, and clean separation of concerns (authentication, API communication, and data processing are all handled by different classes).

---

## 2. Server Requirements

Before you begin, ensure your server meets the following requirements:

* **PHP**: Version 8.0 or higher.
* **Composer**: The latest version of the PHP dependency manager.
* **Database**: A MySQL or MariaDB database server.
* **Shell Access**: You will need to run commands from the server's command line (CLI).

---

## 3. Installation Steps

### Step 1: Get the Code

Clone the repository from GitHub to your desired location on the server.

```bash
git clone <your-github-repository-url> api-daemon
cd api-daemon
```

### Step 2: Install Dependencies

Use Composer to install all the required PHP libraries (like Monolog, Dotenv, etc.) defined in `composer.json`.

```bash
composer install
```

This will create a `vendor` directory containing all the necessary packages.

### Step 3: Create the Configuration File

The framework uses a `.env` file to store all environment-specific configurations, such as database credentials and API keys. Copy the example file to create your own configuration.

```bash
cp .env.example .env
```
You will edit this file in the next section.

### Step 4: Set Up the Database

You need to create the necessary tables in your MySQL database. You can use the SQL statements provided in the **Database Structure** section below to create them. Execute these queries using a tool like phpMyAdmin or the MySQL command-line client.

### Step 5: Set File Permissions

The daemon needs to be able to write to the `logs` directory. Make sure the web server user has the correct permissions.

```bash
# Create the logs directory if it doesn't exist
mkdir -p logs

# Set appropriate permissions (adjust user/group as needed)
sudo chown www-data:www-data logs
sudo chmod 775 logs
```

---

## 4. `.env` File Configuration

Open the `.env` file you created and fill in the values for your specific environment.

```ini
# .env.example

# --- Application Settings ---
APP_TIMEZONE="Europe/Amsterdam"
APP_COPYRIGHT="Your Company Name"

# --- Database Connection ---
DB_HOST=127.0.0.1
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS="your_database_password"

# --- Daemon Settings ---
DAEMON_ID=1
DAEMON_WAIT_CYCLE_SECONDS=10
LOG_FILE="logs/daemon.log"

# --- SMTP Mailer Settings (for reports) ---
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_AUTH=true
SMTP_USERNAME=your_smtp_user
SMTP_PASSWORD="your_smtp_password"
FROM_EMAIL=noreply@example.com
FROM_NAME="Automated Reports"
```

---

## 5. Database Structure

Here are the `CREATE TABLE` statements for the core tables required by the framework.

#### `api_type`
Defines the different types of high-level services or interfaces.
```sql
CREATE TABLE `api_type` (
	`id` INT(3) NOT NULL AUTO_INCREMENT,
	`name` TINYTEXT NULL DEFAULT NULL,
	`description` TINYTEXT NULL DEFAULT NULL,
	`logo` TINYTEXT NULL DEFAULT NULL,
	`protocol` TINYTEXT NULL DEFAULT NULL,
	PRIMARY KEY (`id`) USING BTREE
)
COMMENT='Defines the different types of high-level services/interfaces'
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB;
```

#### `api_script_type`
Defines the specific, reusable scripts or actions available for a given protocol.
```sql
CREATE TABLE `api_script_type` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`name` VARCHAR(50) NULL DEFAULT NULL,
	`description` TEXT NULL DEFAULT NULL,
	`protocol` VARCHAR(50) NULL DEFAULT NULL,
	`version` VARCHAR(12) NULL DEFAULT NULL,
	`script` VARCHAR(50) NULL DEFAULT NULL COMMENT 'Matches a public method in the protocol class',
	`url_ext` TEXT NULL DEFAULT NULL,
	`header` TEXT NULL DEFAULT NULL,
	`installed` TINYINT(1) NULL DEFAULT NULL,
	PRIMARY KEY (`id`) USING BTREE
)
COLLATE='utf8mb4_general_ci'
ENGINE=InnoDB;
```

#### `api_collector`
Stores the connection and authentication details for each specific customer API instance.
```sql
CREATE TABLE `api_collector` (
	`id` INT(4) NOT NULL AUTO_INCREMENT,
	`customerId` INT(11) NULL DEFAULT NULL,
	`serviceId` INT(11) NULL DEFAULT NULL,
	`active` TINYINT(1) NOT NULL DEFAULT '0',
	`archived` TINYINT(1) NOT NULL DEFAULT '0',
	`daemon` TINYINT(1) NOT NULL DEFAULT '2',
	`name` VARCHAR(50) NULL DEFAULT NULL,
	`typeId` INT(3) NULL DEFAULT NULL COMMENT 'FK to api_type.id',
	`apiCustomerName` VARCHAR(50) NULL DEFAULT NULL,
	`ApiType` VARCHAR(50) NULL DEFAULT NULL,
	`vendor` VARCHAR(50) NULL DEFAULT NULL,
	`vendor_logo` TINYTEXT NULL DEFAULT NULL,
	`lastUpdateDateTime` TIMESTAMP NULL DEFAULT NULL,
	`BaseUrl` VARCHAR(70) NULL DEFAULT NULL,
	`username` VARCHAR(128) NULL DEFAULT NULL,
	`password` VARCHAR(128) NULL DEFAULT NULL,
	`auth_type` VARCHAR(50) NULL DEFAULT NULL COMMENT 'e.g., client_credentials, scania_oauth2',
	`clientId` TEXT NULL DEFAULT NULL,
	`secret` TEXT NULL DEFAULT NULL,
	`proxy_host` VARCHAR(50) NULL DEFAULT NULL,
	`proxy_port` INT(11) NULL DEFAULT NULL,
	`contactName` VARCHAR(50) NULL DEFAULT NULL,
	`contactemail` VARCHAR(50) NULL DEFAULT NULL,
	`contactPhone` VARCHAR(12) NULL DEFAULT NULL,
	PRIMARY KEY (`id`) USING BTREE,
	INDEX `idx_api_collector_customer` (`customerId`, `ApiType`) USING BTREE
)
COMMENT='Settings API-MANAGER per interface'
COLLATE='utf8mb4_general_ci'
ENGINE=InnoDB;
```

#### `api_scheduler`
Defines the specific tasks that the daemon needs to run. This is the main "to-do list".
```sql
CREATE TABLE `api_scheduler` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `api_id` int(11) NOT NULL COMMENT 'FK to api_collector.id',
  `name_EndPoint` varchar(255) NOT NULL,
  `protocol` varchar(50) NOT NULL COMMENT 'Matches the Protocol Class name',
  `script` varchar(100) NOT NULL COMMENT 'Matches the public method in the Protocol Class',
  `url_address` varchar(512) NOT NULL,
  `lastUpdateDateTime` datetime DEFAULT NULL,
  `lastStatus` varchar(20) DEFAULT NULL,
  `lastExecution` datetime DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### `api_collector_tokens`
Caches the access tokens obtained from APIs to prevent unnecessary re-authentication.
```sql
CREATE TABLE `api_collector_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `collector_id` int(11) NOT NULL COMMENT 'Corresponds to the id from the api_collector table',
  `token` text DEFAULT NULL,
  `tokenDT` datetime DEFAULT NULL,
  `tokenLT` int(11) DEFAULT NULL,
  `refreshToken` text DEFAULT NULL,
  `refreshTokenDT` datetime DEFAULT NULL,
  `updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `collector_id` (`collector_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 6. Running the Daemon

To run the daemon, execute the main `API_Daemon.php` file from your command line.

```bash
php scheduler/API_Daemon.php
```

It is highly recommended to run the daemon as a background service using a process manager like `supervisor` or `systemd`. This will ensure it restarts automatically if it ever stops.

### Example: Running with `nohup` (for simple testing)

```bash
nohup php scheduler/API_Daemon.php > /dev/null 2>&1 &
```

This will start the daemon in the background. You can check the `logs/daemon.log` file to see its output and ensure it's running correctly.

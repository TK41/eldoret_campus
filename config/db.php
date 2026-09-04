<?php
// ============================================================
// config/db.php
// Database connection configuration
// Edit DB_HOST, DB_NAME, DB_USER, DB_PASS to match your XAMPP setup
// Default XAMPP: host=localhost, user=root, pass=(empty)
// ============================================================

// Set timezone to Kenya (Africa/Nairobi)
date_default_timezone_set('Africa/Nairobi');

// -- Database credentials --
define('DB_HOST', 'localhost');
define('DB_NAME', 'kimc_inventory');
define('DB_USER', 'root');        // XAMPP default
define('DB_PASS', '');            // XAMPP default is empty — change in production

// -- Application settings --
define('APP_NAME',  'KIMC Eldoret Campus Inventory System');
define('APP_SHORT', 'KIMC-IS');
define('APP_ROOT',  '/eldoret_campus');  // URL path relative to htdocs

// ============================================================
// getDB() — returns a singleton PDO connection
// Uses PDO for prepared statements (prevents SQL injection)
// ============================================================
function getDB(): PDO {
    static $pdo = null;   // reuse the same connection per request

    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // throw exceptions on error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // return arrays by default
            PDO::ATTR_EMULATE_PREPARES   => false,                    // use real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            // Set MySQL session timezone to UTC for consistent storage
            $pdo->exec("SET time_zone='+00:00'");
        } catch (PDOException $e) {
            http_response_code(503);
            header('Location: ' . APP_ROOT . '/maintenance.php', true, 503);
            exit;
        }

        // ============================================================
        // One-time migration: Update tier names to new format
        // Runs automatically on first page load after update
        // ============================================================
        try {
            $pdo->exec("UPDATE IGNORE tiers SET name='Certificate' WHERE tier_id=1");
            $pdo->exec("UPDATE IGNORE tiers SET name='Diploma' WHERE tier_id=2");
            $pdo->exec("UPDATE IGNORE tiers SET name='Advanced / Honours' WHERE tier_id=3");
            $pdo->exec("UPDATE IGNORE tiers SET name='Staff / Faculty' WHERE tier_id=4");
        } catch (PDOException $e) {
            // Silently fail if migration already ran or tiers table doesn't exist
        }
    }

    return $pdo;
}
?>

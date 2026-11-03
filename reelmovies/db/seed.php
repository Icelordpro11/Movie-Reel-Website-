<?php
/**
 * Reset the database to an empty state (no accounts).
 * The FIRST account you register in the app becomes the admin.
 * Run:  php db/seed.php
 */
require __DIR__ . '/../api/lib/Database.php';

$pdo = Database::connection();
$pdo->exec("SET foreign_key_checks = 0");
foreach (['likes', 'friendships', 'user_settings', 'login_attempts', 'sessions', 'movies', 'users'] as $table) {
    $pdo->exec("TRUNCATE TABLE $table");
}
$pdo->exec("SET foreign_key_checks = 1");

echo "Database ready. No accounts yet — the first account you register becomes the admin.\n";

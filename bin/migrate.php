<?php

require __DIR__ . '/../vendor/autoload.php';

use Database\Database;

$db = new Database();
$pdo = $db->getConnection();

echo "Starting Migrations...\n";

// 1. Ensure the migrations table exists
$pdo->exec("
    CREATE TABLE IF NOT EXISTS applied_migrations (
        migration VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )
");

// 2. Scan for migration files
$migrationMetadata = [];
$files = glob(__DIR__ . '/../migrations/*.sql');
sort($files); // Ensure we run them in order (001, 002, etc.)

// 3. Get applied migrations
$applied = $pdo->query("SELECT migration FROM applied_migrations")->fetchAll(PDO::FETCH_COLUMN);

$newMigrations = 0;

foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied)) {
        continue;
    }

    echo "Migrating: $filename... ";

    try {
        $sql = file_get_contents($file);

        $pdo->beginTransaction();

        // Allow multiple queries in one file
        $pdo->exec($sql);

        $stmt = $pdo->prepare("INSERT INTO applied_migrations (migration) VALUES (?)");
        $stmt->execute([$filename]);

        $pdo->commit();
        echo "DONE.\n";
        $newMigrations++;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "FAILED.\n";
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($newMigrations === 0) {
    echo "Nothing to migrate.\n";
} else {
    echo "All migrations applied successfully.\n";
}

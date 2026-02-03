<?php

namespace Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Class Database
 *
 * A simple wrapper around PDO to handle database connections.
 * This class uses environment variables for configuration.
 *
 * @package Database
 */
class Database
{
    /**
     * @var PDO|null The PDO instance.
     */
    private ?PDO $pdo = null;

    /**
     * Get the PDO connection.
     *
     * Creates a new connection if one does not exist.
     *
     * @return PDO
     * @throws RuntimeException If the connection fails.
     */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $host = getenv('DB_HOST') ?: 'db';
            $db = getenv('MYSQL_DATABASE') ?: 'tariff_db';
            $user = getenv('MYSQL_USER') ?: 'root';
            $pass = getenv('MYSQL_PASSWORD') ?: 'root';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            try {
                $this->pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new RuntimeException("Database Connection Failed: " . $e->getMessage(), 0, $e);
            }
        }

        return $this->pdo;
    }
}

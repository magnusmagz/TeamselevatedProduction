<?php
/**
 * Database Connection - Neon PostgreSQL
 */

require_once __DIR__ . '/env.php';

class Database {
    private static $instance = null;
    private $connection;
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;

    private function __construct() {
        // Load from environment variables
        $this->host = Env::get('DB_HOST', 'localhost');
        $this->db_name = Env::get('DB_NAME', 'neondb');
        $this->username = Env::get('DB_USER', 'neondb_owner');
        $this->password = Env::get('DB_PASSWORD', '');
        $this->port = Env::get('DB_PORT', 5432);

        try {
            // PostgreSQL connection with SSL (required by Neon)
            $dsn = sprintf(
                "pgsql:host=%s;port=%d;dbname=%s;sslmode=require",
                $this->host,
                $this->port,
                $this->db_name
            );

            $this->connection = new PDO(
                $dsn,
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => true, // Use emulated prepares for connection pooling compatibility
                ]
            );

            // Set search path for PostgreSQL
            $this->connection->exec("SET search_path TO public");
            // Note: DEALLOCATE ALL removed - not needed with emulated prepares
            // and can interfere with active transactions in connection pooling

        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die(json_encode([
                'error' => 'Database connection failed',
                'message' => Env::get('APP_ENV') === 'development' ? $e->getMessage() : 'Internal server error'
            ]));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    /**
     * Set the JWT claims for Row-Level Security
     *
     * @param string $userId User's ID
     * @param array $additionalClaims Optional additional JWT claims
     */
    public function setAuthUser($userId, $additionalClaims = []) {
        try {
            $claims = array_merge([
                'user_id' => (string)$userId
            ], $additionalClaims);

            $claimsJson = json_encode($claims);

            // Set the JWT claims in the session for RLS
            $this->connection->exec(
                "SELECT set_config('request.jwt.claims', " .
                $this->connection->quote($claimsJson) . ", true)"
            );

        } catch (PDOException $e) {
            error_log('Failed to set auth user: ' . $e->getMessage());
        }
    }

    /**
     * Get database type
     *
     * @return string
     */
    public function getType() {
        return 'postgresql';
    }
}
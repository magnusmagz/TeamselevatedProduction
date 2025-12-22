<?php
/**
 * Environment Configuration Loader
 *
 * Loads environment variables from .env file
 */

class Env {
    private static $loaded = false;
    private static $vars = [];

    /**
     * Load environment variables from .env file
     */
    public static function load() {
        if (self::$loaded) {
            return;
        }

        $envFile = __DIR__ . '/../.env';

        if (!file_exists($envFile)) {
            // Try parent directory
            $envFile = __DIR__ . '/../../.env';
        }

        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                // Skip comments
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }

                // Parse KEY=VALUE
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Remove quotes
                    $value = trim($value, '"\'');

                    self::$vars[$key] = $value;
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }

        self::$loaded = true;
    }

    /**
     * Get environment variable
     *
     * @param string $key Variable name
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get($key, $default = null) {
        self::load();

        if (isset(self::$vars[$key])) {
            return self::$vars[$key];
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return $default;
    }

    /**
     * Check if environment variable exists
     *
     * @param string $key Variable name
     * @return bool
     */
    public static function has($key) {
        self::load();
        return isset(self::$vars[$key]) || isset($_ENV[$key]) || getenv($key) !== false;
    }
}

// Auto-load on include
Env::load();

<?php

class Cors
{
    /**
     * Handle CORS headers and preflight requests.
     *
     * Reads CORS_ALLOWED_ORIGINS from env, validates the request origin,
     * and sets appropriate headers. Handles OPTIONS preflight with 200 exit.
     */
    public static function handle(): void
    {
        $allowedOriginsEnv = getenv('CORS_ALLOWED_ORIGINS')
            ?: 'https://teams-elevated.netlify.app,http://localhost:5173,http://localhost:3000';

        $allowedOrigins = array_map('trim', explode(',', $allowedOriginsEnv));

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array($origin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
        }

        // Handle OPTIONS preflight request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}

<?php

final class Http
{
    // Decode the JSON request body into an associative array (form data as fallback).
    public static function body(): array
    {
        $json = json_decode(file_get_contents('php://input'), true);
        return is_array($json) ? $json : ($_POST ?: []);
    }

    // Send data as JSON with the given status code, then stop. 
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Send an error message as JSON, then stop. 
    public static function fail(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }
}

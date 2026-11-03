<?php

// Central application configuration, with environment variables for easy and secure deployment.
final class Config
{

    public static function db(): array
    {
        return [
            'host'    => getenv('DB_HOST')   ?: 'localhost',
            'port'    => getenv('DB_PORT')   ?: '3306',
            'name'    => getenv('DB_NAME')   ?: 'reelmovies',
            'user'    => getenv('DB_USER')   ?: 'reel',
            'pass'    => getenv('DB_PASS')   ?: 'reelpass',
            'socket'  => getenv('DB_SOCKET') ?: '',
            'charset' => 'utf8mb4',
        ];
    }

    public static function tmdbKey(): string
    {
        return getenv('TMDB_API_KEY') ?: '';
    }

    public static function tmdbImage(): string
    {
        return 'https://image.tmdb.org/t/p';
    }

    public static function corsOrigin(): string
    {
        return getenv('CORS_ORIGIN') ?: '*';
    }
}

<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';

/**
 * Per-user appearance settings. Right now that is the accent colour the user
 * chooses for the otherwise black-and-white interface.
 */
final class Settings
{
    // GET /me/settings — the current user's appearance settings.
    public static function get(): void
    {
        $user = Auth::requireUser();

        $row = Database::run(
            "SELECT accent_color FROM user_settings WHERE user_id = ?",
            [(int) $user['id']]
        )->fetch();

        Http::json(['settings' => ['accent_color' => $row['accent_color'] ?? '#ffffff']]);
    }

    // PUT /me/settings — save a validated #rrggbb accent colour.
    public static function put(): void
    {
        $user  = Auth::requireUser();
        $color = trim((string) (Http::body()['accent_color'] ?? ''));

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) 
        {
            Http::fail('accent_color must be a #rrggbb hex colour.', 422);
        }

        Database::run(
            "INSERT INTO user_settings (user_id, accent_color) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE accent_color = VALUES(accent_color)",
            [(int) $user['id'], $color]
        );

        Http::json(['settings' => ['accent_color' => $color]]);
    }
}

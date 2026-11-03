<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';

// GET /admin/users — every registered user (admins only). 
final class Admin
{
    public static function users(): void
    {
        Auth::requireAdmin();
        $rows = Database::run(
            "SELECT username, email, is_admin, created_at
             FROM users ORDER BY created_at ASC"
        )->fetchAll();

        $users = array_map(fn($u) => [
            'username'   => $u['username'],
            'email'      => $u['email'],
            'is_admin'   => (bool) $u['is_admin'],
            'created_at' => $u['created_at'],
        ], $rows);

        Http::json(['users' => $users]);
    }
}

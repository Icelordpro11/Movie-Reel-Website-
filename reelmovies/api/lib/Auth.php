<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Security.php';

//Handles user accounts and secure session management.
final class Auth
{
    private const SESSION_DAYS = 30;
    private static bool $cached = false;
    private static ?array $user = null;

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/Bearer\s+([A-Za-z0-9]+)/', $header, $m)) 
        {
            return $m[1];
        }

        return $_GET['token'] ?? null;
    }

    // Create a new session for a user and return the raw token.
    public static function startSession(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Database::run(
            "INSERT INTO sessions (token_hash, user_id, expires_at)
             VALUES (?, ?, (NOW() + INTERVAL ? DAY))",
            [self::hash($token), $userId, self::SESSION_DAYS]
        );
        return $token;
    }

    // The signed-in user, resolved from the session token (null if none/expired).
    public static function user(): ?array
    {
        if (self::$cached) 
        {
            return self::$user;
        }

        self::$cached = true;

        $token = self::bearerToken();

        if (!$token) 
        {
            return self::$user = null;
        }

        $hash = self::hash($token);
        $row = Database::run(
            "SELECT u.* FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > NOW()",
            [$hash]
        )->fetch();

        if ($row)
        {
            // Sliding expiry: keep active sessions alive.
            Database::run(
                "UPDATE sessions SET expires_at = (NOW() + INTERVAL ? DAY) WHERE token_hash = ?",
                [self::SESSION_DAYS, $hash]
            );
        }

        return self::$user = ($row ?: null);
    }

    // Return the signed-in user, or stop with 401. 
    public static function requireUser(): array
    {
        $user = self::user();

        if (!$user) 
        {
            Http::fail('Authentication required.', 401);
        }
        return $user;
    }

    // Return the signed-in user if they are an admin, or stop with 403.
    public static function requireAdmin(): array
    {
        $user = self::requireUser();

        if (!$user['is_admin']) 
        {
            Http::fail('Admins only.', 403);
        }
        return $user;
    }

    /** Safe public shape of a user (never the password hash or email). */
    public static function publicUser(array $u): array
    {
        return [
            'id'           => (int) $u['id'],
            'username'     => $u['username'],
            'display_name' => $u['display_name'] ?: $u['username'],
            'is_admin'     => (bool) ($u['is_admin'] ?? false),
        ];
    }

    // Fuller shape for the signed-in user themselves — includes their own email.
    public static function selfUser(array $u): array
    {
        return self::publicUser($u) + ['email' => $u['email'] ?? ''];
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    #Endpoints 

    /** POST /auth/register — create an account (first one becomes admin). */
    public static function register(): void
    {
        $b = Http::body();
        $username = trim($b['username'] ?? '');
        $email    = trim($b['email'] ?? '');
        $password = $b['password'] ?? '';

        if (!preg_match('/^[A-Za-z0-9_.]{3,40}$/', $username)) 
        {
            Http::fail('Username must be 3–40 characters (letters, numbers, _ or .).', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            Http::fail('A valid email address is required.', 422);
        }

        if (strlen($password) < 6) 
        {
            Http::fail('Password must be at least 6 characters.', 422);
        }

        $exists = Database::run("SELECT 1 FROM users WHERE username = ? OR email = ?", [$username, $email])->fetch();

        if ($exists) 
        {
            Http::fail('That username or email is already taken.', 409);
        }

        // First account to register becomes the admin.
        $isFirst = (int) Database::run("SELECT COUNT(*) AS c FROM users")->fetch()['c'] === 0;

        Database::run(
            "INSERT INTO users (username, email, password_hash, display_name, is_admin) VALUES (?,?,?,?,?)",
            [$username, $email, password_hash($password, PASSWORD_DEFAULT), $username, $isFirst ? 1 : 0]
        );
        $id = (int) Database::connection()->lastInsertId();
        Database::run("INSERT INTO user_settings (user_id) VALUES (?)", [$id]);

        $user = Database::run("SELECT * FROM users WHERE id = ?", [$id])->fetch();
        Http::json(['token' => self::startSession($id), 'user' => self::selfUser($user)], 201);
    }

    /** POST /auth/login — rate-limited credential check that starts a session. */
    public static function login(): void
    {
        $ip = Security::clientIp();
        Security::throttleLogin($ip);

        $b = Http::body();
        $login = trim($b['username'] ?? '');
        $user  = Database::run("SELECT * FROM users WHERE username = ? OR email = ?", [$login, $login])->fetch();

        if (!$user || !password_verify($b['password'] ?? '', $user['password_hash'])) 
        {
            Security::recordLoginFailure($ip);
            Http::fail('Wrong username or password.', 401);
        }

        Security::clearLoginFailures($ip);
        Http::json(['token' => self::startSession((int) $user['id']), 'user' => self::selfUser($user)]);
    }

    // POST /auth/logout — end the current session.
    public static function logout(): void
    {
        self::requireUser();
        $token = self::bearerToken();

        if ($token) 
        {
            Database::run("DELETE FROM sessions WHERE token_hash = ?", [self::hash($token)]);
        }

        Http::json(['ok' => true]);
    }

    // PUT /me/email — change email after confirming the current password.
    public static function changeEmail(): void
    {
        $user = self::requireUser();
        $b = Http::body();
        $email = trim($b['email'] ?? '');

        if (!password_verify($b['current_password'] ?? '', $user['password_hash'])) 
        {
            Http::fail('Your current password is incorrect.', 403);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) 
        {
            Http::fail('A valid email address is required.', 422);
        }

        $taken = Database::run("SELECT 1 FROM users WHERE email = ? AND id <> ?", [$email, (int) $user['id']])->fetch();

        if ($taken) 
        {
            Http::fail('That email is already in use.', 409);
        }
        Database::run("UPDATE users SET email = ? WHERE id = ?", [$email, (int) $user['id']]);
        Http::json(['ok' => true, 'email' => $email]);
    }

    // PUT /me/password — change password after confirming the current one.
    public static function changePassword(): void
    {
        $user = self::requireUser();
        $b = Http::body();
        $current = $b['current_password'] ?? '';
        $next    = $b['new_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) 
        {
            Http::fail('Your current password is incorrect.', 403);
        }

        if (strlen($next) < 6) 
        {
            Http::fail('New password must be at least 6 characters.', 422);
        }

        Database::run(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [password_hash($next, PASSWORD_DEFAULT), (int) $user['id']]
        );
        
        // End every other session for safety; keep the current one alive.
        Database::run(
            "DELETE FROM sessions WHERE user_id = ? AND token_hash <> ?",
            [(int) $user['id'], self::hash(self::bearerToken() ?? '')]
        );
        Http::json(['ok' => true]);
    }
}

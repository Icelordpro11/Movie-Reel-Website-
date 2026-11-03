<?php
/**
 * API front controller / router.
 * ------------------------------
 * One entry point for the whole REST API. It splits PATH_INFO into segments
 * and hands the request to the right class method. No framework — this works
 * the same under Apache and the built-in `php -S` server.
 *
 *   GET  api/index.php/feed?page=1
 *   POST api/index.php/like/27205
 *   PUT  api/index.php/me/settings
 */

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Movies.php';
require_once __DIR__ . '/lib/Social.php';
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/Admin.php';

// Hardening headers + CORS (so an Android / external client can call the API).
Security::headers();
header('Access-Control-Allow-Origin: ' . Config::corsOrigin());
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$seg    = array_values(array_filter(explode('/', $_SERVER['PATH_INFO'] ?? '')));

try {
    Router::dispatch($method, $seg);
    Http::fail('Not found.', 404);
} catch (PDOException $e) {
    Http::fail('Database error: ' . $e->getMessage(), 500);
}

/**
 * Router
 * ------
 * Maps a method + URL segments to a controller method. Kept deliberately flat
 * and readable so the whole API surface is visible in one place.
 */
final class Router
{
    public static function dispatch(string $method, array $seg): void
    {
        $r0 = $seg[0] ?? '';
        $id = static fn($i) => (isset($seg[$i]) && ctype_digit($seg[$i])) ? (int) $seg[$i] : null;

        // Health check
        if ($r0 === '' || $r0 === 'health') {
            Http::json(['ok' => true, 'tmdb' => MovieApi::enabled()]);
        }

        // --- Auth ---
        if ($r0 === 'auth') {
            $a = $seg[1] ?? '';
            if ($a === 'register' && $method === 'POST') Auth::register();
            if ($a === 'login'    && $method === 'POST') Auth::login();
            if ($a === 'logout'   && $method === 'POST') Auth::logout();
            if ($a === 'me'       && $method === 'GET')  Http::json(['user' => Auth::selfUser(Auth::requireUser())]);
            Http::fail('Unknown auth route.', 404);
        }

        // --- Movies / discover ---
        if ($r0 === 'feed'    && $method === 'GET')  Movies::feed();
        if ($r0 === 'search'  && $method === 'GET')  Movies::search();
        if ($r0 === 'trailer' && $id(1) && $method === 'GET')  Movies::trailer($id(1));
        if ($r0 === 'like'    && $id(1) && $method === 'POST') Movies::toggleLike($id(1));

        // --- Me ---
        if ($r0 === 'me') {
            $sub = $seg[1] ?? '';
            if ($sub === 'likes'    && $method === 'GET')  Movies::myLikes();
            if ($sub === 'friends'  && $method === 'GET')  Social::myFriends();
            if ($sub === 'requests' && $method === 'GET')  Social::myRequests();
            if ($sub === 'settings' && $method === 'GET')  Settings::get();
            if ($sub === 'settings' && in_array($method, ['PUT', 'POST'], true)) Settings::put();
            if ($sub === 'email'    && in_array($method, ['PUT', 'POST'], true)) Auth::changeEmail();
            if ($sub === 'password' && in_array($method, ['PUT', 'POST'], true)) Auth::changePassword();
            Http::fail('Unknown me route.', 404);
        }

        // --- Admin ---
        if ($r0 === 'admin') {
            if (($seg[1] ?? '') === 'users' && $method === 'GET') Admin::users();
            Http::fail('Unknown admin route.', 404);
        }

        // --- Users / friends ---
        if ($r0 === 'users') {
            if (($seg[1] ?? '') === '' && $method === 'GET') Social::searchUsers();
            if ($id(1) && $method === 'GET') Social::profile($id(1));
            Http::fail('Unknown users route.', 404);
        }
        if ($r0 === 'friends' && $id(1)) {
            $sub = $seg[2] ?? '';
            if ($sub === ''       && $method === 'POST')   Social::request($id(1));
            if ($sub === 'accept' && $method === 'POST')   Social::accept($id(1));
            if ($sub === ''       && $method === 'DELETE') Social::remove($id(1));
            Http::fail('Unknown friends route.', 404);
        }
    }
}

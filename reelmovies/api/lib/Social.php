<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Movies.php';

/**
 * Finding people, sending/accepting friend requests, and viewing a profile.
 * A profile shows the person's taste (top genres) and the movies they like —
 * which is the only place you can see "what kind of movies they like".
 */
final class Social
{
    public static function friendIds(int $userId): array
    {
        $rows = Database::run(
            "SELECT CASE WHEN requester_id = ? THEN addressee_id ELSE requester_id END AS fid
             FROM friendships
             WHERE status = 'accepted' AND (requester_id = ? OR addressee_id = ?)",
            [$userId, $userId, $userId]
        )->fetchAll();

        return array_map('intval', array_column($rows, 'fid'));
    }

    // GET /users?q= — search people by name.
    public static function searchUsers(): void
    {
        $me = Auth::requireUser();
        $q  = trim($_GET['q'] ?? '');

        $rows = Database::run(
            "SELECT * FROM users
             WHERE (username LIKE ? OR display_name LIKE ?) AND id <> ?
             ORDER BY display_name LIMIT 25",
            ['%' . $q . '%', '%' . $q . '%', (int) $me['id']]
        )->fetchAll();

        $friends = self::friendIds((int) $me['id']);
        $pending = self::pendingOut((int) $me['id']);

        $out = array_map(function ($u) use ($friends, $pending) 
        {
            $p = Auth::publicUser($u);
            $p['is_friend'] = in_array((int) $u['id'], $friends, true);
            $p['pending']   = in_array((int) $u['id'], $pending, true);
            return $p;
        }, $rows);

        Http::json(['users' => $out]);
    }

    // GET /users/{id} — a profile with taste + liked movies.
    public static function profile(int $id): void
    {
        $me = Auth::requireUser();
        $u  = Database::run("SELECT * FROM users WHERE id = ?", [$id])->fetch();

        if (!$u) 
        {
            Http::fail('User not found.', 404);
        }

        $p = Auth::publicUser($u);
        $p['is_friend']    = $id === (int) $me['id'] || in_array($id, self::friendIds((int) $me['id']), true);
        $p['taste']        = Movies::taste($id);       // top genres with counts
        $p['likes']        = Movies::likedBy($id);     // the movies they like

        Http::json(['user' => $p]);
    }

    // GET /me/friends — the current user's friends. 
    public static function myFriends(): void
    {
        $me  = Auth::requireUser();
        $ids = self::friendIds((int) $me['id']);

        if (!$ids) 
        {
            Http::json(['friends' => []]);
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::run("SELECT * FROM users WHERE id IN ($in) ORDER BY display_name", $ids)->fetchAll();

        Http::json(['friends' => array_map([Auth::class, 'publicUser'], $rows)]);
    }

    // GET /me/requests — incoming friend requests.
    public static function myRequests(): void
    {
        $me = Auth::requireUser();

        $rows = Database::run(
            "SELECT u.* FROM friendships f
             JOIN users u ON u.id = f.requester_id
             WHERE f.addressee_id = ? AND f.status = 'pending'
             ORDER BY f.created_at DESC",
            [(int) $me['id']]
        )->fetchAll();

        Http::json(['requests' => array_map([Auth::class, 'publicUser'], $rows)]);
    }

    // POST /friends/{id} — send a request (auto-accepts a mutual one).
    public static function request(int $id): void
    {
        $me  = Auth::requireUser();
        $uid = (int) $me['id'];

        if ($id === $uid) 
        {
            Http::fail("You can't add yourself.", 422);
        }
        // If they already asked me, accept instead of creating a duplicate.
        $incoming = Database::run(
            "SELECT 1 FROM friendships WHERE requester_id = ? AND addressee_id = ?",
            [$id, $uid]
        )->fetch();

        if ($incoming) 
        {
            Database::run(
                "UPDATE friendships SET status = 'accepted' WHERE requester_id = ? AND addressee_id = ?",
                [$id, $uid]
            );
            Http::json(['status' => 'accepted']);
        }

        Database::run(
            "INSERT INTO friendships (requester_id, addressee_id, status) VALUES (?, ?, 'pending')
             ON DUPLICATE KEY UPDATE status = status",
            [$uid, $id]
        );

        Http::json(['status' => 'pending']);
    }

    // POST /friends/{id}/accept — accept a pending request.
    public static function accept(int $id): void
    {
        $me = Auth::requireUser();

        $stmt = Database::run(
            "UPDATE friendships SET status = 'accepted'
             WHERE requester_id = ? AND addressee_id = ? AND status = 'pending'",
            [$id, (int) $me['id']]
        );

        if ($stmt->rowCount() === 0) 
        {
            Http::fail('No pending request from that user.', 404);
        }

        Http::json(['status' => 'accepted']);
    }

    // DELETE /friends/{id} — remove a friend (either direction).
    public static function remove(int $id): void
    {
        $me  = Auth::requireUser();
        $uid = (int) $me['id'];

        Database::run(
            "DELETE FROM friendships
             WHERE (requester_id = ? AND addressee_id = ?)
                OR (requester_id = ? AND addressee_id = ?)",
            [$uid, $id, $id, $uid]
        );

        Http::json(['ok' => true]);
    }

    // Ids this user has an outgoing pending request to.
    private static function pendingOut(int $userId): array
    {
        $rows = Database::run(
            "SELECT addressee_id FROM friendships WHERE requester_id = ? AND status = 'pending'",
            [$userId]
        )->fetchAll();

        return array_map('intval', array_column($rows, 'addressee_id'));
    }
}

<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/MovieApi.php';

/**
 * Everything about movies themselves: the Discover feed, searching, trailers,
 * liking/unliking, the list of movies you like, and a user's "taste" (their
 * most-liked genres). Liking a movie also caches its details into the movies
 * table so friends can see it later.
 */
final class Movies
{
    // GET /feed — a page of movies, each marked with whether you like it.
    public static function feed(): void
    {
        $user = Auth::requireUser();

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $seed = max(0, (int) ($_GET['seed'] ?? 0));     // per-session offset for variety

        $movies = MovieApi::feed($page, $seed);

        self::markLiked($movies, (int) $user['id']);
        Http::json(['page' => $page, 'movies' => $movies]);
    }

    // GET /search?q= — search movies by title.
    public static function search(): void
    {
        $user = Auth::requireUser();

        $movies = MovieApi::search($_GET['q'] ?? '');
        self::markLiked($movies, (int) $user['id']);
        
        Http::json(['movies' => $movies]);
    }

    // GET /trailer/{id} — YouTube trailer id for one movie. 
    public static function trailer(int $movieId): void
    {
        Auth::requireUser();
        Http::json(['youtube_id' => MovieApi::trailer($movieId)]);
    }

    // POST /like/{id} — toggle a like. The movie payload is cached on like. 
    public static function toggleLike(int $movieId): void
    {
        $user = Auth::requireUser();
        $uid  = (int) $user['id'];

        $already = Database::run(
            "SELECT 1 FROM likes WHERE user_id = ? AND movie_id = ?",
            [$uid, $movieId]
        )->fetch();

        if ($already) 
        {
            Database::run("DELETE FROM likes WHERE user_id = ? AND movie_id = ?", [$uid, $movieId]);
            Http::json(['liked' => false]);
        }

        self::cacheMovie(Http::body());
        Database::run("INSERT IGNORE INTO likes (user_id, movie_id) VALUES (?, ?)", [$uid, $movieId]);
        Http::json(['liked' => true]);
    }

    // GET /me/likes — the movies the current user likes. 
    public static function myLikes(): void
    {
        $user = Auth::requireUser();
        Http::json(['movies' => self::likedBy((int) $user['id'])]);
    }

#Shared helpers 

    // Cache a client-supplied movie into the movies table (insert or update). 
    public static function cacheMovie(array $m): void
    {
        $id = (int) ($m['id'] ?? 0);

        if (!$id) 
        {
            return;
        }

        Database::run(
            "INSERT INTO movies (id, title, year, overview, poster_url, backdrop_url, genres, rating)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                title        = VALUES(title),
                year         = VALUES(year),
                overview     = VALUES(overview),
                poster_url   = VALUES(poster_url),
                backdrop_url = VALUES(backdrop_url),
                genres       = VALUES(genres),
                rating       = VALUES(rating)",
            [
                $id,
                (string) ($m['title'] ?? ''),
                $m['year'] ?: null,
                (string) ($m['overview'] ?? ''),
                (string) ($m['poster_url'] ?? ''),
                (string) ($m['backdrop_url'] ?? ''),
                is_array($m['genres'] ?? null) ? implode(',', $m['genres']) : (string) ($m['genres'] ?? ''),
                (float) ($m['rating'] ?? 0),
            ]
        );
    }

    // The movies a given user likes, newest first. 
    public static function likedBy(int $userId): array
    {
        $rows = Database::run(
            "SELECT m.* FROM likes l
             JOIN movies m ON m.id = l.movie_id
             WHERE l.user_id = ?
             ORDER BY l.created_at DESC",
            [$userId]
        )->fetchAll();

        return array_map([self::class, 'rowToMovie'], $rows);
    }

    public static function taste(int $userId): array
    {
        $rows = Database::run(
            "SELECT m.genres FROM likes l
             JOIN movies m ON m.id = l.movie_id
             WHERE l.user_id = ?",
            [$userId]
        )->fetchAll();

        $counts = [];
        foreach ($rows as $r) 
        {
            foreach (array_filter(explode(',', $r['genres'])) as $g) 
            {
                $counts[$g] = ($counts[$g] ?? 0) + 1;
            }
        }

        arsort($counts);
        $out = [];

        foreach (array_slice($counts, 0, 6, true) as $name => $count) 
        {
            $out[] = ['name' => $name, 'count' => $count];
        }

        return $out;
    }

    // Convert a movies-table row into the API movie shape. 
    public static function rowToMovie(array $m): array
    {
        return [
            'id'           => (int) $m['id'],
            'title'        => $m['title'],
            'year'         => $m['year'] ? (int) $m['year'] : null,
            'overview'     => $m['overview'],
            'poster_url'   => $m['poster_url'],
            'backdrop_url' => $m['backdrop_url'],
            'genres'       => $m['genres'] !== '' ? explode(',', $m['genres']) : [],
            'rating'       => (float) $m['rating'],
        ];
    }

    // Add a `liked` flag to each movie for the given user.
    private static function markLiked(array &$movies, int $userId): void
    {
        $set = [];

        foreach (Database::run("SELECT movie_id FROM likes WHERE user_id = ?", [$userId])->fetchAll() as $r) 
        {
            $set[(int) $r['movie_id']] = true;
        }

        foreach ($movies as &$m)
        {
            $m['liked'] = isset($set[$m['id']]);
        }
    }
}

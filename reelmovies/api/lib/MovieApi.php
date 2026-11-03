<?php
require_once __DIR__ . '/Config.php';

// Handles TMDB movie requests, including discovery, search and trailer retrieval, with a built-in demo fallback.
final class MovieApi
{
    
    public static function enabled(): bool
    {
        return Config::tmdbKey() !== '';
    }

  // Retrieves movies for the Discover feed, using TMDB or a bundled demo list.
    public static function feed(int $page = 1, int $seed = 0): array
    {
        if (!self::enabled()) 
        {
            return $page <= 1 ? self::shuffledFallback() : [];
        }

        $realPage = (($seed + $page - 1) % 500) + 1;  

        $res = self::get('/discover/movie', [
            'sort_by'        => 'popularity.desc',
            'vote_count.gte' => 150,
            'include_adult'  => 'false',
            'page'           => $realPage,
        ]);

        if (!$res || empty($res['results'])) 
        {
            return [];
        }

        return array_map([self::class, 'shape'], $res['results']);
    }

    // Search movies by title.
    public static function search(string $query): array
    {
        $query = trim($query);

        if ($query === '') 
        {
            return [];
        }

        if (!self::enabled()) 
        {
            // Offline: filter the bundled catalogue by title.
            $q = mb_strtolower($query);
            return array_values(array_filter(
                self::fallbackShaped(),
                fn($m) => mb_strpos(mb_strtolower($m['title']), $q) !== false
            ));
        }

        $res = self::get('/search/movie', ['query' => $query, 'include_adult' => 'false']);
        return $res ? array_map([self::class, 'shape'], $res['results'] ?? []) : [];
    }

    // YouTube trailer id for a movie, or '' when none is available. 
    public static function trailer(int $movieId): string
    {
        if (!self::enabled()) 
        {
            foreach (self::fallbackRaw() as $m) 
            {
                if ($m[0] === $movieId) return $m[8];
            }

            return '';
        }

        $res = self::get("/movie/$movieId/videos");

        foreach (['Trailer', 'Teaser', 'Clip'] as $type) 
        {
            foreach (($res['results'] ?? []) as $v)
            {
                if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === $type) 
                {
                    return $v['key'];
                }
            }
        }

        return '';
    }

#Internals

    // Low-level GET against the TMDB v3 API. Returns a decoded array or null. 
    private static function get(string $path, array $params = []): ?array
    {
        $params['api_key'] = Config::tmdbKey();
        $url = 'https://api.themoviedb.org/3' . $path . '?' . http_build_query($params);
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    // id => name map of TMDB genres, cached on disk for a day.
    private static function genreMap(): array
    {
        static $map = null;

        if ($map !== null) 
        {
            return $map;
        }

        $cache = __DIR__ . '/../../data/tmdb_genres.json';

        if (is_file($cache) && (time() - filemtime($cache) < 86400)) 
        {
            $map = json_decode(file_get_contents($cache), true) ?: [];
            if ($map) return $map;
        }

        $map = [];

        foreach ((self::get('/genre/movie/list')['genres'] ?? []) as $g) 
        {
            $map[$g['id']] = $g['name'];
        }

        if ($map) 
        {
            @file_put_contents($cache, json_encode($map));
        }

        return $map;
    }

    // Normalise a raw TMDB movie object into our own shape.
    private static function shape(array $m): array
    {
        $img  = Config::tmdbImage();
        $gmap = self::genreMap();

        $genres = array_values(array_filter(array_map(
            fn($id) => $gmap[$id] ?? null,
            $m['genre_ids'] ?? []
        )));
        
        return [
            'id'           => (int) $m['id'],
            'title'        => $m['title'] ?? $m['name'] ?? '',
            'year'         => !empty($m['release_date']) ? (int) substr($m['release_date'], 0, 4) : null,
            'overview'     => $m['overview'] ?? '',
            'poster_url'   => !empty($m['poster_path'])   ? "$img/w500{$m['poster_path']}"    : '',
            'backdrop_url' => !empty($m['backdrop_path']) ? "$img/w1280{$m['backdrop_path']}" : '',
            'genres'       => $genres,
            'rating'       => round((float) ($m['vote_average'] ?? 0), 1),
        ];
    }

    // Fallback catalogue in our shape, in a random order.
    private static function shuffledFallback(): array
    {
        $movies = self::fallbackShaped();
        shuffle($movies);
        return $movies;
    }

    private static function fallbackShaped(): array
    {
        $out = [];

        foreach (self::fallbackRaw() as $c) 
        {
            $out[] = [
                'id' => $c[0], 'title' => $c[1], 'year' => $c[2], 'overview' => $c[3],
                'poster_url' => $c[4], 'backdrop_url' => $c[5], 'genres' => $c[6], 'rating' => $c[7],
            ];
        }

        return $out;
    }

    // [id, title, year, overview, poster, backdrop, genres[], rating, youtubeId]
    private static function fallbackRaw(): array
    {
        $P = 'https://image.tmdb.org/t/p';
        return [
            [27205,'Inception',2010,'A thief who steals corporate secrets through dream-sharing is given the inverse task of planting an idea.',"$P/w500/oYuLEt3zVCKq57qu2F8dT7NIa6f.jpg","$P/w1280/s3TBrRGB1iav7gFOCNx3H31MoES.jpg",['Science Fiction','Action'],8.4,'YoHD9XEInc0'],
            [603,'The Matrix',1999,'A hacker learns reality is a simulation and joins a rebellion against the machines.',"$P/w500/f89U3ADr1oiB1s9GkdPOEpXUk5H.jpg","$P/w1280/icmmSD4vTTDKOq2vvdulafOGw93.jpg",['Science Fiction','Action'],8.2,'vKQi3bBA1y8'],
            [496243,'Parasite',2019,'A poor family schemes their way into a wealthy household, one job at a time.',"$P/w500/7IiTTgloJzvGI1TAYymCfbfl3vT.jpg","$P/w1280/TU9NIjwzjoKPwQHoHshkFcQUCG.jpg",['Thriller','Drama'],8.5,'5xH0HfJHsaY'],
            [129,'Spirited Away',2001,'A girl wanders into a world of spirits and must work to free her parents.',"$P/w500/39wmItIWsg5sZMyRUHLkWBcuVCM.jpg","$P/w1280/bSXfU4dwZyBA05fYydgfjQkFMrd.jpg",['Animation','Fantasy'],8.5,'ByXuk9QqQkk'],
            [155,'The Dark Knight',2008,'Batman faces the Joker, an agent of pure chaos testing Gotham.',"$P/w500/qJ2tW6WMUDux911r6m7haRef0WH.jpg","$P/w1280/nMKdUUepR0i5zn0y1T4CsSB5chy.jpg",['Action','Crime'],8.5,'EXeTwQWrcwY'],
            [324857,'Spider-Man: Into the Spider-Verse',2018,'Miles Morales joins Spider-People from across the multiverse.',"$P/w500/iiZZdoQBEYBv6id8su7ImL0oCbD.jpg","$P/w1280/7d6EY00g1c39SGZOoCJ5Py9nNth.jpg",['Animation','Action'],8.4,'g4Hbz2jLxvQ'],
            [244786,'Whiplash',2014,'A young drummer is pushed to his limits by a ruthless instructor.',"$P/w500/7fn624j5lj3xTme2SgiLCeuedmO.jpg","$P/w1280/6uSPcdGMzzjMvwGGib8TUBboStl.jpg",['Drama','Music'],8.4,'7d_jQycdQGo'],
            [546554,'Knives Out',2019,'A detective investigates the death of a wealthy novelist.',"$P/w500/pThyQovXQrw2m0s9x82twj48Jq4.jpg","$P/w1280/70cNFvyQMnETFPB6uUvbZrtMrr3.jpg",['Comedy','Crime'],7.9,'qGqiHJTsRkQ'],
            [76341,'Mad Max: Fury Road',2015,'A woman rebels against a tyrant across a post-apocalyptic desert.',"$P/w500/8tZYtuWezp8JbcsvHYO0O46tFbo.jpg","$P/w1280/phszHPFVhPHhMZgo0fWTKBDQsJA.jpg",['Action','Adventure'],8.1,'hEJnMQG9ev8'],
            [120467,'The Grand Budapest Hotel',2014,'A concierge and lobby boy chase a stolen painting across Europe.',"$P/w500/eWdyYQreja6JGCzqHWXpWHDrrPo.jpg","$P/w1280/xjPugd8Pq7pNyC6ieloc8Tu8mF9.jpg",['Comedy','Drama'],8.1,'1Fg5iWmQjwk'],
            [419430,'Get Out',2017,'A young man uncovers a terrifying secret at his girlfriend\'s family estate.',"$P/w500/tFXcEccSQMf3lfhfXKSU9iRBpa3.jpg","$P/w1280/no4GAPFjQPGkgMX0Vn1dumERj5Q.jpg",['Horror','Thriller'],7.7,'DzfpyUB60YY'],
            [372058,'Your Name',2016,'Two strangers find themselves swapping bodies across distance and time.',"$P/w500/q719jXXEzOoYaps6babgKnONONX.jpg","$P/w1280/mMtUybQ6hL24FXo0F3Z4j2KG7kZ.jpg",['Animation','Romance'],8.5,'xU47nhruN-Q'],
            [438631,'Dune',2021,'Paul Atreides is thrust onto a deadly desert planet and a perilous destiny.',"$P/w500/d5NXSklXo0qyIYkgV94XAgMIckC.jpg","$P/w1280/jYEW5xZkZk2WTrdbMGAPFuBqbDc.jpg",['Science Fiction','Adventure'],8.0,'8g18jFHCLXk'],
            [545611,'Everything Everywhere All at Once',2022,'A woman connects with parallel-universe selves to stop a cosmic threat.',"$P/w500/u68AjlvlutfEIcpmbYpKcdi09ut.jpg","$P/w1280/nGxUxi3PfXDRm7Vg95VBNgNM8yc.jpg",['Science Fiction','Comedy'],8.1,'wxN1T1uxQ2g'],
            [157336,'Interstellar',2014,'A pilot travels through a wormhole to find humanity a new home.',"$P/w500/gEU2QniE6E77NI6lCU6MxlNBvIx.jpg","$P/w1280/xJHokMbljvj8SwWTbHWY9lmG8PT.jpg",['Science Fiction','Drama'],8.4,'zSWdZVtXT7E'],
            [313369,'La La Land',2016,'A jazz pianist and an actress fall in love while chasing their dreams.',"$P/w500/uDO8zWDhfWwoFdKS4fzkUJt0Rf0.jpg","$P/w1280/nlPCdZlHtRNcF6C9hzUH4ebmV1w.jpg",['Romance','Drama'],7.9,'0pdqf4P9MB8'],
            [138843,'The Conjuring',2013,'Paranormal investigators confront a dark presence haunting a family.',"$P/w500/wVYREutTvI2tmxr6ujrHT704wGF.jpg","$P/w1280/5TU5nDkfzIAvFF9rAXObLmFWzHm.jpg",['Horror','Thriller'],7.5,'k10ETZ41q5o'],
        ];
    }
}

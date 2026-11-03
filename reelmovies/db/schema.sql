-- Movie Discovery — MySQL schema (MySQL 8 / MariaDB 10.4+)
-- NOTE: clean (re)install — drops existing tables of these names first, so
-- re-running it wipes old data (intended for a version upgrade).
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

DROP TABLE IF EXISTS likes;
DROP TABLE IF EXISTS friendships;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS auth_tokens;      -- previous name
DROP TABLE IF EXISTS movies;
DROP TABLE IF EXISTS users;
-- tables from older versions, if present:
DROP TABLE IF EXISTS activities;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS watching_status;
DROP TABLE IF EXISTS watchlist;
DROP TABLE IF EXISTS ratings;
DROP TABLE IF EXISTS movie_genres;
DROP TABLE IF EXISTS genres;

SET foreign_key_checks = 1;

-- ---------- Users & auth ----------
CREATE TABLE users (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(40)  NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name  VARCHAR(80)  NOT NULL DEFAULT '',
    is_admin      TINYINT(1)   NOT NULL DEFAULT 0,   -- the first registered user becomes admin
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sessions. The raw token is given to the client; only its SHA-256 hash is
-- stored, so a leaked database cannot be used to impersonate anyone. Sessions
-- expire, and expired rows are ignored and cleaned up on use.
CREATE TABLE sessions (
    token_hash CHAR(64) NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    PRIMARY KEY (token_hash),
    KEY k_user (user_id),
    KEY k_expires (expires_at),
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed-login log, used for simple per-IP rate limiting (brute-force / DoS guard).
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARCHAR(45) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY k_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_settings (
    user_id      BIGINT UNSIGNED NOT NULL,
    accent_color CHAR(7) NOT NULL DEFAULT '#ffffff',
    PRIMARY KEY (user_id),
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- Movies / likes / friends ----------
CREATE TABLE movies (
    id            BIGINT UNSIGNED NOT NULL,          -- TMDB movie id
    title         VARCHAR(200) NOT NULL,
    year          SMALLINT UNSIGNED NULL,
    overview      TEXT NULL,
    poster_url    VARCHAR(500) NOT NULL DEFAULT '',
    backdrop_url  VARCHAR(500) NOT NULL DEFAULT '',
    genres        VARCHAR(255) NOT NULL DEFAULT '',   -- comma-separated names
    rating        DECIMAL(3,1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE likes (
    user_id    BIGINT UNSIGNED NOT NULL,
    movie_id   BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, movie_id),
    KEY k_movie (movie_id),
    CONSTRAINT fk_like_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    CONSTRAINT fk_like_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE friendships (
    requester_id BIGINT UNSIGNED NOT NULL,
    addressee_id BIGINT UNSIGNED NOT NULL,
    status       ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (requester_id, addressee_id),
    KEY k_addressee (addressee_id, status),
    CONSTRAINT fk_fr_req FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fr_adr FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

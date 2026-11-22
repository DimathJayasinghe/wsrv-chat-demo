<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

/**
 * Login sessions. The browser holds a random token in a cookie; the database holds only its SHA-256,
 * so a copy of the database does not let anyone act as a logged-in user.
 */
final class Session
{
    public const TTL = 7 * 24 * 3600;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string the token for the cookie */
    public function start(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->db->run('DELETE FROM sessions WHERE expires_at <= ?', [time()]);
        $this->db->run('INSERT INTO sessions (token_hash, user_id, expires_at, created_at) VALUES (?, ?, ?, ?)', [hash('sha256', $token), $userId, time() + self::TTL, time()]);
        return $token;
    }

    public function end(string $token): void
    {
        $this->db->run('DELETE FROM sessions WHERE token_hash = ?', [hash('sha256', $token)]);
    }

    /** @return array{id: int, username: string}|null null if the token is unknown, expired or logged out */
    public function userFor(string $token): ?array
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $token) !== 1) {
            return null;
        }
        $user = $this->db->run('SELECT u.id, u.username FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?', [hash('sha256', $token), time()])->fetch();
        return $user ?: null;
    }
}

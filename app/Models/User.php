<?php

declare(strict_types=1);

namespace App\Models;

use App\ApiError;
use App\Database;
use PDOException;

/** Accounts: create one, check a login. Passwords are stored only as password_hash() output. */
final class User
{
    private const MAX_FAILURES = 5;
    private const LOCK_SECONDS = 300;
    /** A real hash of nothing in particular: an unknown username still costs one password_verify, so timing does not reveal which names exist. */
    private const DUMMY_HASH = '$2y$12$Ggk54vYWDIIfDhtq.a4ia.63ZzsEjx/fsz0CSDfQUSPJiiJXO4mtS';

    public function __construct(private readonly Database $db)
    {
    }

    public function create(string $username, string $password): void
    {
        if (preg_match('/\A[a-z0-9_]{3,20}\z/', $username) !== 1) {
            throw new ApiError(422, 'username: 3 to 20 characters, a-z, 0-9 and _ only');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {  // bcrypt ignores everything after 72 bytes
            throw new ApiError(422, 'password: 8 to 72 characters');
        }
        try {
            $this->db->run('INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)', [$username, password_hash($password, PASSWORD_DEFAULT), time()]);
        } catch (PDOException $e) {
            throw ($e->errorInfo[1] ?? 0) === 1062 ? new ApiError(409, 'that username is taken') : $e;  // 1062 = duplicate key
        }
    }

    /** @return int the user's id; throws ApiError on a wrong password or a locked account */
    public function authenticate(string $username, string $password): int
    {
        $user = $this->db->run('SELECT id, password_hash FROM users WHERE username = ?', [$username])->fetch();
        if ($user) {
            // Count this attempt BEFORE checking the password, in one statement the database runs atomically
            // per row: parallel guesses cannot all slip past a lock check. A locked account matches no row.
            // (locked_until is assigned first: MySQL evaluates the assignments left to right.)
            $counted = $this->db->run('UPDATE users SET locked_until = IF(failed_logins + 1 >= ?, ?, 0), failed_logins = IF(failed_logins + 1 >= ?, 0, failed_logins + 1) WHERE id = ? AND locked_until <= ?',
                [self::MAX_FAILURES, time() + self::LOCK_SECONDS, self::MAX_FAILURES, $user['id'], time()])->rowCount();
            $counted === 1 || throw new ApiError(429, 'too many wrong passwords, try again in a few minutes');
        }
        // Always one password_verify, so an unknown name costs the same time as a wrong password.
        if (!password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH) || !$user) {
            throw new ApiError(401, 'wrong username or password');  // same answer for "no such user" and "wrong password"
        }
        $this->db->run('UPDATE users SET failed_logins = 0, locked_until = 0 WHERE id = ?', [$user['id']]);
        return (int) $user['id'];
    }
}

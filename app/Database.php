<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;

/** PDO with prepared statements only. Reconnects once if MySQL dropped an idle connection (the chat server runs for days). */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly string $dsn, private readonly string $user, private readonly string $password)
    {
    }

    /** Settings from the environment; the defaults match docker-compose.yml. */
    public static function fromEnv(): self
    {
        return new self(
            getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=chat;charset=utf8mb4',
            getenv('DB_USER') ?: 'chat',
            getenv('DB_PASSWORD') ?: 'chat-local-dev',
        );
    }

    /** @param list<scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        try {
            return $this->execute($sql, $params);
        } catch (PDOException $e) {
            if (!in_array($e->errorInfo[1] ?? 0, [2006, 2013], true)) {  // "server has gone away" / "lost connection"
                throw $e;
            }
            $this->pdo = null;
            return $this->execute($sql, $params);
        }
    }

    public function insertId(): int
    {
        return (int) $this->pdo?->lastInsertId();
    }

    private function execute(string $sql, array $params): PDOStatement
    {
        $this->pdo ??= new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}

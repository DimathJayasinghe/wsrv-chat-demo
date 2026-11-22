<?php

declare(strict_types=1);

namespace App\Models;

use App\Database;

final class Message
{
    public const MAX_LENGTH = 500;  // characters

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{id: int, user: string, text: string, at: int} */
    public function add(int $userId, string $username, string $text): array
    {
        $this->db->run('INSERT INTO messages (user_id, body, created_at) VALUES (?, ?, ?)', [$userId, $text, $now = time()]);
        return ['id' => $this->db->insertId(), 'user' => $username, 'text' => $text, 'at' => $now];
    }

    /** @return list<array{id: int, user: string, text: string, at: int}> oldest first */
    public function recent(int $count = 50): array
    {
        $rows = $this->db->run('SELECT m.id, u.username AS user, m.body AS text, m.created_at AS at FROM messages m JOIN users u ON u.id = m.user_id ORDER BY m.id DESC LIMIT ' . $count)->fetchAll();
        return array_reverse(array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'at' => (int) $r['at']] + $r, $rows));
    }
}

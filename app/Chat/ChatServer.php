<?php

declare(strict_types=1);

namespace App\Chat;

use App\Controllers\AuthController;
use App\Models\Message;
use App\Models\Session;
use Wsrv\ConnectionClosed;
use Wsrv\Embedded\Connection;
use Wsrv\Embedded\Server;
use Wsrv\Embedded\Upgrade;

/**
 * The chat. wsrv does the sockets, the WebSocket protocol, UTF-8 checks, size limits and queues; this
 * class decides WHO may connect and WHAT happens to a message. Handlers run on this PHP thread only.
 *
 * To the browser (JSON text): {"type":"history","messages":[...]}  once, on connect
 *                             {"type":"message","id","user","text","at"}  each new message
 *                             {"type":"presence","users":[...]}  when someone joins or leaves
 *                             {"type":"error","error":"..."}  when a message is refused
 * From the browser: the message, as plain text.
 */
final class ChatServer
{
    private const RATE = [5, 5];  // at most 5 messages per 5 seconds, per connection

    /** @var array<int, array> upgrades accepted, connection not open yet: connection id => user */
    private array $pending = [];
    /** @var array<int, array{conn: Connection, user: array, token: string, sent: list<float>}> */
    private array $online = [];
    private bool $running = true;

    public function __construct(private readonly Server $server, private readonly Session $sessions, private readonly Message $messages)
    {
        $server->on('authenticate', $this->authenticate(...))
            ->on('open', $this->opened(...))
            ->on('message', $this->received(...))
            ->on('close', $this->closed(...));
    }

    public function run(): void
    {
        for ($lastCheck = 0; $this->running; ) {
            $this->server->step(250);
            if (microtime(true) - $lastCheck >= 1) {  // once a second: close connections whose session ended
                foreach ($this->online as $id => $o) {
                    $this->sessions->userFor($o['token']) ?? $this->close($id, 'session ended');
                }
                $lastCheck = microtime(true);
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /** The login check, before the WebSocket exists. Refusing here sends the browser a plain HTTP status. */
    private function authenticate(Upgrade $upgrade): bool|int
    {
        $token = $upgrade->request->cookie(AuthController::COOKIE) ?? '';
        $user = $this->sessions->userFor($token);
        $this->pending[$upgrade->conn] = ['user' => $user, 'token' => $token];  // the question's id is the connection's id
        return $user === null ? 401 : true;
    }

    private function opened(Connection $conn): void
    {
        $this->online[$conn->id] = ['conn' => $conn, 'sent' => []] + $this->pending[$conn->id];
        unset($this->pending[$conn->id]);
        $this->send($conn, ['type' => 'history', 'messages' => $this->messages->recent()]);
        $this->broadcast(['type' => 'presence', 'users' => array_values(array_unique(array_map(static fn ($o) => $o['user']['username'], $this->online)))]);
    }

    private function received(Connection $conn, string $data, bool $binary): void
    {
        $me = $this->online[$conn->id] ?? null;
        $text = trim($data);
        if ($me === null || $text === '') {
            return;
        }
        if ($this->sessions->userFor($me['token']) === null) {  // logged out a moment ago
            $this->close($conn->id, 'session ended');
            return;
        }
        $now = microtime(true);
        $recent = array_values(array_filter($me['sent'], static fn (float $t): bool => $now - $t < self::RATE[1]));
        $error = match (true) {
            $binary => 'text messages only',
            mb_strlen($text) > Message::MAX_LENGTH => 'message too long (500 characters at most)',
            count($recent) >= self::RATE[0] => 'slow down',
            default => null,
        };
        if ($error !== null) {
            $this->send($conn, ['type' => 'error', 'error' => $error]);
            return;
        }
        $this->online[$conn->id]['sent'] = [...$recent, $now];
        $this->broadcast(['type' => 'message'] + $this->messages->add($me['user']['id'], $me['user']['username'], $text));
    }

    private function closed(Connection $conn): void
    {
        unset($this->pending[$conn->id]);
        if (isset($this->online[$conn->id])) {
            unset($this->online[$conn->id]);
            $this->broadcast(['type' => 'presence', 'users' => array_values(array_unique(array_map(static fn ($o) => $o['user']['username'], $this->online)))]);
        }
    }

    private function close(int $id, string $reason): void
    {
        try {
            $this->online[$id]['conn']->close(1008, $reason);  // 1008 = policy violation (RFC 6455 section 7.4.1)
        } catch (ConnectionClosed) {
            // already gone; the close event cleans up
        }
    }

    private function send(Connection $conn, array $payload): void
    {
        try {
            if (!$conn->send(json_encode($payload, JSON_UNESCAPED_UNICODE))) {
                $this->close($conn->id, 'too slow');  // wsrv's queue for this client is full: it is not reading
            }
        } catch (ConnectionClosed) {
        }
    }

    private function broadcast(array $payload): void
    {
        foreach ($this->online as $o) {
            $this->send($o['conn'], $payload);
        }
    }
}

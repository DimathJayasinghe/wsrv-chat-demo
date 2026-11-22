<?php

declare(strict_types=1);

// The WebSocket chat server:   composer chat      (or: php bin/chat-server.php)
// Environment (all optional): WSRV_LIBRARY, DEMO_WS_PORT (default 8081), DEMO_ORIGINS, and the DB_* settings.

use App\Chat\ChatServer;
use App\Database;
use App\Models\Message;
use App\Models\Session;
use Wsrv\Embedded\Server;

require __DIR__ . '/../vendor/autoload.php';

$library = getenv('WSRV_LIBRARY') ?: __DIR__ . '/../lib/libwsrv.so';
is_file($library) || exit("libwsrv.so not found at $library. Run: composer install-lib\n");

$server = new Server([
    'listen_host' => '127.0.0.1',
    'listen_port' => (int) (getenv('DEMO_WS_PORT') ?: 8081),
    // Which pages may open a WebSocket here. A browser always sends its page's Origin and a hostile site
    // cannot forge it, so this stops it from using a visitor's cookie (RFC 6455 section 10.2).
    'allow_origin' => explode(',', getenv('DEMO_ORIGINS') ?: 'http://127.0.0.1:8000,http://localhost:8000'),
    'handshake_hook' => true,                  // ask our authenticate() before accepting
    'handshake_forward_header' => ['Cookie'],  // ... and let it see the cookie
    'max_message_size' => 4096,                // bigger messages are closed with 1009 before PHP sees them
    'max_data_frames_per_second' => 50,        // a flood of tiny frames is closed with 1008
], $library);

$db = Database::fromEnv();
$chat = new ChatServer($server, new Session($db), new Message($db));
$server->start();

pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, $chat->stop(...));
}

echo "chat server on ws://127.0.0.1:{$server->port()}/ (Ctrl+C to stop)\n";
$chat->run();
$server->close();

<?php

declare(strict_types=1);

// End to end: start the real web app and the real chat server as child processes, then use them the
// way a browser does (HTTP with a cookie, then a WebSocket with the same cookie).

const ROOT = __DIR__ . '/..';

/** Wipe the test database and, the first time, start the web app (php -S) and the chat server. Returns [http port, ws port]. */
function start_app(): array
{
    static $ports = null;
    test_db();  // skips when there is no database
    return $ports ??= launch();
}

function launch(): array
{
    $library = getenv('WSRV_LIBRARY') ?: ROOT . '/lib/libwsrv.so';
    is_file($library) || throw new Skip('no libwsrv.so: run `composer install-lib`');
    [$http, $ws] = [random_int(20000, 29999), random_int(30000, 39999)];
    $env = ['DB_DSN' => 'mysql:host=127.0.0.1;port=3307;dbname=chat_test;charset=utf8mb4', 'DEMO_WS_PORT' => (string) $ws,
        'DEMO_ORIGINS' => "http://127.0.0.1:$http", 'WSRV_LIBRARY' => $library, 'PATH' => getenv('PATH')];
    $php = escapeshellarg(PHP_BINARY);
    $processes = [
        proc_open("exec $php -S 127.0.0.1:$http -t " . ROOT . '/public ' . ROOT . '/public/index.php', [2 => ['file', '/dev/null', 'w']], $p1, null, $env),
        proc_open("exec $php -d ffi.enable=1 " . ROOT . '/bin/chat-server.php', [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, $env),
    ];
    foreach ([$http, $ws] as $port) {
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(50_000);
        }
    }
    register_shutdown_function(static fn () => array_map('proc_terminate', $processes));
    return [$http, $ws];
}

/** @return array{0: int, 1: ?array, 2: ?string} status, JSON body, the session cookie the server set (name=value) */
function http(int $port, string $method, string $path, ?array $body = null, ?string $cookie = null, array $headers = []): array
{
    $headers += ['Content-Type' => 'application/json', 'Origin' => "http://127.0.0.1:$port"];  // what the caller passed wins
    $setCookie = null;
    $curl = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass()),
        CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$setCookie): int {
            preg_match('/^Set-Cookie: (wsrv_demo_session=[^;]*)/i', $line, $m) && $setCookie = $m[1];
            return strlen($line);
        },
    ]);
    $cookie === null || curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    $response = curl_exec($curl);
    return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode((string) $response, true), $setCookie];
}

/** Register and log in; returns the cookie. */
function sign_in(int $http, string $name): string
{
    http($http, 'POST', '/api/register', ['username' => $name, 'password' => 'a good password']);
    return http($http, 'POST', '/api/login', ['username' => $name, 'password' => 'a good password'])[2];
}

/** Open a WebSocket like a browser would; returns [stream, HTTP status]. */
function ws_open(int $ws, string $origin, ?string $cookie): array
{
    $s = stream_socket_client("tcp://127.0.0.1:$ws", $errno, $error, 5);
    fwrite($s, "GET / HTTP/1.1\r\nHost: 127.0.0.1:$ws\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\n"
        . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nOrigin: $origin\r\n" . ($cookie ? "Cookie: $cookie\r\n" : '') . "\r\n");
    stream_set_timeout($s, 5);
    for ($head = ''; !str_contains($head, "\r\n\r\n"); $head .= $byte) {
        ($byte = fread($s, 1)) !== '' && $byte !== false || throw new RuntimeException('no answer to the upgrade request');
    }
    return [$s, (int) substr($head, 9, 3)];
}

/** Read one frame; returns [opcode, payload]. (Server frames are unmasked; lengths up to 65535 are enough here.) */
function ws_read($s): array
{
    $h = fread($s, 2) ?: throw new RuntimeException('connection closed or timed out');
    $length = ord($h[1]) & 0x7f;
    $length === 126 && $length = unpack('n', fread($s, 2))[1];
    for ($payload = ''; strlen($payload) < $length; $payload .= $chunk) {
        ($chunk = fread($s, $length - strlen($payload))) !== '' && $chunk !== false || throw new RuntimeException('connection closed or timed out');
    }
    return [ord($h[0]) & 0x0f, $payload];
}

/** Read frames until a JSON message of this type arrives. */
function ws_expect($s, string $type): array
{
    for ($i = 0; $i < 10; $i++) {
        [, $payload] = ws_read($s);
        $json = json_decode($payload, true);
        if (($json['type'] ?? '') === $type) {
            return $json;
        }
    }
    throw new RuntimeException("no $type message");
}

/** Send a masked text frame (clients must mask: RFC 6455 section 5.3). */
function ws_send($s, string $text): void
{
    $mask = random_bytes(4);
    $masked = '';
    for ($i = 0; $i < strlen($text); $i++) {
        $masked .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($s, "\x81" . chr(0x80 | strlen($text)) . $mask . $masked);  // short messages only
}

test('app: register, log in, who am I, log out', function () {
    [$http] = start_app();
    same(201, http($http, 'POST', '/api/register', ['username' => 'alice', 'password' => 'a good password'])[0], 'register');
    same(409, http($http, 'POST', '/api/register', ['username' => 'alice', 'password' => 'a good password'])[0], 'duplicate');
    same(401, http($http, 'POST', '/api/login', ['username' => 'alice', 'password' => 'wrong password'])[0], 'wrong password');
    [$status, $body, $cookie] = http($http, 'POST', '/api/login', ['username' => 'alice', 'password' => 'a good password']);
    same([200, 'alice'], [$status, $body['username']], 'login');
    same('alice', http($http, 'GET', '/api/me', null, $cookie)[1]['username'], 'me');
    same(401, http($http, 'GET', '/api/me')[0], 'me without a cookie');
    same(204, http($http, 'POST', '/api/logout', null, $cookie)[0], 'logout');
    same(401, http($http, 'GET', '/api/me', null, $cookie)[0], 'the old cookie is dead after logout');
});

test('app: cross-site and non-JSON posts are refused', function () {
    [$http] = start_app();
    $body = ['username' => 'mallory', 'password' => 'a good password'];
    same(403, http($http, 'POST', '/api/register', $body, null, ['Origin' => 'http://evil.example'])[0], 'foreign Origin');
    same(415, http($http, 'POST', '/api/register', $body, null, ['Content-Type' => 'text/plain'])[0], 'form-style post');
});

test('chat: no login or a foreign page cannot open a WebSocket', function () {
    [$http, $ws] = start_app();
    $cookie = sign_in($http, 'alice');
    same(401, ws_open($ws, "http://127.0.0.1:$http", null)[1], 'no cookie');
    same(401, ws_open($ws, "http://127.0.0.1:$http", 'wsrv_demo_session=' . str_repeat('0', 64))[1], 'made-up cookie');
    same(403, ws_open($ws, 'http://evil.example', $cookie)[1], 'foreign Origin, valid cookie');
});

test('chat: messages reach everyone, and history reaches latecomers', function () {
    [$http, $ws] = start_app();
    [$alice] = ws_open($ws, "http://127.0.0.1:$http", sign_in($http, 'alice'));
    ws_expect($alice, 'history');
    [$bob] = ws_open($ws, "http://127.0.0.1:$http", sign_in($http, 'bob'));
    same(['alice', 'bob'], ws_expect($bob, 'presence')['users'], 'who is online');
    ws_send($alice, 'hello <b>bob</b>');
    foreach ([$alice, $bob] as $who) {
        $message = ws_expect($who, 'message');
        same(['alice', 'hello <b>bob</b>'], [$message['user'], $message['text']], 'message text is kept as typed (the page shows it with textContent)');
    }
    [$carol] = ws_open($ws, "http://127.0.0.1:$http", sign_in($http, 'carol'));
    same('hello <b>bob</b>', ws_expect($carol, 'history')['messages'][0]['text'], 'history');
});

test('chat: logging out closes the WebSocket', function () {
    [$http, $ws] = start_app();
    $cookie = sign_in($http, 'alice');
    [$socket] = ws_open($ws, "http://127.0.0.1:$http", $cookie);
    ws_expect($socket, 'history');
    http($http, 'POST', '/api/logout', null, $cookie);
    do {
        [$opcode, $payload] = ws_read($socket);  // the presence message may come first
    } while ($opcode !== 8);
    same([8, 1008], [$opcode, unpack('n', $payload)[1]], 'close frame, code 1008');
});

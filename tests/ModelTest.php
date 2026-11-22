<?php

declare(strict_types=1);

use App\Database;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;

/** An empty test database (chat_test, so your chat data is never touched), or a skipped test. */
function test_db(): Database
{
    $db = new Database(getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=chat_test;charset=utf8mb4', getenv('DB_USER') ?: 'chat', getenv('DB_PASSWORD') ?: 'chat-local-dev');
    try {
        foreach (['messages', 'sessions', 'users'] as $table) {
            $db->run("DELETE FROM $table");
        }
    } catch (PDOException $e) {
        throw new Skip('no test database: run `composer db` first');
    }
    return $db;
}

test('user: the password is stored hashed', function () {
    $db = test_db();
    (new User($db))->create('alice', 'correct horse');
    $hash = $db->run('SELECT password_hash FROM users')->fetchColumn();
    check(password_verify('correct horse', $hash), 'hash verifies');
    check(!str_contains($hash, 'correct horse'), 'not plain');
});

test('user: bad names and passwords are refused, a name is unique', function () {
    $users = new User(test_db());
    foreach (['ab', 'Alice', 'has space', 'a-b', "line\n", str_repeat('a', 21)] as $name) {
        fails(422, fn () => $users->create($name, 'a good password'), "username " . json_encode($name));
    }
    fails(422, fn () => $users->create('alice', 'short'), 'short password');
    fails(422, fn () => $users->create('alice', str_repeat('x', 73)), 'password over 72 bytes');
    $users->create('alice', 'a good password');
    fails(409, fn () => $users->create('alice', 'another password'), 'duplicate');
});

test('user: wrong password and unknown user look the same', function () {
    $users = new User(test_db());
    $users->create('alice', 'a good password');
    check($users->authenticate('alice', 'a good password') > 0, 'right password');
    $messages = [];
    foreach ([['alice', 'wrong one'], ['nobody', 'wrong one']] as [$name, $password]) {
        try {
            $users->authenticate($name, $password);
        } catch (App\ApiError $e) {
            $messages[] = $e->status . $e->getMessage();
        }
    }
    same($messages[0], $messages[1], 'same answer');
});

test('user: five wrong passwords lock the account', function () {
    $db = test_db();
    $users = new User($db);
    $users->create('alice', 'a good password');
    for ($i = 0; $i < 5; $i++) {
        fails(401, fn () => $users->authenticate('alice', 'wrong one'), "failure $i");
    }
    fails(429, fn () => $users->authenticate('alice', 'a good password'), 'locked, even with the right password');
    $db->run('UPDATE users SET locked_until = 0');
    check($users->authenticate('alice', 'a good password') > 0, 'works after the lock ends');
});

test('session: start, find, end, expire; only a hash is stored', function () {
    $db = test_db();
    $users = new User($db);
    $users->create('alice', 'a good password');
    $id = $users->authenticate('alice', 'a good password');
    $sessions = new Session($db);
    $token = $sessions->start($id);
    same('alice', $sessions->userFor($token)['username'], 'found');
    same(null, $sessions->userFor(str_repeat('0', 64)), 'made-up token');
    same(null, $sessions->userFor('not hex'), 'garbage');
    same(0, (int) $db->run('SELECT COUNT(*) FROM sessions WHERE token_hash = ?', [$token])->fetchColumn(), 'the token itself is not stored');
    $db->run('UPDATE sessions SET expires_at = ?', [time() - 1]);
    same(null, $sessions->userFor($token), 'expired');
    $token = $sessions->start($id);
    $sessions->end($token);
    $sessions->end($token);  // twice is fine
    same(null, $sessions->userFor($token), 'logged out');
});

test('message: history is oldest first and limited', function () {
    $db = test_db();
    $users = new User($db);
    $users->create('alice', 'a good password');
    $id = $users->authenticate('alice', 'a good password');
    $messages = new Message($db);
    for ($i = 1; $i <= 5; $i++) {
        $messages->add($id, 'alice', "m$i");
    }
    same(['m4', 'm5'], array_column($messages->recent(2), 'text'), 'the newest two, in order');
    same('alice', $messages->recent(1)[0]['user'], 'author');
});

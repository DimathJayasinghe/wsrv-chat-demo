# wsrv chat demo

A small chat app in plain PHP that shows how to use [**wsrv**](https://github.com/DimathJayasinghe/wsrv),
a WebSocket server library, from a PHP project. Four things a user can do:

| Operation | How |
|---|---|
| create an account | `POST /api/register` |
| log in | `POST /api/login` (sets a session cookie) |
| log out | `POST /api/logout` (the open chat connection is closed too) |
| send a message | over the WebSocket, shown live to everyone online |

No framework, no build step. The only dependency is `wsrv/client`.

## How it fits together

```
 browser ──HTTP──▶  public/index.php  ──▶ Controllers ──▶ Models ──▶ MariaDB (Docker)
    │                (php -S, port 8000)                       ▲
    └──WebSocket──▶  bin/chat-server.php (wsrv, port 8081) ───┘
                     ChatServer: who may connect, what a message does
```

MVC, in the smallest form that is still honest:

- **Models** `app/Models`: `User`, `Session`, `Message`. All SQL lives here (prepared statements only).
- **Views** `app/Views/home.php`, plus `public/assets` for the browser side.
- **Controllers** `app/Controllers`: `AuthController` (the account endpoints) and `PageController`.
  `public/index.php` is the front controller: a route table and nothing else.
- **`app/Chat/ChatServer.php`** is the real-time half: a controller for WebSocket events.

The interesting part is `ChatServer::authenticate`: the browser sends its session cookie with the
WebSocket upgrade, wsrv hands the request to PHP **before** accepting it, and PHP answers 401 for a
visitor who is not logged in. wsrv does the protocol, limits and sockets; PHP does the app.

## Run it

You need PHP 8.2+ with `ffi`, `pdo_mysql` and `pcntl`, Composer, Docker, and Linux (glibc 2.39+, x86-64 or aarch64).

```sh
composer install
composer install-lib     # downloads and verifies libwsrv.so (SHA-256) into ./lib
composer db              # MariaDB in Docker, on port 3307
composer serve           # terminal 1: http://127.0.0.1:8000
composer chat            # terminal 2: ws://127.0.0.1:8081
```

Open <http://127.0.0.1:8000> in two browsers (or one normal and one private window), make two accounts, chat.
`composer test` runs unit and end-to-end tests (it starts both servers itself and uses the `chat_test` database).

## What it does for safety (and what it does not)

- Passwords: `password_hash` (bcrypt). Five wrong passwords lock the account for five minutes. Unknown user and wrong password look the same.
- Sessions: a random token in an `HttpOnly`, `SameSite=Lax` cookie; the database stores only its SHA-256. Logout deletes the row; the chat server notices within a second and closes the connection with 1008.
- CSRF: POSTs must be `application/json` and, when the browser says where it came from, from this site.
- Cross-site WebSocket hijacking: wsrv only accepts the Origins in `DEMO_ORIGINS`.
- XSS: the page shows user text with `textContent`, never `innerHTML`.
- Limits: messages 500 characters, 5 per 5 seconds, wsrv caps frame size and frame rate.

Not done, on purpose (it is a demo): HTTPS (use `wss://` behind a proxy in real life), account recovery,
email, per-IP rate limits on login, message pruning, horizontal scaling (one chat process owns all connections).

## Layout

```
app/Models  app/Controllers  app/Views  app/Chat   the application
public/                                            web root: index.php + assets
bin/chat-server.php                                starts wsrv with the chat
database/schema.sql  docker-compose.yml            the database
tests/                                             `php tests/run.php`
```

MIT licence.

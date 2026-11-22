<?php /** @var int $webSocketPort */ ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>wsrv chat demo</title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body data-ws-port="<?= $webSocketPort ?>">
  <main>
    <h1>wsrv chat demo</h1>

    <section id="auth" hidden>
      <form id="register-form">
        <h2>Create account</h2>
        <label>Username <input name="username" required pattern="[a-z0-9_]{3,20}" title="3 to 20 characters: a-z, 0-9, _" autocomplete="username"></label>
        <label>Password <input name="password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label>
        <button>Register</button>
      </form>
      <form id="login-form">
        <h2>Log in</h2>
        <label>Username <input name="username" required autocomplete="username"></label>
        <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
        <button>Log in</button>
      </form>
    </section>

    <section id="chat" hidden>
      <header>
        <span>Signed in as <strong id="me"></strong> <small id="status"></small></span>
        <button id="logout" type="button">Log out</button>
      </header>
      <p id="online"></p>
      <ul id="messages"></ul>
      <form id="send-form">
        <input id="text" maxlength="500" placeholder="Write a message" autocomplete="off" aria-label="Message">
        <button>Send</button>
      </form>
    </section>

    <p id="notice" role="status"></p>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>

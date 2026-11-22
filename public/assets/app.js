// The browser side: no framework, no build step. User text is always shown with textContent, never innerHTML.
const $ = (id) => document.getElementById(id);
let socket = null;

async function api(path, body) {
  const response = await fetch(path, body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  return { ok: response.ok, data: response.status === 204 ? null : await response.json().catch(() => null) };
}

function notice(text, isError = false) {
  $('notice').textContent = text;
  $('notice').className = isError ? 'error' : '';
}

function credentials(form) {
  const data = new FormData(form);
  return { username: data.get('username').trim(), password: data.get('password') };
}

function showAuth(text = '') {
  socket?.close();
  socket = null;
  $('auth').hidden = false;
  $('chat').hidden = true;
  notice(text);
}

function showChat(username) {
  $('me').textContent = username;
  $('messages').replaceChildren();
  $('auth').hidden = true;
  $('chat').hidden = false;
  notice('');
  connect();
}

// ---- accounts: register, log in, log out ----------------------------------------------------

$('register-form').onsubmit = async (event) => {
  event.preventDefault();
  const body = credentials(event.target);
  const created = await api('/api/register', body);
  if (!created.ok) return notice(created.data?.error ?? 'could not register', true);
  const session = await api('/api/login', body);
  session.ok ? showChat(body.username) : notice('Account created. Please log in.');
};

$('login-form').onsubmit = async (event) => {
  event.preventDefault();
  const session = await api('/api/login', credentials(event.target));
  session.ok ? showChat(session.data.username) : notice(session.data?.error ?? 'could not log in', true);
};

$('logout').onclick = async () => {
  await api('/api/logout', {});
  showAuth('Logged out.');
};

// ---- the chat: one WebSocket to the wsrv server ---------------------------------------------

function connect() {
  const ws = new WebSocket(`ws://${location.hostname}:${document.body.dataset.wsPort}/`);
  socket = ws;
  ws.onopen = () => ($('status').textContent = '(connected)');
  ws.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (message.type === 'history') message.messages.forEach(addMessage);
    if (message.type === 'message') addMessage(message);
    if (message.type === 'presence') $('online').textContent = 'Online: ' + message.users.join(', ');
    if (message.type === 'error') notice(message.error, true);
  };
  ws.onclose = (event) => {
    if (socket !== ws) return;  // we closed it ourselves (logout)
    if (event.code === 1008) return showAuth('Your session ended. Please log in again.');
    $('status').textContent = '(disconnected, retrying...)';
    setTimeout(async () => ((await api('/api/me')).ok ? connect() : showAuth('Your session ended.')), 2000);
  };
}

function addMessage({ user, text, at }) {
  const list = $('messages');
  const atBottom = list.scrollTop + list.clientHeight >= list.scrollHeight - 8;
  const item = document.createElement('li');
  const time = document.createElement('time');
  time.textContent = new Date(at * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const who = document.createElement('strong');
  who.textContent = user + ': ';
  item.append(time, who, text);
  list.append(item);
  if (atBottom) list.scrollTop = list.scrollHeight;
}

$('send-form').onsubmit = (event) => {
  event.preventDefault();
  const text = $('text').value.trim();
  if (text && socket?.readyState === WebSocket.OPEN) {
    socket.send(text);
    $('text').value = '';
    notice('');
  }
};

// ---- start: already logged in? --------------------------------------------------------------

api('/api/me').then((me) => (me.ok ? showChat(me.data.username) : showAuth()));

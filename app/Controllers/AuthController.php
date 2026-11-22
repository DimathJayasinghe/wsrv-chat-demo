<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ApiError;
use App\Models\Session;
use App\Models\User;

/** The account endpoints. Each action returns [HTTP status, data]; public/index.php sends it as JSON. */
final class AuthController
{
    public const COOKIE = 'wsrv_demo_session';

    public function __construct(private readonly User $users, private readonly Session $sessions)
    {
    }

    /** POST /api/register: create an account. */
    public function register(): array
    {
        [$username, $password] = $this->credentials();
        $this->users->create($username, $password);
        return [201, ['username' => $username]];
    }

    /** POST /api/login: start a session and set its cookie. */
    public function login(): array
    {
        [$username, $password] = $this->credentials();
        $token = $this->sessions->start($this->users->authenticate($username, $password));
        $this->setCookie($token, time() + Session::TTL);
        return [200, ['username' => $username]];
    }

    /** POST /api/logout: end the session and clear the cookie. */
    public function logout(): array
    {
        $this->sessions->end($_COOKIE[self::COOKIE] ?? '');
        $this->setCookie('', 1);
        return [204, null];
    }

    /** GET /api/me: who am I? */
    public function me(): array
    {
        $user = $this->sessions->userFor($_COOKIE[self::COOKIE] ?? '') ?? throw new ApiError(401, 'not logged in');
        return [200, ['username' => $user['username']]];
    }

    private function credentials(): array
    {
        $data = json_decode(file_get_contents('php://input', length: 4096), true);
        if (!is_array($data) || !is_string($data['username'] ?? null) || !is_string($data['password'] ?? null)) {
            throw new ApiError(400, 'expected {"username": "...", "password": "..."}');
        }
        return [$data['username'], $data['password']];
    }

    private function setCookie(string $value, int $expires): void
    {
        // HttpOnly: JavaScript cannot read it. SameSite=Lax: browsers do not send it on cross-site POSTs.
        setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
}

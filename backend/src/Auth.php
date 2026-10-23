<?php
declare(strict_types=1);
namespace ChupaCabra;

use Predis\Client;

final class Auth
{
    private const COOKIE = 'chupacabra_session';
    public function __construct(private Client $redis) {}

    /** Quantidade de dias de validade do cookie e do registro no Redis. */
    private function lifetimeSeconds(): int
    {
        $days = getenv('SESSION_LIFETIME_DAYS');
        $days = ($days === false || $days === '') ? '30' : $days;
        if (!ctype_digit($days) || (int)$days < 1 || (int)$days > 365) {
            throw new \RuntimeException('SESSION_LIFETIME_DAYS deve estar entre 1 e 365.');
        }
        return (int)$days * 86400;
    }

    private function renewOnActivity(): bool
    {
        $setting = getenv('SESSION_RENEW_ON_ACTIVITY');
        $setting = ($setting === false || $setting === '') ? '1' : $setting;
        if (!in_array($setting, ['0', '1'], true)) {
            throw new \RuntimeException('SESSION_RENEW_ON_ACTIVITY deve ser 0 ou 1.');
        }
        return $setting === '1';
    }

    private function accounts(): array
    {
        $encoded = getenv('AUTH_USERS_B64') ?: '';
        $json = $encoded === '' ? false : base64_decode($encoded, true);
        $value = $json === false ? null : json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    public function login(string $username, string $password, string $ip): array
    {
        $username = trim($username);
        $bucket = 'login:rate:' . hash('sha256', $ip.'|'.$username);
        $attempts = $this->redis->incr($bucket);
        if ((int)$attempts === 1) $this->redis->expire($bucket, 900);
        if ((int)$attempts > 10) throw new ApiException('Muitas tentativas. Tente novamente mais tarde.', 429);
        $hash = $this->accounts()[$username] ?? '$argon2id$v=19$m=65536,t=4,p=1$AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        if (!is_string($hash) || !password_verify($password, $hash)) {
            usleep(300000);
            throw new ApiException('Usuário ou senha incorretos.', 401);
        }
        $this->redis->del([$bucket]);
        $id = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $ttl = $this->lifetimeSeconds();
        $this->redis->setex('session:' . hash('sha256', $id), $ttl, json_encode(['username' => $username, 'csrf' => $csrf], JSON_THROW_ON_ERROR));
        $this->cookie($id, $ttl);
        return ['username' => $username, 'csrf' => $csrf];
    }

    public function session(): ?array
    {
        $id = (string)($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/D', $id)) return null;
        $key = 'session:' . hash('sha256', $id);
        $json = $this->redis->get($key);
        if (!$json) return null;
        $session = json_decode($json, true);
        if (!is_array($session) || !is_string($session['username'] ?? null)
            || !is_string($session['csrf'] ?? null)) {
            return null;
        }
        if ($this->renewOnActivity()) {
            // Renove os DOIS vencimentos: só renovar o Redis mantém o navegador
            // desconectando após a expiração original do cookie.
            $ttl = $this->lifetimeSeconds();
            $this->redis->expire($key, $ttl);
            $this->cookie($id, $ttl);
        }
        return $session;
    }

    public function requireSession(bool $mutate = false): array
    {
        $session = $this->session();
        if (!$session) throw new ApiException('Faça login para continuar.', 401);
        if ($mutate && !hash_equals((string)$session['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            throw new ApiException('Sessão não validada. Atualize a página.', 403);
        }
        return $session;
    }

    public function logout(): void
    {
        $this->requireSession(true);
        $id = (string)($_COOKIE[self::COOKIE] ?? '');
        $this->redis->del(['session:' . hash('sha256', $id)]);
        $this->cookie('', -3600);
    }

    private function cookie(string $token, int $ttl): void
    {
        setcookie(self::COOKIE, $token, [
            'expires' => time() + $ttl, 'path' => '/', 'secure' => getenv('COOKIE_SECURE') !== '0',
            'httponly' => true, 'samesite' => 'Lax'
        ]);
    }
}

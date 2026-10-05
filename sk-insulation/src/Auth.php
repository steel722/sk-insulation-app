<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::pdo()->prepare(
            "SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1"
        );
        $stmt->execute([strtolower(trim($email))]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            return true;
        }
        return false;
    }

    public static function user(): ?array
    {
        self::start();
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = Database::pdo()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isManager(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === 'manager';
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('/login');
        }
    }

    public static function requireManager(): void
    {
        self::requireLogin();
        if (!self::isManager()) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
}

<?php
declare(strict_types=1);

function url(string $path): string
{
    return BASE_URL . $path;
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money(float $n): string
{
    return '$' . number_format($n, 2);
}

function today(): string
{
    return date('Y-m-d');
}

/** Lunes de la semana que contiene $date (Y-m-d). */
function week_start(string $date): string
{
    return date('Y-m-d', strtotime(PAYROLL_WEEK_START . ' this week', strtotime($date)));
}

function week_end(string $date): string
{
    return date('Y-m-d', strtotime(week_start($date) . ' +6 days'));
}

function csrf_token(): string
{
    Auth::start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    Auth::start();
    $sent = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        http_response_code(419);
        exit('Invalid request token.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    Auth::start();
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $msg = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $msg;
}

/** Tarifas de pago activas, por work_type. */
function pay_rates(): array
{
    $rows = Database::pdo()
        ->query("SELECT * FROM pay_rates WHERE active = 1 ORDER BY label")
        ->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['work_type']] = $r;
    }
    return $out;
}

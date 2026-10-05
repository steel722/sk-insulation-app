<?php
declare(strict_types=1);

// SK Insulation — controlador frontal

require __DIR__ . '/src/config.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Auth.php';
require __DIR__ . '/src/helpers.php';

Auth::start();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Quitar la base (/sk-insulation) para quedarnos con la ruta interna
if (str_starts_with($path, BASE_URL)) {
    $path = substr($path, strlen(BASE_URL));
}
$path = rtrim($path, '/') ?: '/';

// --- Rutas públicas --------------------------------------------------------
if ($path === '/login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) {
            redirect('/');
        }
        $error = 'Credenciales inválidas.';
    }
    $user = null;
    $view = 'login';
} elseif ($path === '/logout') {
    Auth::logout();
    redirect('/login');
} else {
    // --- Rutas protegidas --------------------------------------------------
    Auth::requireLogin();
    $user = Auth::user();

    // Exportar trabajos a CSV (Excel)
    if ($path === '/export') {
        Auth::requireManager();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sk-jobs-' . date('Y-m-d') . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM para que Excel lea los acentos
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Job', 'Customer', 'Builder', 'Lot', 'Address', 'Install Date', 'Crew', 'Status', 'Wall SF', 'Ceiling SF', 'Total SF', 'Value USD']);
        $rows = Database::pdo()->query(
            "SELECT j.job_number, j.customer, j.builder, j.lot_number, j.address, j.install_date, c.name AS crew, j.status,
                COALESCE(SUM(CASE WHEN p.work_type = 'wall' THEN p.square_feet ELSE 0 END), 0) AS wall_sf,
                COALESCE(SUM(CASE WHEN p.work_type = 'ceiling_r30' THEN p.square_feet ELSE 0 END), 0) AS ceil_sf,
                COALESCE(SUM(p.square_feet), 0) AS total_sf,
                COALESCE(SUM((CASE WHEN r.unit = 'LF' THEN p.linear_feet ELSE p.square_feet END) * r.rate), 0) AS value
             FROM jobs j
             LEFT JOIN crews c ON c.id = j.crew_id
             LEFT JOIN production p ON p.job_id = j.id
             LEFT JOIN pay_rates r ON r.work_type = p.work_type
             GROUP BY j.id, c.id ORDER BY j.install_date DESC, j.id DESC"
        );
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['job_number'], $r['customer'], $r['builder'], $r['lot_number'],
                $r['address'], $r['install_date'], $r['crew'], $r['status'],
                $r['wall_sf'], $r['ceil_sf'], $r['total_sf'], number_format((float)$r['value'], 2, '.', ''),
            ]);
        }
        exit;
    }

    $view = match ($path) {
        '/'           => 'dashboard',
        '/jobs'       => 'jobs',
        '/crews'      => 'crews',
        '/installers' => 'installers',
        '/payroll'    => 'payroll',
        '/my-pay'     => 'my_pay',
        '/settings'   => 'settings',
        default       => 'not_found',
    };

    if (in_array($view, ['settings', 'payroll', 'crews', 'installers'], true)) {
        Auth::requireManager();
    }
}

ob_start();
require APP_ROOT . "/views/{$view}.php";
$content = ob_get_clean();

require APP_ROOT . '/views/layout.php';

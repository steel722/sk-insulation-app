<?php
declare(strict_types=1);

// SK Insulation — configuración

define('APP_NAME', 'SK Insulation');
define('APP_ROOT', dirname(__DIR__));

// La app vive en http://localhost/sk-insulation
define('BASE_URL', '/sk-insulation');

// MySQL de XAMPP (usuario root sin contraseña por defecto)
define('DB_DSN', 'mysql:host=127.0.0.1;dbname=sk_insulation;charset=utf8mb4');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SESSION_NAME', 'sk_insulation_session');
define('PAYROLL_WEEK_START', 'monday');

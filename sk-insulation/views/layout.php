<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#141b29">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= e(APP_NAME) ?><?= isset($title) ? ' — ' . e($title) : '' ?></title>
<link rel="stylesheet" href="<?= url('/assets/css/app.css') ?>">
</head>
<body>
<?php if (empty($user)): ?>
<div class="login-wrap">
    <?php if ($m = flash('ok')): ?><div class="flash ok" style="position:fixed;top:16px;left:16px;right:16px;max-width:420px;margin:0 auto"><?= e($m) ?></div><?php endif; ?>
    <?php if ($m = flash('error')): ?><div class="flash error" style="position:fixed;top:16px;left:16px;right:16px;max-width:420px;margin:0 auto"><?= e($m) ?></div><?php endif; ?>
    <?= $content ?>
</div>
<?php else:
$isMgr = ($user['role'] ?? '') === 'manager';
$act = $_GET['action'] ?? '';
$initials = strtoupper(substr((string)($user['name'] ?? '?'), 0, 1));
?>
<div class="app">
    <aside class="sidebar">
        <div class="side-brand"><span class="logo">SK</span><span class="logo-text">INSULATION</span></div>
        <nav class="side-nav">
            <?php if ($isMgr): ?>
                <a href="<?= url('/') ?>" class="<?= $view === 'dashboard' ? 'active' : '' ?>"><span class="ni">▦</span> Dashboard</a>
                <a href="<?= url('/jobs') ?>" class="<?= $view === 'jobs' && $act !== 'new' ? 'active' : '' ?>"><span class="ni">🧰</span> Jobs</a>
                <a href="<?= url('/jobs?action=new') ?>" class="<?= $view === 'jobs' && $act === 'new' ? 'active' : '' ?>"><span class="ni">＋</span> Create Job</a>
                <a href="<?= url('/settings?action=materials') ?>" class="<?= $view === 'settings' && $act === 'materials' ? 'active' : '' ?>"><span class="ni">📦</span> Materials</a>
                <a href="<?= url('/crews') ?>" class="<?= in_array($view, ['crews', 'installers'], true) ? 'active' : '' ?>"><span class="ni">👷</span> Crews</a>
                <a href="<?= url('/payroll') ?>" class="<?= $view === 'payroll' ? 'active' : '' ?>"><span class="ni">💵</span> Pay &amp; Payroll</a>
                <a href="<?= url('/settings?action=rates') ?>" class="<?= $view === 'settings' && $act !== 'materials' ? 'active' : '' ?>"><span class="ni">🏷</span> Pay Rates</a>
            <?php else: ?>
                <a href="<?= url('/') ?>" class="<?= $view === 'dashboard' ? 'active' : '' ?>"><span class="ni">▦</span> Dashboard</a>
                <a href="<?= url('/jobs') ?>" class="<?= $view === 'jobs' ? 'active' : '' ?>"><span class="ni">🧰</span> Jobs</a>
                <a href="<?= url('/my-pay') ?>" class="<?= $view === 'my_pay' ? 'active' : '' ?>"><span class="ni">💵</span> My Pay</a>
            <?php endif; ?>
        </nav>
        <div class="side-user">
            <span class="avatar"><?= e($initials) ?></span>
            <span class="who"><b><?= e($user['name']) ?></b><span><?= e($user['role']) ?></span></span>
            <a href="<?= url('/logout') ?>">Salir</a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <span class="mbrand"><span class="logo">SK</span><b>Insulation</b></span>
            <h1 class="pagetitle"><?= e($title ?? 'Dashboard') ?></h1>
            <span class="weekpill"><?= e(week_start(today())) ?> → <?= e(week_end(today())) ?></span>
        </header>
        <main class="content">
            <?php if ($m = flash('ok')): ?><div class="flash ok"><?= e($m) ?></div><?php endif; ?>
            <?php if ($m = flash('error')): ?><div class="flash error"><?= e($m) ?></div><?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>

<nav class="bottomnav">
    <?php if ($isMgr): ?>
        <a href="<?= url('/') ?>" class="<?= $view === 'dashboard' ? 'active' : '' ?>"><span class="ni">▦</span>Panel</a>
        <a href="<?= url('/jobs') ?>" class="<?= $view === 'jobs' ? 'active' : '' ?>"><span class="ni">🧰</span>Trabajos</a>
        <a href="<?= url('/payroll') ?>" class="<?= $view === 'payroll' ? 'active' : '' ?>"><span class="ni">💵</span>Nómina</a>
        <a href="<?= url('/crews') ?>" class="<?= in_array($view, ['crews', 'installers'], true) ? 'active' : '' ?>"><span class="ni">👷</span>Cuadrillas</a>
        <a href="<?= url('/settings') ?>" class="<?= $view === 'settings' ? 'active' : '' ?>"><span class="ni">⚙</span>Más</a>
    <?php else: ?>
        <a href="<?= url('/') ?>" class="<?= $view === 'dashboard' ? 'active' : '' ?>"><span class="ni">▦</span>Panel</a>
        <a href="<?= url('/jobs') ?>" class="<?= $view === 'jobs' ? 'active' : '' ?>"><span class="ni">🧰</span>Trabajos</a>
        <a href="<?= url('/my-pay') ?>" class="<?= $view === 'my_pay' ? 'active' : '' ?>"><span class="ni">💵</span>Mi Pago</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
</body>
</html>

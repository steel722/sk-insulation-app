<?php
declare(strict_types=1);
// SK Insulation — Dashboard estilo mockup (Fase 2).
$title = 'Dashboard';
$pdo = Database::pdo();
$isMgr = ($user['role'] ?? '') === 'manager';
$ws = week_start(today());
$we = week_end(today());

$activeJobs = (int)$pdo->query(
    "SELECT COUNT(*) FROM jobs WHERE status IN ('scheduled','in_progress')"
)->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM jobs WHERE status = 'completed' AND DATE(completed_at) BETWEEN ? AND ?"
);
$stmt->execute([$ws, $we]);
$completedWeek = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT p.square_feet, p.linear_feet, r.rate, r.unit FROM production p
     JOIN pay_rates r ON r.work_type = p.work_type
     WHERE p.work_date BETWEEN ? AND ?"
);
$stmt->execute([$ws, $we]);
$sfWeek = 0.0; $payWeek = 0.0;
foreach ($stmt->fetchAll() as $row) {
    $qty = $row['unit'] === 'LF' ? (float)$row['linear_feet'] : (float)$row['square_feet'];
    $sfWeek += (float)$row['square_feet'];
    $payWeek += $qty * (float)$row['rate'];
}

// Trabajos recientes con SF por categoría y valor
$recent = $pdo->query(
    "SELECT j.id, j.job_number, j.customer, j.install_date, j.status,
        COALESCE(SUM(CASE WHEN p.work_type = 'wall' THEN p.square_feet ELSE 0 END), 0) AS wall_sf,
        COALESCE(SUM(CASE WHEN p.work_type = 'ceiling_r30' THEN p.square_feet ELSE 0 END), 0) AS ceil_sf,
        COALESCE(SUM(p.square_feet), 0) AS total_sf,
        COALESCE(SUM((CASE WHEN r.unit = 'LF' THEN p.linear_feet ELSE p.square_feet END) * r.rate), 0) AS value
     FROM jobs j
     LEFT JOIN production p ON p.job_id = j.id
     LEFT JOIN pay_rates r ON r.work_type = p.work_type
     GROUP BY j.id
     ORDER BY j.install_date DESC, j.id DESC
     LIMIT 8"
)->fetchAll();

// Producción por día (Lun–Dom) para el gráfico
$byDay = array_fill(0, 7, 0.0);
$stmt = $pdo->prepare(
    "SELECT p.work_date, SUM(p.square_feet) AS sf FROM production p
     WHERE p.work_date BETWEEN ? AND ? GROUP BY p.work_date"
);
$stmt->execute([$ws, $we]);
foreach ($stmt->fetchAll() as $row) {
    $dow = (int)date('N', strtotime($row['work_date'])) - 1; // 0 = lunes
    if ($dow >= 0 && $dow < 7) $byDay[$dow] += (float)$row['sf'];
}
$maxDay = max($byDay) > 0 ? max($byDay) : 1;
$dayNames = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

// Top instaladores de la semana
$top = $pdo->prepare(
    "SELECT u.name,
        COALESCE(SUM(p.square_feet), 0) AS sf,
        COALESCE(SUM((CASE WHEN r.unit = 'LF' THEN p.linear_feet ELSE p.square_feet END) * r.rate), 0) AS pay
     FROM users u
     LEFT JOIN production p ON p.installer_id = u.id AND p.work_date BETWEEN ? AND ?
     LEFT JOIN pay_rates r ON r.work_type = p.work_type
     WHERE u.role = 'installer' AND u.active = 1
     GROUP BY u.id ORDER BY pay DESC LIMIT 5"
);
$top->execute([$ws, $we]);
$topInstallers = $top->fetchAll();

// Agenda de hoy
$st = $pdo->prepare(
    "SELECT j.job_number, j.customer, j.lot_number, j.status, c.name AS crew_name
     FROM jobs j LEFT JOIN crews c ON c.id = j.crew_id
     WHERE j.install_date = ? ORDER BY j.job_number"
);
$st->execute([today()]);
$schedule = $st->fetchAll();

// Uso de materiales esta semana (por fecha de instalación)
$st = $pdo->prepare(
    "SELECT m.brand, m.name, SUM(jm.quantity) AS qty,
            SUM(jm.quantity * COALESCE(m.sf_per_bag, 0)) AS total_sf
     FROM job_materials jm
     JOIN materials m ON m.id = jm.material_id
     JOIN jobs j ON j.id = jm.job_id
     WHERE j.install_date BETWEEN ? AND ?
     GROUP BY m.id ORDER BY qty DESC LIMIT 8"
);
$st->execute([$ws, $we]);
$matUsage = $st->fetchAll();

$activeInstallers = $isMgr ? (int)$pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'installer' AND active = 1"
)->fetchColumn() : 0;

$statusEs = ['scheduled' => 'Programado', 'in_progress' => 'En curso', 'completed' => 'Completado', 'cancelled' => 'Cancelado'];
?>

<div class="stats-grid">
    <div class="stat-card">
        <span class="stat-ico blue">🧰</span>
        <div><div class="num"><?= $activeJobs ?></div><div class="lbl">Active Jobs</div><div class="sub">Programados + en curso</div></div>
    </div>
    <div class="stat-card">
        <span class="stat-ico violet">✅</span>
        <div><div class="num"><?= $completedWeek ?></div><div class="lbl">Completed Jobs</div><div class="sub">Esta semana</div></div>
    </div>
    <div class="stat-card">
        <span class="stat-ico green">📐</span>
        <div><div class="num"><?= number_format($sfWeek) ?> SF</div><div class="lbl">Total Installed</div><div class="sub">Esta semana</div></div>
    </div>
    <div class="stat-card">
        <span class="stat-ico amber">💵</span>
        <div><div class="num"><?= money($payWeek) ?></div><div class="lbl">Production Pay</div><div class="sub">Esta semana</div></div>
    </div>
</div>

<div class="grid-2">
    <div>
        <div class="card">
            <div class="card-head"><h2>Recent Jobs</h2><a href="<?= url('/jobs') ?>">View All Jobs</a></div>
            <?php if (!$recent): ?>
                <p class="muted">Aún no hay trabajos. <a class="link" href="<?= url('/jobs?action=new') ?>">Crea el primero</a>.</p>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table class="tbl">
                <thead><tr><th>Job No</th><th>Customer / Lot</th><th>Date</th><th class="num">Wall SF</th><th class="num">Ceiling SF</th><th class="num">Total SF</th><th class="num">Value</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $j): ?>
                    <tr>
                        <td><a class="link" href="<?= url("/jobs?action=view&id={$j['id']}") ?>"><?= e($j['job_number']) ?></a></td>
                        <td><?= e($j['customer']) ?></td>
                        <td><?= e($j['install_date'] ?? '—') ?></td>
                        <td class="num"><?= number_format((float)$j['wall_sf']) ?></td>
                        <td class="num"><?= number_format((float)$j['ceil_sf']) ?></td>
                        <td class="num"><?= number_format((float)$j['total_sf']) ?></td>
                        <td class="num"><?= money((float)$j['value']) ?></td>
                        <td><span class="badge <?= e($j['status']) ?>"><?= e($statusEs[$j['status']] ?? $j['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Production This Week</h2><span class="muted"><?= number_format($sfWeek) ?> SF</span></div>
            <svg class="chart" viewBox="0 0 700 240" role="img" aria-label="SF instalados por día">
                <?php for ($g = 0; $g <= 4; $g++): $y = 200 - $g * 45; ?>
                    <line x1="40" y1="<?= $y ?>" x2="690" y2="<?= $y ?>" stroke="#e2e8f0" stroke-width="1"/>
                <?php endfor; ?>
                <?php foreach ($byDay as $i => $v):
                    $h = max(4, round($v / $maxDay * 170));
                    $x = 60 + $i * 90; $y = 200 - $h;
                ?>
                    <rect class="bar<?= $v == 0 ? ' dim' : '' ?>" x="<?= $x ?>" y="<?= $y ?>" width="56" height="<?= $h ?>" rx="6"/>
                    <?php if ($v > 0): ?><text class="vlabel" x="<?= $x + 28 ?>" y="<?= $y - 8 ?>" text-anchor="middle"><?= number_format($v) ?></text><?php endif; ?>
                    <text x="<?= $x + 28 ?>" y="222" text-anchor="middle"><?= $dayNames[$i] ?></text>
                <?php endforeach; ?>
            </svg>
        </div>

        <div class="card">
            <div class="card-head"><h2>Today's Schedule</h2><a href="<?= url('/jobs') ?>">View Full Schedule</a></div>
            <?php if (!$schedule): ?>
                <p class="muted">Nada programado para hoy.</p>
            <?php else: ?>
            <table class="tbl">
                <thead><tr><th>Job</th><th>Customer</th><th>Lot</th><th>Crew</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($schedule as $s): ?>
                    <tr>
                        <td><b><?= e($s['job_number']) ?></b></td>
                        <td><?= e($s['customer']) ?></td>
                        <td><?= e($s['lot_number'] ?? '—') ?></td>
                        <td><?= e($s['crew_name'] ?? '—') ?></td>
                        <td><span class="badge <?= e($s['status']) ?>"><?= e($statusEs[$s['status']] ?? $s['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Material Usage This Week</h2><a href="<?= url('/settings?action=materials') ?>">View All Materials</a></div>
            <?php if (!$matUsage): ?>
                <p class="muted">Sin materiales en trabajos de esta semana.</p>
            <?php else: ?>
            <table class="tbl">
                <thead><tr><th>Material</th><th class="num">Qty Used</th><th class="num">Total SF</th></tr></thead>
                <tbody>
                <?php foreach ($matUsage as $m): ?>
                    <tr>
                        <td><?= e($m['brand']) ?> — <?= e($m['name']) ?></td>
                        <td class="num"><?= e((string)$m['qty']) ?></td>
                        <td class="num"><?= number_format((float)$m['total_sf']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <?php if ($isMgr): ?>
        <div class="card">
            <div class="card-head"><h2>Quick Actions</h2></div>
            <div class="qa-grid">
                <a class="btn primary small" href="<?= url('/jobs?action=new') ?>">＋ Create Job</a>
                <a class="btn ghost small" href="<?= url('/jobs') ?>">📋 Job Load List</a>
                <a class="btn ghost small" href="<?= url('/settings?action=materials') ?>">📦 Material Catalog</a>
                <a class="btn ghost small" href="<?= url('/export') ?>">📊 Export to Excel</a>
                <a class="btn ghost small" href="<?= url('/payroll') ?>">📑 View Reports</a>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>Installer Status</h2><a href="<?= url('/installers') ?>">View All</a></div>
            <div class="rank">
                <span class="avatar">👷</span>
                <span class="who"><b><?= $activeInstallers ?> Active Installers</b></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-head"><h2>Top Installers This Week</h2></div>
            <?php
            $shown = 0;
            foreach ($topInstallers as $i => $t):
                if ((float)$t['pay'] <= 0 && $shown >= 3) continue;
                $shown++;
            ?>
                <div class="rank">
                    <span class="pos"><?= $i + 1 ?></span>
                    <span class="avatar"><?= e(strtoupper(substr($t['name'], 0, 1))) ?></span>
                    <span class="who"><b><?= e($t['name']) ?></b><span><?= number_format((float)$t['sf']) ?> SF</span></span>
                    <span class="amt"><?= money((float)$t['pay']) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($shown === 0): ?><p class="muted">Sin producción esta semana.</p><?php endif; ?>
        </div>
    </div>
</div>

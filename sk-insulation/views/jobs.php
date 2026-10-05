<?php
declare(strict_types=1);
// SK Insulation — Trabajos (Fase 2): CRUD, materiales, división de pago y producción.
$title = 'Trabajos';
$pdo = Database::pdo();
$isManager = ($user['role'] ?? '') === 'manager';
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$statuses = [
    'scheduled'   => 'Programado',
    'in_progress' => 'En curso',
    'completed'   => 'Completado',
    'cancelled'   => 'Cancelado',
];

function job_installers(PDO $pdo, ?int $crewId): array
{
    if ($crewId) {
        $st = $pdo->prepare(
            "SELECT u.id, u.name FROM crew_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.crew_id = ? AND u.role = 'installer' AND u.active = 1
             ORDER BY u.name"
        );
        $st->execute([$crewId]);
        $rows = $st->fetchAll();
        if ($rows) return $rows;
    }
    return $pdo->query(
        "SELECT id, name FROM users WHERE role = 'installer' AND active = 1 ORDER BY name"
    )->fetchAll();
}

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pa = $_POST['post_action'] ?? '';

    // Guardar trabajo (nuevo / editar)
    if ($pa === 'save_job') {
        if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
        $jid = (int)($_POST['id'] ?? 0);
        $job_number = trim($_POST['job_number'] ?? '');
        $customer = trim($_POST['customer'] ?? '');
        $status = $_POST['status'] ?? 'scheduled';
        $install_date = trim($_POST['install_date'] ?? '');
        $crew_id = trim($_POST['crew_id'] ?? '');
        if (!isset($statuses[$status])) $status = 'scheduled';
        if ($job_number === '' || $customer === '') {
            flash('error', 'El número de trabajo y el cliente son obligatorios.');
            redirect($jid > 0 ? "/jobs?action=edit&id=$jid" : '/jobs?action=new');
        }
        $chk = $pdo->prepare('SELECT id FROM jobs WHERE job_number = ? AND id <> ?');
        $chk->execute([$job_number, $jid]);
        if ($chk->fetch()) {
            flash('error', 'Ese número de trabajo ya existe.');
            redirect($jid > 0 ? "/jobs?action=edit&id=$jid" : '/jobs?action=new');
        }
        $params = [
            $job_number, $customer,
            trim($_POST['builder'] ?? '') ?: null,
            trim($_POST['lot_number'] ?? '') ?: null,
            trim($_POST['address'] ?? '') ?: null,
            $install_date !== '' ? $install_date : null,
            $crew_id !== '' ? (int)$crew_id : null,
            $status,
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($jid > 0) {
            $params[] = $jid;
            $pdo->prepare('UPDATE jobs SET job_number=?, customer=?, builder=?, lot_number=?, address=?, install_date=?, crew_id=?, status=?, notes=? WHERE id=?')
                ->execute($params);
            if ($status === 'completed') {
                $pdo->prepare('UPDATE jobs SET completed_at = COALESCE(completed_at, NOW()) WHERE id = ?')->execute([$jid]);
            } else {
                $pdo->prepare('UPDATE jobs SET completed_at = NULL WHERE id = ?')->execute([$jid]);
            }
            flash('ok', 'Trabajo actualizado.');
        } else {
            $params[] = $user['id'];
            $pdo->prepare('INSERT INTO jobs (job_number, customer, builder, lot_number, address, install_date, crew_id, status, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute($params);
            $jid = (int)$pdo->lastInsertId();
            flash('ok', 'Trabajo creado.');
        }
        redirect("/jobs?action=view&id=$jid");
    }

    // Eliminar trabajo
    if ($pa === 'delete_job') {
        if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
        $jid = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$jid]);
        flash('ok', 'Trabajo eliminado.');
        redirect('/jobs');
    }

    // Agregar material a la lista del trabajo
    if ($pa === 'add_material') {
        if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
        $jid = (int)($_POST['job_id'] ?? 0);
        $mid = (int)($_POST['material_id'] ?? 0);
        $qty = (float)($_POST['quantity'] ?? 1);
        if ($jid > 0 && $mid > 0 && $qty > 0) {
            $pdo->prepare('INSERT INTO job_materials (job_id, material_id, quantity, note) VALUES (?,?,?,?)')
                ->execute([$jid, $mid, $qty, trim($_POST['note'] ?? '') ?: null]);
            flash('ok', 'Material agregado a la lista de carga.');
        } else {
            flash('error', 'Selecciona un material y una cantidad válida.');
        }
        redirect("/jobs?action=view&id=$jid");
    }

    // Quitar material de la lista
    if ($pa === 'remove_material') {
        if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
        $jid = (int)($_POST['job_id'] ?? 0);
        $pdo->prepare('DELETE FROM job_materials WHERE id = ? AND job_id = ?')
            ->execute([(int)($_POST['jm_id'] ?? 0), $jid]);
        redirect("/jobs?action=view&id=$jid");
    }

    // Guardar división de pago (debe sumar 100%)
    if ($pa === 'save_splits') {
        if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
        $jid = (int)($_POST['job_id'] ?? 0);
        $rows = [];
        foreach (($_POST['pct'] ?? []) as $iid => $pct) {
            $iid = (int)$iid; $pct = (float)$pct;
            if ($pct > 0) $rows[$iid] = $pct;
        }
        $sum = array_sum($rows);
        if ($rows && abs($sum - 100) > 0.01) {
            flash('error', 'Los porcentajes deben sumar 100% (ahora suman ' . number_format($sum, 2) . '%).');
            redirect("/jobs?action=view&id=$jid");
        }
        $pdo->prepare('DELETE FROM pay_splits WHERE job_id = ?')->execute([$jid]);
        $ins = $pdo->prepare('INSERT INTO pay_splits (job_id, installer_id, percent) VALUES (?,?,?)');
        foreach ($rows as $iid => $pct) $ins->execute([$jid, $iid, $pct]);
        flash('ok', 'División de pago guardada.');
        redirect("/jobs?action=view&id=$jid");
    }

    // Registrar producción (reparte SF/LF entre instaladores por %)
    if ($pa === 'add_production') {
        $jid = (int)($_POST['job_id'] ?? 0);
        $work_date = trim($_POST['work_date'] ?? '');
        $work_type = trim($_POST['work_type'] ?? '');
        $totalQty = (float)($_POST['total_qty'] ?? 0);
        $percents = [];
        if ($isManager) {
            foreach (($_POST['pct'] ?? []) as $iid => $pct) {
                $iid = (int)$iid; $pct = (float)$pct;
                if ($pct > 0) $percents[$iid] = $pct;
            }
        } else {
            // El instalador solo puede registrar su propia producción
            $percents = [(int)$user['id'] => 100];
        }
        $rates = pay_rates();
        $sum = array_sum($percents);
        if ($jid <= 0 || $work_date === '' || !isset($rates[$work_type]) || $totalQty <= 0 || !$percents || abs($sum - 100) > 0.01) {
            flash('error', 'Revisa los datos: fecha, tipo de trabajo, cantidad y porcentajes (100%).');
            redirect("/jobs?action=view&id=$jid");
        }
        // Semana cerrada: no se puede registrar
        $ws = week_start($work_date);
        $wk = $pdo->prepare('SELECT status FROM payroll_weeks WHERE week_start = ?');
        $wk->execute([$ws]);
        if (($wk->fetch()['status'] ?? 'open') === 'closed') {
            flash('error', 'Esa semana de nómina ya está cerrada.');
            redirect("/jobs?action=view&id=$jid");
        }
        $rate = $rates[$work_type];
        $stmt = $pdo->prepare('INSERT INTO production (job_id, installer_id, work_date, work_type, square_feet, linear_feet, created_by) VALUES (?,?,?,?,?,?,?)');
        foreach ($percents as $iid => $pct) {
            $q = round($totalQty * $pct / 100, 2);
            $sf = $rate['unit'] === 'LF' ? 0 : $q;
            $lf = $rate['unit'] === 'LF' ? $q : 0;
            $stmt->execute([$jid, $iid, $work_date, $work_type, $sf, $lf, $user['id']]);
        }
        flash('ok', 'Producción registrada.');
        redirect("/jobs?action=view&id=$jid");
    }

    // Eliminar una entrada de producción
    if ($pa === 'remove_production') {
        $jid = (int)($_POST['job_id'] ?? 0);
        $pid = (int)($_POST['pid'] ?? 0);
        $row = $pdo->prepare('SELECT * FROM production WHERE id = ?');
        $row->execute([$pid]);
        $prod = $row->fetch();
        if ($prod && ($isManager || (int)$prod['installer_id'] === (int)$user['id'])) {
            $ws = week_start($prod['work_date']);
            $wk = $pdo->prepare('SELECT status FROM payroll_weeks WHERE week_start = ?');
            $wk->execute([$ws]);
            if (($wk->fetch()['status'] ?? 'open') !== 'closed') {
                $pdo->prepare('DELETE FROM production WHERE id = ?')->execute([$pid]);
                flash('ok', 'Entrada eliminada.');
            } else {
                flash('error', 'Esa semana de nómina ya está cerrada.');
            }
        }
        redirect("/jobs?action=view&id=$jid");
    }
}

// ---------------- Vistas ----------------
$crews = $pdo->query('SELECT id, name FROM crews WHERE active = 1 ORDER BY name')->fetchAll();

if ($action === 'new' || $action === 'edit') {
    if (!$isManager) { http_response_code(403); exit('No autorizado.'); }
    $job = null;
    if ($action === 'edit' && $id > 0) {
        $st = $pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $st->execute([$id]);
        $job = $st->fetch();
        if (!$job) { echo '<div class="card"><p class="muted">Trabajo no encontrado.</p></div>'; return; }
    }
    $title = $job ? 'Editar trabajo' : 'Nuevo trabajo';
    ?>
    <div class="card">
        <div class="card-head"><h2><?= $job ? 'Editar trabajo' : 'Nuevo trabajo' ?></h2><a href="<?= url('/jobs') ?>">← Volver</a></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="save_job">
            <input type="hidden" name="id" value="<?= (int)($job['id'] ?? 0) ?>">
            <div class="row2">
                <label>Número de trabajo *<input name="job_number" required value="<?= e($job['job_number'] ?? '') ?>"></label>
                <label>Cliente *<input name="customer" required value="<?= e($job['customer'] ?? '') ?>"></label>
            </div>
            <div class="row2">
                <label>Builder<input name="builder" value="<?= e($job['builder'] ?? '') ?>"></label>
                <label>Lote<input name="lot_number" value="<?= e($job['lot_number'] ?? '') ?>"></label>
            </div>
            <label>Dirección<input name="address" value="<?= e($job['address'] ?? '') ?>"></label>
            <div class="row2">
                <label>Fecha de instalación<input type="date" name="install_date" value="<?= e($job['install_date'] ?? '') ?>"></label>
                <label>Cuadrilla
                    <select name="crew_id">
                        <option value="">— Sin asignar —</option>
                        <?php foreach ($crews as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (int)($job['crew_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>Estado
                <select name="status">
                    <?php foreach ($statuses as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($job['status'] ?? 'scheduled') === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Notas<textarea name="notes" rows="3"><?= e($job['notes'] ?? '') ?></textarea></label>
            <button class="btn primary big" type="submit">Guardar</button>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'view' && $id > 0) {
    $st = $pdo->prepare('SELECT j.*, c.name AS crew_name FROM jobs j LEFT JOIN crews c ON c.id = j.crew_id WHERE j.id = ?');
    $st->execute([$id]);
    $job = $st->fetch();
    if (!$job) { echo '<div class="card"><p class="muted">Trabajo no encontrado.</p></div>'; return; }
    $title = 'Trabajo ' . $job['job_number'];

    $mats = $pdo->prepare(
        'SELECT jm.*, m.brand, m.product_code, m.name FROM job_materials jm
         JOIN materials m ON m.id = jm.material_id WHERE jm.job_id = ? ORDER BY jm.id'
    );
    $mats->execute([$id]);
    $jobMats = $mats->fetchAll();
    $catalog = $pdo->query("SELECT id, brand, product_code, name FROM materials WHERE active = 1 ORDER BY brand, name")->fetchAll();

    $sp = $pdo->prepare('SELECT installer_id, percent FROM pay_splits WHERE job_id = ?');
    $sp->execute([$id]);
    $splits = [];
    foreach ($sp->fetchAll() as $r) $splits[(int)$r['installer_id']] = (float)$r['percent'];

    $people = job_installers($pdo, $job['crew_id'] ? (int)$job['crew_id'] : null);
    $rates = pay_rates();

    $pr = $pdo->prepare(
        'SELECT p.*, u.name AS installer_name, r.label, r.rate, r.unit
         FROM production p JOIN users u ON u.id = p.installer_id
         JOIN pay_rates r ON r.work_type = p.work_type
         WHERE p.job_id = ? ORDER BY p.work_date DESC, p.id DESC'
    );
    $pr->execute([$id]);
    $entries = $pr->fetchAll();
    $totalPay = 0;
    foreach ($entries as $en) {
        $q = $en['unit'] === 'LF' ? (float)$en['linear_feet'] : (float)$en['square_feet'];
        $totalPay += $q * (float)$en['rate'];
    }
    ?>
    <div class="card">
        <div class="card-head">
            <h2><?= e($job['job_number']) ?> — <?= e($job['customer']) ?></h2>
            <a href="<?= url('/jobs') ?>">← Volver</a>
        </div>
        <dl class="kv">
            <dt>Estado</dt><dd><span class="badge <?= e($job['status']) ?>"><?= e($statuses[$job['status']] ?? $job['status']) ?></span></dd>
            <dt>Builder</dt><dd><?= e($job['builder'] ?? '—') ?></dd>
            <dt>Lote</dt><dd><?= e($job['lot_number'] ?? '—') ?></dd>
            <dt>Dirección</dt><dd><?= e($job['address'] ?? '—') ?></dd>
            <dt>Instalación</dt><dd><?= e($job['install_date'] ?? '—') ?></dd>
            <dt>Cuadrilla</dt><dd><?= e($job['crew_name'] ?? '—') ?></dd>
            <dt>Pago producción</dt><dd><strong><?= money($totalPay) ?></strong></dd>
        </dl>
        <?php if ($job['notes']): ?><p class="muted"><?= nl2br(e($job['notes'])) ?></p><?php endif; ?>
        <?php if ($isManager): ?>
        <div class="actions">
            <a class="btn ghost small" href="<?= url("/jobs?action=edit&id=$id") ?>">Editar</a>
            <form class="inline" method="post" onsubmit="return confirm('¿Eliminar este trabajo y su producción?')">
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="delete_job">
                <input type="hidden" name="id" value="<?= $id ?>">
                <button class="btn danger small" type="submit">Eliminar</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Lista de carga (materiales)</h2></div>
        <?php if (!$jobMats): ?>
            <p class="muted">Sin materiales asignados.</p>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Material</th><th class="num">Cant.</th><?php if ($isManager): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($jobMats as $jm): ?>
                <tr>
                    <td><?= e($jm['brand']) ?> — <?= e($jm['name']) ?><?= $jm['note'] ? '<br><span class="muted">' . e($jm['note']) . '</span>' : '' ?></td>
                    <td class="num"><?= e((string)$jm['quantity']) ?></td>
                    <?php if ($isManager): ?>
                    <td><form class="inline" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_action" value="remove_material">
                        <input type="hidden" name="job_id" value="<?= $id ?>">
                        <input type="hidden" name="jm_id" value="<?= $jm['id'] ?>">
                        <button class="btn ghost small" type="submit">Quitar</button>
                    </form></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php if ($isManager): ?>
        <form method="post" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="add_material">
            <input type="hidden" name="job_id" value="<?= $id ?>">
            <div class="row2">
                <label>Material
                    <select name="material_id" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($catalog as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= e($m['brand']) ?> <?= e($m['product_code'] ?? '') ?> — <?= e($m['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Cantidad<input type="number" name="quantity" min="0.01" step="0.01" value="1" required></label>
            </div>
            <label>Nota<input name="note" placeholder="Ej: para el ático"></label>
            <button class="btn primary" type="submit">+ Agregar material</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if ($isManager): ?>
    <div class="card">
        <div class="card-head"><h2>División de pago</h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="save_splits">
            <input type="hidden" name="job_id" value="<?= $id ?>">
            <p class="muted">Reparto del pago del trabajo entre instaladores. Debe sumar 100%.</p>
            <?php
            $n = count($people);
            foreach ($people as $p):
                $pct = $splits[(int)$p['id']] ?? ($n > 0 ? round(100 / $n, 2) : 0);
            ?>
                <label><?= e($p['name']) ?>
                    <input type="number" name="pct[<?= $p['id'] ?>]" min="0" max="100" step="0.01" value="<?= e((string)$pct) ?>">
                </label>
            <?php endforeach; ?>
            <?php if (!$people): ?><p class="muted">No hay instaladores activos. Créelos en Cuadrillas → Instaladores.</p><?php endif; ?>
            <button class="btn primary" type="submit">Guardar división</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><h2>Producción (SF instalados)</h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="add_production">
            <input type="hidden" name="job_id" value="<?= $id ?>">
            <div class="row2">
                <label>Fecha<input type="date" name="work_date" value="<?= today() ?>" required></label>
                <label>Tipo de trabajo
                    <select name="work_type" required>
                        <?php foreach ($rates as $rt): ?>
                            <option value="<?= e($rt['work_type']) ?>"><?= e($rt['label']) ?> — <?= money((float)$rt['rate']) ?>/<?= e($rt['unit']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <label>Cantidad total (SF o LF según el tipo)<input type="number" name="total_qty" min="0.01" step="0.01" required></label>
            <?php if ($isManager): ?>
                <p class="muted">Reparto por instalador (% del total):</p>
                <?php
                $n = count($people);
                foreach ($people as $p):
                    $pct = $splits[(int)$p['id']] ?? ($n > 0 ? round(100 / $n, 2) : 0);
                ?>
                    <label><?= e($p['name']) ?>
                        <input type="number" name="pct[<?= $p['id'] ?>]" min="0" max="100" step="0.01" value="<?= e((string)$pct) ?>">
                    </label>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Se registrará a tu nombre (100%).</p>
            <?php endif; ?>
            <button class="btn primary" type="submit">+ Registrar producción</button>
        </form>

        <?php if ($entries): ?>
        <table class="tbl" style="margin-top:12px">
            <thead><tr><th>Fecha</th><th>Instalador</th><th>Tipo</th><th class="num">Cant.</th><th class="num">$</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($entries as $en):
                $q = $en['unit'] === 'LF' ? (float)$en['linear_feet'] : (float)$en['square_feet'];
                $amt = $q * (float)$en['rate'];
            ?>
                <tr>
                    <td><?= e($en['work_date']) ?></td>
                    <td><?= e($en['installer_name']) ?></td>
                    <td><?= e($en['label']) ?></td>
                    <td class="num"><?= number_format($q, 2) ?> <?= e($en['unit']) ?></td>
                    <td class="num"><?= money($amt) ?></td>
                    <td><?php if ($isManager || (int)$en['installer_id'] === (int)$user['id']): ?>
                        <form class="inline" method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="post_action" value="remove_production">
                            <input type="hidden" name="job_id" value="<?= $id ?>">
                            <input type="hidden" name="pid" value="<?= $en['id'] ?>">
                            <button class="btn ghost small" type="submit" onclick="return confirm('¿Eliminar esta entrada?')">✕</button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
    return;
}

// ---------------- Lista ----------------
$filter = $_GET['status'] ?? '';
$where = '';
$params = [];
if (isset($statuses[$filter])) { $where = 'WHERE j.status = ?'; $params[] = $filter; }
$st = $pdo->prepare("SELECT j.*, c.name AS crew_name FROM jobs j LEFT JOIN crews c ON c.id = j.crew_id $where ORDER BY j.install_date DESC, j.id DESC");
$st->execute($params);
$jobs = $st->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <h2>Trabajos</h2>
        <?php if ($isManager): ?><a class="btn primary small" href="<?= url('/jobs?action=new') ?>">+ Nuevo</a><?php endif; ?>
    </div>
    <form method="get" action="<?= url('/jobs') ?>" style="margin-bottom:12px">
        <label>Estado
            <select name="status" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($statuses as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filter === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <?php if (!$jobs): ?>
        <p class="muted">No hay trabajos.<?php if ($isManager): ?> Crea el primero con el botón + Nuevo.<?php endif; ?></p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>#</th><th>Cliente</th><th>Fecha</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($jobs as $j): ?>
            <tr>
                <td><a class="link" href="<?= url("/jobs?action=view&id={$j['id']}") ?>"><?= e($j['job_number']) ?></a></td>
                <td><?= e($j['customer']) ?></td>
                <td><?= e($j['install_date'] ?? '—') ?></td>
                <td><span class="badge <?= e($j['status']) ?>"><?= e($statuses[$j['status']] ?? $j['status']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

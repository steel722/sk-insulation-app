<?php
declare(strict_types=1);
// SK Insulation — Nómina semanal (Fase 2). Solo manager.
$title = 'Nómina';
$pdo = Database::pdo();

$ws = $_GET['week'] ?? week_start(today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ws)) $ws = week_start(today());
$ws = week_start($ws);
$we = week_end($ws);

$pdo->prepare('INSERT IGNORE INTO payroll_weeks (week_start, week_end) VALUES (?, ?)')->execute([$ws, $we]);
$wst = $pdo->prepare('SELECT * FROM payroll_weeks WHERE week_start = ?');
$wst->execute([$ws]);
$weekRow = $wst->fetch();
$closed = ($weekRow['status'] ?? 'open') === 'closed';

$rates = pay_rates();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'add_adjustment') {
        if ($closed) { flash('error', 'La semana está cerrada.'); redirect("/payroll?week=$ws"); }
        $iid = (int)($_POST['installer_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        if ($iid > 0 && $amount != 0 && $reason !== '') {
            $pdo->prepare('INSERT INTO adjustments (week_start, installer_id, amount, reason, created_by) VALUES (?,?,?,?,?)')
                ->execute([$ws, $iid, $amount, $reason, $user['id']]);
            flash('ok', 'Ajuste registrado.');
        } else {
            flash('error', 'Completa instalador, monto (negativo para descuento) y motivo.');
        }
        redirect("/payroll?week=$ws");
    }

    if ($pa === 'remove_adjustment') {
        if ($closed) { flash('error', 'La semana está cerrada.'); redirect("/payroll?week=$ws"); }
        $pdo->prepare('DELETE FROM adjustments WHERE id = ? AND week_start = ?')
            ->execute([(int)($_POST['aid'] ?? 0), $ws]);
        redirect("/payroll?week=$ws");
    }

    if ($pa === 'close_week') {
        $pdo->prepare("UPDATE payroll_weeks SET status = 'closed', closed_at = NOW() WHERE week_start = ?")
            ->execute([$ws]);
        flash('ok', 'Semana cerrada.');
        redirect("/payroll?week=$ws");
    }

    if ($pa === 'reopen_week') {
        $pdo->prepare("UPDATE payroll_weeks SET status = 'open', closed_at = NULL WHERE week_start = ?")
            ->execute([$ws]);
        flash('ok', 'Semana reabierta.');
        redirect("/payroll?week=$ws");
    }
}

function pay_amount(array $row): float
{
    $q = $row['unit'] === 'LF' ? (float)$row['linear_feet'] : (float)$row['square_feet'];
    return $q * (float)$row['rate'];
}

$installers = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'installer' AND active = 1 ORDER BY name"
)->fetchAll();

$rows = [];
$grand = 0.0;
foreach ($installers as $ins) {
    $iid = (int)$ins['id'];
    $st = $pdo->prepare(
        'SELECT p.*, j.job_number, r.label, r.rate, r.unit
         FROM production p
         JOIN jobs j ON j.id = p.job_id
         JOIN pay_rates r ON r.work_type = p.work_type
         WHERE p.installer_id = ? AND p.work_date BETWEEN ? AND ?
         ORDER BY p.work_date, j.job_number'
    );
    $st->execute([$iid, $ws, $we]);
    $entries = $st->fetchAll();
    $prodPay = 0.0; $sf = 0.0;
    foreach ($entries as $en) {
        $prodPay += pay_amount($en);
        $sf += (float)$en['square_feet'];
    }
    $ast = $pdo->prepare('SELECT * FROM adjustments WHERE installer_id = ? AND week_start = ? ORDER BY id');
    $ast->execute([$iid, $ws]);
    $adjs = $ast->fetchAll();
    $adjTotal = 0.0;
    foreach ($adjs as $a) $adjTotal += (float)$a['amount'];
    $total = $prodPay + $adjTotal;
    $grand += $total;
    $rows[] = ['ins' => $ins, 'entries' => $entries, 'prod' => $prodPay, 'sf' => $sf, 'adjs' => $adjs, 'adj' => $adjTotal, 'total' => $total];
}

$prev = date('Y-m-d', strtotime("$ws -7 days"));
$next = date('Y-m-d', strtotime("$ws +7 days"));
?>
<div class="card">
    <div class="weeknav">
        <a class="btn ghost small" href="<?= url("/payroll?week=$prev") ?>">← Ant.</a>
        <div style="text-align:center">
            <h2 style="margin:0">Nómina <?= e($ws) ?> → <?= e($we) ?></h2>
            <?= $closed ? '<span class="badge">Cerrada</span>' : '<span class="badge completed">Abierta</span>' ?>
        </div>
        <a class="btn ghost small" href="<?= url("/payroll?week=$next") ?>">Sig. →</a>
    </div>
    <?php if ($closed): ?>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="reopen_week">
            <button class="btn ghost small" type="submit">Reabrir semana</button>
        </form>
    <?php else: ?>
        <form method="post" class="inline" onsubmit="return confirm('¿Cerrar la semana? Ya no se podrá registrar producción ni ajustes.')">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="close_week">
            <button class="btn primary small" type="submit">Cerrar semana</button>
        </form>
    <?php endif; ?>
</div>

<?php foreach ($rows as $r): ?>
<div class="card">
    <div class="card-head"><h2><?= e($r['ins']['name']) ?></h2><span><strong><?= money($r['total']) ?></strong></span></div>
    <?php if ($r['entries']): ?>
    <table class="tbl">
        <thead><tr><th>Fecha</th><th>Trabajo</th><th>Tipo</th><th class="num">Cant.</th><th class="num">$</th></tr></thead>
        <tbody>
        <?php foreach ($r['entries'] as $en):
            $q = $en['unit'] === 'LF' ? (float)$en['linear_feet'] : (float)$en['square_feet'];
        ?>
            <tr>
                <td><?= e($en['work_date']) ?></td>
                <td><a class="link" href="<?= url("/jobs?action=view&id={$en['job_id']}") ?>"><?= e($en['job_number']) ?></a></td>
                <td><?= e($en['label']) ?></td>
                <td class="num"><?= number_format($q, 2) ?> <?= e($en['unit']) ?></td>
                <td class="num"><?= money(pay_amount($en)) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($r['adjs'] as $a): ?>
            <tr>
                <td><?= e($ws) ?></td>
                <td colspan="2">Ajuste: <?= e($a['reason']) ?>
                    <?php if (!$closed): ?>
                    <form class="inline" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_action" value="remove_adjustment">
                        <input type="hidden" name="aid" value="<?= $a['id'] ?>">
                        <button class="btn ghost small" type="submit" onclick="return confirm('¿Quitar ajuste?')">✕</button>
                    </form>
                    <?php endif; ?>
                </td>
                <td class="num">—</td>
                <td class="num"><?= money((float)$a['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="3">Total <?= e($r['ins']['name']) ?> (<?= number_format($r['sf']) ?> SF)</td>
            <td class="num"></td>
            <td class="num"><?= money($r['total']) ?></td>
        </tr>
        </tbody>
    </table>
    <?php else: ?>
        <p class="muted">Sin producción esta semana.</p>
        <?php foreach ($r['adjs'] as $a): ?>
            <p>Ajuste: <?= e($a['reason']) ?> — <strong><?= money((float)$a['amount']) ?></strong>
                <?php if (!$closed): ?>
                <form class="inline" method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="post_action" value="remove_adjustment">
                    <input type="hidden" name="aid" value="<?= $a['id'] ?>">
                    <button class="btn ghost small" type="submit">✕</button>
                </form>
                <?php endif; ?>
            </p>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
    <div class="card-head"><h2>Total de la semana</h2><span style="font-size:22px;font-weight:800"><?= money($grand) ?></span></div>
</div>

<?php if (!$closed): ?>
<div class="card">
    <div class="card-head"><h2>Bono / descuento</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="post_action" value="add_adjustment">
        <div class="row2">
            <label>Instalador
                <select name="installer_id" required>
                    <option value="">— Seleccionar —</option>
                    <?php foreach ($installers as $ins): ?>
                        <option value="<?= $ins['id'] ?>"><?= e($ins['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Monto (negativo = descuento)<input type="number" name="amount" step="0.01" required placeholder="Ej: 50 o -25"></label>
        </div>
        <label>Motivo<input name="reason" required placeholder="Ej: bono por sábado"></label>
        <button class="btn primary" type="submit">Registrar ajuste</button>
    </form>
</div>
<?php endif; ?>

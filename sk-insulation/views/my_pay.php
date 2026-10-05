<?php
declare(strict_types=1);
// SK Insulation — Mi Pago: portal del instalador (Fase 2).
$title = 'Mi Pago';
$pdo = Database::pdo();
$uid = (int)$user['id'];

$ws = $_GET['week'] ?? week_start(today());
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ws)) $ws = week_start(today());
$ws = week_start($ws);
$we = week_end($ws);

$st = $pdo->prepare(
    'SELECT p.*, j.job_number, j.customer, r.label, r.rate, r.unit
     FROM production p
     JOIN jobs j ON j.id = p.job_id
     JOIN pay_rates r ON r.work_type = p.work_type
     WHERE p.installer_id = ? AND p.work_date BETWEEN ? AND ?
     ORDER BY p.work_date DESC'
);
$st->execute([$uid, $ws, $we]);
$entries = $st->fetchAll();

$prod = 0.0; $sf = 0.0;
foreach ($entries as $en) {
    $q = $en['unit'] === 'LF' ? (float)$en['linear_feet'] : (float)$en['square_feet'];
    $prod += $q * (float)$en['rate'];
    $sf += (float)$en['square_feet'];
}

$ast = $pdo->prepare('SELECT * FROM adjustments WHERE installer_id = ? AND week_start = ? ORDER BY id');
$ast->execute([$uid, $ws]);
$adjs = $ast->fetchAll();
$adjTotal = 0.0;
foreach ($adjs as $a) $adjTotal += (float)$a['amount'];

$prev = date('Y-m-d', strtotime("$ws -7 days"));
$next = date('Y-m-d', strtotime("$ws +7 days"));
?>
<div class="card">
    <div class="weeknav">
        <a class="btn ghost small" href="<?= url("/my-pay?week=$prev") ?>">← Ant.</a>
        <div style="text-align:center">
            <h2 style="margin:0"><?= e($ws) ?> → <?= e($we) ?></h2>
        </div>
        <a class="btn ghost small" href="<?= url("/my-pay?week=$next") ?>">Sig. →</a>
    </div>
    <div class="stats">
        <div class="stat"><span class="num"><?= number_format($sf) ?></span><span class="lbl">SF instalados</span></div>
        <div class="stat"><span class="num"><?= money($prod + $adjTotal) ?></span><span class="lbl">Pago total</span></div>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Mi producción</h2></div>
    <?php if (!$entries && !$adjs): ?>
        <p class="muted">Sin producción registrada esta semana.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Fecha</th><th>Trabajo</th><th class="num">Cant.</th><th class="num">$</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $en):
            $q = $en['unit'] === 'LF' ? (float)$en['linear_feet'] : (float)$en['square_feet'];
            $amt = $q * (float)$en['rate'];
        ?>
            <tr>
                <td><?= e($en['work_date']) ?><br><span class="muted"><?= e($en['label']) ?></span></td>
                <td><?= e($en['job_number']) ?><br><span class="muted"><?= e($en['customer']) ?></span></td>
                <td class="num"><?= number_format($q, 2) ?> <?= e($en['unit']) ?></td>
                <td class="num"><?= money($amt) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($adjs as $a): ?>
            <tr>
                <td><?= e($ws) ?><br><span class="muted">Ajuste</span></td>
                <td><?= e($a['reason']) ?></td>
                <td class="num">—</td>
                <td class="num"><?= money((float)$a['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="3">Total</td>
            <td class="num"><?= money($prod + $adjTotal) ?></td>
        </tr>
        </tbody>
    </table>
    <?php endif; ?>
    <p class="muted">Para registrar producción, entra al trabajo en la pestaña Trabajos.</p>
</div>

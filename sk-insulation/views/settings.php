<?php
declare(strict_types=1);
// SK Insulation — Ajustes (Fase 2): tarifas de pago y catálogo de materiales. Solo manager.
$title = 'Ajustes';
$pdo = Database::pdo();
$action = $_GET['action'] ?? 'rates';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pa = $_POST['post_action'] ?? '';

    // Guardar tarifas
    if ($pa === 'save_rates') {
        $upd = $pdo->prepare('UPDATE pay_rates SET rate = ?, active = ? WHERE work_type = ?');
        foreach (($_POST['rate'] ?? []) as $wt => $rate) {
            $upd->execute([(float)$rate, isset($_POST['active'][$wt]) ? 1 : 0, $wt]);
        }
        flash('ok', 'Tarifas actualizadas.');
        redirect('/settings?action=rates');
    }

    // Agregar material al catálogo
    if ($pa === 'add_material') {
        $name = trim($_POST['name'] ?? '');
        $brand = $_POST['brand'] ?? '';
        if ($name === '' || !in_array($brand, ['Owens Corning', 'Knauf', 'Mixed'], true)) {
            flash('error', 'El nombre y la marca son obligatorios.');
            redirect('/settings?action=materials');
        }
        $num = fn($k) => trim($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
        $pdo->prepare('INSERT INTO materials (brand, product_code, name, r_value, thickness_in, width_in, length_ft, sf_per_bag) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$brand, trim($_POST['product_code'] ?? '') ?: null, $name, trim($_POST['r_value'] ?? '') ?: null,
                $num('thickness_in'), $num('width_in'), $num('length_ft'), $num('sf_per_bag')]);
        flash('ok', 'Material agregado al catálogo.');
        redirect('/settings?action=materials');
    }

    // Activar / desactivar material
    if ($pa === 'toggle_material') {
        $mid = (int)($_POST['mid'] ?? 0);
        $pdo->prepare('UPDATE materials SET active = 1 - active WHERE id = ?')->execute([$mid]);
        redirect('/settings?action=materials');
    }
}

if ($action === 'materials') {
    $mats = $pdo->query('SELECT * FROM materials ORDER BY active DESC, brand, name')->fetchAll();
    ?>
    <div class="card">
        <div class="card-head">
            <h2>Catálogo de materiales</h2>
            <a class="link" href="<?= url('/settings?action=rates') ?>">Ver tarifas →</a>
        </div>
        <table class="tbl">
            <thead><tr><th>Material</th><th>Valor R</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($mats as $m): ?>
                <tr>
                    <td><strong><?= e($m['brand']) ?></strong><br><?= e($m['name']) ?><?= $m['product_code'] ? '<br><span class="muted">' . e($m['product_code']) . '</span>' : '' ?></td>
                    <td><?= e($m['r_value'] ?? '—') ?></td>
                    <td><?= $m['active'] ? '<span class="badge completed">Activo</span>' : '<span class="badge">Inactivo</span>' ?></td>
                    <td><form class="inline" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_action" value="toggle_material">
                        <input type="hidden" name="mid" value="<?= $m['id'] ?>">
                        <button class="btn ghost small" type="submit"><?= $m['active'] ? 'Desactivar' : 'Activar' ?></button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <div class="card-head"><h2>Agregar material</h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="add_material">
            <div class="row2">
                <label>Marca *
                    <select name="brand" required>
                        <option>Owens Corning</option>
                        <option>Knauf</option>
                        <option>Mixed</option>
                    </select>
                </label>
                <label>Código<input name="product_code" placeholder="Ej: OC-R13-15"></label>
            </div>
            <label>Nombre *<input name="name" required placeholder="Ej: EcoTouch R-13 Kraft Batts"></label>
            <div class="row2">
                <label>Valor R<input name="r_value" placeholder="R-13"></label>
                <label>Grosor (pulg)<input type="number" name="thickness_in" step="0.01" min="0"></label>
            </div>
            <div class="row2">
                <label>Ancho (pulg)<input type="number" name="width_in" step="0.01" min="0"></label>
                <label>Largo (pies)<input type="number" name="length_ft" step="0.01" min="0"></label>
            </div>
            <label>SF por bolsa<input type="number" name="sf_per_bag" step="0.01" min="0"></label>
            <button class="btn primary" type="submit">Agregar material</button>
        </form>
    </div>
    <?php
    return;
}

// Tarifas (vista por defecto)
$rates = $pdo->query('SELECT * FROM pay_rates ORDER BY label')->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <h2>Tarifas de pago</h2>
        <a class="link" href="<?= url('/settings?action=materials') ?>">Ver materiales →</a>
    </div>
    <p class="muted">El pago se calcula como cantidad × tarifa. Nota: el cálculo usa la tarifa vigente al momento de consultar, así que cambiar una tarifa también afecta cómo se ven semanas pasadas.</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="post_action" value="save_rates">
        <table class="tbl">
            <thead><tr><th>Tipo de trabajo</th><th class="num">Tarifa</th><th>Unidad</th><th>Activo</th></tr></thead>
            <tbody>
            <?php foreach ($rates as $r): ?>
                <tr>
                    <td><?= e($r['label']) ?><br><span class="muted"><?= e($r['work_type']) ?></span></td>
                    <td class="num"><input type="number" name="rate[<?= e($r['work_type']) ?>]" step="0.01" min="0" value="<?= e((string)$r['rate']) ?>" style="width:100px;text-align:right"></td>
                    <td><?= e($r['unit']) ?></td>
                    <td><input type="checkbox" name="active[<?= e($r['work_type']) ?>]" <?= $r['active'] ? 'checked' : '' ?>></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button class="btn primary" type="submit" style="margin-top:12px">Guardar tarifas</button>
    </form>
</div>

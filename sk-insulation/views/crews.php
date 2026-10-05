<?php
declare(strict_types=1);
// SK Insulation — Cuadrillas (Fase 2). Solo manager.
$title = 'Cuadrillas';
$pdo = Database::pdo();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

$installers = $pdo->query(
    "SELECT id, name FROM users WHERE role = 'installer' AND active = 1 ORDER BY name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'create_crew') {
        $name = trim($_POST['name'] ?? '');
        $lead = trim($_POST['lead_user_id'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO crews (name, lead_user_id) VALUES (?, ?)')
                ->execute([$name, $lead !== '' ? (int)$lead : null]);
            flash('ok', 'Cuadrilla creada.');
        } else {
            flash('error', 'El nombre es obligatorio.');
        }
        redirect('/crews');
    }

    if ($pa === 'add_member') {
        $cid = (int)($_POST['crew_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($cid > 0 && $uid > 0) {
            $pdo->prepare('INSERT IGNORE INTO crew_members (crew_id, user_id) VALUES (?, ?)')
                ->execute([$cid, $uid]);
            flash('ok', 'Miembro agregado.');
        }
        redirect("/crews?action=view&id=$cid");
    }

    if ($pa === 'remove_member') {
        $cid = (int)($_POST['crew_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare('DELETE FROM crew_members WHERE crew_id = ? AND user_id = ?')->execute([$cid, $uid]);
        $pdo->prepare('UPDATE crews SET lead_user_id = NULL WHERE id = ? AND lead_user_id = ?')->execute([$cid, $uid]);
        redirect("/crews?action=view&id=$cid");
    }

    if ($pa === 'set_lead') {
        $cid = (int)($_POST['crew_id'] ?? 0);
        $uid = trim($_POST['user_id'] ?? '');
        $pdo->prepare('UPDATE crews SET lead_user_id = ? WHERE id = ?')
            ->execute([$uid !== '' ? (int)$uid : null, $cid]);
        flash('ok', 'Líder actualizado.');
        redirect("/crews?action=view&id=$cid");
    }

    if ($pa === 'delete_crew') {
        $cid = (int)($_POST['crew_id'] ?? 0);
        $pdo->prepare('DELETE FROM crews WHERE id = ?')->execute([$cid]);
        flash('ok', 'Cuadrilla eliminada.');
        redirect('/crews');
    }
}

if ($action === 'view' && $id > 0) {
    $st = $pdo->prepare('SELECT c.*, u.name AS lead_name FROM crews c LEFT JOIN users u ON u.id = c.lead_user_id WHERE c.id = ?');
    $st->execute([$id]);
    $crew = $st->fetch();
    if (!$crew) { echo '<div class="card"><p class="muted">Cuadrilla no encontrada.</p></div>'; return; }
    $title = 'Cuadrilla: ' . $crew['name'];
    $st = $pdo->prepare(
        'SELECT u.id, u.name, u.phone FROM crew_members cm
         JOIN users u ON u.id = cm.user_id WHERE cm.crew_id = ? ORDER BY u.name'
    );
    $st->execute([$id]);
    $members = $st->fetchAll();
    $memberIds = array_column($members, 'id');
    ?>
    <div class="card">
        <div class="card-head"><h2><?= e($crew['name']) ?></h2><a href="<?= url('/crews') ?>">← Volver</a></div>
        <p class="muted">Líder: <strong><?= e($crew['lead_name'] ?? '—') ?></strong> · Miembros: <strong><?= count($members) ?></strong></p>
        <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="set_lead">
            <input type="hidden" name="crew_id" value="<?= $id ?>">
            <label style="display:inline-block;min-width:220px">Cambiar líder
                <select name="user_id" onchange="this.form.submit()">
                    <option value="">— Sin líder —</option>
                    <?php foreach ($members as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= (int)($crew['lead_user_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>Miembros</h2></div>
        <?php if (!$members): ?>
            <p class="muted">Sin miembros todavía.</p>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Nombre</th><th>Teléfono</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($members as $m): ?>
                <tr>
                    <td><?= e($m['name']) ?><?= (int)($crew['lead_user_id'] ?? 0) === (int)$m['id'] ? ' ⭐' : '' ?></td>
                    <td><?= e($m['phone'] ?? '—') ?></td>
                    <td><form class="inline" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_action" value="remove_member">
                        <input type="hidden" name="crew_id" value="<?= $id ?>">
                        <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                        <button class="btn ghost small" type="submit">Quitar</button>
                    </form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <form method="post" style="margin-top:12px">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="add_member">
            <input type="hidden" name="crew_id" value="<?= $id ?>">
            <label>Agregar instalador
                <select name="user_id" required>
                    <option value="">— Seleccionar —</option>
                    <?php foreach ($installers as $ins): ?>
                        <?php if (in_array($ins['id'], $memberIds, true)) continue; ?>
                        <option value="<?= $ins['id'] ?>"><?= e($ins['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn primary" type="submit">+ Agregar</button>
        </form>
    </div>

    <div class="card">
        <form method="post" onsubmit="return confirm('¿Eliminar esta cuadrilla?')">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="delete_crew">
            <input type="hidden" name="crew_id" value="<?= $id ?>">
            <button class="btn danger small" type="submit">Eliminar cuadrilla</button>
        </form>
    </div>
    <?php
    return;
}

$crews = $pdo->query(
    'SELECT c.*, u.name AS lead_name, (SELECT COUNT(*) FROM crew_members cm WHERE cm.crew_id = c.id) AS members
     FROM crews c LEFT JOIN users u ON u.id = c.lead_user_id WHERE c.active = 1 ORDER BY c.name'
)->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <h2>Cuadrillas</h2>
        <a class="btn ghost small" href="<?= url('/installers') ?>">Gestionar instaladores</a>
    </div>
    <?php if (!$crews): ?>
        <p class="muted">No hay cuadrillas. Crea la primera abajo.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Nombre</th><th>Líder</th><th class="num">Miembros</th></tr></thead>
        <tbody>
        <?php foreach ($crews as $c): ?>
            <tr>
                <td><a class="link" href="<?= url("/crews?action=view&id={$c['id']}") ?>"><?= e($c['name']) ?></a></td>
                <td><?= e($c['lead_name'] ?? '—') ?></td>
                <td class="num"><?= (int)$c['members'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h2>Nueva cuadrilla</h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="post_action" value="create_crew">
        <label>Nombre *<input name="name" required placeholder="Ej: Cuadrilla A"></label>
        <label>Líder (opcional)
            <select name="lead_user_id">
                <option value="">— Sin líder —</option>
                <?php foreach ($installers as $ins): ?>
                    <option value="<?= $ins['id'] ?>"><?= e($ins['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Crear cuadrilla</button>
    </form>
</div>

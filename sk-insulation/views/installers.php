<?php
declare(strict_types=1);
// SK Insulation — Instaladores / usuarios (Fase 2). Solo manager.
$title = 'Instaladores';
$pdo = Database::pdo();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pa = $_POST['post_action'] ?? '';

    if ($pa === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'installer') === 'manager' ? 'manager' : 'installer';
        if ($name === '' || $email === '' || $password === '') {
            flash('error', 'Nombre, correo y contraseña son obligatorios.');
            redirect('/installers?action=new');
        }
        $chk = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            flash('error', 'Ese correo ya está registrado.');
            redirect('/installers?action=new');
        }
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone) VALUES (?,?,?,?,?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, trim($_POST['phone'] ?? '') ?: null]);
        flash('ok', 'Usuario creado. Compártele su correo y contraseña.');
        redirect('/installers');
    }

    if ($pa === 'update_user') {
        $uid = (int)($_POST['id'] ?? 0);
        if ($uid === (int)$user['id']) {
            flash('error', 'No puedes desactivarte ni cambiar tu propio rol aquí.');
            redirect("/installers?action=edit&id=$uid");
        }
        $role = ($_POST['role'] ?? 'installer') === 'manager' ? 'manager' : 'installer';
        $pdo->prepare('UPDATE users SET name = ?, phone = ?, role = ?, active = ? WHERE id = ?')
            ->execute([
                trim($_POST['name'] ?? ''), trim($_POST['phone'] ?? '') ?: null,
                $role, isset($_POST['active']) ? 1 : 0, $uid,
            ]);
        flash('ok', 'Usuario actualizado.');
        redirect('/installers');
    }

    if ($pa === 'reset_password') {
        $uid = (int)($_POST['id'] ?? 0);
        $password = $_POST['password'] ?? '';
        if ($password === '') {
            flash('error', 'Escribe la nueva contraseña.');
            redirect("/installers?action=edit&id=$uid");
        }
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $uid]);
        flash('ok', 'Contraseña actualizada.');
        redirect('/installers');
    }
}

if ($action === 'new') {
    $title = 'Nuevo usuario';
    ?>
    <div class="card">
        <div class="card-head"><h2>Nuevo usuario</h2><a href="<?= url('/installers') ?>">← Volver</a></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="create_user">
            <label>Nombre *<input name="name" required></label>
            <div class="row2">
                <label>Correo *<input type="email" name="email" required></label>
                <label>Teléfono<input name="phone"></label>
            </div>
            <div class="row2">
                <label>Contraseña *<input type="password" name="password" required minlength="6"></label>
                <label>Rol
                    <select name="role">
                        <option value="installer">Instalador</option>
                        <option value="manager">Manager</option>
                    </select>
                </label>
            </div>
            <button class="btn primary big" type="submit">Crear usuario</button>
        </form>
    </div>
    <?php
    return;
}

if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u) { echo '<div class="card"><p class="muted">Usuario no encontrado.</p></div>'; return; }
    $title = 'Editar: ' . $u['name'];
    $self = (int)$u['id'] === (int)$user['id'];
    ?>
    <div class="card">
        <div class="card-head"><h2><?= e($u['name']) ?></h2><a href="<?= url('/installers') ?>">← Volver</a></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="update_user">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <label>Nombre<input name="name" value="<?= e($u['name']) ?>" required></label>
            <label>Teléfono<input name="phone" value="<?= e($u['phone'] ?? '') ?>"></label>
            <div class="row2">
                <label>Rol
                    <select name="role" <?= $self ? 'disabled' : '' ?>>
                        <option value="installer" <?= $u['role'] === 'installer' ? 'selected' : '' ?>>Instalador</option>
                        <option value="manager" <?= $u['role'] === 'manager' ? 'selected' : '' ?>>Manager</option>
                    </select>
                </label>
                <label class="checklist" style="padding-top:26px"><label><input type="checkbox" name="active" <?= $u['active'] ? 'checked' : '' ?> <?= $self ? 'disabled' : '' ?>> Activo</label></label>
            </div>
            <p class="muted"><?= e($u['email']) ?><?php if ($self): ?> · (tu propia cuenta: rol y estado bloqueados aquí)<?php endif; ?></p>
            <button class="btn primary" type="submit">Guardar cambios</button>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><h2>Cambiar contraseña</h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="reset_password">
            <input type="hidden" name="id" value="<?= $u['id'] ?>">
            <label>Nueva contraseña<input type="password" name="password" required minlength="6"></label>
            <button class="btn ghost" type="submit">Actualizar contraseña</button>
        </form>
    </div>
    <?php
    return;
}

$users = $pdo->query('SELECT * FROM users ORDER BY active DESC, role, name')->fetchAll();
?>
<div class="card">
    <div class="card-head">
        <h2>Usuarios</h2>
        <a class="btn primary small" href="<?= url('/installers?action=new') ?>">+ Nuevo</a>
    </div>
    <table class="tbl">
        <thead><tr><th>Nombre</th><th>Rol</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><a class="link" href="<?= url("/installers?action=edit&id={$u['id']}") ?>"><?= e($u['name']) ?></a><br><span class="muted"><?= e($u['email']) ?></span></td>
                <td><span class="badge"><?= e($u['role']) ?></span></td>
                <td><?= $u['active'] ? '<span class="badge completed">Activo</span>' : '<span class="badge">Inactivo</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="muted">Los instaladores entran con su correo y contraseña y solo ven Panel, Trabajos y Mi Pago.</p>
</div>

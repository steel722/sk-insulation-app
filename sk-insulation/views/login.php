<?php $title = 'Iniciar sesión'; ?>
<div class="login-card">
    <div class="login-brand"><span class="logo big">SK</span><div>Insulation</div></div>
    <?php if (!empty($error)): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= url('/login') ?>">
        <label>Email
            <input type="email" name="email" required autocomplete="username" inputmode="email">
        </label>
        <label>Contraseña
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn primary big" type="submit">Entrar</button>
    </form>
</div>

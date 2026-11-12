<div class="panel">
  <h1>scrap3r</h1>
  <p class="sub">Sign in to continue.</p>
  <?php if (!empty($error)): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Email</label>
    <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
    <label>Password</label>
    <input type="password" name="password" required>
    <div class="actions"><button class="btn" type="submit">Sign in</button></div>
  </form>
</div>

<div class="panel">
  <h1>Set up scrap3r</h1>
  <p class="sub">Create your workspace and its first admin. This page switches itself off afterwards.</p>
  <?php if (!empty($error)): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="key" value="<?= e($key ?? '') ?>">
    <label>Company name <span class="hint">&mdash; shown at the top of your emails</span></label>
    <input type="text" name="company" required maxlength="120" value="<?= e($_POST['company'] ?? '') ?>">
    <label>Your name</label>
    <input type="text" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
    <label>Email <span class="hint">&mdash; used to sign in</span></label>
    <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <label>Password <span class="hint">&mdash; 10 characters minimum</span></label>
    <input type="password" name="password" required minlength="10" autocomplete="new-password">
    <div class="actions"><button class="btn" type="submit">Create workspace</button></div>
  </form>
  <p class="hint small" style="margin-top:16px">You'll start with a sample email campaign to explore. Edit it or delete it any time.</p>
</div>

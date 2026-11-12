<h1>Leads</h1>
<p class="sub"><?= number_format($total) ?> total. Only leads with an email address can be enrolled in a campaign.</p>

<form class="filters" method="get">
  <div style="flex:1;min-width:220px">
    <label>Search</label>
    <input type="text" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Name, company, email, description">
  </div>
  <div>
    <label>Status</label>
    <select name="status">
      <option value="">Any</option>
      <?php foreach (['new','contacted','replied','booked','won','lost','unqualified'] as $s): ?>
        <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Has email</label>
    <select name="has_email">
      <option value="">Any</option>
      <option value="yes" <?= ($_GET['has_email'] ?? '') === 'yes' ? 'selected' : '' ?>>Yes</option>
      <option value="no"  <?= ($_GET['has_email'] ?? '') === 'no'  ? 'selected' : '' ?>>No</option>
    </select>
  </div>
  <div style="min-width:auto">
    <button class="btn ghost" type="submit">Filter</button>
    <a class="btn ghost" href="<?= e(app_url('import.php')) ?>">Import</a>
  </div>
</form>

<?php if (!$leads): ?>
  <div class="panel"><p class="muted">No leads match. <a href="<?= e(app_url('import.php')) ?>">Import some</a> to get started.</p></div>
<?php else: ?>
<form method="post" action="<?= e(app_url('enroll.php')) ?>">
  <?= csrf_field() ?>
  <div class="panel" style="padding:0">
    <div class="table-wrap"><table>
      <tr>
        <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)"></th>
        <th>Lead</th><th>Company</th><th>Email</th><th>Status</th><th>Last emailed</th><th></th>
      </tr>
      <?php foreach ($leads as $l): $p = parse_description($l['description']); ?>
      <tr>
        <td><input class="pick" type="checkbox" name="lead_ids[]" value="<?= (int) $l['id'] ?>"></td>
        <td>
          <strong><?= e($l['name'] ?: $p['name'] ?: 'Unnamed') ?></strong>
          <div class="muted small"><?= e($l['title'] ?: $p['title'] ?: '') ?></div>
        </td>
        <td class="small"><?= e($l['company'] ?: $p['company'] ?: '—') ?></td>
        <td class="small">
          <?php if ($l['email']): ?>
            <?= e($l['email']) ?>
            <?php if ($l['unsubscribed_at']): ?><br><span class="pill pill-lost">unsubscribed</span><?php endif; ?>
          <?php else: ?><span class="muted">— none —</span><?php endif; ?>
        </td>
        <td><span class="pill pill-<?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
        <td class="small muted nowrap"><?= $l['last_emailed_at'] ? e(date('j M y', strtotime($l['last_emailed_at']))) : '—' ?></td>
        <td class="right"><a class="btn ghost sm" href="<?= e(app_url('lead.php?id=' . $l['id'])) ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </div>

  <div class="actions">
    <select name="campaign_id" style="width:auto">
      <option value="">Add selected to campaign…</option>
      <?php foreach ($campaigns as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Enrol</button>
  </div>
</form>

<?php if ($pages > 1): ?>
  <div class="actions">
    <?php for ($i = 1; $i <= min($pages, 12); $i++):
      $qs = http_build_query(array_merge($_GET, ['page' => $i])); ?>
      <a class="btn ghost sm <?= $i === $page ? 'is-on' : '' ?>" href="?<?= e($qs) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

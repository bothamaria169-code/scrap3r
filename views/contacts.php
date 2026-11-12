<h1>Find contacts</h1>
<p class="sub">Named people and their work emails at a company, from Hunter.io.</p>

<?php if (!Hunter::configured()): ?>
  <div class="notice">
    <strong>No Hunter API key yet.</strong> Sign up at hunter.io, copy the key from
    <em>API</em> in your Hunter dashboard, and paste it on the
    <a href="<?= e(app_url('settings.php')) ?>">Settings</a> page.
  </div>
<?php elseif ($accountError): ?>
  <div class="flash flash-err"><?= e($accountError) ?></div>
<?php elseif ($account): ?>
  <div class="grid grid-4" style="margin-bottom:20px">
    <div class="stat"><div class="n"><?= e((string) ($account['searches_left'] ?? '?')) ?></div><div class="l">Search credits left</div></div>
    <div class="stat"><div class="n"><?= e((string) ($account['verifications_left'] ?? '?')) ?></div><div class="l">Verification credits left</div></div>
    <div class="stat"><div class="n" style="font-size:18px"><?= e((string) $account['plan']) ?></div><div class="l">Hunter plan</div></div>
  </div>
<?php endif; ?>

<div class="panel">
  <h2>Search a company</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="search">
    <label>Company domain or name <span class="hint">— a domain is more accurate, e.g. standardbank.co.za</span></label>
    <input type="text" name="target" required value="<?= e($prefill) ?>" placeholder="standardbank.co.za">

    <div class="grid grid-4">
      <div>
        <label>Department</label>
        <select name="department">
          <option value="">Any</option>
          <?php foreach (Hunter::DEPARTMENTS as $d): ?>
            <option value="<?= e($d) ?>"><?= e($d === 'it' || $d === 'hr' ? strtoupper($d) : ucfirst($d)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Seniority</label>
        <select name="seniority">
          <option value="">Any</option>
          <?php foreach (Hunter::SENIORITIES as $s): ?>
            <option value="<?= e($s) ?>"><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Job titles <span class="hint">— optional</span></label>
        <input type="text" name="job_titles" placeholder="CFO, Head of Procurement">
      </div>
      <div>
        <label>How many</label>
        <select name="limit">
          <option value="10">10 — up to 1 credit</option>
          <option value="25">25 — up to 3 credits</option>
          <option value="50">50 — up to 5 credits</option>
          <option value="100">100 — up to 10 credits</option>
        </select>
      </div>
    </div>

    <div class="actions">
      <button class="btn" type="submit" <?= Hunter::configured() ? '' : 'disabled' ?>>Search</button>
      <span class="small muted">Hunter charges 1 credit per 10 addresses returned. Empty results are free.</span>
    </div>
  </form>
</div>

<?php if ($results && $results['emails']): ?>
<div class="panel">
  <h2>
    <?= e($results['organization'] ?: $results['query']) ?>
    <?php if ($results['domain']): ?><span class="muted small">(<?= e($results['domain']) ?>)</span><?php endif; ?>
  </h2>
  <p class="small muted">
    Showing <?= count($results['emails']) ?> of <?= (int) $results['total'] ?> known people.
    <?php if ($results['pattern']): ?>
      Email pattern: <span class="mono"><?= e($results['pattern']) ?>@<?= e($results['domain']) ?></span>.
    <?php endif; ?>
    <?php if ($results['accept_all']): ?>
      This domain accepts all mail, so individual addresses can't be fully confirmed.
    <?php endif; ?>
    High-confidence contacts are ticked for you.
  </p>

  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div class="table-wrap"><table>
      <tr>
        <th style="width:28px"><input type="checkbox" onclick="document.querySelectorAll('.pick:not(:disabled)').forEach(c=>c.checked=this.checked)"></th>
        <th>Name</th><th>Position</th><th>Email</th><th>Confidence</th><th>Verified</th><th>Phone</th>
      </tr>
      <?php foreach ($results['emails'] as $i => $c):
        $isExisting = isset($existing[$c['email']]);
        $isSuppressed = isset($suppressed[$c['email']]);
        $tick = !$isExisting && !$isSuppressed && $c['confidence'] >= 70; ?>
      <tr>
        <td><input class="pick" type="checkbox" name="pick[]" value="<?= (int) $i ?>" <?= $tick ? 'checked' : '' ?> <?= $isSuppressed ? 'disabled' : '' ?>></td>
        <td>
          <strong><?= e($c['name'] ?? '—') ?></strong>
          <?php if ($isExisting): ?><br><span class="pill pill-contacted">already a lead</span><?php endif; ?>
          <?php if ($isSuppressed): ?><br><span class="pill pill-lost">unsubscribed</span><?php endif; ?>
        </td>
        <td class="small"><?= e($c['position'] ?? '') ?>
          <?php if ($c['department'] || $c['seniority']): ?>
            <div class="muted"><?= e(trim(($c['seniority'] ?? '') . ' ' . ($c['department'] ?? ''))) ?></div>
          <?php endif; ?></td>
        <td class="small"><?= e($c['email']) ?></td>
        <td><span class="pill <?= $c['confidence'] >= 90 ? 'pill-booked' : ($c['confidence'] >= 70 ? 'pill-contacted' : 'pill-replied') ?>"><?= (int) $c['confidence'] ?>%</span></td>
        <td class="small"><?= e($c['status'] ?? '—') ?></td>
        <td class="small nowrap"><?= e($c['phone'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <div class="actions">
      <button class="btn" type="submit">Import selected as leads</button>
      <span class="small muted">Importing is free — the credits were spent on the search.</span>
    </div>
  </form>
  <form method="post" style="margin-top:8px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="clear">
    <button class="btn ghost sm" type="submit">Clear results</button>
  </form>
</div>
<?php endif; ?>

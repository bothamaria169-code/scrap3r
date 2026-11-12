<h1>Dashboard</h1>
<p class="sub">Pipeline at a glance.</p>

<?php if (!Settings::smtpReady()): ?>
  <div class="notice">
    <strong>SMTP is not configured yet.</strong> Campaigns will not send until you fill in
    the mail server details on the <a href="<?= e(app_url('settings.php')) ?>">Settings</a> page.
  </div>
<?php endif; ?>

<?php if ($stats['leads'] > 0 && $stats['mailable'] === 0): ?>
  <div class="notice">
    <strong>No lead has an email address.</strong> The ZoomInfo scraper feed does not include
    contact details, so nothing can be emailed until you add addresses —
    <a href="<?= e(app_url('import.php')) ?>">import a CSV</a> or edit leads individually.
  </div>
<?php endif; ?>

<div class="grid grid-4" style="margin-bottom:20px">
  <div class="stat"><div class="n"><?= $stats['leads'] ?></div><div class="l">Leads</div></div>
  <div class="stat"><div class="n"><?= $stats['mailable'] ?></div><div class="l">With an email</div></div>
  <div class="stat"><div class="n"><?= $stats['sent_7d'] ?></div><div class="l">Sent, last 7 days</div></div>
  <div class="stat"><div class="n"><?= $stats['booked'] ?></div><div class="l">Upcoming meetings</div></div>
  <div class="stat"><div class="n"><?= $stats['suppressed'] ?></div><div class="l">Unsubscribed</div></div>
  <div class="stat"><div class="n"><?= $stats['failed_24h'] ?></div><div class="l">Failed sends, 24h</div></div>
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>Next meetings</h2>
    <?php if (!$upcoming): ?>
      <p class="muted small">Nothing booked yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>When</th><th>Lead</th><th>Rep</th></tr>
        <?php foreach ($upcoming as $a): ?>
        <tr>
          <td class="nowrap"><?= e(date('D j M, g:i A', strtotime($a['starts_at']))) ?></td>
          <td><?= e($a['lead_name'] ?: $a['email'] ?: 'Lead #' . $a['lead_id']) ?><br>
              <span class="muted small"><?= e($a['company'] ?? '') ?></span></td>
          <td class="small"><?= e($a['rep']) ?></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Recent sends</h2>
    <?php if (!$recent): ?>
      <p class="muted small">No mail sent yet.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>When</th><th>To</th><th>Status</th></tr>
        <?php foreach ($recent as $m): ?>
        <tr>
          <td class="nowrap small"><?= e(date('j M H:i', strtotime($m['created_at']))) ?></td>
          <td class="small"><?= e($m['lead_name'] ?: $m['to_email']) ?><br>
              <span class="muted"><?= e($m['subject']) ?></span></td>
          <td><span class="pill pill-<?= e($m['status']) ?>"><?= e($m['status']) ?></span>
            <?php if ($m['error']): ?><div class="muted small"><?= e(mb_strimwidth($m['error'], 0, 70, '…')) ?></div><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>
</div>

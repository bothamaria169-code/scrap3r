<?php $p = parse_description($lead['description']); ?>
<h1><?= e($lead['name'] ?: $p['name'] ?: 'Unnamed lead') ?></h1>
<p class="sub">
  <?= e($lead['title'] ?: $p['title'] ?: '') ?>
  <?php if ($lead['company'] || $p['company']): ?> · <?= e($lead['company'] ?: $p['company']) ?><?php endif; ?>
  <?php if ($lead['source_url']): ?> · <a href="<?= e($lead['source_url']) ?>" target="_blank" rel="noopener">ZoomInfo profile</a><?php endif; ?>
</p>

<?php if ($lead['unsubscribed_at']): ?>
  <div class="notice"><strong>This person opted out on <?= e(date('j M Y', strtotime($lead['unsubscribed_at']))) ?>.</strong>
  They are on the suppression list and cannot be emailed.</div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="panel">
    <h2>Details</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
      <label>Name</label><input type="text" name="name" value="<?= e($lead['name'] ?: $p['name']) ?>">
      <label>Title</label><input type="text" name="title" value="<?= e($lead['title'] ?: $p['title']) ?>">
      <label>Company</label><input type="text" name="company" value="<?= e($lead['company'] ?: $p['company']) ?>">
      <label>Email <span class="hint">— not supplied by the scraper; add it here</span></label>
      <input type="email" name="email" value="<?= e($lead['email']) ?>">
      <label>Phone</label><input type="text" name="phone" value="<?= e($lead['phone']) ?>">
      <label>Status</label>
      <select name="status">
        <?php foreach (['new','contacted','replied','booked','won','lost','unqualified'] as $s): ?>
          <option value="<?= $s ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select>
      <label>Owner</label>
      <select name="owner_id">
        <option value="">Unassigned</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int) $u['id'] ?>" <?= (int) $lead['owner_id'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="actions">
        <button class="btn" type="submit">Save</button>
        <?php if ($lead['email'] && !$lead['unsubscribed_at']): ?>
          <button class="btn ghost" type="submit" name="action" value="suppress"
                  onclick="return confirm('Add this address to the do-not-contact list?')">Suppress</button>
        <?php endif; ?>
      </div>
    </form>
    <?php if ($lead['description']): ?>
      <p class="muted small" style="margin-top:16px"><strong>Scraped description:</strong><br><?= e($lead['description']) ?></p>
    <?php endif; ?>
  </div>

  <div>
    <div class="panel">
      <h2>Email lookup</h2>
      <?php
        $personName = $lead['name'] ?: $p['name'];
        $companyTarget = $lead['website'] ?: ($lead['company'] ?: $p['company']);
      ?>
      <?php if (!Hunter::configured()): ?>
        <p class="small muted">Add a Hunter.io API key under <a href="<?= e(app_url('settings.php')) ?>">Settings</a> to find and verify email addresses.</p>
      <?php elseif ($lead['email']): ?>
        <p class="small">
          <strong><?= e($lead['email']) ?></strong>
          <?php if ($lead['email_confidence'] !== null): ?> &middot; <?= (int) $lead['email_confidence'] ?>% confidence<?php endif; ?>
          <?php if ($lead['email_status']): ?>
            &middot; <span class="pill <?= $lead['email_status'] === 'valid' ? 'pill-booked' : ($lead['email_status'] === 'invalid' ? 'pill-failed' : 'pill-replied') ?>"><?= e($lead['email_status']) ?></span>
          <?php endif; ?>
          <?php if ($lead['email_source']): ?><span class="muted"> &middot; from <?= e($lead['email_source']) ?></span><?php endif; ?>
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="hunter_verify">
          <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
          <button class="btn ghost" type="submit">Verify address <span class="muted small">(1 verification credit)</span></button>
        </form>
      <?php elseif ($personName && $lead['record_type'] !== 'company'): ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="hunter_find">
          <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
          <label>Find <?= e((string) $personName) ?>'s email at <span class="hint">&mdash; company domain or name</span></label>
          <input type="text" name="target" required value="<?= e((string) $companyTarget) ?>" placeholder="company.co.za">
          <div class="actions"><button class="btn" type="submit">Find email <span class="small">(1 credit, only if found)</span></button></div>
        </form>
      <?php else: ?>
        <p class="small muted">This is a company record with no person attached, so there is no one to look up.</p>
        <?php if ($companyTarget): ?>
          <a class="btn" href="<?= e(app_url('contacts.php?q=' . urlencode((string) $companyTarget))) ?>">Find people at <?= e((string) ($lead['company'] ?: $p['company'])) ?></a>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php $sendable = is_valid_email($lead['email']) && !$lead['unsubscribed_at'] && $lead['email_status'] !== 'invalid'; ?>
    <?php if ($sendable && $templates): ?>
    <div class="panel">
      <h2>Send an email now</h2>
      <p class="small muted">Sends one template straight away &mdash; for example the brochure when someone replies that they're interested.</p>
      <form method="post" onsubmit="return confirm(<?= e(json_encode('Send this email to ' . $lead['email'] . ' now?')) ?>)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_template">
        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
        <label>Template</label>
        <select name="template_id" required>
          <?php foreach ($templates as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?><?= $t['attachment_name'] ? ' (with attachment)' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="actions"><button class="btn" type="submit">Send now</button></div>
      </form>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Book an appointment</h2>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="id" value="<?= (int) $lead['id'] ?>">
        <label>Rep</label>
        <select name="user_id" required>
          <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
        </select>
        <label>Starts at <span class="hint">— in the rep's timezone</span></label>
        <input type="datetime-local" name="starts_at" required>
        <label>Notes</label>
        <input type="text" name="notes" placeholder="Optional">
        <div class="actions"><button class="btn" type="submit">Book</button></div>
      </form>
      <?php if ($lead['email'] && !$lead['unsubscribed_at']): ?>
        <p class="small muted" style="margin-top:14px">Self-booking link for this lead:<br>
          <span class="mono"><?= e(Tokens::bookingUrl((int) $lead['id'])) ?></span></p>
      <?php endif; ?>
    </div>

    <?php if ($appointments): ?>
    <div class="panel">
      <h2>Appointments</h2>
      <div class="table-wrap"><table>
        <tr><th>When</th><th>Rep</th><th>Source</th><th>Status</th></tr>
        <?php foreach ($appointments as $a): ?>
        <tr>
          <td class="nowrap small"><?= e(date('j M Y, g:i A', strtotime($a['starts_at']))) ?></td>
          <td class="small"><?= e($a['rep']) ?></td>
          <td class="small muted"><?= e($a['source']) ?></td>
          <td><span class="pill pill-<?= $a['status'] === 'scheduled' ? 'booked' : 'lost' ?>"><?= e($a['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>

    <?php if ($enrollments): ?>
    <div class="panel">
      <h2>Campaigns</h2>
      <div class="table-wrap"><table>
        <tr><th>Campaign</th><th>Step</th><th>Status</th><th>Next</th></tr>
        <?php foreach ($enrollments as $en): ?>
        <tr>
          <td class="small"><?= e($en['campaign']) ?></td>
          <td class="small"><?= (int) $en['current_step'] ?></td>
          <td class="small"><?= e($en['status']) ?><?php if ($en['stop_reason']): ?><br><span class="muted"><?= e($en['stop_reason']) ?></span><?php endif; ?></td>
          <td class="small muted nowrap"><?= $en['next_send_at'] ? e(date('j M H:i', strtotime($en['next_send_at']))) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h2>Message history</h2>
  <?php if (!$messages): ?>
    <p class="muted small">Nothing sent to this lead yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>Subject</th><th>Status</th><th>Detail</th></tr>
    <?php foreach ($messages as $m): ?>
    <tr>
      <td class="nowrap small"><?= e(date('j M y H:i', strtotime($m['created_at']))) ?></td>
      <td class="small"><?= e($m['subject']) ?></td>
      <td><span class="pill pill-<?= e($m['status']) ?>"><?= e($m['status']) ?></span></td>
      <td class="small muted"><?= e($m['error'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

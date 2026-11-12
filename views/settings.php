<h1>Settings</h1>
<p class="sub">Mail server, sender identity and the compliance footer.</p>

<div class="grid grid-2">
  <div class="panel">
    <h2>SMTP</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <label>Host</label>
      <input type="text" name="smtp_host" value="<?= e($settings['smtp_host']) ?>" placeholder="smtp.gmail.com">
      <div class="grid grid-2">
        <div><label>Port</label><input type="number" name="smtp_port" value="<?= e($settings['smtp_port']) ?>"></div>
        <div><label>Security</label>
          <select name="smtp_secure">
            <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25)'] as $v => $t): ?>
              <option value="<?= $v ?>" <?= $settings['smtp_secure'] === $v ? 'selected' : '' ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <label>Username</label>
      <input type="text" name="smtp_user" value="<?= e($settings['smtp_user']) ?>" autocomplete="off">
      <label>Password <span class="hint">— leave blank to keep the stored one</span></label>
      <input type="password" name="smtp_pass" value="" autocomplete="new-password"
             placeholder="<?= $settings['smtp_pass'] !== '' ? '•••••••• stored' : 'not set' ?>">

      <h2 style="margin-top:26px">Sender identity</h2>
      <label>From name</label>
      <input type="text" name="from_name" value="<?= e($settings['from_name']) ?>">
      <label>From email</label>
      <input type="email" name="from_email" value="<?= e($settings['from_email']) ?>">
      <label>Reply-to <span class="hint">— optional</span></label>
      <input type="email" name="reply_to" value="<?= e($settings['reply_to']) ?>">
      <label>Email signature <span class="hint">&mdash; HTML; inserted wherever a template uses {{signature}}</span></label>
      <textarea name="signature_html" style="min-height:120px"><?= e($settings['signature_html']) ?></textarea>

      <h2 style="margin-top:26px">Brand</h2>
      <p class="hint small" style="margin-top:-6px">Every email design uses these unless it sets its own.</p>
      <label>Logo <span class="hint">&mdash; PNG or JPG, shown at the top of your emails</span></label>
      <?php if ($settings['brand_logo'] !== ''): ?>
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px">
          <img src="<?= e(app_url($settings['brand_logo'])) ?>" alt="Current logo" style="max-height:40px;max-width:200px">
          <label style="font-weight:400;margin:0"><input type="checkbox" name="remove_logo" value="1" style="width:auto"> Remove</label>
        </div>
      <?php endif; ?>
      <input type="file" name="brand_logo_file" accept=".png,.jpg,.jpeg,.gif,.webp">
      <div class="grid grid-2">
        <div><label>Brand colour</label>
          <input type="color" name="brand_color" value="<?= e($settings['brand_color'] ?: '#4f46e5') ?>" style="width:64px;height:38px;padding:3px"></div>
        <div><label>Header text <span class="hint">&mdash; when there's no logo</span></label>
          <input type="text" name="brand_header" value="<?= e($settings['brand_header']) ?>" placeholder="<?= e($settings['company_name'] ?: 'Your company') ?>"></div>
      </div>

      <h2 style="margin-top:26px">Hunter.io</h2>
      <label>API key <span class="hint">&mdash; from hunter.io &rarr; API. Leave blank to keep the stored one</span></label>
      <input type="password" name="hunter_api_key" value="" autocomplete="off"
             placeholder="<?= $settings['hunter_api_key'] !== '' ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull; stored' : 'not set' ?>">

      <h2 style="margin-top:26px">Footer</h2>
      <label>Company name</label>
      <input type="text" name="company_name" value="<?= e($settings['company_name']) ?>">
      <label>Postal address <span class="hint">— required in commercial email</span></label>
      <textarea name="company_address" style="min-height:80px"><?= e($settings['company_address']) ?></textarea>
      <label>Daily send cap <span class="hint">— across all campaigns</span></label>
      <input type="number" name="daily_send_cap" value="<?= e($settings['daily_send_cap']) ?>" min="1">

      <div class="actions"><button class="btn" type="submit">Save settings</button></div>
    </form>
  </div>

  <div>
    <div class="panel">
      <h2>Send a test</h2>
      <p class="small muted">Opens a real SMTP session using the saved settings and shows the transcript.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test">
        <label>Send test to</label>
        <input type="email" name="test_to" required value="<?= e($_POST['test_to'] ?? '') ?>">
        <div class="actions"><button class="btn ghost" type="submit">Send test</button></div>
      </form>
      <?php if ($testResult): ?>
        <div class="flash flash-<?= $testResult['ok'] ? 'ok' : 'err' ?>" style="margin-top:14px">
          <?= $testResult['ok'] ? 'Success' : 'Failed' ?>
        </div>
        <pre class="log"><?= e($testResult['text']) ?></pre>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Sales reps</h2>
      <div class="table-wrap"><table>
        <tr><th>Name</th><th>Email</th><th>Role</th><th>Timezone</th><th>Slot</th></tr>
        <?php foreach ($reps as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td>
          <td class="small"><?= e($r['email']) ?></td>
          <td class="small"><?= e($r['role']) ?></td>
          <td class="small muted"><?= e($r['timezone']) ?></td>
          <td class="small"><?= (int) $r['slot_minutes'] ?> min</td>
        </tr>
        <?php endforeach; ?>
      </table></div>
      <p class="small muted" style="margin-top:10px">Add reps with
        <span class="mono">php bin/add_user.php</span>.</p>
    </div>

    <div class="panel">
      <h2>Suppression list <span class="muted small">(<?= Suppression::count() ?>)</span></h2>
      <?php if (!$suppressions): ?>
        <p class="muted small">Nobody has opted out.</p>
      <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Email</th><th>Reason</th><th>When</th></tr>
        <?php foreach ($suppressions as $s): ?>
        <tr><td class="small"><?= e($s['email']) ?></td>
            <td class="small"><?= e($s['reason']) ?></td>
            <td class="small muted nowrap"><?= e(date('j M y', strtotime($s['created_at']))) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

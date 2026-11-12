<h1><?= e($campaign['name']) ?></h1>
<p class="sub">
  <span class="pill pill-<?= e($campaign['status']) ?>"><?= e($campaign['status']) ?></span>
  · <?= count($steps) ?> step(s) · <?= count($enrolled) ?> enrolled
</p>

<div class="actions" style="margin-bottom:20px">
  <?php foreach (['running' => 'Start', 'paused' => 'Pause', 'draft' => 'Back to draft', 'done' => 'Mark done'] as $s => $labelText): ?>
    <?php if ($campaign['status'] !== $s): ?>
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="status" value="<?= $s ?>">
      <button class="btn <?= $s === 'running' ? '' : 'ghost' ?>" type="submit"><?= $labelText ?></button>
    </form>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>Sequence</h2>
    <?php if (!$steps): ?>
      <p class="muted small">No steps yet. Step 1 sends as soon as a lead is enrolled.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>#</th><th>Template</th><th>Delay</th><th></th></tr>
        <?php foreach ($steps as $s): ?>
        <tr>
          <td><?= (int) $s['step_no'] ?></td>
          <td><a href="<?= e(app_url('composer.php?id=' . (int) $s['template_id'])) ?>"><strong><?= e($s['template_name']) ?></strong></a><div class="muted small"><?= e($s['subject']) ?></div></td>
          <td class="small nowrap"><?= (int) $s['step_no'] === 1 ? 'immediately' : '+' . (int) $s['delay_hours'] . ' h' ?></td>
          <td class="right">
            <form method="post" onsubmit="return confirm('Remove this step?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
              <input type="hidden" name="action" value="delete_step">
              <input type="hidden" name="step_id" value="<?= (int) $s['id'] ?>">
              <button class="btn ghost sm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <form method="post" style="margin-top:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
      <input type="hidden" name="action" value="add_step">
      <label>Add step — template</label>
      <select name="template_id" required>
        <?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
      </select>
      <label>Delay after previous step <span class="hint">— hours; ignored for step 1</span></label>
      <input type="number" name="delay_hours" value="72" min="0">
      <div class="actions"><button class="btn ghost" type="submit">Add step</button></div>
    </form>
  </div>

  <div class="panel">
    <h2>Sending settings</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
      <input type="hidden" name="action" value="settings">
      <label>Name</label>
      <input type="text" name="name" value="<?= e($campaign['name']) ?>" required>
      <label>From name</label>
      <input type="text" name="from_name" value="<?= e($campaign['from_name']) ?>">
      <label>From email <span class="hint">— the SMTP account's address is used to send</span></label>
      <input type="email" name="from_email" value="<?= e($campaign['from_email']) ?>">
      <label>Reply-to</label>
      <input type="email" name="reply_to" value="<?= e($campaign['reply_to']) ?>">
      <label>Throttle <span class="hint">— emails per hour</span></label>
      <input type="number" name="throttle_per_hour" min="1" max="500" value="<?= (int) $campaign['throttle_per_hour'] ?>">
      <div class="grid grid-2">
        <div><label>Send window from</label>
          <input type="time" name="send_window_start" value="<?= e(substr($campaign['send_window_start'], 0, 5)) ?>"></div>
        <div><label>to</label>
          <input type="time" name="send_window_end" value="<?= e(substr($campaign['send_window_end'], 0, 5)) ?>"></div>
      </div>
      <label style="font-weight:400;margin-top:12px">
        <input type="checkbox" name="skip_weekends" style="width:auto" <?= $campaign['skip_weekends'] ? 'checked' : '' ?>>
        Skip weekends
      </label>
      <div class="actions"><button class="btn" type="submit">Save</button></div>
    </form>
  </div>
</div>

<div class="panel">
  <h2>Enrolled leads</h2>
  <p class="small muted">Add leads from the <a href="<?= e(app_url('leads.php')) ?>">Leads</a> page.
    A lead that books or unsubscribes is stopped automatically.</p>
  <?php if (!$enrolled): ?>
    <p class="muted small">Nobody enrolled yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Lead</th><th>Email</th><th>Step</th><th>Status</th><th>Next send</th><th></th></tr>
    <?php foreach ($enrolled as $en): ?>
    <tr>
      <td><?= e($en['name'] ?: 'Unnamed') ?><div class="muted small"><?= e($en['company'] ?? '') ?></div></td>
      <td class="small"><?= e($en['email']) ?></td>
      <td><?= (int) $en['current_step'] ?></td>
      <td class="small"><?= e($en['status']) ?>
        <?php if ($en['stop_reason']): ?><div class="muted"><?= e($en['stop_reason']) ?></div><?php endif; ?></td>
      <td class="small muted nowrap"><?= $en['next_send_at'] ? e(date('j M H:i', strtotime($en['next_send_at']))) : '—' ?></td>
      <td class="right">
        <form method="post" onsubmit="return confirm('Remove this lead from the campaign?')">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $campaign['id'] ?>">
          <input type="hidden" name="action" value="unenroll">
          <input type="hidden" name="enrollment_id" value="<?= (int) $en['id'] ?>">
          <button class="btn ghost sm" type="submit">Remove</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

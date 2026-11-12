<h1>Appointments</h1>
<p class="sub">Meetings booked by reps or by leads through their own booking link.</p>

<div class="actions" style="margin-bottom:16px">
  <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $k => $labelText): ?>
    <a class="btn <?= $scope === $k ? '' : 'ghost' ?> sm" href="?scope=<?= $k ?>"><?= $labelText ?></a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <?php if (!$appointments): ?>
    <p class="muted small">Nothing here.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>Lead</th><th>Rep</th><th>Source</th><th>Status</th><th></th></tr>
    <?php foreach ($appointments as $a): ?>
    <tr>
      <td class="nowrap"><?= e(date('D j M Y, g:i A', strtotime($a['starts_at']))) ?>
        <div class="muted small"><?= e($a['timezone']) ?></div></td>
      <td>
        <a href="<?= e(app_url('lead.php?id=' . $a['lead_id'])) ?>"><?= e($a['lead_name'] ?: $a['email'] ?: 'Lead') ?></a>
        <div class="muted small"><?= e($a['company'] ?? '') ?></div>
      </td>
      <td class="small"><?= e($a['rep']) ?></td>
      <td class="small muted"><?= $a['source'] === 'self' ? 'self-booked' : 'manual' ?></td>
      <td><span class="pill pill-<?= $a['status'] === 'scheduled' ? 'booked' : ($a['status'] === 'completed' ? 'won' : 'lost') ?>"><?= e(str_replace('_', ' ', $a['status'])) ?></span></td>
      <td class="right">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="appointment_id" value="<?= (int) $a['id'] ?>">
          <select name="status" style="width:auto" onchange="this.form.submit()">
            <?php foreach (['scheduled','completed','cancelled','no_show'] as $s): ?>
              <option value="<?= $s ?>" <?= $a['status'] === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

<h1>Campaigns</h1>
<p class="sub">A campaign is an ordered set of steps. The worker sends whatever is due.</p>

<div class="panel">
  <h2>New campaign</h2>
  <form method="post">
    <?= csrf_field() ?>
    <label>Name</label>
    <input type="text" name="name" required placeholder="Q4 outbound — fintech">
    <div class="actions"><button class="btn" type="submit">Create</button></div>
  </form>
</div>

<div class="panel">
  <h2>All campaigns</h2>
  <?php if (!$campaigns): ?>
    <p class="muted small">None yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Name</th><th>Status</th><th>Steps</th><th>Enrolled</th><th>Active</th><th>Sent</th><th>Owner</th><th></th></tr>
    <?php foreach ($campaigns as $c): ?>
    <tr>
      <td><strong><?= e($c['name']) ?></strong></td>
      <td><span class="pill pill-<?= e($c['status']) ?>"><?= e($c['status']) ?></span></td>
      <td><?= (int) $c['steps'] ?></td>
      <td><?= (int) $c['enrolled'] ?></td>
      <td><?= (int) $c['active'] ?></td>
      <td><?= (int) $c['sent'] ?></td>
      <td class="small muted"><?= e($c['owner'] ?? '—') ?></td>
      <td class="right"><a class="btn ghost sm" href="<?= e(app_url('campaign.php?id=' . $c['id'])) ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

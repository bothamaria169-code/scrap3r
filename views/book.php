<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $booked ? 'You are booked' : 'Pick a time' ?></title>
<link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body>
<main style="max-width:640px">
<?php if ($booked): ?>
  <div class="panel">
    <h1>You're booked</h1>
    <p class="sub">
      <?= e(date('l j F Y, g:i A', strtotime($booked['starts_at']))) ?>
      (<?= e($rep['timezone']) ?>) with <?= e($rep['name']) ?>.
    </p>
    <p class="muted small">A calendar invitation will follow by email. If you need to move it, just reply to that message.</p>
  </div>
<?php else: ?>
  <div class="panel">
    <h1>Pick a time with <?= e($rep['name'] ?? 'our team') ?></h1>
    <p class="sub">
      <?php $n = $record['lead_name'] ?: parse_description($record['description'])['name']; ?>
      <?= $n ? 'Hi ' . e(explode(' ', (string) $n)[0]) . ' — c' : 'C' ?>hoose whichever slot suits you.
      Times shown in <strong><?= e($rep['timezone'] ?? 'UTC') ?></strong>.
    </p>

    <?php if (!empty($error)): ?><div class="flash flash-err"><?= e($error) ?></div><?php endif; ?>

    <?php if (!$slots): ?>
      <p class="muted">There are no open slots in the next two weeks. Reply to the email and we'll find a time.</p>
    <?php else: ?>
      <form method="post" id="f">
        <input type="hidden" name="t" value="<?= e($token) ?>">
        <input type="hidden" name="slot" id="slot">
        <?php foreach ($slots as $day => $daySlots): ?>
          <div class="day-head"><?= e(date('l j F', strtotime($day))) ?></div>
          <div class="slots">
            <?php foreach ($daySlots as $s): ?>
              <button type="button" onclick="document.getElementById('slot').value='<?= e($s['start']) ?>';document.getElementById('f').submit()">
                <?= e($s['label']) ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>
</main>
</body>
</html>

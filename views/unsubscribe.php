<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Unsubscribe</title>
<link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body>
<main style="max-width:520px">
  <div class="panel">
    <h1>Unsubscribe</h1>
    <p class="sub">Confirm and we'll stop emailing <strong><?= e($record['lead_email'] ?: 'this address') ?></strong> immediately.</p>
    <form method="post">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <div class="actions"><button class="btn" type="submit">Unsubscribe me</button></div>
    </form>
  </div>
</main>
</body>
</html>

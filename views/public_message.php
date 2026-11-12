<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($heading) ?></title>
<link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body>
<main style="max-width:560px">
  <div class="panel">
    <h1><?= e($heading) ?></h1>
    <p class="sub"><?= e($body) ?></p>
  </div>
</main>
</body>
</html>

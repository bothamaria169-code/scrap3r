<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preview: <?= e($tpl['name']) ?></title>
<link rel="stylesheet" href="<?= e(app_url('assets/app.css')) ?>">
</head>
<body style="margin:0">
<div style="background:#1c2430;color:#e6ebf1;padding:12px 20px;font-size:13px;display:flex;gap:20px;flex-wrap:wrap;align-items:center">
  <strong style="color:#fff">Preview</strong>
  <span><span style="color:#9aa5b1">Subject:</span> <?= e($mail['subject']) ?></span>
  <span><span style="color:#9aa5b1">As sent to:</span> <?= e((string) ($lead['name'] ?: ($lead['email'] ?: 'lead #' . $lead['id']))) ?></span>
  <?php if (!empty($tpl['attachment_name'])): ?>
    <span><span style="color:#9aa5b1">Attachment:</span> <?= e($tpl['attachment_name']) ?></span>
  <?php endif; ?>
  <a href="<?= e(app_url('templates.php?edit=' . (int) $tpl['id'])) ?>" style="color:#9cc2ff;margin-left:auto">Edit template</a>
</div>
<!-- Sandboxed so the email's own styles and links can't touch the app. -->
<iframe title="Email preview" sandbox="allow-popups allow-popups-to-escape-sandbox"
        srcdoc="<?= e('<base target="_blank">' . $mail['html']) ?>"
        style="width:100%;height:calc(100vh - 46px);border:0;background:#fff"></iframe>
</body>
</html>

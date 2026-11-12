<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit: <?= e($tpl['name']) ?> · scrap3r</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('assets/composer.css')) ?>">
</head>
<body class="composer-body">

<header class="cbar">
  <a class="cbar-back" href="<?= e(app_url('templates.php')) ?>" title="Back to email designs">&larr;</a>
  <input id="f-name" class="cbar-name" aria-label="Design name" maxlength="160">
  <span id="save-state" class="cbar-state">Saved</span>
  <div class="cbar-actions">
    <button type="button" id="btn-preview" class="btn ghost">Preview</button>
    <button type="button" id="btn-send" class="btn soft">Send&hellip;</button>
    <button type="button" id="btn-save" class="btn">Save</button>
  </div>
</header>

<div class="cgrid">
  <aside class="cside cleft">
    <h3>Add a block</h3>
    <div class="blocklist" id="blocklist"></div>

    <h3>Style</h3>
    <div class="style-grid">
      <label>Brand colour
        <span class="swatch-row">
          <input type="color" id="s-brand">
          <label class="check"><input type="checkbox" id="s-brand-inherit"> Workspace</label>
        </span>
      </label>
      <label>Background <input type="color" id="s-bg"></label>
      <label>Header text <input type="text" id="s-header" maxlength="80"></label>
      <label>Logo
        <span class="logo-row">
          <span id="s-logo-preview" class="logo-preview"></span>
          <button type="button" class="btn ghost sm" id="s-logo-btn">Upload</button>
          <button type="button" class="btn ghost sm" id="s-logo-clear" title="Use the workspace logo">Reset</button>
        </span>
      </label>
    </div>
    <p class="hint small">Leave colour and logo on <em>Workspace</em> and every email picks up the brand set in Settings.</p>
  </aside>

  <section class="ccenter">
    <div class="rt" id="rt" aria-label="Text formatting">
      <button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
      <button type="button" data-cmd="underline" title="Underline"><u>U</u></button>
      <span class="rt-sep"></span>
      <button type="button" data-cmd="link" title="Link">Link</button>
      <button type="button" data-cmd="insertUnorderedList" title="Bullet list">&bull; List</button>
      <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
      <span class="rt-sep"></span>
      <select id="rt-merge" title="Insert a personal detail">
        <option value="">Insert field&hellip;</option>
      </select>
      <span class="rt-hint">Click any text in the email to edit it.</span>
    </div>
    <div class="canvas-scroll" id="canvas-scroll">
      <div class="email-bg" id="email-bg">
        <div class="legacy-note" id="legacy-note" hidden>
          This email was written in HTML, so it opens as one <strong>Custom HTML</strong> block.
          <button type="button" class="btn soft sm" id="legacy-convert">Put it in the branded layout</button>
        </div>
        <div class="email-card" id="email-card">
          <div class="email-head" id="email-head"></div>
          <div class="email-body" id="canvas"></div>
        </div>
      </div>
    </div>
  </section>

  <aside class="cside cright" id="inspector"></aside>
</div>

<div class="modal" id="preview-modal" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <div>
        <div class="muted small">Subject</div>
        <strong id="pv-subject"></strong>
      </div>
      <div class="seg" role="group" aria-label="Device">
        <button type="button" data-width="700" class="is-on">Desktop</button>
        <button type="button" data-width="390">Mobile</button>
      </div>
      <button type="button" class="btn ghost sm" id="pv-close">Close</button>
    </div>
    <div class="modal-body">
      <iframe id="pv-frame" title="Email preview" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>
    </div>
    <p class="muted small modal-foot">Shown as sent to a sample contact, Thandi Mokoena at Standard Bank.</p>
  </div>
</div>

<div class="modal" id="send-modal" hidden>
  <div class="modal-card send-card">
    <div class="modal-head">
      <div><strong>Send this email</strong><div class="muted small">It goes out through your mailbox, a few at a time.</div></div>
      <button type="button" class="btn ghost sm" id="sd-close">Close</button>
    </div>
    <div class="send-body">
      <label>Who gets it
        <select id="sd-status">
          <option value="">Everyone with an email address</option>
          <option value="new">Contacts marked New (not emailed yet)</option>
          <option value="contacted">Contacted</option>
          <option value="replied">Replied</option>
          <option value="booked">Booked</option>
          <option value="won">Won</option>
        </select>
      </label>
      <div class="send-count"><span id="sd-count">&hellip;</span><span id="sd-note" class="muted small"></span></div>
      <p class="hint small">People who unsubscribed, or whose address failed verification, are always left out.
        Delivery runs through the scheduled sender: up to 30 an hour, weekdays 08:00&ndash;18:00, so your mailbox isn&rsquo;t flagged for bulk sending.</p>
      <div class="actions">
        <button type="button" class="btn" id="sd-go" disabled>Send</button>
        <button type="button" class="btn ghost" id="sd-cancel">Cancel</button>
      </div>
    </div>
  </div>
</div>

<input type="file" id="file-image" accept=".png,.jpg,.jpeg,.gif,.webp" hidden>
<input type="file" id="file-attachment" accept=".pdf,.doc,.docx,.xlsx,.png,.jpg,.jpeg" hidden>
<div class="toast" id="toast" hidden></div>

<script type="application/json" id="composer-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="<?= e(asset_url('assets/composer.js')) ?>"></script>
</body>
</html>

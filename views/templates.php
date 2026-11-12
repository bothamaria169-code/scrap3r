<div class="page-head">
  <div>
    <h1>Email designs</h1>
    <p class="sub">Every email you send, designed visually. Campaign steps and one-off sends use these.</p>
  </div>
  <form method="post" class="new-design">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <select name="from" aria-label="Start from">
      <option value="blank">Blank email</option>
      <optgroup label="Sample: workshop campaign">
        <?php foreach ($samples as $key => $s): ?>
          <option value="sample:<?= e($key) ?>"><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </optgroup>
    </select>
    <button class="btn" type="submit">+ New design</button>
  </form>
</div>

<?php if (!$templates): ?>
  <div class="empty">
    <h3>No email designs yet</h3>
    <p>Start from a blank email, or from the sample workshop campaign to see how a high-reply sequence is put together.</p>
  </div>
<?php else: ?>
<div class="design-grid">
  <?php foreach ($templates as $t): ?>
  <article class="design-card">
    <a class="design-thumb" href="<?= e(app_url('composer.php?id=' . (int) $t['id'])) ?>" aria-label="Edit <?= e($t['name']) ?>">
      <iframe tabindex="-1" aria-hidden="true" sandbox="" loading="lazy" srcdoc="<?= e($t['thumb']) ?>"></iframe>
      <span class="design-open">Open in editor</span>
    </a>
    <div class="design-meta">
      <h3 title="<?= e($t['name']) ?>"><?= e($t['name']) ?></h3>
      <p class="muted small design-subject" title="<?= e($t['subject']) ?>"><?= e($t['subject']) ?></p>
      <div class="design-tags">
        <?php if ((int) $t['is_sample']): ?><span class="pill pill-contacted">Sample</span><?php endif; ?>
        <?php if ($t['attachment_name']): ?><span class="pill pill-new" title="<?= e($t['attachment_name']) ?>">📎 Attachment</span><?php endif; ?>
        <?php if ((int) $t['used_in']): ?><span class="pill pill-running">In <?= (int) $t['used_in'] ?> campaign<?= (int) $t['used_in'] > 1 ? 's' : '' ?></span><?php endif; ?>
        <?php if (!$t['design_json']): ?><span class="pill pill-replied">HTML</span><?php endif; ?>
      </div>
      <div class="design-actions">
        <a class="btn sm" href="<?= e(app_url('composer.php?id=' . (int) $t['id'])) ?>">Edit</a>
        <a class="btn ghost sm" href="<?= e(app_url('templates.php?preview=' . (int) $t['id'])) ?>" target="_blank" rel="noopener">Preview</a>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="duplicate">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="btn ghost sm" type="submit">Duplicate</button>
        </form>
        <form method="post" onsubmit="return confirm('Delete this email design?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
          <button class="btn ghost sm" type="submit" title="Delete">Delete</button>
        </form>
      </div>
      <p class="muted small design-updated">Updated <?= e(date('j M Y, H:i', strtotime($t['updated_at']))) ?></p>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
.new-design { display: flex; gap: 8px; align-items: center; }
.new-design select { width: auto; min-width: 230px; }
.design-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 20px; }
.design-card { background: var(--panel); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow); display: flex; flex-direction: column; transition: box-shadow .15s, transform .15s; }
.design-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-2px); }
.design-thumb { position: relative; display: block; height: 230px; overflow: hidden; background: #f4f4f5; border-bottom: 1px solid var(--line); }
.design-thumb iframe { width: 650px; height: 820px; border: 0; transform: scale(.42); transform-origin: 0 0; pointer-events: none; background: #f4f4f5; }
.design-open { position: absolute; inset: 0; display: grid; place-items: center; background: rgba(15,23,42,.45); color: #fff; font-weight: 600; opacity: 0; transition: opacity .15s; }
.design-thumb:hover .design-open { opacity: 1; }
.design-thumb:hover { text-decoration: none; }
.design-meta { padding: 14px 16px 12px; display: flex; flex-direction: column; gap: 6px; flex: 1; }
.design-meta h3 { margin: 0; font-size: 14.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.design-subject { margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.design-tags { display: flex; gap: 6px; flex-wrap: wrap; min-height: 22px; }
.design-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
.design-actions form { display: inline; }
.design-updated { margin: 2px 0 0; font-size: 12px; }
</style>

<h1>Import leads</h1>
<p class="sub">Three ways in. Only the CSV route supplies email addresses.</p>

<div class="notice">
  <strong>The ZoomInfo feed carries no contact details.</strong>
  Scraper imports give you names, titles, companies and profile URLs for targeting —
  but no email or phone. Add addresses by CSV upload or by editing a lead directly.
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>1. Run the scraper</h2>
    <?php if (!LeadImporter::scraperAvailable()): ?>
      <div class="notice"><?= e(LeadImporter::SCRAPER_UNAVAILABLE) ?></div>
    <?php endif; ?>
    <p class="small muted">Calls the Bright Data API through the Python collector, then imports the result.</p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="scrape">
      <label>ZoomInfo URLs <span class="hint">— one per line</span></label>
      <textarea name="urls" style="min-height:110px" placeholder="https://www.zoominfo.com/c/google-llc/34518"></textarea>
      <div class="actions"><button class="btn" type="submit" <?= LeadImporter::scraperAvailable() ? '' : 'disabled' ?>>Scrape and import</button></div>
    </form>
    <?php if (!empty($scraperLog)): ?>
      <p class="small muted" style="margin-top:12px">Scraper output:</p>
      <pre class="log"><?= e($scraperLog) ?></pre>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>2. Upload a CSV</h2>
    <p class="small muted">Needs an email column. Exports from Apollo, Hunter and similar tools work as-is: headers like <code>First Name</code>/<code>Last Name</code>, <code>Company Name</code>, <code>Email address</code> and <code>Mobile Phone</code> are recognised. Our own format: <code>email</code>, <code>name</code>,
      <code>title</code>, <code>company</code>, <code>phone</code>, <code>source_url</code>,
      <code>description</code>. Rows matching an existing profile URL or address update that lead
      instead of duplicating it.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="csv">
      <label>CSV file</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <div class="actions"><button class="btn" type="submit">Upload</button></div>
    </form>
  </div>
</div>

<div class="panel">
  <h2>3. Import an existing scraper JSON file</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="json">
    <label>Path <span class="hint">— defaults to output/zoominfo_data.json</span></label>
    <input type="text" name="json_path" placeholder="<?= e(BASE_PATH . '/output/zoominfo_data.json') ?>">
    <div class="actions"><button class="btn ghost" type="submit">Import file</button></div>
  </form>
</div>

<div class="panel">
  <h2>Recent imports</h2>
  <?php if (!$imports): ?>
    <p class="muted small">Nothing imported yet.</p>
  <?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>When</th><th>Source</th><th>File</th><th>Seen</th><th>New</th><th>Updated</th><th>Skipped</th><th>Blocked</th><th>By</th></tr>
    <?php foreach ($imports as $i): ?>
    <tr>
      <td class="nowrap small"><?= e(date('j M y H:i', strtotime($i['created_at']))) ?></td>
      <td class="small"><?= e($i['source']) ?></td>
      <td class="small muted"><?= e($i['filename'] ?? '—') ?></td>
      <td><?= (int) $i['rows_seen'] ?></td>
      <td><?= (int) $i['rows_created'] ?></td>
      <td><?= (int) $i['rows_updated'] ?></td>
      <td><?= (int) $i['rows_skipped'] ?></td>
      <td><?= (int) ($i['rows_errored'] ?? 0) ?></td>
      <td class="small muted"><?= e($i['who'] ?? '—') ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>

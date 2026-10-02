<?php
require_once __DIR__ . '/lib.php';
$pageTitle = 'Videos';
$cameraFilter = (string)($_GET['camera'] ?? '');
$allRecordings = list_recordings();
$recordings = $allRecordings;
if ($cameraFilter !== '') {
    $recordings = array_values(array_filter($recordings, fn($r) => $r['camera'] === $cameraFilter));
}
$cameras = array_values(array_unique(array_column($allRecordings, 'camera')));
$protected = protected_files();

$status = [];
try { $status = helper_request('GET', '/status'); } catch (Throwable $e) {}
$disk = $status['disk'] ?? ['total'=>0,'used'=>0,'free'=>0,'percent_used'=>0];

require __DIR__ . '/header.php';
?>
<div class="page-head">
  <div>
    <h1>Recordings</h1>
    <p class="muted"><?= count($recordings) ?> clips</p>
  </div>
  <form method="get" class="inline-filter">
    <select name="camera" onchange="this.form.submit()">
      <option value="">All cameras</option>
      <?php foreach ($cameras as $cam): ?>
      <option value="<?= h($cam) ?>" <?= $cam === $cameraFilter ? 'selected' : '' ?>><?= h($cam) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (($disk['total'] ?? 0) > 0): ?>
<div class="disk-card <?= (($disk['percent_used'] ?? 0) >= 85) ? 'warn' : '' ?>">
  <div><strong>Recording storage</strong><span><?= h(human_bytes((int)$disk['used'])) ?> used of <?= h(human_bytes((int)$disk['total'])) ?></span></div>
  <div class="meter"><i style="width:<?= min(100,(float)$disk['percent_used']) ?>%"></i></div>
  <strong><?= h((string)$disk['percent_used']) ?>%</strong>
</div>
<?php endif; ?>

<?php if (!$recordings): ?>
<div class="empty">No MP4 recordings found.</div>
<?php else: ?>
<div class="video-grid">
<?php foreach ($recordings as $r):
  $isProtected = isset($protected[$r['path']]);
?>
  <article class="video-card">
    <a class="thumb-wrap" href="video.php?f=<?= rawurlencode($r['path']) ?>">
      <img loading="lazy" src="thumb.php?f=<?= rawurlencode($r['path']) ?>" alt="">
      <span class="play">▶</span>
      <?php if ($isProtected): ?><span class="protected-badge">Protected</span><?php endif; ?>
    </a>
    <div class="video-meta">
      <strong><?= h($r['camera']) ?></strong>
      <span><?= h(date('M j, Y g:i:s A', $r['mtime'])) ?></span>
      <span><?= h(human_bytes((int)$r['size'])) ?></span>
      <div class="card-actions">
        <a class="button-link" href="video.php?f=<?= rawurlencode($r['path']) ?>">Play</a>
        <a class="button-link secondary-link" href="download.php?f=<?= rawurlencode($r['path']) ?>">Download</a>
        <?php if (is_admin()): ?>
        <form method="post" action="action.php">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="file" value="<?= h($r['path']) ?>">
          <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
          <input type="hidden" name="action" value="<?= $isProtected ? 'unprotect' : 'protect' ?>">
          <button class="secondary" type="submit"><?= $isProtected ? 'Unprotect' : 'Protect' ?></button>
        </form>
        <?php if (!$isProtected): ?>
        <form method="post" action="action.php" onsubmit="return confirm('Delete this recording?');">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="file" value="<?= h($r['path']) ?>">
          <input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI']) ?>">
          <input type="hidden" name="action" value="delete">
          <button class="danger" type="submit">Delete</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/footer.php'; ?>

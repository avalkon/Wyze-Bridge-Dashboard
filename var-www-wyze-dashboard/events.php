<?php
require_once __DIR__ . '/lib.php';
$pageTitle = 'Events';
$events = read_events();
$recordings = list_recordings();
require __DIR__ . '/header.php';
?>
<div class="page-head">
  <div>
    <h1>Motion events</h1>
    <p class="muted"><?= count($events) ?> recorded events</p>
  </div>
</div>

<div class="timeline">
<?php foreach ($events as $e):
  $clip = recording_for_event($e, $recordings, 900);
?>
<article class="event">
  <div class="event-dot"></div>
  <div class="event-time"><?= h(date('M j, Y', (int)$e['ts'])) ?><strong><?= h(date('g:i:s A', (int)$e['ts'])) ?></strong></div>
  <div class="event-card">
    <div>
      <strong><?= h((string)$e['camera']) ?></strong>
      <span class="muted"><?= h((string)($e['type'] ?? 'motion')) ?></span>
    </div>
    <?php if ($clip): ?>
    <div class="event-preview">
      <img loading="lazy" src="thumb.php?f=<?= rawurlencode($clip['path']) ?>" alt="">
      <div>
        <a class="button-link" href="video.php?f=<?= rawurlencode($clip['path']) ?>">Open nearby clip</a>
        <a href="index.php?camera=<?= rawurlencode((string)$e['camera']) ?>">All <?= h((string)$e['camera']) ?> videos</a>
      </div>
    </div>
    <?php else: ?>
    <span class="muted">No nearby recording found.</span>
    <?php endif; ?>
  </div>
</article>
<?php endforeach; ?>
<?php if (!$events): ?><div class="empty">No events recorded yet.</div><?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>

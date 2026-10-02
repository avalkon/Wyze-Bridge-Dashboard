<?php
require_once __DIR__ . '/lib.php';
require_admin();
$pageTitle = 'Settings';
$message = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save') {
            $days = array_values(array_unique(array_map('intval', $_POST['days'] ?? [])));
            $settings = [
                'segment_seconds' => max(30, min(3600, (int)($_POST['segment_seconds'] ?? 300))),
                'delete_unprotected_after_minutes' => max(1, min(10080, (int)($_POST['delete_after'] ?? 30))),
                'motion_before_seconds' => max(0, min(3600, (int)($_POST['before'] ?? 300))),
                'motion_after_seconds' => max(0, min(3600, (int)($_POST['after'] ?? 300))),
                'keep_motion_days' => max(1, min(3650, (int)($_POST['keep_motion_days'] ?? 30))),
                'emergency_cleanup_enabled' => isset($_POST['emergency_cleanup_enabled']),
                'emergency_cleanup_percent' => max(80, min(99, (int)($_POST['emergency_cleanup_percent'] ?? 92))),
                'emergency_cleanup_target_percent' => max(50, min(98, (int)($_POST['emergency_cleanup_target_percent'] ?? 85))),
                'cameras' => [
                    trim((string)($_POST['camera'] ?? 'shop-cam')) => [
                        'enabled' => isset($_POST['enabled']),
                        'schedule' => [[
                            'days' => $days ?: [0,1,2,3,4,5,6],
                            'start' => preg_match('/^\d\d:\d\d$/', (string)($_POST['start'] ?? '')) ? $_POST['start'] : '00:00',
                            'end' => preg_match('/^\d\d:\d\d$/', (string)($_POST['end'] ?? '')) ? $_POST['end'] : '23:59',
                        ]]
                    ]
                ]
            ];
            helper_request('POST', '/settings', $settings);
            $message = 'Settings saved. The recorder reloads them automatically.';
        } elseif ($action === 'restart') {
            $target = (string)($_POST['target'] ?? '');
            helper_request('POST', '/restart', ['target' => $target]);
            $message = "Restart requested for {$target}.";
        }
    }

    $settings = helper_request('GET', '/settings');
    $status = helper_request('GET', '/status');
} catch (Throwable $e) {
    $error = $e->getMessage();
    $settings = $settings ?? [];
    $status = $status ?? [];
}

$cameraName = array_key_first($settings['cameras'] ?? ['shop-cam'=>[]]) ?: 'shop-cam';
$cam = $settings['cameras'][$cameraName] ?? [];
$schedule = $cam['schedule'][0] ?? ['days'=>[0,1,2,3,4,5,6],'start'=>'00:00','end'=>'23:59'];
$dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

require __DIR__ . '/header.php';
?>
<div class="page-head"><div><h1>Settings</h1><p class="muted">Recorder and service controls</p></div></div>
<?php if ($message): ?><div class="alert success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>

<?php if (!empty($status['disk']['total'])): ?>
<section class="panel">
<h2>Storage</h2>
<div class="disk-card <?= (($status['disk']['percent_used'] ?? 0) >= 85) ? 'warn' : '' ?>">
  <div><strong>Recording disk</strong><span><?= h(human_bytes((int)$status['disk']['free'])) ?> free</span></div>
  <div class="meter"><i style="width:<?= min(100,(float)$status['disk']['percent_used']) ?>%"></i></div>
  <strong><?= h((string)$status['disk']['percent_used']) ?>%</strong>
</div>
<?php if (($status['disk']['percent_used'] ?? 0) >= 85): ?><div class="alert error">Disk usage is above 85%. Consider shortening retention or freeing space.</div><?php endif; ?>
</section>
<?php endif; ?>

<section class="panel">
<h2>Service status</h2>
<div class="status-grid">
<?php foreach (['wyze-bridge','motion-recorder'] as $name):
    $s = $status['containers'][$name] ?? ['state'=>'unknown'];
?>
<div class="status-card">
  <strong><?= h($name) ?></strong>
  <span class="pill <?= h((string)$s['state']) ?>"><?= h((string)$s['state']) ?></span>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="restart">
    <input type="hidden" name="target" value="<?= h($name) ?>">
    <button class="secondary" type="submit">Restart</button>
  </form>
</div>
<?php endforeach; ?>
</div>
</section>

<section class="panel">
<h2>Recording</h2>
<form method="post" class="settings-form">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<input type="hidden" name="action" value="save">

<label>Camera slug
<input name="camera" value="<?= h($cameraName) ?>" pattern="[A-Za-z0-9_-]+" required>
</label>

<label class="check"><input type="checkbox" name="enabled" <?= !empty($cam['enabled']) ? 'checked' : '' ?>> Recording enabled</label>

<div class="form-grid">
<label>Clip length (seconds)
<input type="number" name="segment_seconds" min="30" max="3600" value="<?= (int)($settings['segment_seconds'] ?? 300) ?>">
</label>

<label>Delete non-motion clips after (minutes)
<input type="number" name="delete_after" min="1" max="10080" value="<?= (int)($settings['delete_unprotected_after_minutes'] ?? 30) ?>">
</label>

<label>Protect before motion (seconds)
<input type="number" name="before" min="0" max="3600" value="<?= (int)($settings['motion_before_seconds'] ?? 300) ?>">
</label>

<label>Protect after motion (seconds)
<input type="number" name="after" min="0" max="3600" value="<?= (int)($settings['motion_after_seconds'] ?? 300) ?>">
</label>

<label>Delete motion clips after (days)
<input type="number" name="keep_motion_days" min="1" max="3650" value="<?= (int)($settings['keep_motion_days'] ?? 30) ?>">
</label>
</div>

<h3>Schedule</h3>
<div class="days">
<?php foreach ($dayNames as $i=>$name): ?>
<label class="day"><input type="checkbox" name="days[]" value="<?= $i ?>" <?= in_array($i, $schedule['days'] ?? [], true) ? 'checked' : '' ?>> <?= $name ?></label>
<?php endforeach; ?>
</div>
<div class="form-grid">
<label>Start
<input type="time" name="start" value="<?= h((string)($schedule['start'] ?? '00:00')) ?>">
</label>
<label>End
<input type="time" name="end" value="<?= h((string)($schedule['end'] ?? '23:59')) ?>">
</label>
</div>
<p class="muted small">Overnight windows are supported, e.g. 18:00–06:00.</p>

<h3>Emergency disk cleanup</h3>
<label class="check"><input type="checkbox" name="emergency_cleanup_enabled" <?=!empty($settings['emergency_cleanup_enabled'])?'checked':''?>> Enable emergency cleanup</label>
<div class="form-grid"><label>Start cleanup at disk usage (%)<input type="number" name="emergency_cleanup_percent" min="80" max="99" value="<?=(int)($settings['emergency_cleanup_percent']??92)?>"></label><label>Stop cleanup below (%)<input type="number" name="emergency_cleanup_target_percent" min="50" max="98" value="<?=(int)($settings['emergency_cleanup_target_percent']??85)?>"></label></div>
<p class="muted small">Deletes oldest unprotected recordings first. Manually protected clips are never deleted by emergency cleanup.</p><button type="submit">Save settings</button>
</form>
</section>
<?php require __DIR__ . '/footer.php'; ?>

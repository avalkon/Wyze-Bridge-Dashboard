<?php
declare(strict_types=1); require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['logged_in'])) { header('Location: index.php'); exit; }
$error='';
if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
 $username=(string)($_POST['username']??''); $password=(string)($_POST['password']??''); $matched=null;
 foreach (load_users() as $u) { if (!is_array($u)||empty($u['enabled'])) continue; if (!hash_equals((string)($u['username']??''),$username)) continue; if (password_verify($password,(string)($u['password_hash']??''))) { $matched=$u; break; } }
 if ($matched) { session_regenerate_id(true); $_SESSION['logged_in']=true; $_SESSION['username']=(string)$matched['username']; $_SESSION['role']=(($matched['role']??'viewer')==='admin')?'admin':'viewer'; $_SESSION['csrf']=bin2hex(random_bytes(32)); $next=(string)($_POST['next']??'index.php'); if (!preg_match('/^[A-Za-z0-9_.?=&%\\/-]+$/',$next)) $next='index.php'; header('Location: '.$next); exit; }
 usleep(350000); $error='Invalid username or password.';
}
$next=(string)($_GET['next']??$_POST['next']??'index.php');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Wyze Recorder Login</title><link rel="stylesheet" href="style.css"></head>
<body class="login-page"><form class="login-card" method="post"><h1>Wyze Recorder</h1><p class="muted">Sign in to view recordings and events.</p><?php if($error):?><div class="alert error"><?=h($error)?></div><?php endif;?>
<label>Username<input name="username" required autofocus autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><input type="hidden" name="next" value="<?=h($next)?>"><button type="submit">Sign in</button></form></body></html>

<?php 
require_once __DIR__.'/auth.php'; 
require_login(); 
$pageTitle=$pageTitle??'Wyze-Dashboard'; 
$user=current_user(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>
<?=h($pageTitle)?>
</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
  <div class="brand">KB Farms</div>
  <nav>
    <a href="index.php">Videos</a>
    <a href="events.php">Events</a>
    <?php if(is_admin()):?>
      <a href="settings.php">Settings</a>
      <a href="users.php">Users</a>
    <?php endif;?>
  <span class="nav-user">
    <?=h((string)($user['username']??''))?>
  </span>
    <a href="logout.php">Logout</a>
  </nav>
</header>
<main class="container">

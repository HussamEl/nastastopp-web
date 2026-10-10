<?php
declare(strict_types=1);

// The owner's page: codes for new devices, the devices and their requests, and a switch to stop
// one. Signed in with the password whose hash is in the config; nothing here shows a key.
require __DIR__ . '/../src/bootstrap.php';

use Nasta\Config;
use Nasta\Store;

session_set_cookie_params(['lifetime' => 0, 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'");

$config = Config::load();
$h = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

if ($posted && ($_POST['do'] ?? '') === 'login') {
    // A wrong password waits a second, so it cannot be guessed fast.
    if (password_verify((string) ($_POST['password'] ?? ''), $config->string('admin_password_hash'))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        sleep(1);
    }
}
if ($posted && ($_POST['do'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

// First setup: no password yet. The page turns the one typed into the line for the config file;
// the password itself is not kept or shown.
$setupLine = null;
$needsSetup = $config->string('admin_password_hash') === '';
if ($needsSetup && $posted && ($_POST['do'] ?? '') === 'setup') {
    $password = (string) ($_POST['password'] ?? '');
    if (strlen($password) >= 12 && $password === (string) ($_POST['again'] ?? '')) {
        $setupLine = "'admin_password_hash' => '" . password_hash($password, PASSWORD_DEFAULT) . "',";
    }
}

$signedIn = !$needsSetup && ($_SESSION['admin'] ?? false) === true;
$newCode = null;
if ($signedIn) {
    $store = Store::open($config);
    if ($posted && ($_POST['do'] ?? '') === 'code') {
        $newCode = $store->newCode((string) ($_POST['label'] ?? 'device'));
    }
    if ($posted && in_array($_POST['do'] ?? '', ['stop', 'start'], true)) {
        $store->setActive((int) ($_POST['device'] ?? 0), $_POST['do'] === 'start');
    }
}
$csrf = $h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Nästa Stopp · الإدارة</title>
<style>
:root { --bg: #0b0d10; --card: #15181d; --edge: #262b33; --fg: #eef1f5; --muted: #9aa3ae; --accent: #6ea8ff; --bad: #ff6b6b; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 16px/1.6 system-ui, sans-serif; }
main { max-width: 960px; margin: 0 auto; padding: 24px 16px; display: grid; gap: 16px; }
section { background: var(--card); border: 1px solid var(--edge); border-radius: 14px; padding: 16px; }
h1 { margin: 0; font-size: 1.4rem; } h2 { margin: 0 0 8px; font-size: 1.1rem; }
input, button { font: inherit; padding: 8px 12px; border-radius: 10px; border: 1px solid var(--edge); background: #0f1216; color: var(--fg); }
button { background: var(--accent); color: #04101f; border: 0; cursor: pointer; font-weight: 600; }
button.quiet { background: transparent; color: var(--muted); border: 1px solid var(--edge); }
table { width: 100%; border-collapse: collapse; font-size: .92rem; } td, th { padding: 8px 6px; border-top: 1px solid var(--edge); text-align: start; vertical-align: top; }
.code { font: 700 2rem/1.2 ui-monospace, monospace; letter-spacing: .15em; color: var(--accent); }
.muted { color: var(--muted); } .off { color: var(--bad); }
.wrap { overflow-x: auto; } form.inline { display: inline; }
</style>
</head>
<body>
<main>
<h1>Nästa Stopp · الإدارة</h1>
<?php if ($needsSetup): ?>
<section>
  <h2>أول مرة: كلمة سر الإدارة</h2>
  <p class="muted">اكتب كلمة سر (12 حرفاً على الأقل) مرتين. تظهر لك سطراً واحداً: انسخه إلى ملف nastastopp-config.php مكان سطر admin_password_hash، ثم افتح الصفحة من جديد.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="setup">
    <input type="password" name="password" autocomplete="new-password" minlength="12" required placeholder="كلمة السر">
    <input type="password" name="again" autocomplete="new-password" minlength="12" required placeholder="مرة ثانية">
    <button>أعطني السطر</button>
  </form>
  <?php if ($setupLine !== null): ?><p><code style="direction:ltr;display:block;word-break:break-all"><?= $h($setupLine) ?></code></p><?php endif; ?>
</section>
<?php elseif (!$signedIn): ?>
<section>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="login">
    <label>كلمة السر <input type="password" name="password" autocomplete="current-password" required></label>
    <button>دخول</button>
  </form>
</section>
<?php else: ?>
<section>
  <h2>رمز لجهاز جديد</h2>
  <p class="muted">يُكتب مرة واحدة في التطبيق، صالح 7 أيام، ولا يُعرض مرة ثانية.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="code">
    <input name="label" placeholder="اسم الجهاز: تابلت السيارة 40274" maxlength="80" required>
    <button>أنشئ رمزاً</button>
  </form>
  <?php if ($newCode !== null): ?><p class="code"><?= $h($newCode) ?></p><?php endif; ?>
  <?php $open = $store->openCodes(); if ($open): ?>
  <p class="muted">رموز لم تُستعمل بعد: <?= $h(implode('، ', array_map(fn ($c) => $c['label'] . ' (حتى ' . substr($c['expires_at'], 0, 10) . ')', $open))) ?></p>
  <?php endif; ?>
</section>
<section>
  <h2>الأجهزة</h2>
  <div class="wrap"><table>
    <tr><th>الجهاز</th><th>النوع</th><th>آخر ظهور (UTC)</th><th>الطلبات اليوم / هذا الشهر</th><th></th></tr>
    <?php foreach ($store->devices() as $d): ?>
    <tr>
      <td><?= $h($d['label']) ?><?= $d['active'] ? '' : ' <span class="off">موقوف</span>' ?></td>
      <td><?= $h($d['role']) ?></td>
      <td class="muted"><?= $h((string) ($d['last_seen'] ?? '—')) ?></td>
      <td><?php foreach ($d['usage'] as $u): ?><?= $h($u['service']) ?>: <?= (int) $u['today'] ?> / <?= (int) $u['month'] ?><br><?php endforeach; ?></td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="device" value="<?= (int) $d['id'] ?>">
          <input type="hidden" name="do" value="<?= $d['active'] ? 'stop' : 'start' ?>">
          <button class="<?= $d['active'] ? 'quiet' : '' ?>"><?= $d['active'] ? 'أوقف' : 'شغّل' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="do" value="logout"><button class="quiet">خروج</button></form>
<?php endif; ?>
</main>
</body>
</html>

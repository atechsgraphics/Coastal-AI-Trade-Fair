<?php
declare(strict_types=1);

/**
 * One-time installer.
 * Creates the database, loads the real event content and makes the first
 * administrator account. Once it has run it locks itself and can be deleted.
 */

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/database/schema.php';

$errors = [];
$done   = false;

/* Already installed? Send the visitor where they actually want to go. */
if (is_installed()) {
    setup_page('Already installed', function (): void {
        ?>
        <p>This website has already been set up, so the installer is locked.</p>
        <p>If you need to start again, delete <code>data/site.db</code> and <code>data/installed.lock</code> on the server, then reload this page.</p>
        <div class="s-actions">
          <a class="s-btn s-btn-primary" href="admin/">Open the control panel</a>
          <a class="s-btn" href="index.php">View the website</a>
        </div>
        <p class="s-note">For security, delete <code>setup.php</code> from the server once the site is live.</p>
        <?php
    });
    exit;
}

/* ------------------------------------------------------------- install */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $name     = trim((string) ($_POST['name'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
        $errors[] = 'The username needs 3–40 characters: letters, numbers, dot, dash or underscore.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters long.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'That email address does not look right.';
    }

    foreach ([DATA_PATH, UPLOAD_PATH] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $errors[] = 'Could not create the folder ' . basename($dir) . '. Please create it manually and make it writable.';
        }
    }

    if (!$errors) {
        try {
            schema_create();
            schema_seed();

            if (table_empty('users')) {
                db_run(
                    'INSERT INTO users (username, name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin', date('Y-m-d H:i:s')]
                );
            }

            setup_protect();
            file_put_contents(LOCK_FILE, 'Installed ' . date('c') . "\n");
            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}

/**
 * Drop small .htaccess guards so the database and uploaded files cannot be
 * fetched or executed directly. Harmless on servers that ignore them.
 */
function setup_protect(): void
{
    $denyIis = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
        . "    <security><requestFiltering><hiddenSegments><add segment=\".\" /></hiddenSegments></requestFiltering></security>\n"
        . "    <authorization><deny users=\"*\" /></authorization>\n  </system.webServer>\n</configuration>\n";

    // The database folder: nothing in it may ever be fetched over HTTP.
    @file_put_contents(DATA_PATH . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    @file_put_contents(DATA_PATH . '/web.config', $denyIis);
    @file_put_contents(DATA_PATH . '/index.html', '');

    // The uploads folder: files may be read, but never executed as code.
    @file_put_contents(
        UPLOAD_PATH . '/.htaccess',
        "php_flag engine off\n"
        . "<FilesMatch \"\\.(php|phtml|php[0-9]|pl|py|cgi|sh|htaccess)$\">\n"
        . "  Require all denied\n"
        . "  <IfModule !mod_authz_core.c>\n    Deny from all\n  </IfModule>\n"
        . "</FilesMatch>\n"
    );
    @file_put_contents(
        UPLOAD_PATH . '/web.config',
        "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
        . "    <handlers><clear /><add name=\"StaticFile\" path=\"*\" verb=\"*\" modules=\"StaticFileModule\" resourceType=\"Either\" requireAccess=\"Read\" /></handlers>\n"
        . "  </system.webServer>\n</configuration>\n"
    );
}

if ($done) {
    setup_page('Setup complete', function (): void {
        ?>
        <p class="s-ok">Your website is ready.</p>
        <p>The database has been created and loaded with the real 2026 event details: dates, venue, contact numbers, sponsors, packages, stall rates, payment details and the programme.</p>
        <p>Sign in to the control panel to change any of it — no code required.</p>
        <div class="s-actions">
          <a class="s-btn s-btn-primary" href="admin/">Sign in to the control panel</a>
          <a class="s-btn" href="index.php">View the website</a>
        </div>
        <p class="s-note"><strong>One last step:</strong> delete <code>setup.php</code> from the server.</p>
        <?php
    });
    exit;
}

/* ------------------------------------------------------------- the form */

setup_page('Set up your website', function () use ($errors): void {
    ?>
    <p>This runs once. It creates the database, loads the 2026 event content and makes your administrator account.</p>

    <?php if ($errors): ?>
      <div class="s-alert">
        <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php
    $php = version_compare(PHP_VERSION, '7.4.0', '>=');
    $pdo = extension_loaded('pdo_sqlite');
    ?>
    <ul class="s-checks">
      <li class="<?= $php ? 'ok' : 'bad' ?>"><span><?= $php ? '✓' : '✕' ?></span> PHP <?= e(PHP_VERSION) ?> <?= $php ? '' : '— version 7.4 or newer is required' ?></li>
      <li class="<?= $pdo ? 'ok' : 'bad' ?>"><span><?= $pdo ? '✓' : '✕' ?></span> SQLite database support <?= $pdo ? '' : '— ask your host to enable pdo_sqlite' ?></li>
      <li class="<?= is_writable(ROOT_PATH) ? 'ok' : 'bad' ?>"><span><?= is_writable(ROOT_PATH) ? '✓' : '✕' ?></span> Website folder is writable</li>
    </ul>

    <form method="post">
      <div class="s-field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e((string) ($_POST['username'] ?? 'admin')) ?>" required autofocus>
      </div>
      <div class="s-field">
        <label for="name">Your full name</label>
        <input type="text" id="name" name="name" value="<?= e((string) ($_POST['name'] ?? '')) ?>">
      </div>
      <div class="s-field">
        <label for="email">Your email address</label>
        <input type="email" id="email" name="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
      </div>
      <div class="s-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>
        <small>At least 8 characters. Write it down somewhere safe.</small>
      </div>
      <div class="s-field">
        <label for="confirm">Repeat the password</label>
        <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
      </div>
      <button class="s-btn s-btn-primary s-btn-block" type="submit"<?= ($php && $pdo) ? '' : ' disabled' ?>>Install the website</button>
    </form>
    <?php
});

/* --------------------------------------------------------- page shell */

function setup_page(string $title, callable $body): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · Coastal AI Summit &amp; SME Trade Fair</title>
<style>
  *,*::before,*::after{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:1.5rem;
    background:linear-gradient(150deg,#04121f,#0a2a45);color:#16242f;
    font-family:"Inter","Segoe UI",system-ui,sans-serif;font-size:15px;line-height:1.6}
  .s-box{width:100%;max-width:470px;padding:2.25rem;background:#fff;border-radius:16px;
    box-shadow:0 30px 80px rgba(0,0,0,.4)}
  .s-logo{width:96px;margin:0 auto 1.25rem;display:block}
  h1{margin:0 0 .6rem;font-size:1.4rem;letter-spacing:-.02em;text-align:center}
  p{margin:0 0 1rem;color:#5c6d7c;font-size:.92rem}
  code{background:#f2f4f6;padding:.1em .4em;border-radius:4px;font-size:.88em;color:#16242f}
  .s-field{margin-bottom:1rem}
  .s-field label{display:block;margin-bottom:.3rem;font-size:.82rem;font-weight:600;color:#16242f}
  .s-field input{width:100%;padding:.6rem .75rem;font:inherit;font-size:.92rem;
    border:1px solid #dfe5ea;border-radius:8px}
  .s-field input:focus{outline:none;border-color:#0f9fc4;box-shadow:0 0 0 3px rgba(15,159,196,.15)}
  .s-field small{display:block;margin-top:.3rem;font-size:.78rem;color:#67788a}
  .s-btn{display:inline-flex;align-items:center;justify-content:center;padding:.65rem 1.15rem;
    border:1px solid #dfe5ea;border-radius:8px;background:#fff;color:#16242f;
    font:inherit;font-size:.88rem;font-weight:600;text-decoration:none;cursor:pointer}
  .s-btn:hover{background:#f2f4f6}
  .s-btn-primary{background:#0f9fc4;border-color:#0f9fc4;color:#fff}
  .s-btn-primary:hover{background:#0c8bad}
  .s-btn-primary[disabled]{opacity:.5;cursor:not-allowed}
  .s-btn-block{width:100%;margin-top:.5rem}
  .s-actions{display:flex;gap:.6rem;flex-wrap:wrap;margin:1.5rem 0 1rem}
  .s-alert{padding:.85rem 1.1rem;margin-bottom:1.25rem;background:#fdeceb;border:1px solid #efc0bc;
    border-radius:8px;color:#8f2822;font-size:.88rem}
  .s-alert p{margin:0 0 .3rem;color:inherit}
  .s-alert p:last-child{margin:0}
  .s-checks{list-style:none;margin:0 0 1.5rem;padding:0;font-size:.86rem}
  .s-checks li{display:flex;gap:.55rem;align-items:flex-start;margin-bottom:.35rem}
  .s-checks span{width:20px;height:20px;flex:none;display:grid;place-items:center;border-radius:50%;
    font-size:.72rem;font-weight:700}
  .s-checks .ok span{background:#e6f6ef;color:#1e7d5a}
  .s-checks .bad span{background:#fdeceb;color:#c2382f}
  .s-ok{color:#1e7d5a;font-weight:600}
  .s-note{margin:0;padding-top:1rem;border-top:1px solid #dfe5ea;font-size:.82rem}
</style>
</head>
<body>
  <main class="s-box">
    <img class="s-logo" src="images/generated/coastal-ai-summit-logo-revamped.png" alt="Coastal AI Summit &amp; SME Trade Fair 2026">
    <h1><?= e($title) ?></h1>
    <?php $body(); ?>
  </main>
</body>
</html>
    <?php
}

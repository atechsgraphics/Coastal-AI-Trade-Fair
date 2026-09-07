<?php
declare(strict_types=1);

/** Client sign-in and sign-out. */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

if (($_GET['do'] ?? '') === 'logout') {
    client_logout();
    bk_flash('ok', 'You have been signed out.');
    redirect('auth/login.php');
}

if (client_logged_in()) {
    redirect('booking/account.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!csrf_check()) {
        bk_flash('error', 'Your session expired. Please sign in again.');
        redirect('auth/login.php');
    }
    if (!rate_limit('clientlogin:' . client_ip(), 10, 900)) {
        bk_flash('error', 'Too many sign-in attempts from this connection. Please wait fifteen minutes and try again.');
        redirect('auth/login.php');
    }

    $problem = client_login($email, (string) ($_POST['password'] ?? ''));
    if ($problem !== null) {
        bk_keep_input(['email' => $email]);
        bk_flash('error', $problem);
        redirect('auth/login.php');
    }

    redirect(bk_take_redirect());
}

$old = bk_old_take();
$page = site_head('login', 'Sign in', 'Sign in to see your bookings, upload proof of payment and download your tickets and receipts.', true);
site_header('login');
bk_hero('CLIENT ACCOUNTS', 'Welcome {back.}', 'Sign in to manage your bookings, payments and tickets.');
?>

<section class="section">
  <div class="shell bk-narrow">
    <?php bk_flash_render(); ?>

    <div class="bk-panel">
      <form method="post" action="<?= e(url('auth/login.php')) ?>" class="bk-form" autocomplete="on" novalidate>
        <?= csrf_field() ?>

        <label class="field"><span>Email address</span>
          <input type="email" name="email" required maxlength="190" autocomplete="email" autofocus value="<?= bk_old($old, 'email') ?>">
        </label>

        <label class="field"><span>Password</span>
          <input type="password" name="password" required autocomplete="current-password">
        </label>

        <div class="bk-form-actions">
          <button class="btn btn-primary" type="submit">Sign in <span aria-hidden="true">→</span></button>
          <p>
            <a class="text-link" href="<?= e(url('auth/forgot-password.php')) ?>">Forgotten your password?</a><br>
            New here? <a class="text-link" href="<?= e(url('auth/register.php')) ?>">Create an account</a>
          </p>
        </div>
      </form>
    </div>

    <p class="bk-foot-note">
      Staff sign in through the <a href="<?= e(url('admin/')) ?>">control panel</a>.
    </p>
  </div>
</section>

<?php site_footer(); ?>

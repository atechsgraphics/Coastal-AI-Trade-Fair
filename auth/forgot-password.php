<?php
declare(strict_types=1);

/** Ask for a password-reset link. */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired. Please try again.');
        redirect('auth/forgot-password.php');
    }
    if (!rate_limit('resetsend:' . client_ip(), 5, 900)) {
        bk_flash('error', 'Too many requests from this connection. Please wait fifteen minutes and try again.');
        redirect('auth/forgot-password.php');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $client = $email !== '' ? db_one('SELECT * FROM clients WHERE email = :e', [':e' => $email]) : null;

    if ($client && $client['status'] === 'active') {
        $token = client_issue_token((int) $client['id'], 'reset', 60);
        bk_mail([
            'to'        => (string) $client['email'],
            'subject'   => 'Reset your password',
            'template'  => 'password_reset',
            'client_id' => (int) $client['id'],
            'html'      => bk_email_html(
                'Reset your password',
                'Somebody asked to reset the password for this account. If it was you, use the button below. '
                    . 'The link works for one hour and can only be used once.',
                [],
                [['text' => 'Choose a new password', 'url' => bk_url('auth/reset-password.php') . '?token=' . $token]],
                'If you did not ask for this, you can safely ignore this message — your password has not changed.'
            ),
        ]);
    }

    // Identical response either way, so this form cannot reveal who has an account.
    bk_flash('ok', 'If that email address has an account with us, a reset link is on its way to it now.');
    redirect('auth/login.php');
}

$page = site_head('forgot-password', 'Reset your password', '', true);
site_header('forgot-password');
bk_hero('CLIENT ACCOUNTS', 'Reset your {password.}', 'We will email you a link to choose a new one.');
?>

<section class="section">
  <div class="shell bk-narrow">
    <?php bk_flash_render(); ?>

    <div class="bk-panel">
      <form method="post" action="<?= e(url('auth/forgot-password.php')) ?>" class="bk-form" novalidate>
        <?= csrf_field() ?>
        <label class="field"><span>Your email address</span>
          <input type="email" name="email" required maxlength="190" autocomplete="email" autofocus>
        </label>
        <div class="bk-form-actions">
          <button class="btn btn-primary" type="submit">Email me a reset link</button>
          <p><a class="text-link" href="<?= e(url('auth/login.php')) ?>">Back to sign in</a></p>
        </div>
      </form>
    </div>
  </div>
</section>

<?php site_footer(); ?>

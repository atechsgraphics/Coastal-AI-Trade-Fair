<?php
declare(strict_types=1);

/** Choose a new password from an emailed reset link. */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));
$client = $token !== '' ? client_by_token('reset', $token) : null;

if ($client && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    $error = null;
    if (!csrf_check()) {
        $error = 'Your session expired before the form was sent. Please use the link again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $error = client_password_problem($password);
    }

    if ($error !== null) {
        bk_flash('error', $error);
        redirect('auth/reset-password.php?token=' . rawurlencode($token));
    }

    db_run(
        "UPDATE clients SET password_hash = :h, reset_hash = '', reset_expires = '', updated_at = :u WHERE id = :id",
        [':h' => password_hash($password, PASSWORD_DEFAULT), ':u' => date('Y-m-d H:i:s'), ':id' => (int) $client['id']]
    );

    // A working reset link also proves the address, so treat it as verified.
    if (trim((string) $client['email_verified_at']) === '') {
        db_run("UPDATE clients SET email_verified_at = :t WHERE id = :id", [':t' => date('Y-m-d H:i:s'), ':id' => (int) $client['id']]);
    }

    bk_mail([
        'to'        => (string) $client['email'],
        'subject'   => 'Your password has been changed',
        'template'  => 'password_changed',
        'client_id' => (int) $client['id'],
        'html'      => bk_email_html(
            'Your password has been changed',
            'The password for your account was changed just now.',
            [['When', date('d F Y \a\t H:i')]],
            [['text' => 'Sign in', 'url' => bk_url('auth/login.php')]],
            'If this was not you, please contact us straight away on ' . setting('email_primary') . '.'
        ),
    ]);

    client_logout();
    bk_flash('ok', 'Your password has been changed. Please sign in with it.');
    redirect('auth/login.php');
}

$page = site_head('reset-password', 'Choose a new password', '', true);
site_header('reset-password');
bk_hero('CLIENT ACCOUNTS', 'Choose a new {password.}', '');
?>

<section class="section">
  <div class="shell bk-narrow">
    <?php bk_flash_render(); ?>

    <div class="bk-panel">
      <?php if (!$client): ?>
        <div class="alert alert-error" role="alert">
          That reset link is no longer valid. Links expire after an hour and can only be used once.
        </div>
        <p><a class="btn btn-primary" href="<?= e(url('auth/forgot-password.php')) ?>">Ask for a new link</a></p>
      <?php else: ?>
        <p class="bk-panel-lead">Choose a new password for <strong><?= e((string) $client['email']) ?></strong>.</p>
        <form method="post" action="<?= e(url('auth/reset-password.php')) ?>" class="bk-form" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">

          <label class="field"><span>New password</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password" autofocus>
            <span class="field-hint">At least 10 characters.</span>
          </label>
          <label class="field"><span>Repeat the new password</span>
            <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
          </label>

          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Save my new password</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php site_footer(); ?>

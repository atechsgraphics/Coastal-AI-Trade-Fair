<?php
declare(strict_types=1);

/**
 * Confirm a client's email address from the link in their welcome message,
 * and resend that link when it has expired.
 */

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/client-ui.php';

start_session();
bk_require_platform();

$heading = 'Confirm your email address';
$message = '';
$tone    = 'error';

/* ---- resend the link ---- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired. Please try again.');
        redirect('verify-email.php');
    }
    if (!rate_limit('verifysend:' . client_ip(), 5, 900)) {
        bk_flash('error', 'Too many requests. Please wait fifteen minutes before asking for another link.');
        redirect('verify-email.php');
    }

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $client = $email !== '' ? db_one('SELECT * FROM clients WHERE email = :e', [':e' => $email]) : null;

    if ($client && trim((string) $client['email_verified_at']) === '' && $client['status'] === 'active') {
        $token = client_issue_token((int) $client['id'], 'verify', 60 * 48);
        bk_mail([
            'to'        => (string) $client['email'],
            'subject'   => 'Confirm your email address',
            'template'  => 'verify_email_resend',
            'client_id' => (int) $client['id'],
            'html'      => bk_email_html(
                'Confirm your email address',
                'Here is a fresh link to confirm your email address. It works for the next 48 hours.',
                [],
                [['text' => 'Confirm my email address', 'url' => bk_url('verify-email.php') . '?token=' . $token]],
                'If you did not ask for this, you can ignore this message.'
            ),
        ]);
    }

    // The same answer either way, so the form cannot be used to find accounts.
    bk_flash('ok', 'If that address needs confirming, a new link is on its way to it now.');
    redirect('login.php');
}

/* ---- follow the link ---- */
$token = (string) ($_GET['token'] ?? '');
$showResend = true;

if ($token !== '') {
    $client = client_by_token('verify', $token);

    if ($client) {
        db_run(
            "UPDATE clients SET email_verified_at = :t, verify_hash = '', verify_expires = '', updated_at = :u WHERE id = :id",
            [':t' => date('Y-m-d H:i:s'), ':u' => date('Y-m-d H:i:s'), ':id' => (int) $client['id']]
        );

        $heading = 'Your email address is confirmed';
        $message = 'Thank you, ' . $client['full_name'] . '. Your account is ready to use.';
        $tone = 'ok';
        $showResend = false;

        if (!client_logged_in()) {
            session_regenerate_id(true);
            $_SESSION['client_id'] = (int) $client['id'];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    } else {
        $message = 'That confirmation link is no longer valid — links expire after 48 hours, and each one can only be used once. Ask for a new link below.';
    }
} else {
    $message = 'Please open the link in the email we sent you, or ask for a new one below.';
}

$page = site_head('verify-email', 'Confirm your email address', '', true);
site_header('verify-email');
bk_hero('CLIENT ACCOUNTS', $tone === 'ok' ? 'All {confirmed.}' : 'Confirm your {email.}', '');
?>

<section class="section">
  <div class="shell bk-narrow">
    <div class="bk-panel">
      <h2 class="bk-panel-title"><?= e($heading) ?></h2>
      <div class="alert <?= $tone === 'ok' ? 'alert-ok' : 'alert-error' ?>" role="status"><?= e($message) ?></div>

      <?php if (!$showResend): ?>
        <p><a class="btn btn-primary" href="account.php">Go to my bookings <span aria-hidden="true">→</span></a>
           <a class="btn btn-ghost" href="booking.php">Make a booking</a></p>
      <?php else: ?>
        <form method="post" action="verify-email.php" class="bk-form">
          <?= csrf_field() ?>
          <label class="field"><span>Your email address</span>
            <input type="email" name="email" required maxlength="190" autocomplete="email">
          </label>
          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Send me a new link</button>
            <p><a class="text-link" href="login.php">Back to sign in</a></p>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php site_footer(); ?>

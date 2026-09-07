<?php
declare(strict_types=1);

/**
 * Create a client account.
 * A verification link is emailed straight away; the account can browse and
 * book once verified (or immediately, if verification is switched off).
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

if (client_logged_in()) {
    redirect('booking/account.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $input = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'email'     => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'     => trim((string) ($_POST['phone'] ?? '')),
        'company'   => trim((string) ($_POST['company'] ?? '')),
        'city'      => trim((string) ($_POST['city'] ?? '')),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    $error = null;

    if (!csrf_check()) {
        $error = 'Your session expired before the form was sent. Please try again.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        // Honeypot: quietly pretend it worked.
        bk_flash('ok', 'Please check your email for the link that activates your account.');
        redirect('auth/login.php');
    } elseif (!rate_limit('register:' . client_ip(), 6, 3600)) {
        $error = 'Too many accounts have been created from this connection. Please try again later.';
    } elseif ($input['full_name'] === '') {
        $error = 'Please tell us your full name.';
    } elseif ($input['email'] === '' || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please give a working email address — your booking confirmations are sent there.';
    } elseif ($input['phone'] === '') {
        $error = 'Please give us a phone number in case we need to reach you about a booking.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        $error = client_password_problem($password);
    }

    if ($error === null && db_one('SELECT id FROM clients WHERE email = :e', [':e' => $input['email']])) {
        // Never reveal whether an address is registered.
        bk_flash('ok', 'Please check your email — we have sent you a link to finish setting up your account.');
        redirect('auth/login.php');
    }

    if ($error !== null) {
        bk_keep_input($input);
        bk_flash('error', $error);
        redirect('auth/register.php');
    }

    $now = date('Y-m-d H:i:s');
    db_run(
        'INSERT INTO clients (email, password_hash, full_name, phone, company, city, status, created_at, updated_at)
         VALUES (:e, :h, :n, :p, :c, :city, :s, :cr, :up)',
        [
            ':e'    => mb_substr($input['email'], 0, 190),
            ':h'    => password_hash($password, PASSWORD_DEFAULT),
            ':n'    => mb_substr($input['full_name'], 0, 190),
            ':p'    => mb_substr($input['phone'], 0, 60),
            ':c'    => mb_substr($input['company'], 0, 190),
            ':city' => mb_substr($input['city'], 0, 120),
            ':s'    => 'active',
            ':cr'   => $now,
            ':up'   => $now,
        ]
    );

    $clientId = (int) db()->lastInsertId();
    $token = client_issue_token($clientId, 'verify', 60 * 48);

    bk_mail([
        'to'        => $input['email'],
        'subject'   => 'Confirm your email address',
        'template'  => 'verify_email',
        'client_id' => $clientId,
        'html'      => bk_email_html(
            'Welcome, ' . $input['full_name'],
            'Thank you for creating an account with ' . setting('org_name') . ".\n\n"
                . 'Please confirm your email address so we can send you booking confirmations, tickets and receipts. '
                . 'This link works for the next 48 hours.',
            [],
            [['text' => 'Confirm my email address', 'url' => bk_url('auth/verify-email.php') . '?token=' . $token]],
            'If you did not create this account you can ignore this message and nothing further will happen.'
        ),
    ]);

    if (bk_bool('bk_require_verified_email', true)) {
        bk_flash('ok', 'Your account has been created. Please open the email we have just sent and click the link to confirm your address.');
        redirect('auth/login.php');
    }

    client_login($input['email'], $password);
    bk_flash('ok', 'Welcome — your account is ready.');
    redirect(bk_take_redirect());
}

$old = bk_old_take();
$page = site_head('register', 'Create an account', 'Create an account to book online, pay by EFT and download your tickets and receipts.', true);
site_header('register');
bk_hero('CLIENT ACCOUNTS', 'Create your {account.}', 'One account keeps every booking, payment and ticket in one place.');
?>

<section class="section">
  <div class="shell bk-narrow">
    <?php bk_flash_render(); ?>

    <div class="bk-panel">
      <form method="post" action="<?= e(url('auth/register.php')) ?>" class="bk-form" autocomplete="on" novalidate>
        <?= csrf_field() ?>

        <div class="field-row">
          <label class="field"><span>Full name *</span>
            <input type="text" name="full_name" required maxlength="190" autocomplete="name" value="<?= bk_old($old, 'full_name') ?>">
          </label>
          <label class="field"><span>Email address *</span>
            <input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= bk_old($old, 'email') ?>">
          </label>
        </div>

        <div class="field-row">
          <label class="field"><span>Phone / WhatsApp *</span>
            <input type="tel" name="phone" required maxlength="60" autocomplete="tel" value="<?= bk_old($old, 'phone') ?>">
          </label>
          <label class="field"><span>Company or organisation</span>
            <input type="text" name="company" maxlength="190" autocomplete="organization" value="<?= bk_old($old, 'company') ?>">
          </label>
        </div>

        <label class="field"><span>Town or city</span>
          <input type="text" name="city" maxlength="120" autocomplete="address-level2" value="<?= bk_old($old, 'city') ?>">
        </label>

        <div class="field-row">
          <label class="field"><span>Password *</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
            <span class="field-hint">At least 10 characters.</span>
          </label>
          <label class="field"><span>Repeat password *</span>
            <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
          </label>
        </div>

        <label class="hp-field" aria-hidden="true">Leave this empty
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>

        <div class="bk-form-actions">
          <button class="btn btn-primary" type="submit">Create my account <span aria-hidden="true">→</span></button>
          <p>Already registered? <a class="text-link" href="<?= e(url('auth/login.php')) ?>">Sign in instead</a></p>
        </div>
      </form>
    </div>
  </div>
</section>

<?php site_footer(); ?>

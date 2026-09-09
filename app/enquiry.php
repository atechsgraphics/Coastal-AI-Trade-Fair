<?php
declare(strict_types=1);

/**
 * The public enquiry / registration form: rendering, validation, storage
 * and notification. Used by both the home page and the contact page so the
 * behaviour stays identical wherever a visitor submits.
 */

// The site mailer, so an enquiry can go out through the mailbox on the host
// rather than a bare mail() call. Only eft.php used to load this, and the
// contact page does not load eft.php — which is why enquiries were the one
// thing on the site that could never use SMTP.
require_once __DIR__ . '/mailer.php';

function enquiry_interests(): array
{
    return [
        'Attending the fair',
        'Exhibiting / booking a stall',
        'Sponsorship',
        'Partnership',
        'AI Masterclass ticket',
        'Speaking opportunity',
        'Media & press',
        'General enquiry',
    ];
}

/**
 * Processes a POSTed enquiry. Returns [status, message] where status is
 * '', 'ok' or 'error'. On success the row is stored and an email attempted.
 */
function enquiry_handle(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['', ''];
    }

    if (!csrf_check()) {
        return ['error', 'Your session expired. Please try sending the form again.'];
    }

    // The hidden field, the timing check and a look at what was actually
    // written. Each answers with the same thank-you a real sender gets, so a
    // script cannot tell which of its attempts got through.
    if (bot_trap_problem(3) !== null) {
        return ['ok', 'Thank you — your message has been received.'];
    }
    if (bot_trap_text_problem((string) ($_POST['message'] ?? '')) !== null) {
        return ['ok', 'Thank you — your message has been received.'];
    }

    if (!rate_limit('enquiry:' . client_ip(), 5, 900)) {
        return ['error', 'Too many messages from this connection. Please try again a little later.'];
    }

    $name     = trim((string) ($_POST['name'] ?? ''));
    $company  = trim((string) ($_POST['company'] ?? ''));
    $email    = trim((string) ($_POST['email'] ?? ''));
    $phone    = trim((string) ($_POST['phone'] ?? ''));
    $interest = trim((string) ($_POST['interest'] ?? ''));
    $option   = trim((string) ($_POST['option_key'] ?? ''));
    $message  = trim((string) ($_POST['message'] ?? ''));

    if ($name === '' || $email === '' || $interest === '' || $message === '') {
        return ['error', 'Please complete your name, email, area of interest and message.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error', 'That email address does not look right. Please check and try again.'];
    }
    if (mb_strlen($message) > 4000) {
        return ['error', 'Your message is a little too long. Please keep it under 4000 characters.'];
    }

    db_run(
        'INSERT INTO enquiries (name, company, email, phone, interest, option_key, message, status, ip, created_at)
         VALUES (:name, :company, :email, :phone, :interest, :option, :message, :status, :ip, :created)',
        [
            ':name'     => mb_substr($name, 0, 160),
            ':company'  => mb_substr($company, 0, 160),
            ':email'    => mb_substr($email, 0, 190),
            ':phone'    => mb_substr($phone, 0, 60),
            ':interest' => mb_substr($interest, 0, 120),
            ':option'   => mb_substr($option, 0, 160),
            ':message'  => $message,
            ':status'   => 'new',
            ':ip'       => client_ip(),
            ':created'  => date('Y-m-d H:i:s'),
        ]
    );

    enquiry_notify($name, $company, $email, $phone, $interest, $option, $message);

    return ['ok', 'Thank you, ' . $name . '. Your enquiry has reached the event team — we will be in touch shortly.'];
}

/**
 * Emails the event team. Failure is not shown to the visitor: the enquiry is
 * already stored in the admin panel, so nothing is lost when mail() is
 * unavailable (as it is on a local XAMPP machine).
 */
function enquiry_notify(string $name, string $company, string $email, string $phone, string $interest, string $option, string $message): bool
{
    $to = setting('email_form_to', setting('email_primary'));
    if ($to === '') {
        return false;
    }

    $clean = static function (string $value): string {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    };

    $subject = 'Website enquiry (' . $clean($interest) . ') — ' . $clean($name);
    $body = "A new enquiry was submitted on " . setting('site_name') . ".\n\n"
        . "Name:     {$name}\n"
        . "Company:  " . ($company !== '' ? $company : '—') . "\n"
        . "Email:    {$email}\n"
        . "Phone:    " . ($phone !== '' ? $phone : '—') . "\n"
        . "Interest: {$interest}\n"
        . "Option:   " . ($option !== '' ? $option : '—') . "\n"
        . "Received: " . date('d M Y H:i') . "\n\n"
        . "Message:\n{$message}\n";

    // Through the site's own mailer, so an enquiry travels the same way every
    // other message does: out through the mailbox on the host, and written to
    // the email log whether it succeeds or fails. Before this the contact form
    // was the one thing on the site that could not use SMTP and left no trace
    // when it did not arrive.
    if (function_exists('bk_mail')) {
        return bk_mail([
            'to'       => $to,
            'subject'  => $subject,
            'text'     => $body,
            'reply_to' => $clean($email),
            'template' => 'website_enquiry',
        ]);
    }

    // Only reached if the booking platform is switched off entirely.
    $headers = [
        'From: ' . setting('site_name') . ' Website <no-reply@' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>',
        'Reply-To: ' . $clean($email),
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    return function_exists('mail') && @mail($to, $subject, $body, implode("\r\n", $headers));
}

/**
 * Renders the enquiry form. $variant 'full' shows every field,
 * 'compact' is the shorter version used on the home page.
 */
function enquiry_form(string $variant = 'full', string $status = '', string $notice = ''): void
{
    $stalls = active('stalls');
    $packages = active('packages');
    $preselect = trim((string) ($_GET['option'] ?? ''));
    ?>
<form class="form-card" id="enquiryForm" method="post" action="contact.php#enquiry">
  <?= csrf_field() ?>
  <?php if ($notice !== ''): ?>
    <div class="alert alert-<?= $status === 'ok' ? 'ok' : 'error' ?>" role="status"><?= e($notice) ?></div>
  <?php endif; ?>

  <div class="field-row">
    <label class="field"><span>Your name *</span>
      <input type="text" name="name" autocomplete="name" required maxlength="160">
    </label>
    <label class="field"><span>Email address *</span>
      <input type="email" name="email" autocomplete="email" required maxlength="190">
    </label>
  </div>

  <div class="field-row">
    <label class="field"><span>Company / organisation</span>
      <input type="text" name="company" autocomplete="organization" maxlength="160">
    </label>
    <label class="field"><span>Phone / WhatsApp</span>
      <input type="tel" name="phone" autocomplete="tel" maxlength="60">
    </label>
  </div>

  <label class="field"><span>I'm interested in *</span>
    <select name="interest" required>
      <option value="">Select an option</option>
      <?php foreach (enquiry_interests() as $item): ?>
        <option value="<?= e($item) ?>"><?= e($item) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <?php if ($variant === 'full' && ($stalls || $packages)): ?>
    <label class="field"><span>Package or stall you have in mind</span>
      <select name="option_key">
        <option value="">Not sure yet — please advise</option>
        <?php if ($packages): ?>
          <optgroup label="Sponsorship packages">
            <?php foreach ($packages as $p): ?>
              <?php $v = $p['name'] . ' — ' . $p['price']; ?>
              <option value="<?= e($v) ?>"<?= $preselect === $v ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
        <?php if ($stalls): ?>
          <optgroup label="Stalls, tickets &amp; passes">
            <?php foreach ($stalls as $s): ?>
              <?php $v = $s['category'] . ($s['details'] !== '' ? ' (' . $s['details'] . ')' : '') . ' — ' . $s['rate']; ?>
              <option value="<?= e($v) ?>"<?= $preselect === $v ? ' selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
          </optgroup>
        <?php endif; ?>
      </select>
    </label>
  <?php else: ?>
    <input type="hidden" name="option_key" value="<?= e($preselect) ?>">
  <?php endif; ?>

  <label class="field"><span>Tell us more *</span>
    <textarea name="message" rows="<?= $variant === 'full' ? 6 : 4 ?>" required maxlength="4000"></textarea>
  </label>

  <?= bot_trap_fields() ?>
  <label class="hp-field" aria-hidden="true" hidden>Leave this field empty
    <input type="text" name="website_2" tabindex="-1" autocomplete="off">
  </label>

  <button class="btn btn-primary" type="submit">Send enquiry <span aria-hidden="true">→</span></button>
  <p class="field-hint">We reply from <?= e(setting('email_primary')) ?>. Your details are only used to answer your enquiry.</p>
</form>
    <?php
}

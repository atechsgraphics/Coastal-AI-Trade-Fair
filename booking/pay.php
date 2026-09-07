<?php
declare(strict_types=1);

/**
 * EFT payment instructions and the proof-of-payment upload.
 *
 * Two ways in, both checked:
 *   • the signed-in client who owns the booking   (?id=12)
 *   • the emailed link, reference plus its token  (?ref=ATF-26F3K9&t=…)
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

/* --------------------------------------------------------- find the booking */

$booking = null;
$viaToken = false;

if (isset($_GET['ref']) || isset($_POST['ref'])) {
    $reference = (string) ($_GET['ref'] ?? $_POST['ref'] ?? '');
    $token     = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
    $booking = bk_booking_by_token($reference, $token);
    $viaToken = $booking !== null;
}

if (!$booking) {
    $id = (int) ($_GET['id'] ?? ($_POST['booking_id'] ?? 0));
    if ($id > 0) {
        $booking = bk_require_own_booking($id);
    }
}

if (!$booking) {
    http_response_code(404);
    bk_deny_page(
        'Booking not found',
        'That payment link is not valid, or the booking belongs to a different account. '
        . 'Please sign in and open the booking from your account.'
    );
}

$linkQuery = $viaToken
    ? 'ref=' . rawurlencode((string) $booking['reference']) . '&t=' . rawurlencode((string) $booking['access_token'])
    : 'id=' . (int) $booking['id'];

/* ----------------------------------------------------------- handle upload */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired before the file was sent. Please choose the file again.');
        redirect('booking/pay.php?' . $linkQuery);
    }
    if (!rate_limit('proof:' . client_ip(), 15, 3600)) {
        bk_flash('error', 'Too many uploads from this connection. Please wait a while and try again.');
        redirect('booking/pay.php?' . $linkQuery);
    }

    $client = client_user();
    $result = bk_upload_proof(
        $booking,
        (array) ($_FILES['proof'] ?? []),
        [
            'amount'         => (string) ($_POST['amount'] ?? ''),
            'bank_reference' => (string) ($_POST['bank_reference'] ?? ''),
            'paid_on'        => (string) ($_POST['paid_on'] ?? ''),
            'client_notes'   => (string) ($_POST['client_notes'] ?? ''),
        ],
        'client',
        $client ? (string) ($client['full_name'] ?: $client['email']) : (string) $booking['contact_name']
    );

    bk_flash($result['ok'] ? 'ok' : 'error', $result['message']);
    redirect('booking/pay.php?' . $linkQuery);
}

/* ------------------------------------------------------------------ render */

$payment  = bk_payment_ensure($booking);
$proofs   = bk_proofs((int) $booking['id']);
$canUpload = in_array((string) $booking['status'], bk_awaiting_payment_statuses(), true);
$outstanding = round((float) $booking['amount_due'] - (float) $booking['amount_paid'], 2);
$allowed = bk_allowed_extensions();

$page = site_head('pay', 'Pay for booking ' . (string) $booking['reference'], '', true);
site_header('pay');
bk_hero(
    'BOOKING ' . (string) $booking['reference'],
    (string) $booking['status'] === 'confirmed' ? 'Payment {confirmed.}' : 'Pay by {EFT.}',
    bk_progress_note($booking)
);
?>

<section class="section">
  <div class="shell">
    <?php bk_flash_render(); ?>

    <?php bk_journey_render($booking); ?>

    <div class="bk-review">
      <div class="bk-panel">

        <?php if ($canUpload): ?>
          <h2 class="bk-panel-title">1 · Transfer the amount</h2>
          <p class="bk-panel-lead">
            Please transfer <strong><?= e(bk_money($outstanding > 0 ? $outstanding : (float) $booking['amount_due'], (string) $booking['currency'])) ?></strong>
            using <strong><?= e((string) $booking['reference']) ?></strong> as your payment reference.
          </p>

          <?php if (setting('bank_account_number') !== ''): ?>
            <div class="bk-bank">
              <?php bk_facts_render(bk_bank_facts($booking), 'detail-list bank-list'); ?>
            </div>
          <?php else: ?>
            <div class="alert alert-warn" role="status">
              Our banking details have not been published yet. Please contact us on
              <a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a> for them.
            </div>
          <?php endif; ?>

          <?php $deadline = bk_deadline_text($booking); ?>
          <?php if ($deadline !== ''): ?>
            <p class="bk-deadline"><span aria-hidden="true">⏱</span> Please pay by <strong><?= e($deadline) ?></strong>, after which the slot may be released.</p>
          <?php endif; ?>

          <?php if (trim(bk('bk_payment_instructions')) !== ''): ?>
            <ul class="terms-list bk-instructions">
              <?php foreach (lines(bk('bk_payment_instructions')) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
            </ul>
          <?php endif; ?>

          <hr class="bk-rule">

          <h2 class="bk-panel-title">2 · Upload your proof of payment</h2>

          <?php if ((string) $booking['status'] === 'declined' && trim((string) $booking['decline_reason']) !== ''): ?>
            <div class="alert alert-error" role="alert">
              <strong>Your last proof of payment was not accepted.</strong><br>
              Reason from our team: <?= e((string) $booking['decline_reason']) ?><br>
              Please upload a corrected proof below.
            </div>
          <?php elseif (in_array((string) $booking['status'], ['proof_submitted', 'under_review'], true)): ?>
            <div class="alert alert-ok" role="status">
              We have your proof of payment and it is waiting to be verified. You can still replace it below if you sent the wrong file.
            </div>
          <?php endif; ?>

          <form method="post" action="<?= e(url('booking/pay.php')) ?>" class="bk-form" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <?php if ($viaToken): ?>
              <input type="hidden" name="ref" value="<?= e((string) $booking['reference']) ?>">
              <input type="hidden" name="t" value="<?= e((string) $booking['access_token']) ?>">
            <?php else: ?>
              <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
            <?php endif; ?>

            <label class="field"><span>Proof of payment *</span>
              <input type="file" name="proof" required
                     accept="<?= e('.' . implode(',.', $allowed)) ?>,application/pdf,image/jpeg,image/png">
              <span class="field-hint">
                <?= e(strtoupper(implode(', ', $allowed))) ?> only, up to <?= bk_int('bk_upload_max_mb', 6) ?> MB.
                A bank confirmation, screenshot or statement extract all work.
              </span>
            </label>

            <div class="field-row">
              <label class="field"><span>Amount you paid</span>
                <input type="text" name="amount" inputmode="decimal" maxlength="20"
                       placeholder="<?= e(number_format($outstanding > 0 ? $outstanding : (float) $booking['amount_due'], 2, '.', '')) ?>">
              </label>
              <label class="field"><span>Date of the transfer</span>
                <input type="date" name="paid_on" max="<?= e(bk_now()->format('Y-m-d')) ?>">
              </label>
            </div>

            <label class="field"><span>Reference your bank shows</span>
              <input type="text" name="bank_reference" maxlength="120" placeholder="The reference on your transfer, if it differs">
            </label>

            <label class="field"><span>Anything we should know</span>
              <textarea name="client_notes" rows="3" maxlength="1000" placeholder="For example: paid in two parts, or paid by a colleague"></textarea>
            </label>

            <div class="bk-form-actions">
              <button class="btn btn-primary" type="submit">Upload proof of payment <span aria-hidden="true">↑</span></button>
              <p><?= e(bk('bk_payment_note')) ?></p>
            </div>
          </form>

        <?php else: ?>
          <h2 class="bk-panel-title">Payment status</h2>
          <div class="alert <?= (string) $booking['status'] === 'confirmed' ? 'alert-ok' : 'alert-warn' ?>" role="status">
            <?= e(bk_progress_note($booking)) ?>
          </div>

          <?php if ((string) $booking['status'] === 'confirmed'): ?>
            <p>
              <a class="btn btn-primary" href="<?= e(url('booking/download.php')) ?>?kind=ticket&amp;<?= e($linkQuery) ?>">Download my ticket <span aria-hidden="true">↓</span></a>
              <a class="btn btn-ghost" href="<?= e(url('booking/download.php')) ?>?kind=receipt&amp;<?= e($linkQuery) ?>">Download my receipt <span aria-hidden="true">↓</span></a>
            </p>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($proofs): ?>
          <hr class="bk-rule">
          <h3 class="bk-sub-title">What you have sent us</h3>
          <ul class="bk-proof-list">
            <?php foreach ($proofs as $proof): ?>
              <li class="is-<?= e((string) $proof['status']) ?>">
                <div>
                  <strong><?= e((string) ($proof['original_name'] ?: 'Proof of payment')) ?></strong>
                  <small>
                    <?= e(strtoupper((string) $proof['extension'])) ?> ·
                    <?= e(bk_filesize((int) $proof['size'])) ?> ·
                    uploaded <?= e(date('d M Y, H:i', strtotime((string) $proof['created_at']) ?: time())) ?>
                    <?php if ((float) $proof['amount'] > 0): ?> · <?= e(bk_money((float) $proof['amount'])) ?><?php endif; ?>
                  </small>
                  <?php if ((string) $proof['status'] === 'declined' && trim((string) $proof['review_reason']) !== ''): ?>
                    <small class="bk-proof-reason">Not accepted: <?= e((string) $proof['review_reason']) ?></small>
                  <?php endif; ?>
                </div>
                <span class="bk-proof-state"><?= e([
                    'submitted'  => 'Awaiting review',
                    'approved'   => 'Approved',
                    'declined'   => 'Not accepted',
                    'superseded' => 'Replaced',
                ][(string) $proof['status']] ?? (string) $proof['status']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <aside class="bk-aside">
        <h3>Your booking</h3>
        <p class="bk-aside-status"><?= bk_pill((string) $booking['status']) ?></p>
        <?php bk_facts_render(bk_booking_facts($booking), 'detail-list bk-aside-facts'); ?>
        <p>
          <?php if (client_logged_in()): ?>
            <a class="btn btn-ghost" href="<?= e(url('booking/account.php')) ?>?p=booking&amp;id=<?= (int) $booking['id'] ?>">Open in my account <span aria-hidden="true">→</span></a>
          <?php else: ?>
            <a class="btn btn-ghost" href="<?= e(url('auth/login.php')) ?>">Sign in to my account <span aria-hidden="true">→</span></a>
          <?php endif; ?>
        </p>
        <p class="bk-muted">Questions? Email <a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a><?php
          if (setting('phone_1') !== ''): ?> or call <?= e(setting('phone_1')) ?><?php endif; ?>.</p>
      </aside>
    </div>
  </div>
</section>

<?php site_footer(); ?>

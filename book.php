<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_installed();
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/booking.php';

start_session();

/* Post / Redirect / Get, so a refresh never books twice. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    [$status, $message, $booking] = booking_handle();

    if ($status === 'ok' && $booking) {
        redirect('book.php?ref=' . rawurlencode($booking['reference']) . '&t=' . rawurlencode($booking['token']));
    }

    $_SESSION['booking_flash'] = ['status' => $status, 'message' => $message, 'old' => $_POST];
    redirect('book.php#booking-form');
}

$flash = $_SESSION['booking_flash'] ?? ['status' => '', 'message' => '', 'old' => []];
unset($_SESSION['booking_flash']);
$status = (string) $flash['status'];
$message = (string) $flash['message'];
$old = (array) ($flash['old'] ?? []);

/* A completed booking is shown as a receipt instead of the form. */
$confirmed = null;
if (isset($_GET['ref'])) {
    $confirmed = booking_find_by_token((string) $_GET['ref'], (string) ($_GET['t'] ?? ''));
    if (!$confirmed) {
        $status = 'error';
        $message = 'That booking link is not valid. Please check the address, or contact the event team quoting your reference.';
    }
}

$page = site_head('book');
$groups = booking_options();
$labels = booking_group_labels();

/** Value the applicant typed before a validation error sent them back. */
function old_value(array $old, string $key): string
{
    return e((string) ($old[$key] ?? ''));
}

site_header('book');
page_hero($page);
?>

<?php if ($confirmed): /* ============================ BOOKING CONFIRMED */ ?>

  <section class="section" id="booking-form">
    <div class="shell">
      <div class="booking-receipt reveal">
        <div class="receipt-head">
          <span class="receipt-tick" aria-hidden="true">✓</span>
          <div>
            <p class="label">BOOKING RECEIVED</p>
            <h2>Thank you, <em><?= e($confirmed['contact_person']) ?>.</em></h2>
            <p class="lede">Your booking is recorded and the event team has been notified. Keep your reference safe — quote it in your payment and in any email to us.</p>
          </div>
        </div>

        <div class="receipt-facts">
          <div><small>Booking reference</small><strong><?= e($confirmed['reference']) ?></strong></div>
          <div><small>Booked on</small><strong><?= e(date('d M Y, H:i', strtotime($confirmed['created_at']))) ?></strong></div>
          <div><small>Amount due</small><strong><?= e(money_format((float) $confirmed['total'])) ?></strong></div>
          <div><small>Status</small><strong><?= e(booking_statuses()[$confirmed['status']] ?? $confirmed['status']) ?></strong></div>
        </div>

        <h3>What you booked</h3>
        <div class="rate-table receipt-table">
          <div class="rate-row head"><span>Category</span><span>Details</span><span>Amount</span></div>
          <?php foreach (booking_items($confirmed) as $item): ?>
            <div class="rate-row">
              <strong><?= e($item['category']) ?><?= (int) $item['quantity'] > 1 ? ' × ' . (int) $item['quantity'] : '' ?></strong>
              <span class="detail">
                <?= e($item['details']) ?>
                <?php if (($item['note'] ?? '') !== ''): ?><em><?= e($item['note']) ?></em><?php endif; ?>
              </span>
              <b><?= e(money_format((float) $item['amount'])) ?></b>
            </div>
          <?php endforeach; ?>
          <div class="rate-row total"><strong>Total due</strong><span class="detail"></span><b><?= e(money_format((float) $confirmed['total'])) ?></b></div>
        </div>

        <div class="receipt-actions">
          <a class="btn btn-primary" href="admin/booking-pdf.php?ref=<?= rawurlencode($confirmed['reference']) ?>&amp;t=<?= rawurlencode($confirmed['token']) ?>">
            Download your registration form (PDF) <span aria-hidden="true">↓</span>
          </a>
          <a class="btn btn-ghost" href="index.php">Back to the website <span aria-hidden="true">→</span></a>
        </div>
        <p class="receipt-note">Bookmark this page — the link above always brings back your booking and its PDF.</p>
      </div>

      <?php if (setting('bank_account_number') !== ''): ?>
        <div class="info-panel reveal" style="margin-top:2rem">
          <div>
            <h3>Next step — pay to confirm</h3>
            <p style="color:var(--muted)">Stalls and masterclass seats are only reserved once proof of payment is received. Prime stall locations are assigned on a first-paid, first-served basis.</p>
            <dl class="detail-list" style="margin-top:1.25rem">
              <div><dt>Account name</dt><dd><?= e(setting('bank_account_name')) ?></dd></div>
              <div><dt>Bank</dt><dd><?= e(setting('bank_name')) ?></dd></div>
              <div><dt>Account number</dt><dd><?= e(setting('bank_account_number')) ?></dd></div>
              <div><dt>Branch code</dt><dd><?= e(setting('bank_branch_code')) ?></dd></div>
              <div><dt>Account type</dt><dd><?= e(setting('bank_account_type')) ?></dd></div>
              <div><dt>Payment reference</dt><dd><?= e($confirmed['company']) ?></dd></div>
            </dl>
          </div>
          <div>
            <h3>Send your proof of payment</h3>
            <p style="color:var(--muted)">Email your bank confirmation to the address below, quoting booking <strong><?= e($confirmed['reference']) ?></strong>.</p>
            <p style="margin-top:1rem">
              <a class="btn btn-dark" href="mailto:<?= e(setting('payment_proof_email', setting('email_primary'))) ?>?subject=<?= rawurlencode('Proof of payment - ' . $confirmed['reference'] . ' - ' . $confirmed['company']) ?>">
                Email proof of payment <span aria-hidden="true">→</span>
              </a>
            </p>
            <p style="margin-top:1.5rem;color:var(--muted);font-size:.92rem">
              Questions? Call <?= e(setting('phone_1')) ?><?= setting('phone_2') !== '' ? ' or ' . e(setting('phone_2')) : '' ?>.
            </p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>

<?php else: /* ==================================== THE BOOKING FORM ==== */ ?>

  <section class="section on-dark" id="booking-form" style="padding-top:clamp(3rem,6vw,4.5rem)">
    <div class="shell">

      <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $status === 'ok' ? 'ok' : 'error' ?>" role="alert" style="max-width:52rem"><?= e($message) ?></div>
      <?php endif; ?>

      <?php if (!setting_bool('registration_open', true)): ?>
        <div class="alert alert-error" style="max-width:52rem">
          Online booking is closed at the moment. Please contact the event team on <?= e(setting('email_primary')) ?> or <?= e(setting('phone_1')) ?>.
        </div>
      <?php else: ?>

      <form class="booking-form" method="post" action="book.php#booking-form" novalidate>
        <?= csrf_field() ?>

        <!-- ------------------------------------------ 1. APPLICANT -->
        <fieldset class="booking-step">
          <legend><span class="step-no">1</span> Applicant details</legend>

          <div class="field-row">
            <label class="field"><span>Company / participant name *</span>
              <input type="text" name="company" required maxlength="190" autocomplete="organization" value="<?= old_value($old, 'company') ?>">
            </label>
            <label class="field"><span>Contact person *</span>
              <input type="text" name="contact_person" required maxlength="190" autocomplete="name" value="<?= old_value($old, 'contact_person') ?>">
            </label>
          </div>

          <div class="field-row">
            <label class="field"><span>Phone / WhatsApp *</span>
              <input type="tel" name="phone" required maxlength="60" autocomplete="tel" value="<?= old_value($old, 'phone') ?>">
            </label>
            <label class="field"><span>Email *</span>
              <input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= old_value($old, 'email') ?>">
            </label>
          </div>

          <label class="field"><span>Product</span>
            <input type="text" name="product" maxlength="500" placeholder="What you will be showing or selling" value="<?= old_value($old, 'product') ?>">
          </label>

          <label class="field"><span>Special stall size or requirements</span>
            <textarea name="special_requirements" rows="3" maxlength="1000" placeholder="Power, extra tables, a corner position, anything else we should plan for"><?= old_value($old, 'special_requirements') ?></textarea>
          </label>
        </fieldset>

        <!-- --------------------------------------------- 2. OPTIONS -->
        <fieldset class="booking-step">
          <legend><span class="step-no">2</span> Registration options</legend>
          <p class="step-intro">Tick everything you would like to book and set the quantity. Your total updates as you choose.</p>

          <?php
          $selectedOld = array_map('intval', (array) ($old['options'] ?? []));
          $qtyOld = (array) ($old['qty'] ?? []);
          foreach ($groups as $key => $rows):
              if (!$rows) { continue; }
              ?>
            <h3 class="option-group-title"><?= e($labels[$key]) ?></h3>
            <div class="option-list">
              <?php foreach ($rows as $row): ?>
                <?php $id = (int) $row['id']; ?>
                <label class="option-card" data-option>
                  <input type="checkbox" name="options[]" value="<?= $id ?>"
                         data-price="<?= e((string) $row['value']) ?>"
                         data-kind="<?= e((string) ($row['kind'] ?? 'other')) ?>"
                         data-free-with-stall="<?= (int) ($row['free_with_stall'] ?? 0) ?>"
                         <?= in_array($id, $selectedOld, true) ? 'checked' : '' ?>>
                  <span class="option-box" aria-hidden="true"></span>
                  <span class="option-body">
                    <strong><?= e($row['category']) ?></strong>
                    <?php if ($row['details'] !== ''): ?><span class="option-detail"><?= e($row['details']) ?></span><?php endif; ?>
                    <?php if ($row['note'] !== ''): ?><span class="option-note"><?= e($row['note']) ?></span><?php endif; ?>
                  </span>
                  <span class="option-rate"><?= e($row['rate']) ?></span>
                  <span class="option-qty">
                    <span>Qty</span>
                    <input type="number" name="qty[<?= $id ?>]" min="1" max="50"
                           value="<?= e((string) max(1, (int) ($qtyOld[$id] ?? 1))) ?>"
                           aria-label="Quantity for <?= e($row['category']) ?>">
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>

          <div class="booking-total" data-total-box>
            <div>
              <small>Your total</small>
              <strong data-total>N$ 0.00</strong>
            </div>
            <p data-total-note hidden>Your AI Masterclass ticket is free because you are booking a stall.</p>
          </div>
        </fieldset>

        <!-- --------------------------------------------- 3. PAYMENT -->
        <?php if (setting('bank_account_number') !== ''): ?>
          <fieldset class="booking-step">
            <legend><span class="step-no">3</span> Payment details</legend>
            <p class="step-intro">
              To confirm your registration, pay by direct bank transfer after submitting this form and email your proof of payment to
              <a href="mailto:<?= e(setting('payment_proof_email', setting('email_primary'))) ?>"><?= e(setting('payment_proof_email', setting('email_primary'))) ?></a>.
            </p>
            <dl class="detail-list bank-list">
              <div><dt>Account name</dt><dd><?= e(setting('bank_account_name')) ?></dd></div>
              <div><dt>Bank</dt><dd><?= e(setting('bank_name')) ?></dd></div>
              <div><dt>Account number</dt><dd><?= e(setting('bank_account_number')) ?></dd></div>
              <div><dt>Branch code</dt><dd><?= e(setting('bank_branch_code')) ?></dd></div>
              <div><dt>Account type</dt><dd><?= e(setting('bank_account_type')) ?></dd></div>
              <div><dt>Payment reference</dt><dd>Your company name</dd></div>
            </dl>
          </fieldset>
        <?php endif; ?>

        <!-- ----------------------------------------------- 4. TERMS -->
        <fieldset class="booking-step">
          <legend><span class="step-no">4</span> Terms &amp; conditions</legend>

          <ul class="terms-list">
            <?php foreach (['Confirmation' => 'terms_confirmation', 'Allocations' => 'terms_allocations', 'Cancellations' => 'terms_cancellations'] as $label => $key): ?>
              <?php if (setting($key) !== ''): ?>
                <li><strong><?= e($label) ?>:</strong> <?= e(setting($key)) ?></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>

          <label class="consent">
            <input type="checkbox" name="agreed" value="1" required <?= isset($old['agreed']) ? 'checked' : '' ?>>
            <span>I have read and accept the terms and conditions above, and confirm that the details I have given are correct.</span>
          </label>

          <div class="field-row">
            <label class="field"><span>Signature — type your full name *</span>
              <input type="text" name="signature" required maxlength="190" class="signature-input" value="<?= old_value($old, 'signature') ?>">
            </label>
            <label class="field"><span>Date</span>
              <input type="text" value="<?= e(date('d / m / Y')) ?>" readonly>
            </label>
          </div>

          <?= bot_trap_fields() ?>
          <label class="hp-field" aria-hidden="true" hidden>Leave this empty
            <input type="text" name="website_2" tabindex="-1" autocomplete="off">
          </label>

          <div class="booking-submit">
            <button class="btn btn-primary" type="submit">Submit my booking <span aria-hidden="true">→</span></button>
            <p>You will get a booking reference and a PDF copy of this form straight away.</p>
          </div>
        </fieldset>
      </form>

      <?php endif; ?>
    </div>
  </section>

  <section class="section tight">
    <div class="shell">
      <?php section_head('HOW IT WORKS', 'From this form to your {stall.}'); ?>
      <div class="focus-grid">
        <article class="focus-card reveal"><span class="icon" aria-hidden="true">1</span><h3>Book online</h3><p>Complete the form above. It is the same official registration form, filled in on screen.</p></article>
        <article class="focus-card reveal reveal-delay-1"><span class="icon" aria-hidden="true">2</span><h3>Get your reference</h3><p>You receive a booking reference and a PDF copy of your registration immediately.</p></article>
        <article class="focus-card reveal reveal-delay-2"><span class="icon" aria-hidden="true">3</span><h3>Pay by transfer</h3><p>Transfer the amount due and email your proof of payment, quoting your reference.</p></article>
        <article class="focus-card reveal reveal-delay-3"><span class="icon" aria-hidden="true">4</span><h3>Space confirmed</h3><p>The event team confirms your stall. Prime locations go first-paid, first-served.</p></article>
      </div>
    </div>
  </section>

<?php endif; ?>

<?php site_footer(); ?>

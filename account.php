<?php
declare(strict_types=1);

/**
 * The client dashboard.
 *
 * Every screen goes through require_client(), and every booking is loaded with
 * bk_require_own_booking(), so one client can never reach another's bookings,
 * payments, files or profile.
 */

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/client-ui.php';

start_session();
bk_require_platform();

$client = require_client();
$screen = (string) ($_GET['p'] ?? 'overview');
$id     = (int) ($_GET['id'] ?? 0);

/* ========================================================== POST HANDLERS */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired. Please sign in and try again.');
        redirect('login.php');
    }

    $action = (string) ($_POST['do'] ?? '');

    /* ---- profile ---- */
    if ($action === 'profile') {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone    = trim((string) ($_POST['phone'] ?? ''));

        if ($fullName === '' || $phone === '') {
            bk_flash('error', 'Please keep your name and phone number filled in — we need them for your bookings.');
            redirect('account.php?p=profile');
        }

        db_run(
            'UPDATE clients SET full_name = :n, phone = :p, company = :c, address = :a, city = :city,
                    country = :country, updated_at = :u
              WHERE id = :id',
            [
                ':n'       => mb_substr($fullName, 0, 190),
                ':p'       => mb_substr($phone, 0, 60),
                ':c'       => mb_substr(trim((string) ($_POST['company'] ?? '')), 0, 190),
                ':a'       => mb_substr(trim((string) ($_POST['address'] ?? '')), 0, 300),
                ':city'    => mb_substr(trim((string) ($_POST['city'] ?? '')), 0, 120),
                ':country' => mb_substr(trim((string) ($_POST['country'] ?? '')), 0, 120),
                ':u'       => date('Y-m-d H:i:s'),
                ':id'      => (int) $client['id'],
            ]
        );

        bk_flash('ok', 'Your details have been saved.');
        redirect('account.php?p=profile');
    }

    /* ---- password ---- */
    if ($action === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $new     = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!password_verify($current, (string) $client['password_hash'])) {
            bk_flash('error', 'Your current password is not correct.');
        } elseif ($new !== $confirm) {
            bk_flash('error', 'The two new passwords do not match.');
        } elseif (($problem = client_password_problem($new)) !== null) {
            bk_flash('error', $problem);
        } else {
            db_run('UPDATE clients SET password_hash = :h, updated_at = :u WHERE id = :id', [
                ':h' => password_hash($new, PASSWORD_DEFAULT), ':u' => date('Y-m-d H:i:s'), ':id' => (int) $client['id'],
            ]);
            bk_mail([
                'to'        => (string) $client['email'],
                'subject'   => 'Your password has been changed',
                'template'  => 'password_changed',
                'client_id' => (int) $client['id'],
                'html'      => bk_email_html(
                    'Your password has been changed',
                    'The password on your account was changed just now.',
                    [['When', date('d F Y \a\t H:i')]],
                    [],
                    'If this was not you, please contact us straight away on ' . setting('email_primary') . '.'
                ),
            ]);
            bk_flash('ok', 'Your password has been changed.');
        }
        redirect('account.php?p=password');
    }

    /* ---- cancellation / reschedule requests ---- */
    if ($action === 'request_cancel' || $action === 'request_reschedule') {
        $booking = bk_require_own_booking((int) ($_POST['booking_id'] ?? 0));
        $type = $action === 'request_cancel' ? 'cancel' : 'reschedule';

        $result = bk_create_request($booking, $type, [
            'reason'         => (string) ($_POST['reason'] ?? ''),
            'requested_date' => (string) ($_POST['requested_date'] ?? ''),
            'requested_time' => (string) ($_POST['requested_time'] ?? ''),
        ]);

        bk_flash($result['ok'] ? 'ok' : 'error', $result['message']);
        redirect('account.php?p=booking&id=' . (int) $booking['id']);
    }
}

/* =============================================================== RENDERING */

$counts = [
    'upcoming'  => count(bk_client_bookings((int) $client['id'], 'upcoming', 500)),
    'awaiting'  => (int) (db_one(
        "SELECT COUNT(*) AS c FROM service_bookings WHERE client_id = :c AND status IN ('awaiting_eft','declined')",
        [':c' => (int) $client['id']]
    )['c'] ?? 0),
    'review'    => (int) (db_one(
        "SELECT COUNT(*) AS c FROM service_bookings WHERE client_id = :c AND status IN ('proof_submitted','under_review')",
        [':c' => (int) $client['id']]
    )['c'] ?? 0),
    'confirmed' => (int) (db_one(
        "SELECT COUNT(*) AS c FROM service_bookings WHERE client_id = :c AND status IN ('confirmed','payment_confirmed','completed')",
        [':c' => (int) $client['id']]
    )['c'] ?? 0),
];

$titles = [
    'overview' => 'My bookings',
    'bookings' => 'All my bookings',
    'booking'  => 'Booking details',
    'profile'  => 'My details',
    'password' => 'Change my password',
    'history'  => 'Payment history',
];
$title = $titles[$screen] ?? 'My account';

$page = site_head('account', $title, '', true);
site_header('account');
bk_hero(
    'MY ACCOUNT',
    $screen === 'overview' ? 'Hello, {' . bk_first_name((string) $client['full_name']) . '.}' : $title,
    $screen === 'overview' ? 'Everything you have booked with us, and where each payment has got to.' : ''
);
?>

<section class="section">
  <div class="shell">
    <?php bk_flash_render(); ?>

    <?php if (client_needs_verification($client)): ?>
      <div class="alert alert-warn" role="status">
        Your email address is not confirmed yet. Please open the link we emailed you, or
        <a href="verify-email.php">ask for a new one</a>. You need a confirmed address before you can book.
      </div>
    <?php endif; ?>

    <nav class="bk-tabs" aria-label="Account sections">
      <a href="account.php"<?= $screen === 'overview' ? ' class="on"' : '' ?>>Overview</a>
      <a href="account.php?p=bookings"<?= in_array($screen, ['bookings', 'booking'], true) ? ' class="on"' : '' ?>>My bookings</a>
      <a href="account.php?p=history"<?= $screen === 'history' ? ' class="on"' : '' ?>>Payments</a>
      <a href="account.php?p=profile"<?= $screen === 'profile' ? ' class="on"' : '' ?>>My details</a>
      <a href="account.php?p=password"<?= $screen === 'password' ? ' class="on"' : '' ?>>Password</a>
      <a href="login.php?do=logout" class="bk-tab-out">Sign out</a>
    </nav>

<?php
switch ($screen) {
    case 'booking':  account_booking_view($id); break;
    case 'bookings': account_bookings_view($client); break;
    case 'profile':  account_profile_view($client); break;
    case 'password': account_password_view(); break;
    case 'history':  account_history_view($client); break;
    default:         account_overview_view($client, $counts);
}
?>
  </div>
</section>

<?php site_footer(); ?>

<?php
/* ================================================================= VIEWS */

/** First word of a name, for the greeting. */
function bk_first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    return (string) ($parts[0] ?? 'there');
}

function account_overview_view(array $client, array $counts): void
{
    $upcoming = bk_client_bookings((int) $client['id'], 'upcoming', 6);
    $needsAction = db_all(
        "SELECT * FROM service_bookings
          WHERE client_id = :c AND status IN ('awaiting_eft','declined')
          ORDER BY booking_date ASC LIMIT 6",
        [':c' => (int) $client['id']]
    );
    ?>
    <div class="bk-stat-row">
      <div class="bk-stat"><strong><?= (int) $counts['upcoming'] ?></strong><span>Upcoming</span></div>
      <div class="bk-stat<?= $counts['awaiting'] > 0 ? ' is-alert' : '' ?>"><strong><?= (int) $counts['awaiting'] ?></strong><span>Need payment</span></div>
      <div class="bk-stat"><strong><?= (int) $counts['review'] ?></strong><span>Under review</span></div>
      <div class="bk-stat is-good"><strong><?= (int) $counts['confirmed'] ?></strong><span>Confirmed</span></div>
    </div>

    <?php if ($needsAction): ?>
      <h2 class="bk-section-title">Waiting for your payment</h2>
      <div class="bk-booking-list">
        <?php foreach ($needsAction as $row) { account_booking_row($row, true); } ?>
      </div>
    <?php endif; ?>

    <h2 class="bk-section-title">Coming up</h2>
    <?php if (!$upcoming): ?>
      <div class="bk-panel bk-panel-centred">
        <p class="lede">You have no upcoming bookings.</p>
        <p><a class="btn btn-primary" href="booking.php">Make a booking <span aria-hidden="true">→</span></a></p>
      </div>
    <?php else: ?>
      <div class="bk-booking-list">
        <?php foreach ($upcoming as $row) { account_booking_row($row); } ?>
      </div>
      <p class="bk-list-more"><a class="text-link" href="account.php?p=bookings">See all my bookings →</a></p>
    <?php endif; ?>
    <?php
}

function account_bookings_view(array $client): void
{
    $when = (string) ($_GET['when'] ?? 'all');
    $when = in_array($when, ['all', 'upcoming', 'past'], true) ? $when : 'all';
    $rows = bk_client_bookings((int) $client['id'], $when, 200);
    ?>
    <div class="bk-filter-row">
      <div class="bk-tabs bk-tabs-small">
        <a href="account.php?p=bookings&amp;when=all"<?= $when === 'all' ? ' class="on"' : '' ?>>All</a>
        <a href="account.php?p=bookings&amp;when=upcoming"<?= $when === 'upcoming' ? ' class="on"' : '' ?>>Upcoming</a>
        <a href="account.php?p=bookings&amp;when=past"<?= $when === 'past' ? ' class="on"' : '' ?>>Past</a>
      </div>
      <a class="btn btn-primary" href="booking.php">New booking <span aria-hidden="true">→</span></a>
    </div>

    <?php if (!$rows): ?>
      <div class="bk-panel bk-panel-centred">
        <p class="lede">Nothing here yet.</p>
        <p><a class="btn btn-primary" href="booking.php">Make your first booking <span aria-hidden="true">→</span></a></p>
      </div>
    <?php else: ?>
      <div class="bk-booking-list">
        <?php foreach ($rows as $row) { account_booking_row($row); } ?>
      </div>
    <?php endif; ?>
    <?php
}

/** One booking, as it appears in a list. */
function account_booking_row(array $row, bool $highlight = false): void
{
    $confirmed = in_array((string) $row['status'], ['confirmed', 'payment_confirmed', 'completed'], true);
    ?>
    <article class="bk-booking-card<?= $highlight ? ' is-alert' : '' ?>">
      <div class="bk-booking-when">
        <strong><?= e(date('j M', strtotime((string) $row['booking_date']) ?: time())) ?></strong>
        <span><?= e(date('Y', strtotime((string) $row['booking_date']) ?: time())) ?></span>
        <small><?= e((string) $row['start_time']) ?></small>
      </div>
      <div class="bk-booking-main">
        <h3><?= e((string) $row['service_name']) ?></h3>
        <p class="bk-booking-ref"><?= e((string) $row['reference']) ?>
          <?php if ((int) $row['guests'] > 1): ?> · <?= (int) $row['guests'] ?> guests<?php endif; ?>
          · <?= e(bk_money((float) $row['amount_due'], (string) $row['currency'])) ?>
        </p>
        <p class="bk-booking-note"><?= e(bk_progress_note($row)) ?></p>
      </div>
      <div class="bk-booking-side">
        <?= bk_pill((string) $row['status']) ?>
        <div class="bk-booking-actions">
          <a class="btn btn-ghost btn-small" href="account.php?p=booking&amp;id=<?= (int) $row['id'] ?>">Open</a>
          <?php if (in_array((string) $row['status'], bk_awaiting_payment_statuses(), true)): ?>
            <a class="btn btn-small btn-primary" href="pay.php?id=<?= (int) $row['id'] ?>">Pay / upload</a>
          <?php elseif ($confirmed): ?>
            <a class="btn btn-small btn-primary" href="download.php?kind=ticket&amp;booking=<?= (int) $row['id'] ?>">Ticket ↓</a>
          <?php endif; ?>
        </div>
      </div>
    </article>
    <?php
}

function account_booking_view(int $id): void
{
    $booking = bk_require_own_booking($id);
    $payment = bk_payment_ensure($booking);
    $proofs  = bk_proofs($id);
    $guests  = bk_guests($id);
    $ticket  = bk_ticket_for($id);
    $receipt = bk_receipt_for($id);
    $requests = bk_requests($id);
    $pending = bk_pending_request($id);

    $confirmed = in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true);
    $canPay = in_array((string) $booking['status'], bk_awaiting_payment_statuses(), true);
    $cancelRule = bk_can_request_cancel($booking);
    $moveRule = bk_can_request_reschedule($booking);
    $service = bk_service((int) $booking['service_id']);
    ?>
    <p class="bk-back"><a class="text-link" href="account.php?p=bookings">← All my bookings</a></p>

    <?php bk_journey_render($booking); ?>

    <div class="bk-review">
      <div class="bk-panel">
        <div class="bk-panel-head">
          <div>
            <h2 class="bk-panel-title"><?= e((string) $booking['service_name']) ?></h2>
            <p class="bk-panel-lead"><?= e((string) $booking['reference']) ?> · booked <?= e(date('d M Y', strtotime((string) $booking['created_at']) ?: time())) ?></p>
          </div>
          <?= bk_pill((string) $booking['status']) ?>
        </div>

        <div class="alert <?= $confirmed ? 'alert-ok' : ((string) $booking['status'] === 'declined' ? 'alert-error' : 'alert-warn') ?>" role="status">
          <?= e(bk_progress_note($booking)) ?>
        </div>

        <?php if ((string) $booking['status'] === 'declined' && trim((string) $booking['decline_reason']) !== ''): ?>
          <div class="bk-callout bk-callout-bad">
            <strong>Why your proof of payment was not accepted</strong>
            <p><?= nl2br(e((string) $booking['decline_reason'])) ?></p>
            <p><a class="btn btn-primary btn-small" href="pay.php?id=<?= (int) $booking['id'] ?>">Upload a corrected proof <span aria-hidden="true">→</span></a></p>
          </div>
        <?php endif; ?>

        <?php
        $facts = bk_booking_facts($booking);
        $facts[] = ['Amount paid', bk_money((float) $booking['amount_paid'], (string) $booking['currency'])];
        if (trim((string) $booking['extra_details']) !== '' && $service && trim((string) $service['extra_field_label']) !== '') {
            $facts[] = [(string) $service['extra_field_label'], (string) $booking['extra_details']];
        }
        if (trim((string) $booking['client_notes']) !== '') {
            $facts[] = ['Your notes', (string) $booking['client_notes']];
        }
        if ($guests) {
            $facts[] = ['Guests', implode("\n", array_column($guests, 'full_name'))];
        }
        bk_facts_render($facts);
        ?>

        <div class="bk-cta-row">
          <?php if ($canPay): ?>
            <a class="btn btn-primary" href="pay.php?id=<?= (int) $booking['id'] ?>">
              <?= (string) $booking['status'] === 'awaiting_eft' ? 'Pay by EFT / upload proof' : 'Replace my proof of payment' ?>
              <span aria-hidden="true">→</span>
            </a>
          <?php endif; ?>
          <?php if ($confirmed && $ticket && (string) $ticket['status'] !== 'void'): ?>
            <a class="btn btn-primary" href="download.php?kind=ticket&amp;booking=<?= (int) $booking['id'] ?>">Download my ticket <span aria-hidden="true">↓</span></a>
          <?php endif; ?>
          <?php if ($confirmed && $receipt): ?>
            <a class="btn btn-ghost" href="download.php?kind=receipt&amp;booking=<?= (int) $booking['id'] ?>">Download my receipt <span aria-hidden="true">↓</span></a>
          <?php endif; ?>
        </div>

        <?php if ($ticket && (string) $ticket['status'] !== 'void' && $confirmed): ?>
          <div class="bk-callout">
            <strong>Your ticket</strong>
            <p>Ticket <?= e((string) $ticket['ticket_number']) ?> · verification code
               <span class="bk-code"><?= e((string) $ticket['verification_code']) ?></span></p>
          </div>
        <?php endif; ?>

        <?php if ($proofs): ?>
          <hr class="bk-rule">
          <h3 class="bk-sub-title">Proof of payment</h3>
          <ul class="bk-proof-list">
            <?php foreach ($proofs as $proof): ?>
              <li class="is-<?= e((string) $proof['status']) ?>">
                <div>
                  <strong><?= e((string) ($proof['original_name'] ?: 'Proof of payment')) ?></strong>
                  <small><?= e(strtoupper((string) $proof['extension'])) ?> · <?= e(bk_filesize((int) $proof['size'])) ?>
                    · <?= e(date('d M Y, H:i', strtotime((string) $proof['created_at']) ?: time())) ?></small>
                  <?php if ((string) $proof['status'] === 'declined' && trim((string) $proof['review_reason']) !== ''): ?>
                    <small class="bk-proof-reason">Not accepted: <?= e((string) $proof['review_reason']) ?></small>
                  <?php endif; ?>
                </div>
                <span class="bk-proof-state"><?= e([
                    'submitted' => 'Awaiting review', 'approved' => 'Approved',
                    'declined' => 'Not accepted', 'superseded' => 'Replaced',
                ][(string) $proof['status']] ?? (string) $proof['status']) ?></span>
                <a class="btn btn-ghost btn-small" href="download.php?kind=proof&amp;id=<?= (int) $proof['id'] ?>">View</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if ($requests): ?>
          <hr class="bk-rule">
          <h3 class="bk-sub-title">Your requests</h3>
          <ul class="bk-request-list">
            <?php foreach ($requests as $request): ?>
              <li>
                <strong><?= e($request['type'] === 'cancel' ? 'Cancellation requested' : 'Change of date requested') ?></strong>
                <small><?= e(date('d M Y, H:i', strtotime((string) $request['created_at']) ?: time())) ?>
                  <?php if ($request['type'] === 'reschedule' && $request['requested_date'] !== ''): ?>
                    · to <?= e(bk_date_long((string) $request['requested_date'])) ?> at <?= e((string) $request['requested_time']) ?>
                  <?php endif; ?>
                  · <?= e(ucfirst((string) $request['status'])) ?>
                </small>
                <?php if (trim((string) $request['admin_response']) !== ''): ?>
                  <small class="bk-proof-reason"><?= e((string) $request['admin_response']) ?></small>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <aside class="bk-aside">
        <?php if ($canPay): ?>
          <h3>How to pay</h3>
          <?php bk_facts_render(bk_bank_facts($booking), 'detail-list bk-aside-facts'); ?>
          <p class="bk-muted"><?= e(bk('bk_payment_note')) ?></p>
          <hr class="bk-rule">
        <?php endif; ?>

        <h3>Change this booking</h3>
        <?php if ($pending): ?>
          <p class="bk-muted">You have a <?= e($pending['type'] === 'cancel' ? 'cancellation' : 'date change') ?>
             request waiting for our team. We will email you as soon as it is dealt with.</p>
        <?php else: ?>

          <?php if ($moveRule['allowed'] && $service): ?>
            <details class="bk-details">
              <summary>Ask to move this booking</summary>
              <form method="post" action="account.php" class="bk-form bk-form-tight">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="request_reschedule">
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">

                <label class="field"><span>New date</span>
                  <select name="requested_date" required>
                    <option value="">— choose a date —</option>
                    <?php foreach (bk_open_dates($service, 30, (int) $booking['id']) as $entry): ?>
                      <option value="<?= e($entry['date']) ?>"><?= e(bk_date_long($entry['date'])) ?> (<?= (int) $entry['open'] ?> open)</option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="field"><span>New time</span>
                  <input type="time" name="requested_time" required step="60">
                  <span class="field-hint">Use one of the times shown on the booking page for that date.</span>
                </label>
                <label class="field"><span>Why are you moving it?</span>
                  <textarea name="reason" rows="2" maxlength="1000"></textarea>
                </label>
                <button class="btn btn-primary btn-small" type="submit">Send the request</button>
              </form>
            </details>
          <?php else: ?>
            <p class="bk-muted"><?= e($moveRule['reason']) ?></p>
          <?php endif; ?>

          <?php if ($cancelRule['allowed']): ?>
            <details class="bk-details">
              <summary>Ask to cancel this booking</summary>
              <form method="post" action="account.php" class="bk-form bk-form-tight">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="request_cancel">
                <input type="hidden" name="booking_id" value="<?= (int) $booking['id'] ?>">
                <label class="field"><span>Reason for cancelling</span>
                  <textarea name="reason" rows="3" maxlength="1000" required></textarea>
                </label>
                <button class="btn btn-small btn-danger" type="submit">Request cancellation</button>
              </form>
            </details>
            <?php if (trim(bk('bk_cancel_policy')) !== ''): ?>
              <p class="bk-muted bk-policy"><?= nl2br(e(bk('bk_cancel_policy'))) ?></p>
            <?php endif; ?>
          <?php else: ?>
            <p class="bk-muted"><?= e($cancelRule['reason']) ?></p>
          <?php endif; ?>

        <?php endif; ?>

        <p class="bk-muted">Need something else? Email
          <a href="mailto:<?= e(setting('email_primary')) ?>?subject=<?= rawurlencode('Booking ' . (string) $booking['reference']) ?>"><?= e(setting('email_primary')) ?></a>.</p>
      </aside>
    </div>
    <?php
}

function account_history_view(array $client): void
{
    $rows = db_all(
        'SELECT p.*, b.service_name, b.booking_date, b.contact_name, b.id AS booking_row_id
           FROM eft_payments p
           JOIN service_bookings b ON b.id = p.booking_id
          WHERE b.client_id = :c
          ORDER BY p.id DESC LIMIT 200',
        [':c' => (int) $client['id']]
    );
    ?>
    <h2 class="bk-section-title">Payment history</h2>

    <?php if (!$rows): ?>
      <div class="bk-panel bk-panel-centred"><p class="lede">No payments recorded yet.</p></div>
    <?php else: ?>
      <div class="bk-panel bk-panel-flush">
        <div class="bk-table-wrap">
          <table class="bk-table">
            <thead><tr><th>Reference</th><th>Service</th><th>Booking date</th><th>Amount due</th><th>Paid</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td data-label="Reference"><strong><?= e((string) $row['reference']) ?></strong></td>
                <td data-label="Service"><?= e((string) $row['service_name']) ?></td>
                <td data-label="Booking date"><?= e(bk_date_long((string) $row['booking_date'])) ?></td>
                <td data-label="Amount due"><?= e(bk_money((float) $row['amount_due'], (string) $row['currency'])) ?></td>
                <td data-label="Paid"><?= (float) $row['amount_received'] > 0 ? e(bk_money((float) $row['amount_received'], (string) $row['currency'])) : '—' ?></td>
                <td data-label="Status"><?= e([
                    'awaiting' => 'Awaiting payment', 'submitted' => 'Proof submitted',
                    'under_review' => 'Under review', 'confirmed' => 'Confirmed',
                    'declined' => 'Declined', 'refunded' => 'Refunded',
                ][(string) $row['status']] ?? (string) $row['status']) ?></td>
                <td data-label="" class="bk-right">
                  <a class="btn btn-ghost btn-small" href="account.php?p=booking&amp;id=<?= (int) $row['booking_row_id'] ?>">Open</a>
                  <?php if ((string) $row['status'] === 'confirmed'): ?>
                    <a class="btn btn-ghost btn-small" href="download.php?kind=receipt&amp;booking=<?= (int) $row['booking_row_id'] ?>">Receipt ↓</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <?php
}

function account_profile_view(array $client): void
{
    ?>
    <div class="bk-narrow">
      <div class="bk-panel">
        <h2 class="bk-panel-title">My details</h2>
        <p class="bk-panel-lead">These details are filled in for you each time you make a booking.</p>

        <form method="post" action="account.php" class="bk-form" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="profile">

          <div class="field-row">
            <label class="field"><span>Full name *</span>
              <input type="text" name="full_name" required maxlength="190" value="<?= e((string) $client['full_name']) ?>">
            </label>
            <label class="field"><span>Email address</span>
              <input type="email" value="<?= e((string) $client['email']) ?>" readonly>
              <span class="field-hint">
                <?= trim((string) $client['email_verified_at']) !== ''
                    ? 'Confirmed. Contact us if you need it changed.'
                    : 'Not confirmed yet.' ?>
              </span>
            </label>
          </div>

          <div class="field-row">
            <label class="field"><span>Phone / WhatsApp *</span>
              <input type="tel" name="phone" required maxlength="60" value="<?= e((string) $client['phone']) ?>">
            </label>
            <label class="field"><span>Company or organisation</span>
              <input type="text" name="company" maxlength="190" value="<?= e((string) $client['company']) ?>">
            </label>
          </div>

          <label class="field"><span>Address</span>
            <textarea name="address" rows="2" maxlength="300"><?= e((string) $client['address']) ?></textarea>
          </label>

          <div class="field-row">
            <label class="field"><span>Town or city</span>
              <input type="text" name="city" maxlength="120" value="<?= e((string) $client['city']) ?>">
            </label>
            <label class="field"><span>Country</span>
              <input type="text" name="country" maxlength="120" value="<?= e((string) $client['country']) ?>">
            </label>
          </div>

          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Save my details</button>
          </div>
        </form>
      </div>
    </div>
    <?php
}

function account_password_view(): void
{
    ?>
    <div class="bk-narrow">
      <div class="bk-panel">
        <h2 class="bk-panel-title">Change my password</h2>
        <form method="post" action="account.php" class="bk-form" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="password">

          <label class="field"><span>Current password</span>
            <input type="password" name="current" required autocomplete="current-password">
          </label>
          <div class="field-row">
            <label class="field"><span>New password</span>
              <input type="password" name="new" required minlength="10" autocomplete="new-password">
              <span class="field-hint">At least 10 characters.</span>
            </label>
            <label class="field"><span>Repeat the new password</span>
              <input type="password" name="confirm" required minlength="10" autocomplete="new-password">
            </label>
          </div>

          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Change my password</button>
          </div>
        </form>
      </div>
    </div>
    <?php
}

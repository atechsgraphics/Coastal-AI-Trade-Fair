<?php
declare(strict_types=1);

/**
 * Ticket verification for staff on the door.
 *
 * A ticket can be looked up by its QR code (which links straight here),
 * its verification code, its ticket number or the booking reference.
 *
 * The page shows only what somebody checking people in needs to see. Marking
 * a ticket as checked in requires a signed-in member of staff.
 */

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/client-ui.php';
require __DIR__ . '/inc/admin-lib.php';

start_session();
bk_require_platform();

$staff  = admin_user();
$query  = trim((string) ($_GET['code'] ?? ($_POST['code'] ?? '')));
$ticket = null;
$booking = null;
$message = '';

/* ------------------------------------------------------------- check in */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['do'] ?? '') === 'checkin') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired. Please scan again.');
        redirect('verify-ticket.php');
    }
    if (!$staff) {
        bk_flash('error', 'Only signed-in staff can check a ticket in.');
        redirect('verify-ticket.php?code=' . rawurlencode($query));
    }

    $target = db_one('SELECT * FROM booking_tickets WHERE id = :id', [':id' => (int) ($_POST['ticket_id'] ?? 0)]);
    if ($target && (string) $target['status'] === 'valid') {
        db_run(
            "UPDATE booking_tickets SET status = 'used', checked_in_at = :t, checked_in_by = :by WHERE id = :id",
            [
                ':t'  => date('Y-m-d H:i:s'),
                ':by' => (string) ($staff['name'] ?: $staff['username']),
                ':id' => (int) $target['id'],
            ]
        );
        bk_audit((int) $target['booking_id'], 'ticket checked in', (string) $target['ticket_number']);
        bk_flash('ok', 'Ticket ' . $target['ticket_number'] . ' checked in.');
    } else {
        bk_flash('error', 'That ticket could not be checked in.');
    }

    redirect('verify-ticket.php?code=' . rawurlencode((string) ($target['verification_code'] ?? $query)));
}

/* --------------------------------------------------------------- lookup */

if ($query !== '') {
    if (!rate_limit('ticketcheck:' . client_ip(), 60, 900)) {
        $message = 'Too many lookups from this connection. Please wait a few minutes.';
    } else {
        $needle = strtoupper($query);

        $ticket = db_one(
            'SELECT * FROM booking_tickets WHERE verification_code = :c OR ticket_number = :n LIMIT 1',
            [':c' => $needle, ':n' => $needle]
        );

        if (!$ticket) {
            $found = bk_booking_by_reference($needle);
            if ($found) {
                $ticket = bk_ticket_for((int) $found['id']);
                $booking = $found;
            }
        }

        if ($ticket) {
            $booking = $booking ?? bk_booking((int) $ticket['booking_id']);
        }
        if (!$ticket || !$booking) {
            $message = 'No ticket matches that code. Check the spelling, or search by the booking reference.';
            $ticket = null;
            $booking = null;
        }
    }
}

/* --------------------------------------------------------------- verdict */

$verdict = 'unknown';
$verdictText = '';

if ($ticket && $booking) {
    if ((string) $ticket['status'] === 'void' || (string) $booking['status'] === 'cancelled') {
        $verdict = 'bad';
        $verdictText = 'Not valid — this booking was cancelled.';
    } elseif (!in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true)) {
        $verdict = 'bad';
        $verdictText = 'Not valid — the EFT payment for this booking has not been confirmed.';
    } elseif ((string) $ticket['status'] === 'used') {
        $verdict = 'warn';
        $verdictText = 'Already checked in on '
            . date('d M Y \a\t H:i', strtotime((string) $ticket['checked_in_at']) ?: time())
            . ((string) $ticket['checked_in_by'] !== '' ? ' by ' . $ticket['checked_in_by'] : '') . '.';
    } elseif ((string) $booking['booking_date'] !== bk_now()->format('Y-m-d')) {
        $verdict = 'warn';
        $verdictText = 'Valid ticket, but it is for ' . bk_date_long((string) $booking['booking_date']) . ', not today.';
    } else {
        $verdict = 'good';
        $verdictText = 'Valid ticket for today.';
    }
}

$page = site_head('verify-ticket', 'Verify a ticket', '', true);
site_header('verify-ticket');
bk_hero('TICKET VERIFICATION', 'Check a {ticket.}', 'Scan the QR code on a ticket, or type the code below.');
?>

<section class="section">
  <div class="shell bk-narrow">
    <?php bk_flash_render(); ?>

    <div class="bk-panel">
      <form method="get" action="verify-ticket.php" class="bk-form bk-verify-form">
        <label class="field"><span>Verification code, ticket number or booking reference</span>
          <input type="text" name="code" maxlength="60" autocomplete="off" autocapitalize="characters"
                 spellcheck="false" placeholder="7K2M-9QXA" value="<?= e($query) ?>" autofocus>
        </label>
        <button class="btn btn-primary" type="submit">Check this ticket <span aria-hidden="true">→</span></button>
      </form>

      <?php if ($message !== ''): ?>
        <div class="alert alert-error" role="alert"><?= e($message) ?></div>
      <?php endif; ?>

      <?php if ($ticket && $booking): ?>
        <div class="bk-verdict bk-verdict-<?= e($verdict) ?>">
          <span class="bk-verdict-mark" aria-hidden="true"><?= $verdict === 'good' ? '✓' : ($verdict === 'warn' ? '!' : '✕') ?></span>
          <div>
            <strong><?= e($verdictText) ?></strong>
            <span><?= e((string) $booking['service_name']) ?></span>
          </div>
        </div>

        <?php
        bk_facts_render([
            ['Name', (string) $booking['contact_name']],
            ['Company', (string) $booking['company']],
            ['Booking reference', (string) $booking['reference']],
            ['Ticket number', (string) $ticket['ticket_number']],
            ['Date', bk_date_long((string) $booking['booking_date'])],
            ['Time', bk_time_range((string) $booking['start_time'], (string) $booking['end_time'])],
            ['Guests / participants', (string) $booking['guests']],
            ['Location', (string) $booking['location']],
            ['Booking status', bk_status_label((string) $booking['status'])],
            ['EFT payment', bk_payment_label($booking)],
            ['Amount paid', bk_money((float) $booking['amount_paid'], (string) $booking['currency'])],
        ]);
        ?>

        <?php if ($staff): ?>
          <?php if ((string) $ticket['status'] === 'valid' && $verdict !== 'bad'): ?>
            <form method="post" action="verify-ticket.php" class="bk-form">
              <?= csrf_field() ?>
              <input type="hidden" name="do" value="checkin">
              <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
              <input type="hidden" name="code" value="<?= e($query) ?>">
              <button class="btn btn-primary" type="submit">Check this guest in</button>
            </form>
          <?php endif; ?>
          <p class="bk-muted"><a class="text-link" href="admin.php?p=eft_bookings&amp;action=view&amp;id=<?= (int) $booking['id'] ?>">Open the full booking in the control panel →</a></p>
        <?php else: ?>
          <p class="bk-muted"><a class="text-link" href="admin.php">Staff: sign in to check this guest in.</a></p>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php site_footer(); ?>

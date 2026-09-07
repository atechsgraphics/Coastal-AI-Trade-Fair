<?php
declare(strict_types=1);

/**
 * The only way a stored file leaves the server.
 *
 * Tickets, receipts and proof-of-payment files live under data/, which the web
 * server never serves. This script checks who is asking before streaming one:
 *
 *   • tickets and receipts  — the client who owns the booking, that booking's
 *                             emailed token, or a signed-in member of staff
 *   • payment proofs        — the client who uploaded it, or staff
 *
 * Nothing here trusts a file name from the request: only database rows decide
 * which file is read.
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/eft.php';
require __DIR__ . '/../app/admin-lib.php';

start_session();

/** Refuse, without saying anything that would help someone guessing. */
function download_deny(string $message = 'That file is not available to this account.'): void
{
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Not available</title>'
        . '<body style="font-family:system-ui,Segoe UI,sans-serif;margin:4rem auto;max-width:34rem;padding:0 1.25rem;line-height:1.65;color:#16242f">'
        . '<h1 style="font-size:1.3rem;margin:0 0 .6rem">Not available</h1><p>' . e($message) . '</p>'
        . '<p><a href="' . e(url('booking/account.php')) . '" style="color:#0aa6cc">Go to my bookings</a></p></body>';
    exit;
}

$kind = strtolower((string) ($_GET['kind'] ?? 'ticket'));
if (!in_array($kind, ['ticket', 'receipt', 'proof'], true)) {
    download_deny('That is not a kind of file we hand out.');
}

$staff  = admin_user();
$client = client_user();

/* ------------------------------------------------------- locate the booking */

$booking = null;

if (isset($_GET['ref'])) {
    $booking = bk_booking_by_token((string) $_GET['ref'], (string) ($_GET['t'] ?? ''));
    if ($booking && $kind === 'proof') {
        // An emailed link is enough for a ticket or receipt, never for a proof.
        $booking = null;
    }
}

if (!$booking) {
    $bookingId = (int) ($_GET['booking'] ?? 0);

    if ($kind === 'proof') {
        $proofId = (int) ($_GET['id'] ?? 0);
        $proof = $proofId > 0 ? db_one('SELECT * FROM payment_proofs WHERE id = :id', [':id' => $proofId]) : null;
        if (!$proof) {
            download_deny('That file no longer exists.');
        }
        $bookingId = (int) $proof['booking_id'];
    }

    $booking = $bookingId > 0 ? bk_booking($bookingId) : null;
    if (!$booking) {
        download_deny('That booking no longer exists.');
    }
    if (!$staff && !bk_client_owns($booking, $client)) {
        if (!$client) {
            $_SESSION['client_after_login'] = bk_current_url();
            redirect('auth/login.php');
        }
        download_deny();
    }
}

/* -------------------------------------------------------- pick the real file */

$path = null;
$filename = '';
$mime = 'application/pdf';

if ($kind === 'ticket') {
    $ticket = bk_ticket_for((int) $booking['id']);

    // Rebuild a missing PDF for a confirmed booking rather than failing.
    if ((!$ticket || (string) $ticket['file_name'] === '')
        && in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true)) {
        $ticket = bk_ticket_issue($booking);
    }
    if (!$ticket || (string) $ticket['file_name'] === '') {
        download_deny('Your ticket is issued once your EFT payment has been verified.');
    }
    if ((string) $ticket['status'] === 'void') {
        download_deny('That ticket is no longer valid because the booking was cancelled.');
    }

    $path = bk_storage_file('tickets', (string) $ticket['file_name']);
    $filename = 'ticket-' . $booking['reference'] . '.pdf';
} elseif ($kind === 'receipt') {
    $receipt = bk_receipt_for((int) $booking['id']);
    if ((!$receipt || (string) $receipt['file_name'] === '')
        && in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true)) {
        $payment = bk_payment_for((int) $booking['id']);
        if ($payment && (string) $payment['status'] === 'confirmed') {
            $receipt = bk_receipt_issue($booking, $payment);
        }
    }
    if (!$receipt || (string) $receipt['file_name'] === '') {
        download_deny('Your receipt is issued once your EFT payment has been verified.');
    }

    $path = bk_storage_file('receipts', (string) $receipt['file_name']);
    $filename = 'receipt-' . $booking['reference'] . '.pdf';
} else {
    $proofId = (int) ($_GET['id'] ?? 0);
    $proof = db_one(
        'SELECT * FROM payment_proofs WHERE id = :id AND booking_id = :b',
        [':id' => $proofId, ':b' => (int) $booking['id']]
    );
    if (!$proof) {
        download_deny('That file no longer exists.');
    }

    $path = bk_storage_file('proofs', (string) $proof['stored_name']);
    $mime = (string) $proof['mime'] ?: 'application/octet-stream';
    $filename = 'proof-' . $booking['reference'] . '.' . (string) $proof['extension'];

    if ($staff) {
        bk_audit((int) $booking['id'], 'proof viewed', (string) ($proof['original_name'] ?: $proof['stored_name']));
    }
}

if ($path === null) {
    download_deny('That file could not be found on the server. Please contact us and we will send it to you.');
}

/* ------------------------------------------------------------------ stream */

$inline = isset($_GET['view']) && $mime !== 'application/octet-stream';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; object-src \'none\'; sandbox');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($path);
exit;

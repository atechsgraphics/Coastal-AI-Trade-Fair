<?php
declare(strict_types=1);

/**
 * Serves one booking as a PDF registration form.
 *
 * Two ways in:
 *   • the applicant, with ?ref=CAS26-0001&t=<token> from their own booking link
 *   • a signed-in member of the event team, with ?id=12
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/booking.php';
require __DIR__ . '/../app/admin-lib.php';

$booking = null;

if (isset($_GET['id'])) {
    if (admin_user()) {
        $booking = booking_find((int) $_GET['id']);
    }
} elseif (isset($_GET['ref'])) {
    $booking = booking_find_by_token((string) $_GET['ref'], (string) ($_GET['t'] ?? ''));
}

if (!$booking) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Booking not found</title>'
        . '<body style="font-family:system-ui,sans-serif;margin:4rem auto;max-width:34rem;line-height:1.6;color:#16242f">'
        . '<h1 style="font-size:1.4rem">Booking not found</h1>'
        . '<p>That booking link is not valid or has expired. Please check the address you were given, '
        . 'or contact the event team at <a href="mailto:' . e(setting('email_primary')) . '">' . e(setting('email_primary')) . '</a> '
        . 'quoting your booking reference.</p>'
        . '<p><a href="index.php">Return to the website</a></p></body>';
    exit;
}

$inline = isset($_GET['view']);
booking_pdf($booking)->download('registration-' . $booking['reference'] . '.pdf', $inline);

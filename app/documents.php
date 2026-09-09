<?php
declare(strict_types=1);

/**
 * EFT booking platform — tickets and receipts.
 * ---------------------------------------------------------------------------
 * Both documents are produced by the PDF generator that already ships with the
 * site in Core/dompdf. Nothing else is used: the templates below are ordinary
 * HTML and CSS, rendered to A4 and written into data/tickets and
 * data/receipts, which the web server never serves directly.
 */

require_once __DIR__ . '/qr.php';

/** The Dompdf autoloader that ships in the Core folder. */
function bk_dompdf_available(): bool
{
    return is_file(ROOT_PATH . '/Core/dompdf/autoload.inc.php');
}

/**
 * A configured Dompdf instance.
 * Remote content is switched off and the file root is pinned to the site
 * folder, so a template can never be tricked into reading somewhere else.
 */
function bk_dompdf(): ?Dompdf\Dompdf
{
    if (!bk_dompdf_available()) {
        return null;
    }
    require_once ROOT_PATH . '/Core/dompdf/autoload.inc.php';
    if (!class_exists('Dompdf\Dompdf')) {
        return null;
    }

    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', ROOT_PATH);
    $options->set('defaultPaperSize', 'a4');

    $tempDir = DATA_PATH . '/tmp';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }
    if (is_dir($tempDir) && is_writable($tempDir)) {
        $options->set('tempDir', $tempDir);
        $options->set('fontDir', $tempDir);
        $options->set('fontCache', $tempDir);
    }

    return new Dompdf\Dompdf($options);
}

/**
 * Render HTML to PDF bytes with the Core generator.
 * Returns null when the generator is missing or the render fails.
 */
function bk_render_pdf(string $html): ?string
{
    $dompdf = bk_dompdf();
    if ($dompdf === null) {
        return null;
    }

    try {
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $output = $dompdf->output();
    } catch (Throwable $e) {
        error_log('Booking PDF render failed: ' . $e->getMessage());
        return null;
    }

    return ($output === null || $output === '') ? null : $output;
}

/* ============================================================== 1. ASSETS */

/**
 * The site logo as a data: URI so the PDF never needs the filesystem.
 *
 * The logo on this site is a large print-resolution PNG, but it prints at
 * about 84 points. Embedding the original would put well over a megabyte into
 * every ticket and receipt — and those go out as email attachments. So the
 * image is scaled down once and the result cached on disk.
 */
function bk_logo_data_uri(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = '';

    $relative = ltrim(setting('logo'), '/');
    if ($relative === '' || preg_match('#^(https?:)?//#i', $relative)) {
        return $cache;
    }

    $real = realpath(ROOT_PATH . '/' . $relative);
    $root = realpath(ROOT_PATH);
    if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
        return $cache;
    }
    if (filesize($real) > 8 * 1024 * 1024) {
        return $cache;
    }

    $info = @getimagesize($real);
    if ($info === false || !isset($info['mime'])) {
        return $cache;
    }

    $small = bk_logo_thumbnail($real, (string) $info['mime'], (int) $info[0]);
    if ($small !== null) {
        $cache = 'data:image/png;base64,' . base64_encode($small);
        return $cache;
    }

    // Small enough already, or GD could not read it: embed as it is.
    $cache = 'data:' . $info['mime'] . ';base64,' . base64_encode((string) file_get_contents($real));
    return $cache;
}

/** Width, in pixels, that the logo is reduced to for the documents. */
const BK_LOGO_WIDTH = 360;

/**
 * A cached, downscaled PNG of the logo, or null when no scaling is needed.
 * The original file is never touched.
 */
function bk_logo_thumbnail(string $path, string $mime, int $width): ?string
{
    if ($width <= BK_LOGO_WIDTH || !function_exists('imagecreatetruecolor')) {
        return null;
    }

    $cacheDir = DATA_PATH . '/tmp';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $key = substr(hash('sha256', $path . '|' . (string) @filemtime($path) . '|' . BK_LOGO_WIDTH), 0, 24);
    $cacheFile = $cacheDir . '/logo-' . $key . '.png';

    if (is_file($cacheFile)) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false && $cached !== '') {
            return $cached;
        }
    }

    $source = match ($mime) {
        'image/png'  => @imagecreatefrompng($path),
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/gif'  => @imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default      => false,
    };
    if (!$source) {
        return null;
    }

    $sourceWidth  = imagesx($source);
    $sourceHeight = imagesy($source);
    $targetWidth  = BK_LOGO_WIDTH;
    $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, (int) imagecolorallocatealpha($target, 0, 0, 0, 127));
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    ob_start();
    imagepng($target, null, 9);
    $png = (string) ob_get_clean();

    imagedestroy($source);
    imagedestroy($target);

    if ($png === '') {
        return null;
    }

    @file_put_contents($cacheFile, $png);
    return $png;
}

/** The address a scanned ticket sends staff to. */
function bk_ticket_verify_url(array $ticket): string
{
    return bk_url('booking/verify-ticket.php') . '?code=' . rawurlencode((string) $ticket['verification_code']);
}

/* ============================================================= 2. TICKETS */

/** The ticket for a booking, if one has been issued. */
function bk_ticket_for(int $bookingId): ?array
{
    return db_one('SELECT * FROM booking_tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $bookingId]);
}

function bk_ticket_by_number(string $number): ?array
{
    return db_one('SELECT * FROM booking_tickets WHERE ticket_number = :n', [':n' => trim($number)]);
}

function bk_ticket_by_code(string $code): ?array
{
    return db_one('SELECT * FROM booking_tickets WHERE verification_code = :c', [':c' => strtoupper(trim($code))]);
}

/**
 * Issue the digital ticket for a confirmed booking and write its PDF.
 * An existing ticket is reused; only the PDF is rebuilt when it has gone
 * missing or the booking details have changed.
 *
 * @return array|null the ticket row
 */
function bk_ticket_issue(array $booking, bool $rebuild = false): ?array
{
    $bookingId = (int) $booking['id'];
    $ticket = bk_ticket_for($bookingId);
    $now = date('Y-m-d H:i:s');

    if (!$ticket) {
        $number = bk_document_number('booking_tickets', 'ticket_number', 'TKT');
        $code   = bk_verification_code();

        db_run(
            'INSERT INTO booking_tickets (booking_id, ticket_number, verification_code, file_name, status, issued_at, created_at)
             VALUES (:b, :n, :c, :f, :s, :i, :cr)',
            [
                ':b' => $bookingId, ':n' => $number, ':c' => $code,
                ':f' => '', ':s' => 'valid', ':i' => $now, ':cr' => $now,
            ]
        );

        $ticket = bk_ticket_for($bookingId);
        if (!$ticket) {
            return null;
        }
        bk_audit($bookingId, 'ticket issued', $number . ' · code ' . $code);
        $rebuild = true;
    }

    $fileName = (string) $ticket['file_name'];
    if ($fileName === '' || bk_storage_file('tickets', $fileName) === null) {
        $rebuild = true;
    }

    if ($rebuild) {
        $pdf = bk_render_pdf(bk_ticket_html($booking, $ticket));
        if ($pdf === null) {
            return $ticket;                                                 // record exists; PDF can be retried
        }

        $fileName = 'ticket-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $ticket['ticket_number'])
            . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $path = bk_storage_path('tickets') . '/' . $fileName;

        if (@file_put_contents($path, $pdf) === false) {
            return $ticket;
        }
        @chmod($path, 0640);

        // Remove the superseded file so the folder does not grow forever.
        $old = bk_storage_file('tickets', (string) $ticket['file_name']);
        if ($old !== null && basename($old) !== $fileName) {
            @unlink($old);
        }

        db_run('UPDATE booking_tickets SET file_name = :f, issued_at = :i WHERE id = :id', [
            ':f' => $fileName, ':i' => $now, ':id' => $ticket['id'],
        ]);
        $ticket = bk_ticket_for($bookingId);
    }

    return $ticket;
}

/**
 * The ticket a client gets once their payment is approved.
 *
 * Laid out like a real event ticket rather than a form: a photographic banner
 * across the top carrying the event name, the details beneath it in two
 * columns, and a tear-off stub down the right with the QR code and the
 * verification code on it.
 *
 * Everything is tables and absolute positioning because dompdf has no flexbox
 * and no grid. The banner is a background image on a fixed-height block, which
 * dompdf does render, with a dark panel over the lower half so the white
 * lettering holds against whatever the photograph is doing behind it.
 */
function bk_ticket_html(array $booking, array $ticket): string
{
    $logo   = bk_logo_data_uri();
    $qr     = qr_png_data_uri(bk_ticket_verify_url($ticket), 4, 2);
    $accent = setting('theme_accent', '#22c9f0');
    $ink    = setting('theme_ink', '#04121f');
    $banner = bk_image_darkened(setting('ticket_banner_image', 'images/generated/namibia-ai-hero-3d.png'), 900);

    $guests   = (int) $booking['guests'];
    $location = trim((string) $booking['location']) !== ''
        ? (string) $booking['location']
        : trim(setting('venue_name') . ', ' . setting('venue_city'), ', ');

    /* The six facts somebody actually needs at the door, big enough to read
       from a phone screen held up to a scanner. */
    $headline = [
        ['TICKET HOLDER', (string) $booking['contact_name']],
        ['DATE',          bk_date_long((string) $booking['booking_date'])],
        ['ADMITS',        $guests > 0 ? $guests . ($guests === 1 ? ' person' : ' people') : '1 person'],
        ['TIME',          bk_time_range((string) $booking['start_time'], (string) $booking['end_time'])],
        ['VENUE',         $location],
        ['AMOUNT PAID',   bk_money((float) $booking['amount_paid'], (string) $booking['currency'])],
    ];

    $cells = '';
    foreach (array_chunk($headline, 2) as $pair) {
        $cells .= '<tr>';
        foreach ($pair as [$label, $value]) {
            $cells .= '<td class="tk-cell"><span class="tk-label">' . e($label) . '</span>'
                . '<strong class="tk-value">' . e((string) $value) . '</strong></td>';
        }
        $cells .= '</tr>';
    }

    /* The rest goes underneath in the smaller print. */
    $small = [
        ['Booking reference', (string) $booking['reference']],
        ['Ticket number',     (string) $ticket['ticket_number']],
        ['Booking status',    bk_status_label((string) $booking['status'])],
        ['Payment',           bk_payment_label($booking)],
        ['Issued',            date('d F Y', strtotime((string) $ticket['issued_at']) ?: time())],
    ];
    if ((string) $booking['company'] !== '') {
        array_unshift($small, ['Company', (string) $booking['company']]);
    }

    $smallRows = '';
    foreach (array_chunk($small, 2) as $pair) {
        $smallRows .= '<tr>';
        foreach ($pair as [$label, $value]) {
            $smallRows .= '<th>' . e($label) . '</th><td>' . e((string) $value) . '</td>';
        }
        if (count($pair) === 1) {
            $smallRows .= '<th></th><td></td>';
        }
        $smallRows .= '</tr>';
    }

    $instructions = trim(bk('bk_ticket_instructions'));
    $serviceNote  = '';
    $service = bk_service_row_for_booking($booking);
    if ($service && trim((string) $service['instructions']) !== '') {
        $serviceNote = (string) $service['instructions'];
    }

    $bannerStyle = $banner !== ''
        ? 'background-image: url(' . $banner . '); background-size: cover; background-position: 50% 42%;'
        : 'background-color: ' . e($ink) . ';';

    $content = '
<div class="tk">

  <table class="tk-banner" style="' . $bannerStyle . '">
    <tr><td class="tk-banner-cell">
      <div class="tk-kicker">ADMIT ' . ($guests > 1 ? e((string) $guests) : 'ONE') . '</div>
      <div class="tk-event">' . e(setting('event_name')) . '</div>
      <div class="tk-service">' . e((string) $booking['service_name']) . '</div>
    </td></tr>
  </table>

  <table class="tk-body">
    <tr>
      <td class="tk-main">
        <table class="tk-facts">' . $cells . '</table>
      </td>
      <td class="tk-stub">'
        . ($logo !== '' ? '<img class="tk-stub-logo" src="' . $logo . '" alt="">' : '')
        . ($qr !== '' ? '<img class="tk-qr" src="' . $qr . '" alt="">' : '')
        . '<div class="tk-code-label">VERIFICATION CODE</div>
           <div class="tk-code">' . e((string) $ticket['verification_code']) . '</div>
           <div class="tk-stub-id">' . e((string) $ticket['ticket_number']) . '</div>
      </td>
    </tr>
  </table>

  <table class="tk-small">' . $smallRows . '</table>

  <div class="tk-verify">Check this ticket at ' . e(bk_url('booking/verify-ticket.php')) . '</div>
</div>'

    . ($instructions !== '' ? '<div class="note"><strong>Important</strong><p>' . nl2br(e($instructions)) . '</p></div>' : '')
    . ($serviceNote !== '' ? '<div class="note note-plain"><strong>About this booking</strong><p>' . nl2br(e($serviceNote)) . '</p></div>' : '');

    return bk_document_shell(
        'Booking ticket ' . (string) $ticket['ticket_number'],
        $content,
        $logo,
        $accent,
        $ink,
        'This ticket is valid only for the booking shown. ' . e(setting('event_name'))
    );
}

/* ============================================================ 3. RECEIPTS */

function bk_receipt_for(int $bookingId): ?array
{
    return db_one('SELECT * FROM booking_receipts WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $bookingId]);
}

/**
 * Issue the payment receipt for a verified EFT payment.
 */
function bk_receipt_issue(array $booking, array $payment, bool $rebuild = false): ?array
{
    $bookingId = (int) $booking['id'];
    $receipt = bk_receipt_for($bookingId);
    $now = date('Y-m-d H:i:s');
    $amount = (float) $payment['amount_received'] > 0
        ? (float) $payment['amount_received']
        : (float) $booking['amount_paid'];

    if (!$receipt) {
        $number = bk_document_number('booking_receipts', 'receipt_number', 'RCT');
        db_run(
            'INSERT INTO booking_receipts (booking_id, payment_id, receipt_number, amount, currency, file_name, issued_at, created_at)
             VALUES (:b, :p, :n, :a, :c, :f, :i, :cr)',
            [
                ':b' => $bookingId, ':p' => (int) $payment['id'], ':n' => $number,
                ':a' => $amount, ':c' => (string) $booking['currency'],
                ':f' => '', ':i' => $now, ':cr' => $now,
            ]
        );
        $receipt = bk_receipt_for($bookingId);
        if (!$receipt) {
            return null;
        }
        bk_audit($bookingId, 'receipt issued', $number . ' · ' . bk_money($amount, (string) $booking['currency']));
        $rebuild = true;
    } elseif (abs((float) $receipt['amount'] - $amount) > 0.005) {
        db_run('UPDATE booking_receipts SET amount = :a, payment_id = :p WHERE id = :id', [
            ':a' => $amount, ':p' => (int) $payment['id'], ':id' => $receipt['id'],
        ]);
        $receipt = bk_receipt_for($bookingId);
        $rebuild = true;
    }

    $fileName = (string) $receipt['file_name'];
    if ($fileName === '' || bk_storage_file('receipts', $fileName) === null) {
        $rebuild = true;
    }

    if ($rebuild) {
        $pdf = bk_render_pdf(bk_receipt_html($booking, $payment, $receipt));
        if ($pdf === null) {
            return $receipt;
        }

        $fileName = 'receipt-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) $receipt['receipt_number'])
            . '-' . bin2hex(random_bytes(6)) . '.pdf';
        $path = bk_storage_path('receipts') . '/' . $fileName;

        if (@file_put_contents($path, $pdf) === false) {
            return $receipt;
        }
        @chmod($path, 0640);

        $old = bk_storage_file('receipts', (string) $receipt['file_name']);
        if ($old !== null && basename($old) !== $fileName) {
            @unlink($old);
        }

        db_run('UPDATE booking_receipts SET file_name = :f, issued_at = :i WHERE id = :id', [
            ':f' => $fileName, ':i' => $now, ':id' => $receipt['id'],
        ]);
        $receipt = bk_receipt_for($bookingId);
    }

    return $receipt;
}

/** The receipt document. */
function bk_receipt_html(array $booking, array $payment, array $receipt): string
{
    $logo = bk_logo_data_uri();
    $accent = setting('theme_accent', '#22c9f0');
    $ink = setting('theme_ink', '#04121f');
    $currency = (string) $booking['currency'];

    $amount = (float) $receipt['amount'];
    $verified = (string) $payment['verified_at'];

    $rows = [
        ['Receipt number', (string) $receipt['receipt_number']],
        ['Booking reference', (string) $booking['reference']],
        ['Received from', (string) $booking['contact_name']],
        ['Company', (string) $booking['company']],
        ['Email', (string) $booking['contact_email']],
        ['Payment method', 'EFT / bank transfer'],
        ['Payment reference used', (string) ($payment['admin_bank_reference'] ?: $payment['bank_reference'] ?: $booking['reference'])],
        ['Date received', $verified !== '' ? date('d F Y', strtotime($verified) ?: time()) : date('d F Y')],
        ['Verified by', (string) $payment['verified_by_name']],
    ];

    $body = '';
    foreach ($rows as [$label, $value]) {
        if (trim((string) $value) === '') {
            continue;
        }
        $body .= '<tr><th>' . e($label) . '</th><td>' . e((string) $value) . '</td></tr>';
    }

    $guests = (int) $booking['guests'];
    $lineDetail = e((string) $booking['service_name'])
        . '<span class="line-sub">' . e(bk_date_long((string) $booking['booking_date']))
        . ' &middot; ' . e(bk_time_range((string) $booking['start_time'], (string) $booking['end_time']))
        . ($guests > 1 ? ' &middot; ' . $guests . ' guests' : '')
        . '</span>';

    $outstanding = round((float) $booking['amount_due'] - (float) $booking['amount_paid'], 2);

    $lines = '<table class="lines">'
        . '<tr class="lines-head"><th>Description</th><th class="right">Amount</th></tr>'
        . '<tr><td>' . $lineDetail . '</td><td class="right">' . e(bk_money((float) $booking['amount_due'], $currency)) . '</td></tr>'
        . '<tr class="lines-total"><td>Paid by EFT</td><td class="right">' . e(bk_money($amount, $currency)) . '</td></tr>'
        . ($outstanding > 0.005
            ? '<tr class="lines-due"><td>Still outstanding</td><td class="right">' . e(bk_money($outstanding, $currency)) . '</td></tr>'
            : '<tr class="lines-paid"><td colspan="2">PAID IN FULL</td></tr>')
        . '</table>';

    $bankLine = trim(setting('bank_name') . ' · ' . setting('bank_account_name'), ' ·');

    return bk_document_shell(
        'Payment receipt ' . (string) $receipt['receipt_number'],
        '<div class="doc-title">
            <div class="doc-kicker">PAYMENT RECEIPT</div>
            <h1>' . e((string) $receipt['receipt_number']) . '</h1>
            <p class="doc-sub">' . e(bk_money($amount, $currency)) . ' received by electronic funds transfer</p>
         </div>'
         . $lines
         . '<table class="facts facts-wide">' . $body . '</table>'
         . ($bankLine !== '' ? '<div class="note note-plain"><strong>Paid into</strong><p>' . e($bankLine) . '</p></div>' : '')
         . (trim((string) $payment['admin_notes']) !== ''
            ? '<div class="note note-plain"><strong>Notes</strong><p>' . nl2br(e((string) $payment['admin_notes'])) . '</p></div>'
            : ''),
        $logo,
        $accent,
        $ink,
        'Receipt ' . (string) $receipt['receipt_number'] . ' · Booking ' . (string) $booking['reference']
    );
}

/* ========================================================= 4. DOCUMENT SHELL */

/** Shared letterhead, styling and footer for both documents. */
function bk_document_shell(string $title, string $content, string $logo, string $accent, string $ink, string $footer): string
{
    $org = e(setting('org_name'));
    $event = e(setting('event_name'));
    $reg = setting('org_reg_no') !== '' ? 'Reg. No. ' . e(setting('org_reg_no')) : '';
    $contact = implode(' &middot; ', array_filter([
        e(setting('email_primary')),
        e(setting('phone_1')),
    ]));
    $address = e(trim(setting('physical_address') ?: setting('postal_address')));

    return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>
@page { margin: 34px 38px 54px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #12212e; margin: 0; background-color: #ffffff; }
.head { width: 100%; border-bottom: 2.5px solid ' . e($accent) . '; padding-bottom: 12px; }
.head td { vertical-align: top; }
.head .logo { width: 92px; }
.head .logo img { width: 84px; }
.head .org { font-size: 13px; font-weight: bold; color: ' . e($ink) . '; }
.head .meta { font-size: 8.5px; color: #62727f; line-height: 1.55; margin-top: 3px; }
.head .event { text-align: right; font-size: 9px; color: #62727f; line-height: 1.55; }
.head .event strong { display: block; font-size: 10px; color: ' . e($ink) . '; margin-bottom: 2px; }

.doc-title { margin: 22px 0 16px; }
.doc-kicker { font-size: 8.5px; letter-spacing: 1.6px; font-weight: bold; color: ' . e($accent) . '; }
.doc-title h1 { font-size: 20px; margin: 5px 0 3px; color: ' . e($ink) . '; }
.doc-sub { font-size: 10.5px; color: #62727f; margin: 0; }

.stub { width: 100%; border: 1px solid #dbe2e7; border-radius: 8px; }
.stub td { vertical-align: top; padding: 14px 16px; }
.stub-main { width: 66%; }
.stub-side { width: 34%; border-left: 1px solid #dbe2e7; text-align: center; background: #f8fafb; }
.qr { width: 132px; height: 132px; }
.code-label { font-size: 7.5px; letter-spacing: 1.2px; color: #7b8894; margin-top: 8px; }
.code { font-size: 15px; font-weight: bold; letter-spacing: 1.5px; color: ' . e($ink) . '; margin: 3px 0 6px; }
.code-note { font-size: 7.5px; color: #8b97a2; line-height: 1.5; }

.facts { width: 100%; border-collapse: collapse; }
.facts th { text-align: left; font-weight: normal; color: #62727f; font-size: 9px; padding: 4.5px 10px 4.5px 0; width: 42%; vertical-align: top; }
.facts td { font-size: 10px; font-weight: bold; color: #12212e; padding: 4.5px 0; }
.facts-wide { margin-top: 18px; }
.facts-wide th { width: 32%; }

.lines { width: 100%; border-collapse: collapse; margin-top: 4px; }
.lines th, .lines td { padding: 9px 12px; font-size: 10px; border-bottom: 1px solid #e5eaee; }
.lines-head th { background: ' . e($ink) . '; color: #fff; font-size: 8.5px; letter-spacing: 1px; text-align: left; }
.right { text-align: right; }
.line-sub { display: block; font-size: 8.5px; color: #7b8894; margin-top: 2px; font-weight: normal; }
.lines-total td { font-weight: bold; background: #f4f7f8; }
.lines-due td { font-weight: bold; color: #a4571c; }
.lines-paid td { text-align: center; font-weight: bold; letter-spacing: 2px; color: #1c7a4a; background: #eaf6f0; }

.note { margin-top: 16px; padding: 11px 14px; border-left: 3px solid ' . e($accent) . '; background: #f5fbfd; }
.note-plain { border-left-color: #cfd8de; background: #f8fafb; }
.note strong { font-size: 9px; letter-spacing: .8px; color: ' . e($ink) . '; }
.note p { margin: 4px 0 0; font-size: 9.5px; color: #4a5a67; line-height: 1.55; }

/* ---------------------------------------------------------- the ticket */
/* dompdf has no flexbox and no grid, so this is tables and one absolutely
   positioned wash. Widths are percentages of the printable area. */
.tk { border: 1px solid #d7dee4; border-radius: 10px; margin-top: 18px; }

/* The shading that keeps the white lettering readable is baked into the
   picture by bk_image_darkened(), because dompdf cannot lay a translucent
   panel over a background image the way a browser can. */
.tk-banner { width: 100%; border-collapse: collapse; border-radius: 9px 9px 0 0; }
.tk-banner-cell { height: 118px; vertical-align: bottom; padding: 0 18px 15px !important; }
.tk-kicker {
  font-size: 7.5px; letter-spacing: 2.4px; font-weight: bold;
  color: ' . e($accent) . '; margin-bottom: 4px;
}
.tk-event { font-size: 19px; font-weight: bold; color: #ffffff; line-height: 1.15; }
.tk-service { font-size: 10.5px; color: #e4edf3; margin-top: 3px; }

.tk-body { width: 100%; border-collapse: collapse; }
.tk-body td { vertical-align: top; padding: 0; }
.tk-main { width: 68%; padding: 14px 16px 6px !important; }
/* The tear-off stub. The dashed edge stands in for a perforation. */
.tk-stub {
  width: 32%; text-align: center;
  border-left: 2px dashed #c9d3da;
  background-color: #f7fafb;
  padding: 14px 10px 12px !important;
}
.tk-stub-logo { display: block; width: 44px; margin: 0 auto 7px; }
.tk-qr { display: block; width: 104px; height: 104px; margin: 0 auto; }
.tk-code-label { font-size: 6.5px; letter-spacing: 1.4px; color: #7b8894; margin-top: 7px; }
.tk-code { font-size: 14px; font-weight: bold; letter-spacing: 1.6px; color: ' . e($ink) . '; margin: 2px 0 5px; }
.tk-stub-id { font-size: 7.5px; letter-spacing: 1px; color: #8b97a2; }

.tk-facts { width: 100%; border-collapse: collapse; }
.tk-cell { width: 50%; padding: 0 14px 13px 0 !important; }
.tk-label { display: block; font-size: 6.8px; letter-spacing: 1.5px; color: #8b97a2; margin-bottom: 3px; }
.tk-value { display: block; font-size: 12px; font-weight: bold; color: #12212e; line-height: 1.3; }

.tk-small { width: 100%; border-collapse: collapse; border-top: 1px solid #e5eaee; }
.tk-small th {
  text-align: left; font-weight: normal; color: #7b8894;
  font-size: 8px; padding: 6px 8px 6px 16px; width: 17%;
}
.tk-small td { font-size: 9px; font-weight: bold; color: #12212e; padding: 6px 14px 6px 0; width: 33%; }

.tk-verify {
  border-top: 1px solid #e5eaee; padding: 7px 16px;
  font-size: 8px; color: #8b97a2; text-align: center;
  border-radius: 0 0 9px 9px; background-color: #fbfcfd;
}

.foot { position: fixed; bottom: -34px; left: 0; right: 0; border-top: 1px solid #e5eaee; padding-top: 6px; font-size: 7.5px; color: #8b97a2; }
.foot td { font-size: 7.5px; color: #8b97a2; }
</style></head><body>

<table class="head" cellpadding="0" cellspacing="0" width="100%"><tr>'
        . ($logo !== '' ? '<td class="logo"><img src="' . $logo . '" alt=""></td>' : '')
        . '<td><div class="org">' . $org . '</div><div class="meta">'
        . ($reg !== '' ? $reg . '<br>' : '')
        . ($address !== '' ? $address . '<br>' : '')
        . $contact
        . '</div></td>'
        . '<td class="event"><strong>' . $event . '</strong>'
        . e(setting('venue_name')) . '<br>' . e(setting('venue_city'))
        . '</td></tr></table>'

        . $content

        . '<table class="foot" cellpadding="0" cellspacing="0" width="100%"><tr>'
        . '<td>' . e($footer) . '</td>'
        . '<td style="text-align:right">' . $org . '</td>'
        . '</tr></table>'

        . '</body></html>';
}

/** The service a booking was made against, if it still exists. */
function bk_service_row_for_booking(array $booking): ?array
{
    $id = (int) $booking['service_id'];
    return $id > 0 ? db_one('SELECT * FROM services WHERE id = :id', [':id' => $id]) : null;
}

/**
 * Embed one of the site's own images in a PDF as a data URI.
 *
 * Always through a resized copy. The artwork on this site is 1.7 MB and more
 * apiece; dropping an original into a ticket once pushed the file to 1.6 MB,
 * which is a slow download and an awkward email attachment. A ticket banner is
 * never rendered wider than about 520pt, so 900px is plenty.
 */
function bk_image_data_uri(string $relative, int $maxWidth = 900): string
{
    static $cache = [];
    $relative = ltrim(trim($relative), '/');
    $key = $relative . '|' . $maxWidth;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cache[$key] = '';

    if ($relative === '' || preg_match('#^(https?:)?//#i', $relative)) {
        return $cache[$key];
    }

    $root = realpath(ROOT_PATH);
    $real = realpath(ROOT_PATH . '/' . $relative);
    if ($root === false || $real === false || !str_starts_with($real, $root) || !is_file($real)) {
        return $cache[$key];
    }

    // Reuse the site's own resize cache, so this costs nothing after the
    // first ticket and the file is already a sensible size.
    if (function_exists('image_resized')) {
        $small = image_resized($relative, $maxWidth);
        if ($small !== null) {
            $smallReal = realpath(ROOT_PATH . '/' . $small);
            if ($smallReal !== false && is_file($smallReal)) {
                $real = $smallReal;
            }
        }
    }

    if (filesize($real) > 6 * 1024 * 1024) {
        return $cache[$key];
    }
    $info = @getimagesize($real);
    if ($info === false || !isset($info['mime'])) {
        return $cache[$key];
    }

    $cache[$key] = 'data:' . $info['mime'] . ';base64,' . base64_encode((string) file_get_contents($real));
    return $cache[$key];
}

/**
 * A darkened copy of an image, cached, for printing white text over.
 *
 * dompdf cannot stack a translucent panel on top of a background image the way
 * a browser can, so the shading is baked into the picture instead. The result
 * is cached next to the site's other resized images, so this costs one pass
 * the first time a ticket is made and nothing afterwards.
 */
function bk_image_darkened(string $relative, int $maxWidth = 900, float $strength = 0.52): string
{
    $relative = ltrim(trim($relative), '/');
    if ($relative === '' || !function_exists('imagecreatetruecolor')) {
        return bk_image_data_uri($relative, $maxWidth);
    }

    $root = realpath(ROOT_PATH);
    $source = realpath(ROOT_PATH . '/' . $relative);
    if ($root === false || $source === false || !str_starts_with($source, $root) || !is_file($source)) {
        return '';
    }

    $cacheDir = UPLOAD_PATH . '/cache';
    $name = 'tkbanner-' . substr(hash('sha256', $relative . '|' . (string) @filemtime($source) . '|' . $maxWidth . '|' . $strength), 0, 20) . '.jpg';
    $target = $cacheDir . '/' . $name;

    if (!is_file($target)) {
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            return bk_image_data_uri($relative, $maxWidth);
        }
        $info = @getimagesize($source);
        if ($info === false) {
            return bk_image_data_uri($relative, $maxWidth);
        }
        $image = match ((int) $info[2]) {
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default        => false,
        };
        if (!$image) {
            return bk_image_data_uri($relative, $maxWidth);
        }

        $w = imagesx($image);
        $h = imagesy($image);
        $tw = min($maxWidth, $w);
        $th = max(1, (int) round($h * ($tw / $w)));

        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, (int) imagecolorallocate($out, 0, 0, 0));
        imagecopyresampled($out, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagedestroy($image);

        // A flat wash over the whole frame, heavier towards the bottom where
        // the lettering sits.
        for ($y = 0; $y < $th; $y++) {
            $depth = $strength + (1 - $strength) * 0.42 * ($y / max(1, $th - 1));
            $alpha = (int) round(127 * (1 - min(0.92, $depth)));
            $shade = imagecolorallocatealpha($out, 4, 18, 31, $alpha);
            if ($shade !== false) {
                imagefilledrectangle($out, 0, $y, $tw - 1, $y, $shade);
            }
        }

        imagejpeg($out, $target, 84);
        imagedestroy($out);
        @chmod($target, 0644);
    }

    if (!is_file($target)) {
        return bk_image_data_uri($relative, $maxWidth);
    }
    return 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($target));
}

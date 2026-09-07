<?php
declare(strict_types=1);

/**
 * Online booking: the form, its validation, pricing rules, storage and the
 * PDF registration document.
 *
 * Every field mirrors the official "Coastal AI Trade Fair Registration Form
 * 2026", so a booking made here is the same record as a booking made on paper.
 */

require_once __DIR__ . '/pdf.php';

/* ================================================================ pricing */

/** Pull the numeric value out of a rate such as "N$ 9,999" or "From N$ 12,000". */
function money_value(string $rate): float
{
    if (!preg_match('/([0-9][0-9,\s.]*)/', $rate, $match)) {
        return 0.0;
    }
    $digits = str_replace([',', ' ', "\u{00A0}"], '', $match[1]);
    return (float) $digits;
}

function money_format(float $amount): string
{
    return 'N$ ' . number_format($amount, 2, '.', ',');
}

/** Bookable options, grouped for the form. */
function booking_options(): array
{
    $rows = active('stalls', 'bookable = 1');
    $groups = ['stall' => [], 'ticket' => [], 'other' => []];

    foreach ($rows as $row) {
        $kind = $row['kind'] ?? 'other';
        if (!isset($groups[$kind])) {
            $kind = 'other';
        }
        $row['value'] = money_value($row['rate']);
        $groups[$kind][] = $row;
    }

    return $groups;
}

function booking_group_labels(): array
{
    return [
        'stall'  => 'Exhibition stalls & vendor spaces',
        'ticket' => 'Masterclass tickets & passes',
        'other'  => 'Other options',
    ];
}

/**
 * Turn the posted selection into priced line items.
 *
 * Applies the rule printed on the official form: an MSME exhibitor who has
 * booked a stall gets the AI Masterclass ticket free of charge.
 *
 * @return array{items: array<int, array<string, mixed>>, total: float}
 */
function booking_price(array $selected, array $quantities): array
{
    $catalogue = [];
    foreach (active('stalls', 'bookable = 1') as $row) {
        $catalogue[(int) $row['id']] = $row;
    }

    $hasStall = false;
    foreach ($selected as $id) {
        $row = $catalogue[(int) $id] ?? null;
        if ($row && ($row['kind'] ?? 'other') === 'stall') {
            $hasStall = true;
            break;
        }
    }

    $items = [];
    $total = 0.0;

    foreach ($selected as $id) {
        $id = (int) $id;
        $row = $catalogue[$id] ?? null;
        if (!$row) {
            continue;
        }

        $quantity = max(1, min(50, (int) ($quantities[$id] ?? 1)));
        $unit = money_value($row['rate']);
        $note = '';

        if ($hasStall && (int) ($row['free_with_stall'] ?? 0) === 1) {
            $unit = 0.0;
            $note = 'Included free with your stall booking';
        }

        $amount = $unit * $quantity;
        $total += $amount;

        $items[] = [
            'id'       => $id,
            'category' => $row['category'],
            'details'  => $row['details'],
            'quantity' => $quantity,
            'unit'     => $unit,
            'amount'   => $amount,
            'note'     => $note,
        ];
    }

    return ['items' => $items, 'total' => $total];
}

/* ================================================================ storage */

/** Next booking reference, e.g. CAS26-0007. */
function booking_reference(): string
{
    $year = date('y', (int) strtotime(setting('event_start_date', '2026-09-30')));
    $row = db_one('SELECT COUNT(*) AS c FROM bookings');
    $number = (int) ($row['c'] ?? 0) + 1;

    do {
        $reference = 'CAS' . $year . '-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $clash = db_one('SELECT id FROM bookings WHERE reference = :r', [':r' => $reference]);
        $number++;
    } while ($clash);

    return $reference;
}

function booking_find(int $id): ?array
{
    return db_one('SELECT * FROM bookings WHERE id = :id', [':id' => $id]);
}

/** Look a booking up by the reference and token given to the applicant. */
function booking_find_by_token(string $reference, string $token): ?array
{
    $row = db_one('SELECT * FROM bookings WHERE reference = :r', [':r' => $reference]);
    if (!$row || $token === '' || !hash_equals($row['token'], $token)) {
        return null;
    }
    return $row;
}

/** Decode the stored line items. */
function booking_items(array $booking): array
{
    $items = json_decode((string) $booking['items'], true);
    return is_array($items) ? $items : [];
}

function booking_statuses(): array
{
    return [
        'pending'   => 'Awaiting payment',
        'confirmed' => 'Confirmed',
        'paid'      => 'Paid in full',
        'cancelled' => 'Cancelled',
    ];
}

/* ============================================================= submission */

/**
 * Validate and store a booking submitted from book.php.
 *
 * @return array{0:string,1:string,2:?array} [status, message, booking]
 */
function booking_handle(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['', '', null];
    }

    if (!csrf_check()) {
        return ['error', 'Your session expired before the form was sent. Please check your details and submit again.', null];
    }

    // Honeypot.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        return ['ok', 'Thank you — your booking has been received.', null];
    }

    if (!rate_limit('booking:' . client_ip(), 6, 900)) {
        return ['error', 'Too many booking attempts from this connection. Please try again in fifteen minutes.', null];
    }

    $company   = trim((string) ($_POST['company'] ?? ''));
    $contact   = trim((string) ($_POST['contact_person'] ?? ''));
    $phone     = trim((string) ($_POST['phone'] ?? ''));
    $email     = trim((string) ($_POST['email'] ?? ''));
    $product   = trim((string) ($_POST['product'] ?? ''));
    $special   = trim((string) ($_POST['special_requirements'] ?? ''));
    $signature = trim((string) ($_POST['signature'] ?? ''));
    $agreed    = isset($_POST['agreed']);

    $selected   = array_map('intval', (array) ($_POST['options'] ?? []));
    $quantities = (array) ($_POST['qty'] ?? []);

    $errors = [];
    if ($company === '')  { $errors[] = 'company name'; }
    if ($contact === '')  { $errors[] = 'contact person'; }
    if ($phone === '')    { $errors[] = 'phone or WhatsApp number'; }
    if ($email === '')    { $errors[] = 'email address'; }

    if ($errors) {
        return ['error', 'Please complete your ' . natural_list($errors) . '.', null];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error', 'That email address does not look right. We need a working address to confirm your booking.', null];
    }
    if (!$selected) {
        return ['error', 'Please choose at least one stall, ticket or pass from section 2.', null];
    }
    if (!$agreed) {
        return ['error', 'Please accept the terms and conditions in section 4 before submitting.', null];
    }
    if ($signature === '') {
        return ['error', 'Please type your full name as your signature in section 4.', null];
    }

    $priced = booking_price($selected, $quantities);
    if (!$priced['items']) {
        return ['error', 'The options you chose are no longer available. Please pick from the list again.', null];
    }

    $reference = booking_reference();
    $token     = bin2hex(random_bytes(16));
    $now       = date('Y-m-d H:i:s');

    db_run(
        'INSERT INTO bookings
           (reference, token, company, contact_person, phone, email, product, special_requirements,
            items, total, signature, agreed, status, admin_notes, ip, created_at, updated_at)
         VALUES
           (:reference, :token, :company, :contact, :phone, :email, :product, :special,
            :items, :total, :signature, 1, :status, :notes, :ip, :created, :updated)',
        [
            ':reference' => $reference,
            ':token'     => $token,
            ':company'   => mb_substr($company, 0, 190),
            ':contact'   => mb_substr($contact, 0, 190),
            ':phone'     => mb_substr($phone, 0, 60),
            ':email'     => mb_substr($email, 0, 190),
            ':product'   => mb_substr($product, 0, 500),
            ':special'   => mb_substr($special, 0, 1000),
            ':items'     => json_encode($priced['items'], JSON_UNESCAPED_UNICODE),
            ':total'     => $priced['total'],
            ':signature' => mb_substr($signature, 0, 190),
            ':status'    => 'pending',
            ':notes'     => '',
            ':ip'        => client_ip(),
            ':created'   => $now,
            ':updated'   => $now,
        ]
    );

    $booking = db_one('SELECT * FROM bookings WHERE reference = :r', [':r' => $reference]);
    if ($booking) {
        booking_notify($booking);
        booking_acknowledge($booking);
    }

    return ['ok', 'Booking ' . $reference . ' received.', $booking];
}

/** "a, b and c" */
function natural_list(array $parts): string
{
    if (count($parts) <= 1) {
        return (string) ($parts[0] ?? '');
    }
    $last = array_pop($parts);
    return implode(', ', $parts) . ' and ' . $last;
}

/** Email the event team. Never blocks the booking if mail is unavailable. */
function booking_notify(array $booking): bool
{
    $to = setting('email_form_to', setting('email_primary'));
    if ($to === '' || !function_exists('mail')) {
        return false;
    }

    $lines = '';
    foreach (booking_items($booking) as $item) {
        $lines .= sprintf(
            "  %-42s x%-3d %s\n",
            mb_substr($item['category'] . ($item['details'] !== '' ? ' (' . $item['details'] . ')' : ''), 0, 42),
            $item['quantity'],
            money_format((float) $item['amount'])
        );
    }

    $body = "A new online booking has been received.\n\n"
        . "Reference:  {$booking['reference']}\n"
        . "Company:    {$booking['company']}\n"
        . "Contact:    {$booking['contact_person']}\n"
        . "Phone:      {$booking['phone']}\n"
        . "Email:      {$booking['email']}\n"
        . "Product:    " . ($booking['product'] !== '' ? $booking['product'] : '-') . "\n"
        . "Special:    " . ($booking['special_requirements'] !== '' ? $booking['special_requirements'] : '-') . "\n\n"
        . "Options:\n" . $lines
        . "\n  TOTAL: " . money_format((float) $booking['total']) . "\n\n"
        . "Signed:     {$booking['signature']}\n"
        . "Received:   " . date('d M Y H:i', strtotime($booking['created_at'])) . "\n\n"
        . "Open the control panel to view the full booking and download the PDF.\n";

    $headers = [
        'From: ' . setting('site_name') . ' Bookings <no-reply@' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>',
        'Reply-To: ' . preg_replace('/[\r\n]+/', ' ', $booking['email']),
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    return @mail($to, 'New booking ' . $booking['reference'] . ' - ' . $booking['company'], $body, implode("\r\n", $headers));
}

/** Send the applicant their reference and the payment instructions. */
function booking_acknowledge(array $booking): bool
{
    if (!function_exists('mail')) {
        return false;
    }

    $body = "Dear {$booking['contact_person']},\n\n"
        . "Thank you for booking your place at the " . setting('event_name') . ".\n\n"
        . "Your booking reference is {$booking['reference']}.\n"
        . "Amount due: " . money_format((float) $booking['total']) . "\n\n"
        . "TO CONFIRM YOUR BOOKING\n"
        . "Pay by direct bank transfer and email your proof of payment to "
        . setting('payment_proof_email', setting('email_primary')) . ".\n\n"
        . "  Account name:   " . setting('bank_account_name') . "\n"
        . "  Bank:           " . setting('bank_name') . "\n"
        . "  Account number: " . setting('bank_account_number') . "\n"
        . "  Branch code:    " . setting('bank_branch_code') . "\n"
        . "  Account type:   " . setting('bank_account_type') . "\n"
        . "  Reference:      {$booking['company']}\n\n"
        . "Stalls and masterclass seats are only reserved once proof of payment is received.\n"
        . "Prime stall locations are assigned on a first-paid, first-served basis.\n\n"
        . event_date_range() . "\n"
        . setting('venue_name') . ', ' . setting('venue_city') . "\n\n"
        . setting('org_name') . "\n"
        . setting('email_primary') . ' | ' . setting('phone_1') . "\n";

    $headers = [
        'From: ' . setting('site_name') . ' <no-reply@' . preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>',
        'Reply-To: ' . setting('email_primary'),
        'Content-Type: text/plain; charset=UTF-8',
        'MIME-Version: 1.0',
    ];

    return @mail($booking['email'], 'Your booking ' . $booking['reference'] . ' - ' . setting('event_name'), $body, implode("\r\n", $headers));
}

/* ================================================================== PDF */

/**
 * Build the registration document for one booking, laid out to match the
 * official paper form section by section.
 */
function booking_pdf(array $booking): SimplePdf
{
    $pdf = new SimplePdf();
    $left = $pdf->margin;
    $right = SimplePdf::PAGE_W - $pdf->margin;
    $width = $pdf->contentWidth();

    $ink    = [0.05, 0.12, 0.19];
    $muted  = [0.40, 0.47, 0.53];
    $accent = [0.06, 0.62, 0.77];
    $rule   = [0.82, 0.85, 0.88];
    $items  = booking_items($booking);

    // Footer on every page, drawn as each page is closed.
    $pdf->pageFooter = static function (SimplePdf $doc, int $page) use ($left, $right, $muted, $rule, $booking): void {
        $baseline = SimplePdf::PAGE_H - $doc->margin + 2;
        $doc->line($left, $baseline - 12, $right, $baseline - 12, 0.5, $rule);
        $doc->text($left, $baseline - 6, setting('event_name') . '  |  ' . setting('venue_name') . ', ' . setting('venue_city'), 7.2, 'R', $muted);
        $doc->textRight($right, $baseline - 6, 'Booking ' . $booking['reference'] . '  ·  Page ' . $page, 7.2, 'R', $muted);
    };

    /* ---------------------------------------------------------- letterhead */
    $logo = ROOT_PATH . '/' . ltrim(setting('logo'), '/');
    $hasLogo = $pdf->image($logo, $left, $pdf->y, 62, 62);

    $textLeft = $hasLogo ? $left + 76 : $left;
    $pdf->text($textLeft, $pdf->y, setting('org_name'), 11.5, 'B', $ink);
    $pdf->y += 15;

    foreach (array_filter([
        setting('org_reg_no') !== '' ? 'Reg. No. ' . setting('org_reg_no') : '',
        setting('postal_address'),
        setting('physical_address'),
        trim(implode(' / ', array_filter([setting('phone_1'), setting('phone_2'), setting('phone_3')]))),
        trim(implode(' / ', array_filter([setting('email_primary'), setting('email_secondary')]))),
    ]) as $line) {
        foreach ($pdf->wrap($line, $width - ($textLeft - $left), 7.6) as $wrapped) {
            $pdf->text($textLeft, $pdf->y, $wrapped, 7.6, 'R', $muted);
            $pdf->y += 9.6;
        }
    }

    $pdf->y = max($pdf->y, $pdf->margin + 66) + 6;
    $pdf->line($left, $pdf->y, $right, $pdf->y, 1.4, $accent);
    $pdf->y += 16;

    /* --------------------------------------------------------- event title */
    $pdf->text($left, $pdf->y, strtoupper(setting('event_name')), 15, 'B', $ink);
    $pdf->y += 20;
    $pdf->text($left, $pdf->y, event_date_range() . '   |   ' . setting('venue_name') . ', ' . setting('venue_city'), 9, 'R', $muted);
    $pdf->y += 13;
    if (setting('org_collaboration') !== '') {
        $pdf->text($left, $pdf->y, setting('org_collaboration'), 8, 'R', $muted);
        $pdf->y += 12;
    }

    /* ------------------------------------------------------ reference band */
    $pdf->y += 6;
    $bandTop = $pdf->y;
    $pdf->rect($left, $bandTop, $width, 44, [0.95, 0.97, 0.98]);
    $pdf->rect($left, $bandTop, 3, 44, $accent);

    $statuses = booking_statuses();
    $pdf->text($left + 14, $bandTop + 9, 'BOOKING REFERENCE', 7, 'B', $muted);
    $pdf->text($left + 14, $bandTop + 21, $booking['reference'], 14, 'B', $ink);

    $pdf->text($left + 190, $bandTop + 9, 'DATE RECEIVED', 7, 'B', $muted);
    $pdf->text($left + 190, $bandTop + 22, date('d F Y, H:i', strtotime($booking['created_at'])), 9.5, 'R', $ink);

    $pdf->textRight($right - 14, $bandTop + 9, 'STATUS', 7, 'B', $muted);
    $pdf->textRight($right - 14, $bandTop + 22, strtoupper($statuses[$booking['status']] ?? $booking['status']), 9.5, 'B', $accent);

    $pdf->y = $bandTop + 44 + 22;

    /* ------------------------------------------------ 1. applicant details */
    booking_pdf_heading($pdf, '1.  APPLICANT DETAILS', $ink, $accent);

    $fields = [
        'Company / Participant Name'       => $booking['company'],
        'Contact Person'                   => $booking['contact_person'],
        'Phone / WhatsApp'                 => $booking['phone'],
        'Email'                            => $booking['email'],
        'Product'                          => $booking['product'],
        'Special stall size or requirements' => $booking['special_requirements'],
    ];

    foreach ($fields as $label => $value) {
        $value = trim((string) $value);
        $lines = $value === '' ? ['-'] : $pdf->wrap($value, $width - 170, 9.5);
        $pdf->need(max(18.0, count($lines) * 12.5 + 6));

        $rowTop = $pdf->y;
        $pdf->text($left, $rowTop, $label, 8.2, 'R', $muted);
        foreach ($lines as $i => $line) {
            $pdf->text($left + 170, $rowTop + ($i * 12.5), $line, 9.5, 'B', $ink);
        }
        $pdf->y = $rowTop + max(17.0, count($lines) * 12.5 + 4.5);
        $pdf->line($left, $pdf->y - 5, $right, $pdf->y - 5, 0.5, $rule);
    }

    /* --------------------------------------------- 2. registration options */
    $pdf->y += 14;
    booking_pdf_heading($pdf, '2.  REGISTRATION OPTIONS', $ink, $accent);

    $colQty = $right - 190;
    $colUnit = $right - 130;
    $colAmount = $right;

    $pdf->need(30);
    $headTop = $pdf->y;
    $pdf->rect($left, $headTop, $width, 20, [0.05, 0.12, 0.19]);
    $pdf->text($left + 10, $headTop + 6, 'CATEGORY  /  DETAILS & SPECS', 7.4, 'B', [1, 1, 1]);
    $pdf->textRight($colQty + 26, $headTop + 6, 'QTY', 7.4, 'B', [1, 1, 1]);
    $pdf->textRight($colUnit + 44, $headTop + 6, 'RATE', 7.4, 'B', [1, 1, 1]);
    $pdf->textRight($colAmount - 10, $headTop + 6, 'AMOUNT (N$)', 7.4, 'B', [1, 1, 1]);
    $pdf->y = $headTop + 20;

    foreach ($items as $index => $item) {
        $detail = trim((string) $item['details']);
        $detailLines = $detail === '' ? [] : $pdf->wrap($detail, $width - 210, 8.2);
        $noteLines = trim((string) ($item['note'] ?? '')) === '' ? [] : $pdf->wrap((string) $item['note'], $width - 210, 8);
        $rowHeight = 17.0 + (count($detailLines) * 10) + (count($noteLines) * 10);

        $pdf->need($rowHeight + 4);
        $rowTop = $pdf->y;

        if ($index % 2 === 1) {
            $pdf->rect($left, $rowTop, $width, $rowHeight, [0.975, 0.98, 0.985]);
        }

        $pdf->text($left + 10, $rowTop + 5, (string) $item['category'], 9.5, 'B', $ink);
        $textY = $rowTop + 17;
        foreach ($detailLines as $line) {
            $pdf->text($left + 10, $textY, $line, 8.2, 'R', $muted);
            $textY += 10;
        }
        foreach ($noteLines as $line) {
            $pdf->text($left + 10, $textY, $line, 8, 'B', $accent);
            $textY += 10;
        }

        $pdf->textRight($colQty + 26, $rowTop + 5, (string) $item['quantity'], 9.5, 'R', $ink);
        $pdf->textRight($colUnit + 44, $rowTop + 5, number_format((float) $item['unit'], 2, '.', ','), 9.5, 'R', $ink);
        $pdf->textRight($colAmount - 10, $rowTop + 5, number_format((float) $item['amount'], 2, '.', ','), 9.5, 'B', $ink);

        $pdf->y = $rowTop + $rowHeight;
        $pdf->line($left, $pdf->y, $right, $pdf->y, 0.5, $rule);
    }

    $pdf->need(34);
    $totalTop = $pdf->y;
    $pdf->rect($left, $totalTop, $width, 30, [0.95, 0.97, 0.98]);
    $pdf->text($left + 10, $totalTop + 10, 'TOTAL DUE', 9.5, 'B', $ink);
    $pdf->textRight($colAmount - 10, $totalTop + 9, money_format((float) $booking['total']), 13, 'B', $ink);
    $pdf->y = $totalTop + 30 + 20;

    /* ----------------------------------------------------- 3. payment */
    // Keep payment, terms and the signature together so a second page never
    // carries just a signature line.
    $pdf->need(330);
    booking_pdf_heading($pdf, '3.  PAYMENT DETAILS', $ink, $accent);

    $pdf->paragraph(
        $left,
        $width,
        'To confirm your registration, please pay by direct bank transfer and send proof of payment to '
        . setting('payment_proof_email', setting('email_primary')) . '.',
        9,
        'R',
        13,
        $muted
    );
    $pdf->y += 8;

    $bank = [
        'Account Name'      => setting('bank_account_name'),
        'Bank'              => setting('bank_name'),
        'Account Number'    => setting('bank_account_number'),
        'Branch Code'       => setting('bank_branch_code'),
        'Account Type'      => setting('bank_account_type'),
        'Payment Reference' => $booking['company'],
    ];

    $boxTop = $pdf->y;
    $boxHeight = (count($bank) * 15) + 16;
    $pdf->rect($left, $boxTop, $width, $boxHeight, [0.99, 0.99, 0.99]);
    $pdf->rect($left, $boxTop, $width, $boxHeight, $rule, false, 0.6);

    $rowY = $boxTop + 10;
    foreach ($bank as $label => $value) {
        $pdf->text($left + 12, $rowY, $label, 8.4, 'R', $muted);
        $pdf->text($left + 170, $rowY, (string) $value, 9.2, 'B', $ink);
        $rowY += 15;
    }
    $pdf->y = $boxTop + $boxHeight + 20;

    /* ------------------------------------------------------- 4. terms */
    $pdf->need(150);
    booking_pdf_heading($pdf, '4.  TERMS & CONDITIONS', $ink, $accent);

    foreach ([
        'Confirmation'  => setting('terms_confirmation'),
        'Allocations'   => setting('terms_allocations'),
        'Cancellations' => setting('terms_cancellations'),
    ] as $label => $text) {
        if (trim((string) $text) === '') {
            continue;
        }
        $pdf->need(24);
        $rowTop = $pdf->y;
        $pdf->text($left, $rowTop, $label . ':', 8.6, 'B', $ink);
        $pdf->y = $rowTop;
        $used = $pdf->paragraph($left + 78, $width - 78, (string) $text, 8.6, 'R', 12, $muted);
        $pdf->y = $rowTop + max(14.0, $used) + 4;
    }

    /* --------------------------------------------------------- signature */
    $pdf->y += 26;
    $pdf->need(60);
    $signTop = $pdf->y;

    $pdf->line($left, $signTop + 20, $left + 210, $signTop + 20, 0.7, $ink);
    $pdf->text($left, $signTop + 4, (string) $booking['signature'], 11, 'B', $ink);
    $pdf->text($left, $signTop + 26, 'Signature (submitted online)', 7.6, 'R', $muted);

    $pdf->line($right - 190, $signTop + 20, $right, $signTop + 20, 0.7, $ink);
    $pdf->text($right - 190, $signTop + 4, date('d / m / Y', strtotime($booking['created_at'])), 11, 'B', $ink);
    $pdf->text($right - 190, $signTop + 26, 'Date', 7.6, 'R', $muted);

    $pdf->y = $signTop + 46;

    if (trim((string) $booking['admin_notes']) !== '') {
        $pdf->y += 16;
        $pdf->need(40);
        $pdf->text($left, $pdf->y, 'EVENT TEAM NOTES', 7.4, 'B', $muted);
        $pdf->y += 12;
        $pdf->paragraph($left, $width, (string) $booking['admin_notes'], 8.6, 'R', 12, $ink);
    }

    return $pdf;
}

/** Section heading used by the registration document. */
function booking_pdf_heading(SimplePdf $pdf, string $title, array $ink, array $accent): void
{
    $pdf->need(30);
    $pdf->text($pdf->margin, $pdf->y, $title, 10, 'B', $ink);
    $pdf->y += 14;
    $pdf->line($pdf->margin, $pdf->y, $pdf->margin + 46, $pdf->y, 1.6, $accent);
    $pdf->y += 12;
}

/* ============================================================ CSV export */

/** Stream every booking as a spreadsheet. */
function booking_export_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Reference', 'Date', 'Status', 'Company', 'Contact person', 'Phone', 'Email',
        'Product', 'Special requirements', 'Options', 'Total (N$)', 'Signature', 'Notes',
    ]);

    foreach ($rows as $row) {
        $options = [];
        foreach (booking_items($row) as $item) {
            $options[] = $item['category']
                . ($item['details'] !== '' ? ' (' . $item['details'] . ')' : '')
                . ' x' . $item['quantity'] . ' = ' . number_format((float) $item['amount'], 2);
        }
        fputcsv($out, [
            $row['reference'], $row['created_at'], booking_statuses()[$row['status']] ?? $row['status'],
            $row['company'], $row['contact_person'], $row['phone'], $row['email'],
            $row['product'], $row['special_requirements'], implode('; ', $options),
            number_format((float) $row['total'], 2, '.', ''), $row['signature'], $row['admin_notes'],
        ]);
    }

    fclose($out);
}

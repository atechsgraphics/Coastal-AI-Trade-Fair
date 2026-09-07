<?php
declare(strict_types=1);

/**
 * EFT booking platform — bookings, payments, proofs and verification.
 * ---------------------------------------------------------------------------
 * The rule this file exists to enforce: a booking is only ever marked
 * "Payment Confirmed" / "Booking Confirmed" by bk_approve_payment(), which can
 * only be reached by a signed-in member of staff. Uploading a proof of payment
 * moves a booking to "Payment Proof Submitted" and no further.
 */

require_once __DIR__ . '/services.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/documents.php';

/* ============================================================= 1. READING */

function bk_booking(int $id): ?array
{
    return db_one('SELECT * FROM service_bookings WHERE id = :id', [':id' => $id]);
}

function bk_booking_by_reference(string $reference): ?array
{
    return db_one('SELECT * FROM service_bookings WHERE reference = :r', [':r' => strtoupper(trim($reference))]);
}

/** A booking reached from an emailed link: reference plus its own token. */
function bk_booking_by_token(string $reference, string $token): ?array
{
    $booking = bk_booking_by_reference($reference);
    if (!$booking || !bk_token_equals((string) $booking['access_token'], $token)) {
        return null;
    }
    return $booking;
}

/**
 * Bookings belonging to one client.
 *
 * @param string $when all | upcoming | past
 */
function bk_client_bookings(int $clientId, string $when = 'all', int $limit = 200): array
{
    $params = [':c' => $clientId, ':today' => bk_now()->format('Y-m-d')];
    $sql = 'SELECT * FROM service_bookings WHERE client_id = :c';

    if ($when === 'upcoming') {
        $sql .= " AND booking_date >= :today AND status NOT IN ('cancelled','declined','completed','refunded')";
        $sql .= ' ORDER BY booking_date ASC, start_time ASC';
    } elseif ($when === 'past') {
        $sql .= " AND (booking_date < :today OR status IN ('cancelled','completed','refunded'))";
        $sql .= ' ORDER BY booking_date DESC, start_time DESC';
    } else {
        $sql .= ' ORDER BY booking_date DESC, start_time DESC';
    }

    return db_all($sql . ' LIMIT ' . max(1, $limit), $params);
}

/** True when this client owns this booking. Nothing else grants access. */
function bk_client_owns(array $booking, ?array $client): bool
{
    return $client !== null && (int) $booking['client_id'] === (int) $client['id'] && (int) $client['id'] > 0;
}

/**
 * Load a booking for the signed-in client, or stop the request.
 * This is the single gate every client-facing booking screen goes through.
 */
function bk_require_own_booking(int $id): array
{
    $client = require_client();
    $booking = bk_booking($id);

    if (!$booking || !bk_client_owns($booking, $client)) {
        http_response_code(404);
        bk_deny_page('Booking not found', 'That booking does not exist, or it belongs to a different account.');
    }

    return $booking;
}

/** A plain, branded refusal page used by every access check. */
function bk_deny_page(string $title, string $message): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<body style="font-family:system-ui,Segoe UI,sans-serif;margin:4rem auto;max-width:34rem;padding:0 1.25rem;line-height:1.65;color:#16242f">'
        . '<h1 style="font-size:1.35rem;margin:0 0 .6rem">' . e($title) . '</h1>'
        . '<p>' . e($message) . '</p>'
        . '<p><a href="' . e(url('booking/account.php')) . '" style="color:#0aa6cc">Go to my bookings</a> &nbsp;·&nbsp; '
        . '<a href="' . e(url('index.php')) . '" style="color:#0aa6cc">Return to the website</a></p></body>';
    exit;
}

/* ============================================================ 2. PAYMENTS */

function bk_payment_for(int $bookingId): ?array
{
    return db_one('SELECT * FROM eft_payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $bookingId]);
}

/** The payment record for a booking, created on demand. */
function bk_payment_ensure(array $booking): array
{
    $payment = bk_payment_for((int) $booking['id']);
    if ($payment) {
        return $payment;
    }

    $now = date('Y-m-d H:i:s');
    db_run(
        'INSERT INTO eft_payments (booking_id, reference, amount_due, currency, status, created_at, updated_at)
         VALUES (:b, :r, :a, :c, :s, :n, :n2)',
        [
            ':b' => (int) $booking['id'],
            ':r' => (string) $booking['reference'],
            ':a' => (float) $booking['amount_due'],
            ':c' => (string) $booking['currency'],
            ':s' => 'awaiting',
            ':n' => $now,
            ':n2' => $now,
        ]
    );

    return bk_payment_for((int) $booking['id']) ?? [];
}

/** Every proof uploaded against a booking, newest first. */
function bk_proofs(int $bookingId): array
{
    return db_all('SELECT * FROM payment_proofs WHERE booking_id = :b ORDER BY id DESC', [':b' => $bookingId]);
}

/** The proof currently under consideration. */
function bk_latest_proof(int $bookingId): ?array
{
    return db_one('SELECT * FROM payment_proofs WHERE booking_id = :b ORDER BY id DESC LIMIT 1', [':b' => $bookingId]);
}

/* ============================================================ 3. CREATION */

/**
 * Create a booking.
 *
 * The availability check and the INSERT happen inside one immediate
 * transaction, so two people submitting the same slot at the same moment
 * cannot both succeed — the second is told the slot has gone.
 *
 * @param array $input        service_id, date, time, guests, contact fields, guest list
 * @param string $source      online | admin
 * @param bool $notifyClient  false when staff take a booking and will speak to the client themselves
 * @return array{ok: bool, message: string, booking: ?array}
 */
function bk_create_booking(array $input, ?array $client, string $source = 'online', bool $notifyClient = true): array
{
    $service = bk_service((int) ($input['service_id'] ?? 0));
    if (!$service || (int) $service['is_active'] !== 1) {
        return ['ok' => false, 'message' => 'That service is not available for booking.', 'booking' => null];
    }

    $date  = trim((string) ($input['date'] ?? ''));
    $start = trim((string) ($input['time'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $start)) {
        return ['ok' => false, 'message' => 'Please choose a date and a time for your booking.', 'booking' => null];
    }

    $minGuests = max(1, (int) $service['min_guests']);
    $maxGuests = max($minGuests, (int) $service['max_guests']);
    $guests = (int) ($input['guests'] ?? $minGuests);
    $guests = max($minGuests, min($maxGuests, $guests));

    $name  = trim((string) ($input['contact_name'] ?? ''));
    $email = strtolower(trim((string) ($input['contact_email'] ?? '')));
    $phone = trim((string) ($input['contact_phone'] ?? ''));

    if ($name === '') {
        return ['ok' => false, 'message' => 'Please give us the name the booking is for.', 'booking' => null];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Please give us a working email address so we can send your confirmation.', 'booking' => null];
    }
    if ($phone === '') {
        return ['ok' => false, 'message' => 'Please give us a phone number in case we need to reach you.', 'booking' => null];
    }

    $extra = trim((string) ($input['extra_details'] ?? ''));
    if ((int) $service['extra_field_required'] === 1 && trim((string) $service['extra_field_label']) !== '' && $extra === '') {
        return ['ok' => false, 'message' => 'Please complete "' . $service['extra_field_label'] . '".', 'booking' => null];
    }

    $seats = bk_service_seats($service, $guests);
    $end   = bk_slot_end($service, $start);
    $price = bk_service_price($service, $guests);

    $deadlineHours = (int) $service['payment_deadline_hours'] > 0
        ? (int) $service['payment_deadline_hours']
        : bk_int('bk_payment_deadline_hours', 48);

    // Never let the payment deadline fall after the booking itself.
    $deadline = bk_now()->modify('+' . max(1, $deadlineHours) . ' hours');
    $bookingStart = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $date . ' ' . $start,
        new DateTimeZone(setting('event_timezone', 'Africa/Windhoek'))
    );
    if ($bookingStart && $deadline > $bookingStart) {
        $deadline = $bookingStart;
    }

    $now = date('Y-m-d H:i:s');
    $reference = '';
    $bookingId = 0;

    try {
        bk_begin();

        $problem = bk_slot_problem($service, $date, $start, $seats);
        if ($problem !== null) {
            bk_rollback();
            return ['ok' => false, 'message' => $problem, 'booking' => null];
        }

        $reference = bk_new_reference();
        db_run(
            'INSERT INTO service_bookings
                (reference, access_token, client_id, service_id, service_name, booking_date, start_time, end_time,
                 guests, seats, location, unit_price, amount_due, amount_paid, currency, status,
                 contact_name, contact_email, contact_phone, company, extra_details, client_notes,
                 payment_deadline, source, ip, created_at, updated_at)
             VALUES
                (:ref, :token, :client, :service, :sname, :date, :start, :end,
                 :guests, :seats, :location, :unit, :due, 0, :currency, :status,
                 :cname, :cemail, :cphone, :company, :extra, :notes,
                 :deadline, :source, :ip, :created, :updated)',
            [
                ':ref'      => $reference,
                ':token'    => bin2hex(random_bytes(16)),
                ':client'   => $client ? (int) $client['id'] : 0,
                ':service'  => (int) $service['id'],
                ':sname'    => mb_substr((string) $service['name'], 0, 190),
                ':date'     => $date,
                ':start'    => $start,
                ':end'      => $end,
                ':guests'   => $guests,
                ':seats'    => $seats,
                ':location' => mb_substr((string) $service['location'], 0, 190),
                ':unit'     => (float) $service['price'],
                ':due'      => $price,
                ':currency' => bk_currency(),
                ':status'   => 'awaiting_eft',
                ':cname'    => mb_substr($name, 0, 190),
                ':cemail'   => mb_substr($email, 0, 190),
                ':cphone'   => mb_substr($phone, 0, 60),
                ':company'  => mb_substr(trim((string) ($input['company'] ?? '')), 0, 190),
                ':extra'    => mb_substr($extra, 0, 1000),
                ':notes'    => mb_substr(trim((string) ($input['client_notes'] ?? '')), 0, 1000),
                ':deadline' => $deadline->format('Y-m-d H:i:s'),
                ':source'   => $source === 'admin' ? 'admin' : 'online',
                ':ip'       => client_ip(),
                ':created'  => $now,
                ':updated'  => $now,
            ]
        );

        $bookingId = (int) db()->lastInsertId();
        bk_commit();
    } catch (Throwable $e) {
        bk_rollback();
        error_log('Booking creation failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'We could not save that booking. Please try again in a moment.', 'booking' => null];
    }

    $booking = bk_booking($bookingId);
    if (!$booking) {
        return ['ok' => false, 'message' => 'We could not save that booking. Please try again in a moment.', 'booking' => null];
    }

    bk_save_guests($bookingId, (array) ($input['guest_names'] ?? []));
    bk_payment_ensure($booking);

    bk_audit($bookingId, 'booking created', $reference . ' · ' . $service['name'] . ' · ' . $date . ' ' . $start);

    // Staff taking a booking over the phone already know about it, so the
    // team alert is only sent for bookings made online.
    bk_notify_booking_created($booking, $notifyClient, $source !== 'admin');

    return ['ok' => true, 'message' => 'Booking ' . $reference . ' created.', 'booking' => $booking];
}

/** Replace the guest list for a booking. */
function bk_save_guests(int $bookingId, array $names): void
{
    db_run('DELETE FROM booking_guests WHERE booking_id = :b', [':b' => $bookingId]);

    $position = 0;
    foreach ($names as $name) {
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $position++;
        db_run(
            'INSERT INTO booking_guests (booking_id, full_name, position) VALUES (:b, :n, :p)',
            [':b' => $bookingId, ':n' => mb_substr($name, 0, 190), ':p' => $position]
        );
        if ($position >= 60) {
            break;
        }
    }
}

function bk_guests(int $bookingId): array
{
    return db_all('SELECT * FROM booking_guests WHERE booking_id = :b ORDER BY position, id', [':b' => $bookingId]);
}

/* ======================================================== 4. TRANSACTIONS */

/**
 * How deep we are inside bk_begin().
 *
 * PDO::inTransaction() cannot be used for this: pdo_sqlite on PHP 8.0 only
 * tracks transactions opened with PDO::beginTransaction() and always reports
 * false for the BEGIN IMMEDIATE this code needs, which would leave every
 * commit and rollback silently doing nothing.
 */
function bk_tx_depth(?int $set = null): int
{
    static $depth = 0;
    if ($set !== null) {
        $depth = max(0, $set);
    }
    return $depth;
}

/** True once something inside the transaction has asked to roll back. */
function bk_tx_poisoned(?bool $set = null): bool
{
    static $poisoned = false;
    if ($set !== null) {
        $poisoned = $set;
    }
    return $poisoned;
}

/**
 * BEGIN IMMEDIATE, so a second writer waits for us instead of racing.
 * A nested call simply joins the transaction that is already running.
 */
function bk_begin(): void
{
    $depth = bk_tx_depth();
    if ($depth > 0) {
        bk_tx_depth($depth + 1);
        return;
    }

    db_begin_exclusive();                // nothing to unwind if this throws
    bk_tx_depth(1);
    bk_tx_poisoned(false);
}

/**
 * Finish the outermost block. A rollback anywhere inside still rolls the
 * whole thing back, so a caller can never half-commit.
 */
function bk_commit(): void
{
    $depth = bk_tx_depth();
    if ($depth === 0) {
        return;
    }
    if ($depth > 1) {
        bk_tx_depth($depth - 1);
        return;
    }

    bk_tx_depth(0);
    bk_end_transaction(bk_tx_poisoned() ? 'ROLLBACK' : 'COMMIT');
    bk_tx_poisoned(false);
}

function bk_rollback(): void
{
    $depth = bk_tx_depth();
    if ($depth === 0) {
        return;
    }

    bk_tx_poisoned(true);
    if ($depth > 1) {
        bk_tx_depth($depth - 1);
        return;
    }

    bk_tx_depth(0);
    bk_end_transaction('ROLLBACK');
    bk_tx_poisoned(false);
}

/** Close the SQLite transaction, never letting the attempt itself throw. */
function bk_end_transaction(string $statement): void
{
    try {
        db()->exec($statement);
    } catch (Throwable $e) {
        // Already closed, or the connection has gone. Either way the request
        // ends here and SQLite rolls back anything still open.
        error_log('Booking transaction ' . $statement . ' failed: ' . $e->getMessage());
    }
}

/* ===================================================== 5. PROOF OF PAYMENT */

/** File extensions the site accepts, from the admin setting. */
function bk_allowed_extensions(): array
{
    $configured = strtolower(bk('bk_upload_types', 'pdf,jpg,jpeg,png'));
    $allowed = [];
    foreach (preg_split('/[,\s]+/', $configured) ?: [] as $ext) {
        $ext = trim($ext, ". \t");
        if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            $allowed[$ext] = true;
        }
    }
    return $allowed ? array_keys($allowed) : ['pdf', 'jpg', 'jpeg', 'png'];
}

/** The largest proof file the site accepts, in bytes. */
function bk_max_upload_bytes(): int
{
    $mb = bk_int('bk_upload_max_mb', 6);
    $mb = max(1, min(20, $mb));
    return $mb * 1024 * 1024;
}

/** The extensions each accepted MIME type may carry. */
function bk_mime_extensions(): array
{
    return [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
    ];
}

/**
 * Validate and store one proof-of-payment file.
 *
 * The file is checked by upload status, size, extension, real MIME type and
 * content signature, then written under a random name into data/proofs, which
 * the web server does not serve.
 *
 * @return array{ok: bool, message: string, proof: ?array}
 */
function bk_upload_proof(array $booking, array $file, array $fields, string $uploadedBy = 'client', string $uploaderName = ''): array
{
    $fail = static fn (string $message): array => ['ok' => false, 'message' => $message, 'proof' => null];

    if (!in_array((string) $booking['status'], bk_awaiting_payment_statuses(), true)) {
        return $fail('This booking is not waiting for a proof of payment.');
    }

    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $fail('Please choose your proof of payment before uploading.');
    }
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return $fail('That file is too large. The limit is ' . bk_int('bk_upload_max_mb', 6) . ' MB.');
    }
    if ($error === UPLOAD_ERR_PARTIAL) {
        return $fail('The upload was interrupted. Please try again.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        return $fail('That file could not be uploaded (error code ' . $error . '). Please try again.');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return $fail('That upload could not be verified. Please try again.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return $fail('That file is empty. Please upload the proof of payment again.');
    }
    if ($size > bk_max_upload_bytes()) {
        return $fail('That file is larger than ' . bk_int('bk_upload_max_mb', 6) . ' MB. Please upload a smaller version.');
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = bk_allowed_extensions();
    if (!in_array($extension, $allowed, true)) {
        return $fail('Please upload a ' . strtoupper(implode(', ', $allowed)) . ' file.');
    }

    // The real type, read from the file's own bytes rather than its name.
    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
    }
    $mimeMap = bk_mime_extensions();
    if ($mime === '' || !isset($mimeMap[$mime])) {
        return $fail('That file is not a PDF or an image we can read. Please upload a PDF, JPG or PNG.');
    }
    if (!in_array($extension, $mimeMap[$mime], true)) {
        return $fail('That file name does not match what is inside the file. Please upload the original PDF, JPG or PNG.');
    }

    // Content checks, so a renamed script can never be stored.
    if ($mime === 'application/pdf') {
        $head = (string) @file_get_contents($tmp, false, null, 0, 5);
        if (strncmp($head, '%PDF-', 5) !== 0) {
            return $fail('That file claims to be a PDF but does not look like one.');
        }
    } else {
        $info = @getimagesize($tmp);
        $expected = $mime === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($info === false || (int) ($info[2] ?? 0) !== $expected) {
            return $fail('That image could not be read. Please upload it again, or send a PDF instead.');
        }
    }

    $folder = bk_storage_path('proofs');
    if (!is_dir($folder) || !is_writable($folder)) {
        return $fail('Proof of payment cannot be stored at the moment. Please contact us so we can help.');
    }

    $storedName = 'proof-' . date('Ymd') . '-' . bin2hex(random_bytes(16)) . '.' . $extension;
    $target = $folder . '/' . $storedName;

    if (!move_uploaded_file($tmp, $target)) {
        return $fail('The file could not be saved. Please try again.');
    }
    @chmod($target, 0640);

    $payment = bk_payment_ensure($booking);
    $amount = bk_money_parse((string) ($fields['amount'] ?? ''));
    $bankReference = mb_substr(trim((string) ($fields['bank_reference'] ?? '')), 0, 120);
    $paidOn = trim((string) ($fields['paid_on'] ?? ''));
    $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn) ? $paidOn : '';
    $notes = mb_substr(trim((string) ($fields['client_notes'] ?? '')), 0, 1000);

    // Anything still open is superseded by this upload.
    db_run(
        "UPDATE payment_proofs SET status = 'superseded' WHERE booking_id = :b AND status = 'submitted'",
        [':b' => (int) $booking['id']]
    );

    db_run(
        'INSERT INTO payment_proofs
            (booking_id, payment_id, stored_name, original_name, mime, extension, size, checksum,
             amount, bank_reference, paid_on, client_notes, status, uploaded_by, uploaded_by_name, uploaded_ip, created_at)
         VALUES
            (:b, :p, :stored, :original, :mime, :ext, :size, :sum,
             :amount, :bank, :paid, :notes, :status, :by, :byname, :ip, :created)',
        [
            ':b'        => (int) $booking['id'],
            ':p'        => (int) ($payment['id'] ?? 0),
            ':stored'   => $storedName,
            ':original' => mb_substr(preg_replace('/[\x00-\x1F]/', '', $originalName) ?? '', 0, 190),
            ':mime'     => $mime,
            ':ext'      => $extension,
            ':size'     => $size,
            ':sum'      => (string) @hash_file('sha256', $target),
            ':amount'   => $amount,
            ':bank'     => $bankReference,
            ':paid'     => $paidOn,
            ':notes'    => $notes,
            ':status'   => 'submitted',
            ':by'       => $uploadedBy === 'admin' ? 'admin' : 'client',
            ':byname'   => mb_substr($uploaderName, 0, 120),
            ':ip'       => client_ip(),
            ':created'  => date('Y-m-d H:i:s'),
        ]
    );

    $proofId = (int) db()->lastInsertId();

    db_run(
        "UPDATE eft_payments
            SET status = 'submitted', amount_declared = :amount, bank_reference = :bank,
                paid_on = :paid, client_notes = :notes, decline_reason = '', updated_at = :u
          WHERE id = :id",
        [
            ':amount' => $amount,
            ':bank'   => $bankReference,
            ':paid'   => $paidOn,
            ':notes'  => $notes,
            ':u'      => date('Y-m-d H:i:s'),
            ':id'     => (int) ($payment['id'] ?? 0),
        ]
    );

    bk_set_status($booking, 'proof_submitted', 'proof of payment uploaded');
    bk_audit(
        (int) $booking['id'],
        'proof uploaded',
        $storedName . ' · ' . strtoupper($extension) . ' · ' . round($size / 1024) . ' KB'
            . ($amount > 0 ? ' · declared ' . bk_money($amount) : '')
    );

    $booking = bk_booking((int) $booking['id']) ?? $booking;
    bk_notify_proof_uploaded($booking);

    return [
        'ok'      => true,
        'message' => 'Thank you — your proof of payment has been received and is now waiting for our team to verify it.',
        'proof'   => db_one('SELECT * FROM payment_proofs WHERE id = :id', [':id' => $proofId]),
    ];
}

/* ================================================= 6. STATUS TRANSITIONS */

/** Move a booking to a new status and record why. */
function bk_set_status(array $booking, string $status, string $detail = ''): void
{
    if (!array_key_exists($status, bk_statuses())) {
        return;
    }
    $from = (string) $booking['status'];
    if ($from === $status) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $extra = '';
    $params = [':s' => $status, ':u' => $now, ':id' => (int) $booking['id']];

    if ($status === 'confirmed') {
        $extra = ', confirmed_at = :t';
        $params[':t'] = $now;
    } elseif ($status === 'cancelled' || $status === 'declined') {
        $extra = ', cancelled_at = :t';
        $params[':t'] = $now;
    } elseif ($status === 'completed') {
        $extra = ', completed_at = :t';
        $params[':t'] = $now;
    }

    db_run("UPDATE service_bookings SET status = :s, updated_at = :u{$extra} WHERE id = :id", $params);

    bk_audit(
        (int) $booking['id'],
        'status changed',
        bk_status_label($from) . ' → ' . bk_status_label($status) . ($detail !== '' ? ' (' . $detail . ')' : '')
    );
}

/* ================================================ 7. ADMIN VERIFICATION */

/**
 * Approve an EFT payment. This is the only route to a confirmed booking.
 *
 * On success the booking becomes "Booking Confirmed", the ticket and receipt
 * are produced by the Core PDF generator, and the client is emailed both.
 *
 * @param array $input amount_received, bank_reference, notes, verified_on
 * @return array{ok: bool, message: string}
 */
function bk_approve_payment(array $booking, array $input, array $staff): array
{
    $payment = bk_payment_ensure($booking);
    if (!$payment) {
        return ['ok' => false, 'message' => 'That booking has no payment record.'];
    }
    if (in_array((string) $booking['status'], ['cancelled', 'refunded'], true)) {
        return ['ok' => false, 'message' => 'That booking has been ' . strtolower(bk_status_label((string) $booking['status'])) . ' and cannot be approved.'];
    }

    $received = bk_money_parse((string) ($input['amount_received'] ?? ''));
    if ($received <= 0) {
        $received = (float) $booking['amount_due'];
    }

    $verifiedOn = trim((string) ($input['verified_on'] ?? ''));
    $verifiedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $verifiedOn)
        ? $verifiedOn . ' ' . date('H:i:s')
        : date('Y-m-d H:i:s');

    $bankReference = mb_substr(trim((string) ($input['bank_reference'] ?? '')), 0, 120);
    $notes = mb_substr(trim((string) ($input['notes'] ?? '')), 0, 1000);
    $staffName = (string) ($staff['name'] ?: $staff['username']);

    db_run(
        "UPDATE eft_payments
            SET status = 'confirmed', amount_received = :amount, admin_bank_reference = :bank,
                admin_notes = :notes, decline_reason = '', verified_by = :uid, verified_by_name = :uname,
                verified_at = :at, updated_at = :u
          WHERE id = :id",
        [
            ':amount' => $received,
            ':bank'   => $bankReference,
            ':notes'  => $notes,
            ':uid'    => (int) $staff['id'],
            ':uname'  => $staffName,
            ':at'     => $verifiedAt,
            ':u'      => date('Y-m-d H:i:s'),
            ':id'     => (int) $payment['id'],
        ]
    );

    db_run(
        "UPDATE payment_proofs
            SET status = 'approved', reviewed_by_name = :name, reviewed_at = :at, review_reason = ''
          WHERE booking_id = :b AND status IN ('submitted','declined')",
        [':name' => $staffName, ':at' => date('Y-m-d H:i:s'), ':b' => (int) $booking['id']]
    );

    db_run(
        "UPDATE service_bookings SET amount_paid = :paid, decline_reason = '', updated_at = :u WHERE id = :id",
        [':paid' => $received, ':u' => date('Y-m-d H:i:s'), ':id' => (int) $booking['id']]
    );

    $booking = bk_booking((int) $booking['id']) ?? $booking;
    bk_audit(
        (int) $booking['id'],
        'payment approved',
        bk_money($received, (string) $booking['currency'])
            . ($bankReference !== '' ? ' · bank ref ' . $bankReference : '')
            . ' · verified by ' . $staffName
    );

    // Payment Confirmed, then automatically Booking Confirmed.
    bk_set_status($booking, 'payment_confirmed', 'EFT payment verified by ' . $staffName);
    $booking = bk_booking((int) $booking['id']) ?? $booking;
    bk_set_status($booking, 'confirmed', 'confirmed automatically after payment approval');
    $booking = bk_booking((int) $booking['id']) ?? $booking;

    $payment = bk_payment_for((int) $booking['id']) ?? $payment;
    $ticket  = bk_ticket_issue($booking, true);
    $receipt = bk_receipt_issue($booking, $payment, true);

    $warnings = [];
    if (!$ticket || (string) $ticket['file_name'] === '') {
        $warnings[] = 'the ticket PDF could not be produced';
    }
    if (!$receipt || (string) $receipt['file_name'] === '') {
        $warnings[] = 'the receipt PDF could not be produced';
    }

    $sent = bk_notify_payment_approved($booking, $payment, $ticket, $receipt);
    bk_notify_admin_decision($booking, 'approved', $notes);

    $message = 'Payment approved. Booking ' . $booking['reference'] . ' is confirmed';
    $message .= $sent ? ' and the client has been emailed their ticket and receipt.' : '.';
    if (!$sent) {
        $message .= ' The confirmation email could not be sent — check Email log.';
    }
    if ($warnings) {
        $message .= ' Note: ' . implode(' and ', $warnings) . '.';
    }

    return ['ok' => true, 'message' => $message];
}

/**
 * Decline a proof of payment. A reason is required and is sent to the client.
 *
 * @return array{ok: bool, message: string}
 */
function bk_decline_payment(array $booking, string $reason, array $staff): array
{
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => 'Please give a reason. It is sent to the client so they can correct it.'];
    }

    $payment = bk_payment_ensure($booking);
    $staffName = (string) ($staff['name'] ?: $staff['username']);
    $now = date('Y-m-d H:i:s');

    db_run(
        "UPDATE eft_payments SET status = 'declined', decline_reason = :r, verified_by = :uid,
                verified_by_name = :uname, verified_at = :at, updated_at = :u
          WHERE id = :id",
        [
            ':r' => mb_substr($reason, 0, 500), ':uid' => (int) $staff['id'], ':uname' => $staffName,
            ':at' => $now, ':u' => $now, ':id' => (int) $payment['id'],
        ]
    );

    db_run(
        "UPDATE payment_proofs SET status = 'declined', reviewed_by_name = :name, reviewed_at = :at, review_reason = :r
          WHERE booking_id = :b AND status = 'submitted'",
        [':name' => $staffName, ':at' => $now, ':r' => mb_substr($reason, 0, 500), ':b' => (int) $booking['id']]
    );

    db_run(
        'UPDATE service_bookings SET decline_reason = :r, updated_at = :u WHERE id = :id',
        [':r' => mb_substr($reason, 0, 500), ':u' => $now, ':id' => (int) $booking['id']]
    );

    bk_audit((int) $booking['id'], 'payment declined', $reason . ' · by ' . $staffName);
    bk_set_status($booking, 'declined', 'proof of payment declined by ' . $staffName);

    $booking = bk_booking((int) $booking['id']) ?? $booking;
    bk_notify_payment_declined($booking, $reason);
    bk_notify_admin_decision($booking, 'declined', $reason);

    return ['ok' => true, 'message' => 'Proof of payment declined and the client has been told why.'];
}

/** Put a declined booking back into the queue so the client can try again. */
function bk_reopen_payment(array $booking): void
{
    $payment = bk_payment_ensure($booking);
    db_run(
        "UPDATE eft_payments SET status = 'awaiting', decline_reason = '', updated_at = :u WHERE id = :id",
        [':u' => date('Y-m-d H:i:s'), ':id' => (int) $payment['id']]
    );
    db_run(
        "UPDATE service_bookings SET decline_reason = '', updated_at = :u WHERE id = :id",
        [':u' => date('Y-m-d H:i:s'), ':id' => (int) $booking['id']]
    );
    bk_set_status($booking, 'awaiting_eft', 'reopened for a new proof of payment');
}

/* ================================================ 8. CANCEL / RESCHEDULE */

/** Hours between now and the start of a booking. */
function bk_hours_until(array $booking): float
{
    $start = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        (string) $booking['booking_date'] . ' ' . (string) $booking['start_time'],
        new DateTimeZone(setting('event_timezone', 'Africa/Windhoek'))
    );
    if (!$start) {
        return 0.0;
    }
    return ($start->getTimestamp() - bk_now()->getTimestamp()) / 3600;
}

/**
 * Whether the client may ask to cancel, and why not when they may not.
 *
 * @return array{allowed: bool, reason: string}
 */
function bk_can_request_cancel(array $booking): array
{
    if (!bk_bool('bk_cancel_enabled', true)) {
        return ['allowed' => false, 'reason' => 'Cancellations are handled by our team — please contact us.'];
    }
    if (in_array((string) $booking['status'], ['cancelled', 'declined', 'completed', 'refunded'], true)) {
        return ['allowed' => false, 'reason' => 'This booking is already ' . strtolower(bk_status_label((string) $booking['status'])) . '.'];
    }
    if (bk_pending_request((int) $booking['id'])) {
        return ['allowed' => false, 'reason' => 'You already have a request waiting for our team.'];
    }

    $minimum = bk_int('bk_cancel_min_hours', 48);
    $hours = bk_hours_until($booking);
    if ($hours < $minimum) {
        return [
            'allowed' => false,
            'reason'  => 'Cancellations must be requested at least ' . $minimum . ' hours before the booking. Please contact us directly.',
        ];
    }

    return ['allowed' => true, 'reason' => ''];
}

/**
 * Whether the client may ask to move the booking.
 *
 * @return array{allowed: bool, reason: string}
 */
function bk_can_request_reschedule(array $booking): array
{
    if (!bk_bool('bk_reschedule_enabled', true)) {
        return ['allowed' => false, 'reason' => 'Rescheduling is handled by our team — please contact us.'];
    }
    if (in_array((string) $booking['status'], ['cancelled', 'declined', 'completed', 'refunded'], true)) {
        return ['allowed' => false, 'reason' => 'This booking is already ' . strtolower(bk_status_label((string) $booking['status'])) . '.'];
    }
    if (bk_pending_request((int) $booking['id'])) {
        return ['allowed' => false, 'reason' => 'You already have a request waiting for our team.'];
    }

    $max = bk_int('bk_reschedule_max', 2);
    if ($max > 0 && (int) $booking['reschedules'] >= $max) {
        return ['allowed' => false, 'reason' => 'This booking has already been moved ' . $max . ' times. Please contact us.'];
    }

    $minimum = bk_int('bk_reschedule_min_hours', 48);
    if (bk_hours_until($booking) < $minimum) {
        return [
            'allowed' => false,
            'reason'  => 'Changes must be requested at least ' . $minimum . ' hours before the booking. Please contact us directly.',
        ];
    }

    return ['allowed' => true, 'reason' => ''];
}

function bk_pending_request(int $bookingId): ?array
{
    return db_one(
        "SELECT * FROM booking_requests WHERE booking_id = :b AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [':b' => $bookingId]
    );
}

function bk_requests(int $bookingId): array
{
    return db_all('SELECT * FROM booking_requests WHERE booking_id = :b ORDER BY id DESC', [':b' => $bookingId]);
}

/**
 * Record a client's cancellation or reschedule request.
 *
 * @return array{ok: bool, message: string}
 */
function bk_create_request(array $booking, string $type, array $input): array
{
    $type = $type === 'reschedule' ? 'reschedule' : 'cancel';
    $check = $type === 'cancel' ? bk_can_request_cancel($booking) : bk_can_request_reschedule($booking);
    if (!$check['allowed']) {
        return ['ok' => false, 'message' => $check['reason']];
    }

    $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 1000);
    $date = '';
    $time = '';

    if ($type === 'reschedule') {
        $date = trim((string) ($input['requested_date'] ?? ''));
        $time = trim((string) ($input['requested_time'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return ['ok' => false, 'message' => 'Please choose the new date and time you would like.'];
        }

        $service = bk_service((int) $booking['service_id']);
        if (!$service) {
            return ['ok' => false, 'message' => 'That service is no longer available. Please contact us.'];
        }
        $problem = bk_slot_problem($service, $date, $time, (int) $booking['seats'], (int) $booking['id']);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
    }

    db_run(
        'INSERT INTO booking_requests (booking_id, client_id, type, requested_date, requested_time, reason, status, created_at)
         VALUES (:b, :c, :t, :d, :ti, :r, :s, :cr)',
        [
            ':b' => (int) $booking['id'], ':c' => (int) $booking['client_id'], ':t' => $type,
            ':d' => $date, ':ti' => $time, ':r' => $reason, ':s' => 'pending', ':cr' => date('Y-m-d H:i:s'),
        ]
    );

    bk_audit(
        (int) $booking['id'],
        $type . ' requested',
        $type === 'reschedule' ? 'to ' . $date . ' ' . $time . ($reason !== '' ? ' · ' . $reason : '') : $reason
    );
    bk_notify_admin_request($booking, $type, $date, $time, $reason);

    return [
        'ok' => true,
        'message' => $type === 'cancel'
            ? 'Your cancellation request has been sent to our team. We will be in touch.'
            : 'Your request to move this booking has been sent to our team. We will confirm by email.',
    ];
}

/**
 * Move a booking to a new slot. Used by the admin panel, both for its own
 * edits and when approving a client's reschedule request.
 *
 * @return array{ok: bool, message: string}
 */
function bk_reschedule(array $booking, string $date, string $time, string $detail = ''): array
{
    $service = bk_service((int) $booking['service_id']);
    if (!$service) {
        return ['ok' => false, 'message' => 'That service no longer exists.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
        return ['ok' => false, 'message' => 'Please give a valid date and time.'];
    }

    try {
        bk_begin();
        $problem = bk_slot_problem($service, $date, $time, (int) $booking['seats'], (int) $booking['id']);
        if ($problem !== null) {
            bk_rollback();
            return ['ok' => false, 'message' => $problem];
        }

        db_run(
            'UPDATE service_bookings
                SET booking_date = :d, start_time = :s, end_time = :e,
                    reschedules = reschedules + 1, updated_at = :u
              WHERE id = :id',
            [
                ':d' => $date, ':s' => $time, ':e' => bk_slot_end($service, $time),
                ':u' => date('Y-m-d H:i:s'), ':id' => (int) $booking['id'],
            ]
        );
        bk_commit();
    } catch (Throwable $e) {
        bk_rollback();
        return ['ok' => false, 'message' => 'That change could not be saved. Please try again.'];
    }

    $was = (string) $booking['booking_date'] . ' ' . (string) $booking['start_time'];
    bk_audit((int) $booking['id'], 'booking rescheduled', $was . ' → ' . $date . ' ' . $time . ($detail !== '' ? ' · ' . $detail : ''));

    $booking = bk_booking((int) $booking['id']) ?? $booking;

    // A confirmed booking gets a fresh ticket showing the new time.
    if (in_array((string) $booking['status'], ['confirmed', 'payment_confirmed'], true)) {
        bk_ticket_issue($booking, true);
    }

    bk_notify_rescheduled($booking, $was);

    return ['ok' => true, 'message' => 'Booking moved to ' . bk_date_long($date) . ' at ' . $time . '.'];
}

/** Cancel a booking and let everybody know. */
function bk_cancel_booking(array $booking, string $reason, string $by = 'staff'): array
{
    if ((string) $booking['status'] === 'cancelled') {
        return ['ok' => false, 'message' => 'That booking is already cancelled.'];
    }

    // Built in PHP rather than in SQL: "||" concatenates in SQLite but means
    // logical OR in MySQL, so doing it in the query is not portable.
    $existing = trim((string) $booking['admin_notes']);
    $note = 'Cancelled: ' . mb_substr($reason, 0, 400);
    $notes = trim($existing === '' ? $note : $existing . "\n" . $note);

    db_run(
        'UPDATE service_bookings SET admin_notes = :notes, updated_at = :u WHERE id = :id',
        [
            ':notes' => mb_substr($notes, 0, 2000),
            ':u'     => date('Y-m-d H:i:s'),
            ':id'    => (int) $booking['id'],
        ]
    );

    bk_set_status($booking, 'cancelled', $reason !== '' ? $reason : 'cancelled by ' . $by);
    db_run("UPDATE booking_tickets SET status = 'void' WHERE booking_id = :b", [':b' => (int) $booking['id']]);

    $booking = bk_booking((int) $booking['id']) ?? $booking;
    bk_notify_cancelled($booking, $reason);

    return ['ok' => true, 'message' => 'Booking ' . $booking['reference'] . ' cancelled.'];
}

/* ============================================================= 9. EMAILS */

/** Every fact about a booking, ready for an email table. */
function bk_booking_facts(array $booking): array
{
    $facts = [
        ['Booking reference', (string) $booking['reference']],
        ['Service', (string) $booking['service_name']],
        ['Date', bk_date_long((string) $booking['booking_date'])],
        ['Time', bk_time_range((string) $booking['start_time'], (string) $booking['end_time'])],
    ];
    if ((int) $booking['guests'] > 1) {
        $facts[] = ['Guests', (string) $booking['guests']];
    }
    if (trim((string) $booking['location']) !== '') {
        $facts[] = ['Location', (string) $booking['location']];
    }
    $facts[] = ['Amount due', bk_money((float) $booking['amount_due'], (string) $booking['currency'])];
    $facts[] = ['Booking status', bk_status_label((string) $booking['status'])];
    $facts[] = ['Payment status', bk_payment_label($booking)];

    return $facts;
}

/** The banking details, formatted for an email or a page. */
function bk_bank_facts(array $booking): array
{
    return array_values(array_filter([
        ['Account name', setting('bank_account_name')],
        ['Bank', setting('bank_name')],
        ['Account number', setting('bank_account_number')],
        ['Branch code', setting('bank_branch_code')],
        ['Account type', setting('bank_account_type')],
        ['SWIFT / BIC', bk('bk_bank_swift')],
        ['Amount to pay', bk_money((float) $booking['amount_due'], (string) $booking['currency'])],
        ['Payment reference', (string) $booking['reference']],
    ], static fn (array $row): bool => trim((string) $row[1]) !== ''));
}

function bk_deadline_text(array $booking): string
{
    $deadline = (string) $booking['payment_deadline'];
    if ($deadline === '') {
        return '';
    }
    $time = strtotime($deadline);
    return $time ? date('d F Y \a\t H:i', $time) : '';
}

/** The client has just made a booking: send instructions, tell the team. */
function bk_notify_booking_created(array $booking, bool $toClient = true, bool $toAdmin = true): void
{
    $deadline = bk_deadline_text($booking);
    $facts = array_merge(bk_booking_facts($booking), bk_bank_facts($booking));
    if ($deadline !== '') {
        $facts[] = ['Pay by', $deadline];
    }

    $instructions = trim(bk('bk_payment_instructions'));
    $note = trim(bk('bk_payment_note'));

    if ($toClient) {
        bk_mail([
            'to'         => (string) $booking['contact_email'],
            'subject'    => 'Booking ' . $booking['reference'] . ' received — please pay by EFT',
            'template'   => 'booking_created',
            'booking_id' => (int) $booking['id'],
            'client_id'  => (int) $booking['client_id'],
            'html'       => bk_email_html(
                'We have your booking',
                'Thank you, ' . (string) $booking['contact_name'] . ". Your booking is recorded and held for you.\n\n"
                    . 'To confirm it, please pay by electronic funds transfer using the details below and quote '
                    . $booking['reference'] . ' as your payment reference. Then upload your proof of payment.',
                $facts,
                [['text' => 'Upload proof of payment', 'url' => bk_url('booking/pay.php') . '?ref=' . rawurlencode((string) $booking['reference']) . '&t=' . rawurlencode((string) $booking['access_token'])]],
                trim($instructions . ($instructions !== '' && $note !== '' ? "\n\n" : '') . $note)
            ),
        ]);
    }

    $recipients = $toAdmin ? bk_admin_recipients() : [];
    if ($recipients) {
        bk_mail([
            'to'         => $recipients,
            'subject'    => 'New booking ' . $booking['reference'] . ' — ' . $booking['service_name'],
            'template'   => 'admin_booking_created',
            'booking_id' => (int) $booking['id'],
            'html'       => bk_email_html(
                'New booking received',
                'A new booking has come in and is awaiting EFT payment.',
                array_merge(bk_booking_facts($booking), [
                    ['Client', (string) $booking['contact_name']],
                    ['Email', (string) $booking['contact_email']],
                    ['Phone', (string) $booking['contact_phone']],
                    ['Company', (string) $booking['company']],
                ]),
                [['text' => 'Open in the control panel', 'url' => bk_url('admin/') . '?p=eft_bookings&action=view&id=' . (int) $booking['id']]]
            ),
        ]);
    }
}

/** A proof has arrived: reassure the client, alert the team. */
function bk_notify_proof_uploaded(array $booking): void
{
    bk_mail([
        'to'         => (string) $booking['contact_email'],
        'subject'    => 'Proof of payment received for ' . $booking['reference'],
        'template'   => 'proof_received',
        'booking_id' => (int) $booking['id'],
        'client_id'  => (int) $booking['client_id'],
        'html'       => bk_email_html(
            'Proof of payment received',
            'Thank you. We have your proof of payment for booking ' . $booking['reference'] . ".\n\n"
                . 'A member of our team will check it against our bank account. Your booking will be confirmed '
                . 'once the payment has been verified, and you will get your ticket and receipt by email.',
            bk_booking_facts($booking),
            [['text' => 'View my booking', 'url' => bk_url('booking/account.php') . '?p=booking&id=' . (int) $booking['id']]],
            'Please note: a booking is only confirmed after our team has verified the payment. Uploading a file does not confirm it on its own.'
        ),
    ]);

    $recipients = bk_admin_recipients();
    if ($recipients) {
        $proof = bk_latest_proof((int) $booking['id']);
        bk_mail([
            'to'         => $recipients,
            'subject'    => 'Proof of payment to review — ' . $booking['reference'],
            'template'   => 'admin_proof_received',
            'booking_id' => (int) $booking['id'],
            'html'       => bk_email_html(
                'A proof of payment is waiting for review',
                'Booking ' . $booking['reference'] . ' has a new proof of payment to verify.',
                array_merge(bk_booking_facts($booking), [
                    ['Client', (string) $booking['contact_name']],
                    ['Declared amount', $proof && (float) $proof['amount'] > 0 ? bk_money((float) $proof['amount']) : '—'],
                    ['Bank reference given', $proof ? (string) $proof['bank_reference'] : ''],
                    ['File', $proof ? strtoupper((string) $proof['extension']) . ' · ' . round(((int) $proof['size']) / 1024) . ' KB' : ''],
                ]),
                [['text' => 'Review the payment', 'url' => bk_url('admin/') . '?p=eft_bookings&action=view&id=' . (int) $booking['id']]]
            ),
        ]);
    }
}

/** Payment approved: send the confirmation with the ticket and receipt. */
function bk_notify_payment_approved(array $booking, array $payment, ?array $ticket, ?array $receipt): bool
{
    $attachments = [];

    if ($ticket && (string) $ticket['file_name'] !== '') {
        $path = bk_storage_file('tickets', (string) $ticket['file_name']);
        if ($path !== null) {
            $attachments[] = ['path' => $path, 'name' => 'ticket-' . $booking['reference'] . '.pdf', 'mime' => 'application/pdf'];
        }
    }
    if ($receipt && (string) $receipt['file_name'] !== '') {
        $path = bk_storage_file('receipts', (string) $receipt['file_name']);
        if ($path !== null) {
            $attachments[] = ['path' => $path, 'name' => 'receipt-' . $booking['reference'] . '.pdf', 'mime' => 'application/pdf'];
        }
    }

    $facts = bk_booking_facts($booking);
    $facts[] = ['Amount received', bk_money((float) $payment['amount_received'], (string) $booking['currency'])];
    $facts[] = ['Payment verified on', $payment['verified_at'] !== '' ? date('d F Y', strtotime((string) $payment['verified_at']) ?: time()) : ''];
    if ($ticket) {
        $facts[] = ['Ticket number', (string) $ticket['ticket_number']];
        $facts[] = ['Verification code', (string) $ticket['verification_code']];
    }
    if ($receipt) {
        $facts[] = ['Receipt number', (string) $receipt['receipt_number']];
    }

    $buttons = [['text' => 'View my booking', 'url' => bk_url('booking/account.php') . '?p=booking&id=' . (int) $booking['id']]];
    if ($ticket) {
        $buttons[] = [
            'text' => 'Download ticket',
            'url'  => bk_url('booking/download.php') . '?kind=ticket&ref=' . rawurlencode((string) $booking['reference'])
                . '&t=' . rawurlencode((string) $booking['access_token']),
        ];
    }
    if ($receipt) {
        $buttons[] = [
            'text' => 'Download receipt',
            'url'  => bk_url('booking/download.php') . '?kind=receipt&ref=' . rawurlencode((string) $booking['reference'])
                . '&t=' . rawurlencode((string) $booking['access_token']),
        ];
    }

    return bk_mail([
        'to'          => (string) $booking['contact_email'],
        'subject'     => 'Confirmed — booking ' . $booking['reference'] . ' · ' . $booking['service_name'],
        'template'    => 'payment_approved',
        'booking_id'  => (int) $booking['id'],
        'client_id'   => (int) $booking['client_id'],
        'attachments' => $attachments,
        'html'        => bk_email_html(
            'Your booking is confirmed',
            'Good news, ' . (string) $booking['contact_name'] . ". We have verified your EFT payment and your booking is confirmed.\n\n"
                . ($attachments
                    ? 'Your ticket and payment receipt are attached to this email, and you can download them from your account at any time.'
                    : 'You can download your ticket and payment receipt from your account.'),
            $facts,
            $buttons,
            trim(bk('bk_ticket_instructions'))
        ),
    ]);
}

/** Payment declined: tell the client exactly why and how to fix it. */
function bk_notify_payment_declined(array $booking, string $reason): void
{
    bk_mail([
        'to'         => (string) $booking['contact_email'],
        'subject'    => 'We could not accept the proof of payment for ' . $booking['reference'],
        'template'   => 'payment_declined',
        'booking_id' => (int) $booking['id'],
        'client_id'  => (int) $booking['client_id'],
        'html'       => bk_email_html(
            'We need a different proof of payment',
            'Thank you for sending your proof of payment for booking ' . $booking['reference'] . ".\n\n"
                . 'Unfortunately our team could not accept it. The reason is below. Your booking is still held — '
                . 'please upload a corrected proof of payment and we will review it again.',
            array_merge(bk_booking_facts($booking), bk_bank_facts($booking)),
            [['text' => 'Upload a corrected proof', 'url' => bk_url('booking/pay.php') . '?ref=' . rawurlencode((string) $booking['reference']) . '&t=' . rawurlencode((string) $booking['access_token'])]],
            'Reason given by our team: ' . $reason
        ),
    ]);
}

/** Tell the team an approval or decline has happened. */
function bk_notify_admin_decision(array $booking, string $decision, string $note): void
{
    $recipients = bk_admin_recipients();
    if (!$recipients) {
        return;
    }

    bk_mail([
        'to'         => $recipients,
        'subject'    => 'Payment ' . $decision . ' — ' . $booking['reference'],
        'template'   => 'admin_payment_' . $decision,
        'booking_id' => (int) $booking['id'],
        'html'       => bk_email_html(
            'Payment ' . $decision,
            'Booking ' . $booking['reference'] . ' has had its EFT payment ' . $decision . '.',
            array_merge(bk_booking_facts($booking), [['Note', $note]]),
            [['text' => 'Open in the control panel', 'url' => bk_url('admin/') . '?p=eft_bookings&action=view&id=' . (int) $booking['id']]]
        ),
    ]);
}

/** Tell the team a client has asked to cancel or move a booking. */
function bk_notify_admin_request(array $booking, string $type, string $date, string $time, string $reason): void
{
    $recipients = bk_admin_recipients();
    if (!$recipients) {
        return;
    }

    $facts = bk_booking_facts($booking);
    if ($type === 'reschedule') {
        $facts[] = ['Requested new date', bk_date_long($date) . ' at ' . $time];
    }
    $facts[] = ['Client', (string) $booking['contact_name']];
    $facts[] = ['Reason given', $reason];

    bk_mail([
        'to'         => $recipients,
        'subject'    => ucfirst($type) . ' request — ' . $booking['reference'],
        'template'   => 'admin_' . $type . '_request',
        'booking_id' => (int) $booking['id'],
        'html'       => bk_email_html(
            'A client has asked to ' . ($type === 'cancel' ? 'cancel a booking' : 'move a booking'),
            'Booking ' . $booking['reference'] . ' has a ' . $type . ' request waiting.',
            $facts,
            [['text' => 'Open in the control panel', 'url' => bk_url('admin/') . '?p=eft_bookings&action=view&id=' . (int) $booking['id']]]
        ),
    ]);
}

/** A booking has moved. */
function bk_notify_rescheduled(array $booking, string $was): void
{
    bk_mail([
        'to'         => (string) $booking['contact_email'],
        'subject'    => 'Your booking ' . $booking['reference'] . ' has been moved',
        'template'   => 'rescheduled',
        'booking_id' => (int) $booking['id'],
        'client_id'  => (int) $booking['client_id'],
        'html'       => bk_email_html(
            'Your booking has a new date and time',
            'Booking ' . $booking['reference'] . ' has been moved from ' . $was . '.',
            bk_booking_facts($booking),
            [['text' => 'View my booking', 'url' => bk_url('booking/account.php') . '?p=booking&id=' . (int) $booking['id']]],
            in_array((string) $booking['status'], ['confirmed', 'payment_confirmed'], true)
                ? 'A replacement ticket showing the new time is available in your account.'
                : ''
        ),
    ]);

    $recipients = bk_admin_recipients();
    if ($recipients) {
        bk_mail([
            'to'         => $recipients,
            'subject'    => 'Booking moved — ' . $booking['reference'],
            'template'   => 'admin_rescheduled',
            'booking_id' => (int) $booking['id'],
            'html'       => bk_email_html('Booking moved', 'Booking ' . $booking['reference'] . ' moved from ' . $was . '.', bk_booking_facts($booking)),
        ]);
    }
}

/** A booking has been cancelled. */
function bk_notify_cancelled(array $booking, string $reason): void
{
    bk_mail([
        'to'         => (string) $booking['contact_email'],
        'subject'    => 'Booking ' . $booking['reference'] . ' cancelled',
        'template'   => 'cancelled',
        'booking_id' => (int) $booking['id'],
        'client_id'  => (int) $booking['client_id'],
        'html'       => bk_email_html(
            'Your booking has been cancelled',
            'Booking ' . $booking['reference'] . ' has been cancelled. Any ticket issued for it is no longer valid.',
            bk_booking_facts($booking),
            [],
            trim($reason !== '' ? 'Reason: ' . $reason . "\n\n" : '') . trim(bk('bk_cancel_policy'))
        ),
    ]);

    $recipients = bk_admin_recipients();
    if ($recipients) {
        bk_mail([
            'to'         => $recipients,
            'subject'    => 'Booking cancelled — ' . $booking['reference'],
            'template'   => 'admin_cancelled',
            'booking_id' => (int) $booking['id'],
            'html'       => bk_email_html('Booking cancelled', 'Booking ' . $booking['reference'] . ' was cancelled.', array_merge(bk_booking_facts($booking), [['Reason', $reason]])),
        ]);
    }
}

/** Send the EFT instructions again, on request from the admin panel. */
function bk_resend_instructions(array $booking): bool
{
    $facts = array_merge(bk_booking_facts($booking), bk_bank_facts($booking));
    $deadline = bk_deadline_text($booking);
    if ($deadline !== '') {
        $facts[] = ['Pay by', $deadline];
    }

    return bk_mail([
        'to'         => (string) $booking['contact_email'],
        'subject'    => 'Payment instructions for booking ' . $booking['reference'],
        'template'   => 'instructions_resent',
        'booking_id' => (int) $booking['id'],
        'client_id'  => (int) $booking['client_id'],
        'html'       => bk_email_html(
            'How to pay for your booking',
            'Here are the EFT details for booking ' . $booking['reference'] . ' again. Please use the booking reference as your payment reference.',
            $facts,
            [['text' => 'Upload proof of payment', 'url' => bk_url('booking/pay.php') . '?ref=' . rawurlencode((string) $booking['reference']) . '&t=' . rawurlencode((string) $booking['access_token'])]],
            trim(bk('bk_payment_instructions'))
        ),
    ]);
}

/* ============================================================= 10. EXPORT */

/** Stream the bookings list as a spreadsheet. */
function bk_export_bookings_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Reference', 'Created', 'Status', 'Payment status', 'Service', 'Date', 'Start', 'End',
        'Guests', 'Client', 'Email', 'Phone', 'Company', 'Amount due', 'Amount paid', 'Currency',
        'Payment deadline', 'Bank reference', 'Verified by', 'Verified at', 'Ticket', 'Receipt', 'Admin notes',
    ]);

    foreach ($rows as $row) {
        $payment = bk_payment_for((int) $row['id']);
        $ticket  = bk_ticket_for((int) $row['id']);
        $receipt = bk_receipt_for((int) $row['id']);

        fputcsv($out, [
            $row['reference'], $row['created_at'], bk_status_label((string) $row['status']), bk_payment_label($row),
            $row['service_name'], $row['booking_date'], $row['start_time'], $row['end_time'],
            $row['guests'], $row['contact_name'], $row['contact_email'], $row['contact_phone'], $row['company'],
            number_format((float) $row['amount_due'], 2, '.', ''),
            number_format((float) $row['amount_paid'], 2, '.', ''),
            $row['currency'], $row['payment_deadline'],
            $payment['admin_bank_reference'] ?? '', $payment['verified_by_name'] ?? '', $payment['verified_at'] ?? '',
            $ticket['ticket_number'] ?? '', $receipt['receipt_number'] ?? '',
            str_replace(["\r", "\n"], ' ', (string) $row['admin_notes']),
        ]);
    }

    fclose($out);
}

/** Stream the payment records as a spreadsheet. */
function bk_export_payments_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="eft-payments-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Booking reference', 'Client', 'Service', 'Booking date', 'Payment status',
        'Amount due', 'Amount declared', 'Amount received', 'Currency',
        'Client bank reference', 'Bank reference recorded', 'Paid on',
        'Verified by', 'Verified at', 'Decline reason', 'Proofs uploaded',
    ]);

    foreach ($rows as $row) {
        $proofCount = db_one('SELECT COUNT(*) AS c FROM payment_proofs WHERE booking_id = :b', [':b' => (int) $row['booking_id']]);
        fputcsv($out, [
            $row['reference'], $row['contact_name'] ?? '', $row['service_name'] ?? '', $row['booking_date'] ?? '',
            $row['status'],
            number_format((float) $row['amount_due'], 2, '.', ''),
            number_format((float) $row['amount_declared'], 2, '.', ''),
            number_format((float) $row['amount_received'], 2, '.', ''),
            $row['currency'], $row['bank_reference'], $row['admin_bank_reference'], $row['paid_on'],
            $row['verified_by_name'], $row['verified_at'],
            str_replace(["\r", "\n"], ' ', (string) $row['decline_reason']),
            (int) ($proofCount['c'] ?? 0),
        ]);
    }

    fclose($out);
}

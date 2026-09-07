<?php
declare(strict_types=1);

/**
 * EFT booking platform — control-panel actions.
 * ---------------------------------------------------------------------------
 * Everything the booking screens in admin.php POST to, kept apart from the
 * views in eft-admin.php. admin.php has already run csrf_check() and
 * require_admin() before any of this is reached.
 */

require_once __DIR__ . '/eft-admin.php';

/**
 * Deal with every POST the booking screens send.
 * Returns false when the request was not one of ours; otherwise it redirects.
 */
function eft_admin_handle_post(array $staff): bool
{
    $do = (string) ($_POST['do'] ?? '');
    if ($do === '' || !str_starts_with($do, 'eft_')) {
        return false;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $booking = $id > 0 ? bk_booking($id) : null;
    $back = 'admin/?p=eft_bookings&action=view&id=' . $id;
    $staffName = (string) ($staff['name'] ?: $staff['username']);

    switch ($do) {
        /* ---------------------------------------------- approve a payment */
        case 'eft_approve':
            if (!$booking) {
                admin_flash('error', 'That booking no longer exists.');
                redirect('admin/?p=eft_bookings');
            }
            $result = bk_approve_payment($booking, [
                'amount_received' => (string) ($_POST['amount_received'] ?? ''),
                'bank_reference'  => (string) ($_POST['bank_reference'] ?? ''),
                'verified_on'     => (string) ($_POST['verified_on'] ?? ''),
                'notes'           => (string) ($_POST['notes'] ?? ''),
            ], $staff);

            admin_log($result['ok'] ? 'approved EFT payment' : 'could not approve payment', (string) $booking['reference']);
            admin_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect($back);

        /* ---------------------------------------------- decline a payment */
        case 'eft_decline':
            if (!$booking) {
                admin_flash('error', 'That booking no longer exists.');
                redirect('admin/?p=eft_bookings');
            }
            $result = bk_decline_payment($booking, (string) ($_POST['reason'] ?? ''), $staff);
            admin_log($result['ok'] ? 'declined proof of payment' : 'could not decline payment', (string) $booking['reference']);
            admin_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect($back);

        /* ------------------------- build services from the rate list */
        case 'eft_import_rates':
            $result = eft_import_rates();
            admin_log('imported services from rates', $result['created'] . ' created');
            admin_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect('admin/?p=services');

        /* -------------------------------- claim a proof for checking */
        case 'eft_under_review':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            db_run(
                "UPDATE eft_payments SET status = 'under_review', updated_at = :u WHERE booking_id = :b",
                [':u' => date('Y-m-d H:i:s'), ':b' => $id]
            );
            bk_set_status($booking, 'under_review', 'being checked by ' . $staffName);
            admin_log('marked payment under review', (string) $booking['reference']);
            admin_flash('ok', 'Marked as under review.');
            redirect($back);

        /* --------------------------------------- put it back in the queue */
        case 'eft_reopen':
            if ($booking) {
                bk_reopen_payment($booking);
                admin_log('reopened booking for payment', (string) $booking['reference']);
                admin_flash('ok', 'Booking reopened. The client can now upload a new proof of payment.');
            }
            redirect($back);

        /* ------------------------------------------- edit booking details */
        case 'eft_booking_save':
            if (!$booking) {
                admin_flash('error', 'That booking no longer exists.');
                redirect('admin/?p=eft_bookings');
            }

            $status = (string) ($_POST['status'] ?? $booking['status']);
            if (!array_key_exists($status, bk_statuses())) {
                $status = (string) $booking['status'];
            }
            $amount = bk_money_parse((string) ($_POST['amount_due'] ?? ''));

            db_run(
                'UPDATE service_bookings
                    SET contact_name = :name, contact_email = :email, contact_phone = :phone,
                        company = :company, guests = :guests, location = :location,
                        amount_due = :due, admin_notes = :notes, updated_at = :u
                  WHERE id = :id',
                [
                    ':name'     => mb_substr(trim((string) ($_POST['contact_name'] ?? '')), 0, 190),
                    ':email'    => mb_substr(trim((string) ($_POST['contact_email'] ?? '')), 0, 190),
                    ':phone'    => mb_substr(trim((string) ($_POST['contact_phone'] ?? '')), 0, 60),
                    ':company'  => mb_substr(trim((string) ($_POST['company'] ?? '')), 0, 190),
                    ':guests'   => max(1, (int) ($_POST['guests'] ?? 1)),
                    ':location' => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 190),
                    ':due'      => $amount > 0 ? $amount : (float) $booking['amount_due'],
                    ':notes'    => mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 2000),
                    ':u'        => date('Y-m-d H:i:s'),
                    ':id'       => $id,
                ]
            );

            bk_audit($id, 'booking edited', 'details updated in the control panel');

            if ($status !== (string) $booking['status']) {
                bk_set_status($booking, $status, 'set by hand in the control panel');

                if ($status === 'cancelled' || $status === 'refunded') {
                    db_run("UPDATE booking_tickets SET status = 'void' WHERE booking_id = :b", [':b' => $id]);
                }
                if ($status === 'refunded') {
                    // Keep the payment record honest about what happened to the money.
                    db_run(
                        "UPDATE eft_payments SET status = 'refunded', refunded_at = :t, updated_at = :t2 WHERE booking_id = :b",
                        [':t' => date('Y-m-d H:i:s'), ':t2' => date('Y-m-d H:i:s'), ':b' => $id]
                    );
                    bk_audit($id, 'payment refunded', 'recorded by ' . $staffName);
                }
                if ($status === 'completed') {
                    db_run("UPDATE booking_tickets SET status = 'used' WHERE booking_id = :b AND status = 'valid'", [':b' => $id]);
                }
            }

            admin_log('updated booking', (string) $booking['reference']);
            admin_flash('ok', 'Booking ' . $booking['reference'] . ' saved.');
            redirect($back);

        /* -------------------------------------------------- move a booking */
        case 'eft_reschedule':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            $result = bk_reschedule(
                $booking,
                (string) ($_POST['date'] ?? ''),
                (string) ($_POST['time'] ?? ''),
                'moved in the control panel by ' . $staffName
            );
            admin_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect($back);

        /* --------------------------------------------------------- cancel */
        case 'eft_cancel':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            $result = bk_cancel_booking($booking, trim((string) ($_POST['reason'] ?? '')));
            admin_log('cancelled booking', (string) $booking['reference']);
            admin_flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect($back);

        /* ------------------------------- resend emails / rebuild the PDFs */
        case 'eft_resend':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            $what = (string) ($_POST['what'] ?? '');

            if ($what === 'instructions') {
                $sent = bk_resend_instructions($booking);
                bk_audit($id, 'instructions resent', $sent ? 'sent to ' . $booking['contact_email'] : 'send failed');
                admin_flash($sent ? 'ok' : 'error', $sent
                    ? 'Payment instructions sent to ' . $booking['contact_email'] . '.'
                    : 'That email could not be sent. Check Site settings, Email delivery.');
            } elseif ($what === 'confirmation' || $what === 'regenerate') {
                $payment = bk_payment_ensure($booking);
                $rebuild = $what === 'regenerate';
                $ticket  = bk_ticket_issue($booking, $rebuild);
                $receipt = bk_receipt_issue($booking, $payment, $rebuild);

                if ($rebuild) {
                    bk_audit($id, 'documents rebuilt', 'ticket and receipt regenerated');
                    admin_flash('ok', 'The ticket and receipt PDFs have been rebuilt.');
                } else {
                    $sent = bk_notify_payment_approved($booking, $payment, $ticket, $receipt);
                    bk_audit($id, 'confirmation resent', $sent ? 'sent to ' . $booking['contact_email'] : 'send failed');
                    admin_flash($sent ? 'ok' : 'error', $sent
                        ? 'Confirmation, ticket and receipt sent to ' . $booking['contact_email'] . '.'
                        : 'That email could not be sent. Check Site settings, Email delivery.');
                }
            }
            redirect($back);

        /* ---------------------------- attach a proof for the client */
        case 'eft_admin_proof':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            $result = bk_upload_proof(
                $booking,
                (array) ($_FILES['proof'] ?? []),
                [
                    'amount'         => (string) ($_POST['amount'] ?? ''),
                    'bank_reference' => (string) ($_POST['bank_reference'] ?? ''),
                    'client_notes'   => 'Added in the control panel by ' . $staffName,
                ],
                'admin',
                $staffName
            );
            admin_flash($result['ok'] ? 'ok' : 'error', $result['ok']
                ? 'Proof of payment attached and ready to review.'
                : $result['message']);
            redirect($back);

        /* ----------------------- approve or decline a client's request */
        case 'eft_request':
            if (!$booking) {
                redirect('admin/?p=eft_bookings');
            }
            $request = db_one('SELECT * FROM booking_requests WHERE id = :id AND booking_id = :b', [
                ':id' => (int) ($_POST['request_id'] ?? 0),
                ':b'  => $id,
            ]);
            if (!$request || (string) $request['status'] !== 'pending') {
                admin_flash('error', 'That request has already been dealt with.');
                redirect($back);
            }

            $decision = (string) ($_POST['decision'] ?? 'decline') === 'approve' ? 'approved' : 'declined';
            $response = mb_substr(trim((string) ($_POST['response'] ?? '')), 0, 400);

            if ($decision === 'approved') {
                if ((string) $request['type'] === 'cancel') {
                    bk_cancel_booking($booking, $response !== '' ? $response : 'Cancelled at your request.');
                } else {
                    $moved = bk_reschedule(
                        $booking,
                        (string) $request['requested_date'],
                        (string) $request['requested_time'],
                        'client request approved by ' . $staffName
                    );
                    if (!$moved['ok']) {
                        admin_flash('error', $moved['message'] . ' The request has been left open.');
                        redirect($back);
                    }
                }
            }

            db_run(
                'UPDATE booking_requests SET status = :s, admin_response = :r, handled_by = :by, handled_at = :at WHERE id = :id',
                [
                    ':s'  => $decision,
                    ':r'  => $response,
                    ':by' => $staffName,
                    ':at' => date('Y-m-d H:i:s'),
                    ':id' => (int) $request['id'],
                ]
            );

            bk_audit($id, $request['type'] . ' request ' . $decision, $response !== '' ? $response : 'by ' . $staffName);

            if ($decision === 'declined') {
                bk_mail([
                    'to'         => (string) $booking['contact_email'],
                    'subject'    => 'About your request for booking ' . $booking['reference'],
                    'template'   => 'request_declined',
                    'booking_id' => $id,
                    'client_id'  => (int) $booking['client_id'],
                    'html'       => bk_email_html(
                        'We could not action that request',
                        'We have looked at your request for booking ' . $booking['reference'] . ' and cannot action it.',
                        bk_booking_facts($booking),
                        [['text' => 'View my booking', 'url' => bk_url('booking/account.php') . '?p=booking&id=' . $id]],
                        $response !== '' ? $response : 'Please contact us and we will find a way forward together.'
                    ),
                ]);
            }

            admin_flash('ok', 'Request ' . $decision . '.');
            redirect($back);

        /* -------------------------------------- a booking made by staff */
        case 'eft_new_booking':
            $email = strtolower(trim((string) ($_POST['contact_email'] ?? '')));
            $client = $email !== '' ? db_one('SELECT * FROM clients WHERE email = :e', [':e' => $email]) : null;
            $notify = isset($_POST['send_email']) && $_POST['send_email'] === '1';

            $result = bk_create_booking([
                'service_id'    => (int) ($_POST['service_id'] ?? 0),
                'date'          => (string) ($_POST['date'] ?? ''),
                'time'          => (string) ($_POST['time'] ?? ''),
                'guests'        => (int) ($_POST['guests'] ?? 1),
                'contact_name'  => (string) ($_POST['contact_name'] ?? ''),
                'contact_email' => $email,
                'contact_phone' => (string) ($_POST['contact_phone'] ?? ''),
                'company'       => (string) ($_POST['company'] ?? ''),
                'client_notes'  => (string) ($_POST['client_notes'] ?? ''),
            ], $client ?: null, 'admin', $notify);

            if (!$result['ok']) {
                admin_flash('error', $result['message']);
                redirect('admin/?p=eft_bookings&action=new&service=' . (int) ($_POST['service_id'] ?? 0)
                    . '&date=' . rawurlencode((string) ($_POST['date'] ?? '')));
            }

            admin_log('created booking', (string) $result['booking']['reference']);
            admin_flash('ok', 'Booking ' . $result['booking']['reference'] . ' created.');
            redirect('admin/?p=eft_bookings&action=view&id=' . (int) $result['booking']['id']);

        /* ---------------------------------------------- edit a client */
        case 'eft_client_save':
            $clientId = (int) ($_POST['id'] ?? 0);
            $client = db_one('SELECT * FROM clients WHERE id = :id', [':id' => $clientId]);
            if (!$client) {
                admin_flash('error', 'That client account no longer exists.');
                redirect('admin/?p=eft_clients');
            }

            if (isset($_POST['send_reset'])) {
                $token = client_issue_token($clientId, 'reset', 60);
                $sent = bk_mail([
                    'to'        => (string) $client['email'],
                    'subject'   => 'Reset your password',
                    'template'  => 'password_reset_admin',
                    'client_id' => $clientId,
                    'html'      => bk_email_html(
                        'Reset your password',
                        'Our team has sent you a link to set a new password for your account. It works for one hour.',
                        [],
                        [['text' => 'Choose a new password', 'url' => bk_url('auth/reset-password.php') . '?token=' . $token]]
                    ),
                ]);
                admin_log('sent a password reset', (string) $client['email']);
                admin_flash($sent ? 'ok' : 'error', $sent
                    ? 'A reset link has been emailed to ' . $client['email'] . '.'
                    : 'That email could not be sent. Check Site settings, Email delivery.');
                redirect('admin/?p=eft_clients&action=view&id=' . $clientId);
            }

            $status = (string) ($_POST['status'] ?? 'active') === 'suspended' ? 'suspended' : 'active';
            $verified = isset($_POST['mark_verified']) && $_POST['mark_verified'] === '1';
            $verifiedAt = '';
            if ($verified) {
                $verifiedAt = (string) $client['email_verified_at'] !== ''
                    ? (string) $client['email_verified_at']
                    : date('Y-m-d H:i:s');
            }

            db_run(
                'UPDATE clients SET full_name = :n, phone = :p, company = :c, city = :city,
                        status = :s, admin_notes = :notes, email_verified_at = :v, updated_at = :u
                  WHERE id = :id',
                [
                    ':n'     => mb_substr(trim((string) ($_POST['full_name'] ?? '')), 0, 190),
                    ':p'     => mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 60),
                    ':c'     => mb_substr(trim((string) ($_POST['company'] ?? '')), 0, 190),
                    ':city'  => mb_substr(trim((string) ($_POST['city'] ?? '')), 0, 120),
                    ':s'     => $status,
                    ':notes' => mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 2000),
                    ':v'     => $verifiedAt,
                    ':u'     => date('Y-m-d H:i:s'),
                    ':id'    => $clientId,
                ]
            );

            admin_log('updated client', (string) $client['email']);
            admin_flash('ok', 'Client account saved.');
            redirect('admin/?p=eft_clients&action=view&id=' . $clientId);
    }

    return false;
}

/**
 * GET actions that stream a file rather than render a page.
 * Returns true when it has handled and finished the request.
 */
function eft_admin_handle_get(string $route, string $action): bool
{
    if ($route === 'eft_bookings' && $action === 'export') {
        [$rows] = eft_booking_query(5000);
        admin_log('exported bookings', count($rows) . ' rows');
        bk_export_bookings_csv($rows);
        return true;
    }

    if ($route === 'eft_payments' && $action === 'export') {
        $status = (string) ($_GET['s'] ?? '');
        $sql = 'SELECT p.*, b.contact_name, b.service_name, b.booking_date
                  FROM eft_payments p JOIN service_bookings b ON b.id = p.booking_id';
        $params = [];
        if (in_array($status, ['awaiting', 'submitted', 'under_review', 'confirmed', 'declined', 'refunded'], true)) {
            $sql .= ' WHERE p.status = :s';
            $params[':s'] = $status;
        }
        $rows = db_all($sql . ' ORDER BY p.id DESC', $params);
        admin_log('exported payments', count($rows) . ' rows');
        bk_export_payments_csv($rows);
        return true;
    }

    return false;
}

/* ================================================ 14. IMPORT THE RATE LIST */

/**
 * Turn the site's existing rate table (the stalls list, straight off the
 * official registration form) into bookable services.
 *
 * Nothing is invented: names, details and prices come from rows already in the
 * database. What the import *does* decide, and what staff must review
 * afterwards, is how many of each can be sold — the capacity.
 *
 * The event runs for a fixed few days at one venue, so each service gets a
 * single bookable slot on the opening day covering the whole fair, and the
 * calendar is closed either side of the event.
 *
 * Existing services are left alone; a rate that has already been imported is
 * skipped, so the button is safe to press twice.
 *
 * @return array{ok: bool, message: string, created: int, skipped: int}
 */
function eft_import_rates(): array
{
    $rates = db_all('SELECT * FROM stalls WHERE is_active = 1 AND bookable = 1 ORDER BY position, id');
    if (!$rates) {
        return ['ok' => false, 'message' => 'There are no bookable rates to import.', 'created' => 0, 'skipped' => 0];
    }

    $start = event_start();
    $end   = event_end();
    $weekday = (int) $start->format('w');
    $openAt  = $start->format('H:i');
    $closeAt = $end->format('H:i');

    // One slot spanning the working day, so each service offers a single
    // booking on the opening day rather than an hourly diary.
    $minutes = max(60, bk_minutes($closeAt) - bk_minutes($openAt));

    $location = trim(setting('venue_name') . ', ' . setting('venue_city'), ', ');
    $span = $start->format('j F') . ' to ' . $end->format('j F Y');
    $now = date('Y-m-d H:i:s');

    $created = 0;
    $skipped = 0;

    foreach ($rates as $index => $rate) {
        $name = trim((string) $rate['category']);
        if ($name === '') {
            continue;
        }
        if (db_one('SELECT id FROM services WHERE name = :n', [':n' => $name])) {
            $skipped++;
            continue;
        }

        $isTicket = (string) ($rate['kind'] ?? 'other') === 'ticket';
        $price   = bk_money_parse((string) $rate['rate']);
        $details = trim((string) $rate['details']);
        $note    = trim((string) $rate['note']);

        // Tickets are sold per person; a stall or a tent is one booking.
        $priceMode  = $isTicket ? 'person' : 'booking';
        $maxGuests  = $isTicket ? 20 : 1;
        $capacity   = $isTicket ? 100 : 10;        // placeholder — staff must review
        $capacityBy = $isTicket ? 'guest' : 'booking';

        $summary = $details !== '' ? $details : $name;
        $description = trim(
            ($details !== '' ? $details . "\n\n" : '')
            . 'Booked for the full event, ' . $span . ', at ' . $location . '.'
            . ($note !== '' ? "\n\n" . $note : '')
        );

        db_run(
            'INSERT INTO services
                (name, slug, summary, description, location, image, price, price_mode,
                 duration_minutes, buffer_minutes, slot_capacity, capacity_unit,
                 min_guests, max_guests, collect_guest_names,
                 lead_time_hours, max_advance_days, payment_deadline_hours,
                 extra_field_label, extra_field_help, extra_field_required,
                 instructions, position, is_active, created_at, updated_at)
             VALUES
                (:name, :slug, :summary, :description, :location, :image, :price, :mode,
                 :duration, 0, :capacity, :capacityBy,
                 1, :maxGuests, :names,
                 24, :advance, :deadline,
                 :extraLabel, :extraHelp, :extraRequired,
                 :instructions, :position, 1, :now, :now2)',
            [
                ':name'          => mb_substr($name, 0, 190),
                ':slug'          => bk_service_slug($name),
                ':summary'       => mb_substr($summary, 0, 250),
                ':description'   => $description,
                ':location'      => mb_substr($location, 0, 190),
                ':image'         => '',
                ':price'         => $price,
                ':mode'          => $priceMode,
                ':duration'      => $minutes,
                ':capacity'      => $capacity,
                ':capacityBy'    => $capacityBy,
                ':maxGuests'     => $maxGuests,
                ':names'         => $isTicket ? 1 : 0,
                ':advance'       => max(30, (int) ceil(($start->getTimestamp() - time()) / 86400) + 7),
                ':deadline'      => bk_int('bk_payment_deadline_hours', 48),
                ':extraLabel'    => $isTicket ? '' : 'What will you be exhibiting or selling?',
                ':extraHelp'     => $isTicket ? '' : 'This helps us place you next to the right neighbours.',
                ':extraRequired' => $isTicket ? 0 : 1,
                ':instructions'  => $isTicket
                    ? 'Please arrive 15 minutes before the first session. This ticket covers the full programme, ' . $span . '.'
                    : 'Your space is available for the full event, ' . $span . '. Set-up details will be emailed to you before the opening day.',
                ':position'      => $index,
                ':now'           => $now,
                ':now2'          => $now,
            ]
        );

        $serviceId = (int) db()->lastInsertId();
        db_run(
            'INSERT INTO service_hours (service_id, weekday, start_time, end_time, slot_interval, capacity, position, is_active)
             VALUES (:s, :w, :from, :to, 0, 0, 0, 1)',
            [':s' => $serviceId, ':w' => $weekday, ':from' => $openAt, ':to' => $closeAt]
        );

        $created++;
    }

    if ($created > 0) {
        eft_close_calendar_outside_event();
        setting_save('bk_capacity_reviewed', '0');
        settings(true);
    }

    $message = $created > 0
        ? $created . ' service(s) created from your rate list'
            . ($skipped > 0 ? ', ' . $skipped . ' already existed' : '')
            . '. Now check the capacity on each one — how many you can actually sell — under Bookings, Services.'
        : 'Every bookable rate already has a service. Nothing was changed.';

    return ['ok' => true, 'message' => $message, 'created' => $created, 'skipped' => $skipped];
}

/**
 * Close the diary before and after the event, so the only bookable dates are
 * the days of the fair itself. Existing blocks are left alone.
 */
function eft_close_calendar_outside_event(): void
{
    $start = event_start()->format('Y-m-d');
    $end   = event_end()->format('Y-m-d');
    $now   = date('Y-m-d H:i:s');

    $ranges = [
        ['2000-01-01', date('Y-m-d', strtotime($start . ' -1 day')),
            'Closed before the event — delete this row to take bookings all year'],
        [date('Y-m-d', strtotime($end . ' +1 day')), '2099-12-31',
            'Closed after the event — delete this row to take bookings all year'],
    ];

    foreach ($ranges as [$from, $to, $reason]) {
        $exists = db_one(
            'SELECT id FROM blocked_dates WHERE service_id = 0 AND start_date = :f AND end_date = :t',
            [':f' => $from, ':t' => $to]
        );
        if ($exists) {
            continue;
        }
        db_run(
            'INSERT INTO blocked_dates (service_id, start_date, end_date, start_time, end_time, reason, created_at)
             VALUES (0, :f, :t, :st, :et, :r, :c)',
            [':f' => $from, ':t' => $to, ':st' => '', ':et' => '', ':r' => $reason, ':c' => $now]
        );
    }
}

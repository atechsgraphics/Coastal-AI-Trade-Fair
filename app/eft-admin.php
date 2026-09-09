<?php
declare(strict_types=1);

/**
 * EFT booking platform — control-panel screens.
 * ---------------------------------------------------------------------------
 * Everything staff need: the booking queue, the payment review screen with its
 * approve/decline actions, the diary, client accounts, reports and the email
 * log. Services, opening hours and blocked dates are handled by the panel's
 * existing resource engine — see eft_admin_resources().
 *
 * Every view here assumes require_admin() has already run in admin.php.
 */

require_once __DIR__ . '/eft.php';

/* ========================================================= 1. RESOURCES */

/**
 * The three editable lists this platform adds. They plug straight into
 * admin_resources(), so the list, form, ordering and delete controls all come
 * from the panel's existing engine.
 */
function eft_admin_resources(): array
{
    return [
        'services' => [
            'label'    => 'Services',
            'singular' => 'service',
            'group'    => 'Bookings',
            'icon'     => '◈',
            'intro'    => 'What people can book online, what it costs and how long it takes. A service only appears on the booking page once it is visible and has opening hours.',
            'list'     => ['name' => 'Service', 'price' => 'Price', 'duration_minutes' => 'Minutes', 'slot_capacity' => 'Per slot'],
            'after_save'   => 'eft_after_service_save',
            'extra_action' => 'eft_import_button',
            'fields'   => [
                'name'                   => ['label' => 'Service name', 'type' => 'text', 'required' => true, 'placeholder' => 'AI Masterclass — full day'],
                'summary'                => ['label' => 'One-line summary', 'type' => 'text', 'help' => 'Shown on the service card.'],
                'description'            => ['label' => 'Full description', 'type' => 'textarea', 'rows' => 5],
                'image'                  => ['label' => 'Picture', 'type' => 'image'],
                'location'               => ['label' => 'Location', 'type' => 'text', 'help' => 'Printed on the ticket. Leave blank to use the venue in Site settings.'],
                'price'                  => ['label' => 'Price', 'type' => 'decimal', 'required' => true, 'help' => 'Numbers only, e.g. 750 or 1500.50.'],
                'price_mode'             => ['label' => 'Price applies', 'type' => 'select', 'required' => true, 'options' => 'eft_price_modes'],
                'duration_minutes'       => ['label' => 'Duration in minutes', 'type' => 'number', 'required' => true],
                'buffer_minutes'         => ['label' => 'Gap after each booking (minutes)', 'type' => 'number', 'help' => 'Turnaround time. Added to the duration when working out the next start time.'],
                'slot_capacity'          => ['label' => 'Capacity per time slot', 'type' => 'number', 'required' => true, 'help' => 'How many can be taken at the same start time. Use 1 for a one-at-a-time service.'],
                'capacity_unit'          => ['label' => 'Capacity counts', 'type' => 'select', 'required' => true, 'options' => 'eft_capacity_units'],
                'min_guests'             => ['label' => 'Smallest party', 'type' => 'number'],
                'max_guests'             => ['label' => 'Largest party', 'type' => 'number', 'help' => 'Set to 1 to hide the "number of people" question.'],
                'collect_guest_names'    => ['label' => 'Ask for each guest by name', 'type' => 'bool'],
                'lead_time_hours'        => ['label' => 'Least notice required (hours)', 'type' => 'number'],
                'max_advance_days'       => ['label' => 'How far ahead people may book (days)', 'type' => 'number'],
                'payment_deadline_hours' => ['label' => 'EFT payment deadline (hours)', 'type' => 'number', 'help' => 'Leave 0 to use the site-wide deadline in Site settings → Bookings.'],
                'extra_field_label'      => ['label' => 'Extra question on the form', 'type' => 'text', 'help' => 'Leave blank for none. Example: "What will you be exhibiting?"'],
                'extra_field_help'       => ['label' => 'Hint under the extra question', 'type' => 'text'],
                'extra_field_required'   => ['label' => 'The extra question must be answered', 'type' => 'bool'],
                'instructions'           => ['label' => 'Instructions printed on the ticket', 'type' => 'textarea', 'rows' => 3],
                'position'               => ['label' => 'Order', 'type' => 'number'],
                'is_active'              => ['label' => 'Open for booking', 'type' => 'bool'],
            ],
        ],

        'service_hours' => [
            'label'    => 'Opening hours',
            'singular' => 'opening time',
            'group'    => 'Bookings',
            'icon'     => '◷',
            'intro'    => 'When bookings can start, day by day. A rule set to "Every service" applies to any service that has no rules of its own.',
            'list'     => ['service_id' => 'Service', 'weekday' => 'Day', 'start_time' => 'From', 'end_time' => 'Until', 'capacity' => 'Capacity'],
            'list_format' => ['service_id' => 'eft_service_name', 'weekday' => 'eft_weekday_name', 'capacity' => 'eft_capacity_label'],
            'filter'   => ['service_id' => 'Service'],
            'filter_options' => 'eft_service_choices',
            'fields'   => [
                'service_id'    => ['label' => 'Service', 'type' => 'select', 'required' => true, 'options' => 'eft_service_choices'],
                'weekday'       => ['label' => 'Day of the week', 'type' => 'select', 'required' => true, 'options' => 'eft_weekday_choices'],
                'start_time'    => ['label' => 'First booking starts at', 'type' => 'time', 'required' => true],
                'end_time'      => ['label' => 'Last booking must end by', 'type' => 'time', 'required' => true],
                'slot_interval' => ['label' => 'Minutes between start times', 'type' => 'number', 'help' => 'Leave 0 to use the service duration plus its gap.'],
                'capacity'      => ['label' => 'Capacity for this day', 'type' => 'number', 'help' => 'Leave 0 to use the capacity set on the service.'],
                'position'      => ['label' => 'Order', 'type' => 'number'],
                'is_active'     => ['label' => 'In use', 'type' => 'bool'],
            ],
        ],

        'blocked_dates' => [
            'label'    => 'Blocked dates',
            'singular' => 'blocked date',
            'group'    => 'Bookings',
            'icon'     => '⊘',
            'intro'    => 'Public holidays, closures and days already taken. Leave the times blank to close the whole day.',
            'list'     => ['start_date' => 'From', 'end_date' => 'Until', 'service_id' => 'Service', 'reason' => 'Reason'],
            'list_format' => ['service_id' => 'eft_service_name'],
            'fields'   => [
                'service_id' => ['label' => 'Service', 'type' => 'select', 'required' => true, 'options' => 'eft_service_choices'],
                'start_date' => ['label' => 'First day closed', 'type' => 'date', 'required' => true],
                'end_date'   => ['label' => 'Last day closed', 'type' => 'date', 'required' => true, 'help' => 'Use the same date for a single day.'],
                'start_time' => ['label' => 'From (optional)', 'type' => 'time', 'help' => 'Leave both times blank to close the whole day.'],
                'end_time'   => ['label' => 'Until (optional)', 'type' => 'time'],
                'reason'     => ['label' => 'Reason', 'type' => 'text', 'help' => 'Shown to clients when they land on this date.'],
            ],
        ],
    ];
}

/** Give a new service its slug and timestamps. */
function eft_after_service_save(int $id, array $values): void
{
    $service = db_one('SELECT * FROM services WHERE id = :id', [':id' => $id]);
    if (!$service) {
        return;
    }

    $now = date('Y-m-d H:i:s');
    $slug = trim((string) $service['slug']);
    if ($slug === '') {
        $slug = bk_service_slug((string) $service['name'], $id);
    }

    db_run(
        'UPDATE services SET slug = :s, updated_at = :u, created_at = CASE created_at WHEN :empty THEN :c ELSE created_at END WHERE id = :id',
        [':s' => $slug, ':u' => $now, ':empty' => '', ':c' => $now, ':id' => $id]
    );
}

/* Option lists used by the resource definitions above. */

function eft_price_modes(): array
{
    return ['booking' => 'Once per booking', 'person' => 'For each person'];
}

function eft_capacity_units(): array
{
    return ['booking' => 'Bookings in the slot', 'guest' => 'People in the slot'];
}

function eft_weekday_choices(): array
{
    $out = [];
    foreach (bk_weekdays() as $number => $name) {
        $out[(string) $number] = $name;
    }
    return $out;
}

/** Services, plus the "every service" catch-all. */
function eft_service_choices(): array
{
    $out = ['0' => 'Every service'];
    foreach (bk_services(false) as $service) {
        $out[(string) $service['id']] = (string) $service['name'];
    }
    return $out;
}

function eft_service_name($value, array $row = []): string
{
    $id = (int) $value;
    if ($id === 0) {
        return 'Every service';
    }
    $service = bk_service($id);
    return $service ? (string) $service['name'] : 'Service #' . $id;
}

function eft_weekday_name($value, array $row = []): string
{
    return bk_weekdays()[(int) $value] ?? (string) $value;
}

function eft_capacity_label($value, array $row = []): string
{
    return (int) $value > 0 ? (string) (int) $value : 'From the service';
}

/* ==================================================== 2. SETTINGS GROUPS */

/** The two settings tabs this platform adds. */
function eft_admin_setting_groups(): array
{
    return [
        'bookings' => [
            'label'  => 'Bookings & EFT',
            'intro'  => 'How the online booking diary behaves, what clients are told about paying, and the rules for cancelling or moving a booking. The banking details themselves live on the Registration & payment tab.',
            'fields' => [
                'bk_enabled'                => ['label' => 'Online booking is switched on', 'type' => 'bool', 'help' => 'Turn off to close the booking pages without losing any data.'],
                'bk_nav_enabled'            => ['label' => 'Show the account link in the menu', 'type' => 'bool'],
                'bk_require_verified_email' => ['label' => 'Clients must confirm their email before booking', 'type' => 'bool'],
                'bk_currency'               => ['label' => 'Currency symbol', 'type' => 'text', 'placeholder' => 'N$'],
                'bk_reference_prefix'       => ['label' => 'Booking reference prefix', 'type' => 'text', 'placeholder' => 'ATF', 'help' => 'Letters and numbers only. References look like ATF-4K7P2M.'],
                'nav_cta_text'              => ['label' => 'Menu button text', 'type' => 'text', 'placeholder' => 'Book online', 'help' => 'The highlighted button at the end of the menu. Leave blank to hide it.'],
                'nav_cta_link'              => ['label' => 'Menu button link', 'type' => 'text', 'placeholder' => 'booking/', 'help' => 'booking.php for the online booking system, book.php for the exhibitor registration form.'],
                'bk_site_url'               => ['label' => 'Website address', 'type' => 'url', 'placeholder' => 'https://www.example.na', 'help' => 'Used for the links in emails and the QR code on every ticket. Leave blank to work it out from the current address — but set it before going live so tickets are always right.'],
                'bk_payment_deadline_hours' => ['label' => 'Hours a booking is held for payment', 'type' => 'number'],
                'bk_bank_swift'             => ['label' => 'SWIFT / BIC code', 'type' => 'text', 'help' => 'Optional, for payments from outside the country.'],
                'bk_payment_instructions'   => ['label' => 'Payment instructions', 'type' => 'list', 'rows' => 5, 'help' => 'One instruction per line. Shown on the payment page and in the emails.'],
                'bk_payment_note'           => ['label' => 'Note under the upload form', 'type' => 'textarea', 'rows' => 3],
                'bk_terms'                  => ['label' => 'Booking terms', 'type' => 'list', 'rows' => 5, 'help' => 'One term per line. Clients accept these on the review screen.'],
                'bk_ticket_instructions'    => ['label' => 'Instructions printed on every ticket', 'type' => 'textarea', 'rows' => 3],
                'bk_ticket_auto_issue'      => ['label' => 'Make the ticket when a payment is approved', 'type' => 'bool', 'help' => 'On: approving a payment produces the ticket and the receipt straight away. Off: the booking is still confirmed, but you issue the ticket yourself from the booking screen when you are ready.'],
                'bk_ticket_auto_email'      => ['label' => 'Email the ticket to the client automatically', 'type' => 'bool', 'help' => 'On: the ticket and receipt are attached to the confirmation email. Off: the client is told their payment is confirmed, and you send the ticket when it suits you.'],
                'ticket_banner_image'       => ['label' => 'Picture across the top of the ticket', 'type' => 'image', 'help' => 'The photograph printed behind the event name on every ticket. It is darkened automatically so the lettering stays readable.'],
                'bk_upload_max_mb'          => ['label' => 'Largest proof-of-payment file (MB)', 'type' => 'number'],
                'bk_upload_types'           => ['label' => 'Accepted file types', 'type' => 'text', 'placeholder' => 'pdf,jpg,jpeg,png', 'help' => 'Only pdf, jpg, jpeg and png can be accepted.'],
                'bk_capacity_reviewed'      => ['label' => 'Capacity reviewed', 'type' => 'bool', 'help' => 'Tick once you have set the real capacity on every service. The dashboard keeps reminding you until you do.'],
                'bk_storage_verified'       => ['label' => 'Private storage confirmed', 'type' => 'bool', 'help' => 'Tick once you have opened the data folder in a browser and seen an error page rather than a file. The dashboard keeps reminding you until you do.'],
                'bk_cancel_enabled'         => ['label' => 'Clients may request a cancellation', 'type' => 'bool'],
                'bk_cancel_min_hours'       => ['label' => 'Cancellation notice required (hours)', 'type' => 'number'],
                'bk_cancel_policy'          => ['label' => 'Cancellation policy shown to clients', 'type' => 'textarea', 'rows' => 3],
                'bk_reschedule_enabled'     => ['label' => 'Clients may request a new date', 'type' => 'bool'],
                'bk_reschedule_min_hours'   => ['label' => 'Notice required to move a booking (hours)', 'type' => 'number'],
                'bk_reschedule_max'         => ['label' => 'How many times one booking may move', 'type' => 'number'],
                'bk_intro_label'            => ['label' => 'Booking page — small label', 'type' => 'text'],
                'bk_intro_title'            => ['label' => 'Booking page — headline', 'type' => 'textarea', 'rows' => 2],
                'bk_intro_text'             => ['label' => 'Booking page — paragraph', 'type' => 'textarea', 'rows' => 3],
            ],
        ],

        'email' => [
            'label'  => 'Email delivery',
            'intro'  => 'Who booking emails come from and how they are sent. PHP mail() is fine on many hosts but is often silently dropped; SMTP is far more reliable. The SMTP password can be kept out of the database entirely — see the setup notes.',
            'fields' => [
                'bk_from_name'        => ['label' => 'Sender name', 'type' => 'text', 'help' => 'Leave blank to use the site name.'],
                'bk_from_email'       => ['label' => 'Sender address', 'type' => 'email', 'help' => 'Leave blank to use the main email address. Must be an address this server is allowed to send from.'],
                'bk_reply_to'         => ['label' => 'Reply-to address', 'type' => 'email'],
                'bk_admin_emails'     => ['label' => 'Send booking alerts to', 'type' => 'text', 'help' => 'One or more addresses, separated by commas. Leave blank to use the enquiry addresses.'],
                'bk_email_footer'     => ['label' => 'Email sign-off', 'type' => 'textarea', 'rows' => 3, 'help' => 'Leave blank to use the organisation name and contact details.'],
                'bk_mail_transport'   => ['label' => 'How email is sent', 'type' => 'select', 'required' => true, 'options' => 'eft_mail_transports'],
                'bk_smtp_host'        => ['label' => 'SMTP host', 'type' => 'text', 'placeholder' => 'smtp.example.com'],
                'bk_smtp_port'        => ['label' => 'SMTP port', 'type' => 'number', 'help' => '587 for STARTTLS, 465 for SSL.'],
                'bk_smtp_secure'      => ['label' => 'SMTP security', 'type' => 'select', 'required' => true, 'options' => 'eft_smtp_security'],
                'bk_smtp_user'        => ['label' => 'SMTP username', 'type' => 'text'],
                'bk_smtp_pass'        => ['label' => 'SMTP password', 'type' => 'password', 'help' => 'Better still: put BK_SMTP_PASS in data/env.php, which is never served to the web and always wins over this field.'],
                'bk_smtp_verify_peer' => ['label' => 'Verify the mail server certificate', 'type' => 'bool', 'help' => 'Leave on unless your host uses a self-signed certificate.'],
            ],
        ],
    ];
}

function eft_mail_transports(): array
{
    return ['mail' => 'PHP mail() — simple, often unreliable', 'smtp' => 'SMTP — recommended'];
}

function eft_smtp_security(): array
{
    return ['tls' => 'STARTTLS (port 587)', 'ssl' => 'SSL/TLS (port 465)', 'none' => 'None (not recommended)'];
}

/* ================================================== 3. SHARED VIEW PIECES */

/** The status chip used through the panel. */
function eft_pill(string $status): string
{
    return '<span class="a-pill a-pill-bk-' . e(bk_status_tone($status)) . '">' . e(bk_status_label($status)) . '</span>';
}

/** How many bookings are waiting for somebody to look at them. */
function eft_pending_count(): int
{
    $row = db_one("SELECT COUNT(*) AS c FROM service_bookings WHERE status IN ('proof_submitted','under_review')");
    return (int) ($row['c'] ?? 0);
}

function eft_open_requests_count(): int
{
    $row = db_one("SELECT COUNT(*) AS c FROM booking_requests WHERE status = 'pending'");
    return (int) ($row['c'] ?? 0);
}

/* ================================================== 4. THE BOOKING QUEUE */

function eft_admin_bookings_view(): void
{
    [$rows, $filters, $total] = eft_booking_query();

    $summary = db_one(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('proof_submitted','under_review') THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN status = 'awaiting_eft' THEN 1 ELSE 0 END) AS awaiting,
            SUM(CASE WHEN status IN ('confirmed','payment_confirmed','completed') THEN 1 ELSE 0 END) AS confirmed,
            SUM(CASE WHEN status IN ('confirmed','payment_confirmed','completed') THEN amount_paid ELSE 0 END) AS received
         FROM service_bookings"
    ) ?: [];
    ?>
<div class="a-head">
  <div>
    <h1>Bookings</h1>
    <p>Every online booking, its EFT payment and the proof behind it.</p>
  </div>
  <div class="a-head-actions">
    <a class="a-btn a-btn-primary" href="?p=eft_bookings&amp;action=new">+ New booking</a>
    <a class="a-btn a-btn-ghost" href="?p=eft_bookings&amp;action=export<?= e(eft_filter_query($filters)) ?>">Download CSV ↓</a>
  </div>
</div>

<div class="a-stats">
  <div class="a-stat<?= (int) ($summary['review'] ?? 0) > 0 ? ' a-stat-primary' : '' ?>">
    <span class="a-stat-num"><?= (int) ($summary['review'] ?? 0) ?></span>
    <span class="a-stat-label">Waiting for review</span>
    <small>Proof uploaded, not yet verified</small>
  </div>
  <div class="a-stat">
    <span class="a-stat-num"><?= (int) ($summary['awaiting'] ?? 0) ?></span>
    <span class="a-stat-label">Awaiting EFT payment</span>
  </div>
  <div class="a-stat">
    <span class="a-stat-num"><?= (int) ($summary['confirmed'] ?? 0) ?></span>
    <span class="a-stat-label">Confirmed bookings</span>
  </div>
  <div class="a-stat">
    <span class="a-stat-num" style="font-size:1.3rem"><?= e(bk_money((float) ($summary['received'] ?? 0))) ?></span>
    <span class="a-stat-label">Payments received</span>
  </div>
</div>

<form class="a-filter a-filter-wide" method="get">
  <input type="hidden" name="p" value="eft_bookings">
  <label for="fq">Search</label>
  <input type="search" id="fq" name="q" value="<?= e($filters['q']) ?>" placeholder="Reference, name, email or company">

  <label for="fs">Status</label>
  <select id="fs" name="s">
    <option value="">All statuses</option>
    <?php foreach (bk_statuses() as $key => $label): ?>
      <option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="fsv">Service</label>
  <select id="fsv" name="service">
    <option value="">All services</option>
    <?php foreach (bk_services(false) as $service): ?>
      <option value="<?= (int) $service['id'] ?>"<?= $filters['service'] === (int) $service['id'] ? ' selected' : '' ?>><?= e((string) $service['name']) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="ffrom">From</label>
  <input type="date" id="ffrom" name="from" value="<?= e($filters['from']) ?>">
  <label for="fto">To</label>
  <input type="date" id="fto" name="to" value="<?= e($filters['to']) ?>">

  <button class="a-btn a-btn-small a-btn-primary" type="submit">Filter</button>
  <a class="a-btn a-btn-small" href="?p=eft_bookings">Clear</a>
</form>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">No bookings match that. <a href="?p=eft_bookings">Show them all</a>.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead>
        <tr><th>Reference</th><th>Client</th><th>Service</th><th>When</th><th>Amount</th><th>Status</th><th class="a-right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr<?= in_array((string) $row['status'], ['cancelled', 'declined'], true) ? ' class="a-off"' : '' ?>>
          <td><strong><?= e((string) $row['reference']) ?></strong>
              <small><?= e(date('d M Y', strtotime((string) $row['created_at']) ?: time())) ?></small></td>
          <td><?= e((string) $row['contact_name']) ?><small><?= e((string) $row['contact_email']) ?></small></td>
          <td><?= e((string) $row['service_name']) ?><?php if ((int) $row['guests'] > 1): ?><small><?= (int) $row['guests'] ?> guests</small><?php endif; ?></td>
          <td><?= e(date('d M Y', strtotime((string) $row['booking_date']) ?: time())) ?><small><?= e((string) $row['start_time']) ?></small></td>
          <td><strong><?= e(bk_money((float) $row['amount_due'], (string) $row['currency'])) ?></strong>
              <?php if ((float) $row['amount_paid'] > 0): ?><small>paid <?= e(bk_money((float) $row['amount_paid'], (string) $row['currency'])) ?></small><?php endif; ?></td>
          <td><?= eft_pill((string) $row['status']) ?></td>
          <td class="a-right a-actions">
            <a class="a-btn a-btn-small<?= in_array((string) $row['status'], ['proof_submitted', 'under_review'], true) ? ' a-btn-primary' : '' ?>"
               href="?p=eft_bookings&amp;action=view&amp;id=<?= (int) $row['id'] ?>">
              <?= in_array((string) $row['status'], ['proof_submitted', 'under_review'], true) ? 'Review' : 'Open' ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($total > count($rows)): ?>
      <p class="a-empty">Showing the first <?= count($rows) ?> of <?= $total ?> matching bookings. Narrow the filters, or download the CSV for everything.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>
    <?php
}

/**
 * Build the booking list query from the filter controls.
 *
 * @return array{0: array, 1: array, 2: int}
 */
function eft_booking_query(int $limit = 300): array
{
    $filters = [
        'q'       => trim((string) ($_GET['q'] ?? '')),
        'status'  => (string) ($_GET['s'] ?? ''),
        'service' => (int) ($_GET['service'] ?? 0),
        'from'    => (string) ($_GET['from'] ?? ''),
        'to'      => (string) ($_GET['to'] ?? ''),
        'client'  => (int) ($_GET['client'] ?? 0),
    ];
    if (!array_key_exists($filters['status'], bk_statuses())) {
        $filters['status'] = '';
    }
    foreach (['from', 'to'] as $key) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
            $filters[$key] = '';
        }
    }

    $where = [];
    $params = [];

    if ($filters['q'] !== '') {
        $where[] = '(reference LIKE :q OR contact_name LIKE :q OR contact_email LIKE :q OR company LIKE :q OR contact_phone LIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['status'] !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['service'] > 0) {
        $where[] = 'service_id = :service';
        $params[':service'] = $filters['service'];
    }
    if ($filters['client'] > 0) {
        $where[] = 'client_id = :client';
        $params[':client'] = $filters['client'];
    }
    if ($filters['from'] !== '') {
        $where[] = 'booking_date >= :from';
        $params[':from'] = $filters['from'];
    }
    if ($filters['to'] !== '') {
        $where[] = 'booking_date <= :to';
        $params[':to'] = $filters['to'];
    }

    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int) (db_one('SELECT COUNT(*) AS c FROM service_bookings' . $clause, $params)['c'] ?? 0);
    $rows = db_all(
        'SELECT * FROM service_bookings' . $clause . ' ORDER BY booking_date DESC, start_time DESC, id DESC LIMIT ' . max(1, $limit),
        $params
    );

    return [$rows, $filters, $total];
}

/** Rebuild the filter part of a link. */
function eft_filter_query(array $filters): string
{
    $query = array_filter([
        'q'       => $filters['q'],
        's'       => $filters['status'],
        'service' => $filters['service'] > 0 ? (string) $filters['service'] : '',
        'from'    => $filters['from'],
        'to'      => $filters['to'],
        'client'  => $filters['client'] > 0 ? (string) $filters['client'] : '',
    ], static fn ($v): bool => $v !== '' && $v !== null);

    return $query ? '&amp;' . http_build_query($query, '', '&amp;') : '';
}

/* ============================================== 5. ONE BOOKING IN DETAIL */

function eft_admin_booking_view(int $id): void
{
    $booking = bk_booking($id);
    if (!$booking) {
        echo '<div class="a-card"><p class="a-empty">That booking no longer exists.</p></div>';
        return;
    }

    $payment  = bk_payment_ensure($booking);
    $proofs   = bk_proofs($id);
    $guests   = bk_guests($id);
    $ticket   = bk_ticket_for($id);
    $receipt  = bk_receipt_for($id);
    $requests = bk_requests($id);
    $pending  = bk_pending_request($id);
    $audit    = bk_audit_trail($id, 60);
    $emails   = db_all('SELECT * FROM email_log WHERE booking_id = :b ORDER BY id DESC LIMIT 30', [':b' => $id]);
    $service  = bk_service((int) $booking['service_id']);
    $client   = (int) $booking['client_id'] > 0
        ? db_one('SELECT * FROM clients WHERE id = :id', [':id' => (int) $booking['client_id']])
        : null;

    $canReview = in_array((string) $booking['status'], ['proof_submitted', 'under_review', 'awaiting_eft', 'declined'], true);
    $latestProof = $proofs[0] ?? null;
    ?>
<div class="a-head">
  <div>
    <h1>Booking <?= e((string) $booking['reference']) ?> <?= eft_pill((string) $booking['status']) ?></h1>
    <p>
      <?= e((string) $booking['service_name']) ?> ·
      <?= e(bk_date_long((string) $booking['booking_date'])) ?> at <?= e((string) $booking['start_time']) ?> ·
      received <?= e(date('d M Y, H:i', strtotime((string) $booking['created_at']) ?: time())) ?>
    </p>
  </div>
  <div class="a-head-actions">
    <?php if ($ticket && (string) $ticket['file_name'] !== ''): ?>
      <a class="a-btn a-btn-ghost" href="<?= e(url('booking/download.php')) ?>?kind=ticket&amp;booking=<?= $id ?>&amp;view=1" target="_blank" rel="noopener">Ticket ↗</a>
    <?php endif; ?>
    <?php if ($receipt && (string) $receipt['file_name'] !== ''): ?>
      <a class="a-btn a-btn-ghost" href="<?= e(url('booking/download.php')) ?>?kind=receipt&amp;booking=<?= $id ?>&amp;view=1" target="_blank" rel="noopener">Receipt ↗</a>
    <?php endif; ?>
    <a class="a-btn a-btn-ghost" href="?p=eft_bookings">← All bookings</a>
  </div>
</div>

<?php if ($pending): ?>
  <div class="a-card a-card-flag">
    <h2><?= e($pending['type'] === 'cancel' ? 'The client has asked to cancel' : 'The client has asked for a new date') ?></h2>
    <p>
      Requested <?= e(date('d M Y, H:i', strtotime((string) $pending['created_at']) ?: time())) ?>.
      <?php if ($pending['type'] === 'reschedule' && (string) $pending['requested_date'] !== ''): ?>
        New date wanted: <strong><?= e(bk_date_long((string) $pending['requested_date'])) ?> at <?= e((string) $pending['requested_time']) ?></strong>.
      <?php endif; ?>
    </p>
    <?php if (trim((string) $pending['reason']) !== ''): ?>
      <p class="a-quote"><?= nl2br(e((string) $pending['reason'])) ?></p>
    <?php endif; ?>

    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>" class="a-inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_request">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="request_id" value="<?= (int) $pending['id'] ?>">
      <div class="a-field">
        <label for="rq_response">Message back to the client</label>
        <input type="text" id="rq_response" name="response" maxlength="400" placeholder="Optional, sent with the decision">
      </div>
      <div class="a-form-actions">
        <button class="a-btn a-btn-primary" type="submit" name="decision" value="approve">Approve the request</button>
        <button class="a-btn" type="submit" name="decision" value="decline">Decline the request</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="a-grid-2">

  <!-- ------------------------------------------------ payment review -->
  <section class="a-card">
    <h2>EFT payment</h2>

    <table class="a-table">
      <tbody>
        <tr><th>Amount due</th><td><strong><?= e(bk_money((float) $booking['amount_due'], (string) $booking['currency'])) ?></strong></td></tr>
        <tr><th>Amount recorded as received</th><td><?= (float) $booking['amount_paid'] > 0 ? e(bk_money((float) $booking['amount_paid'], (string) $booking['currency'])) : '—' ?></td></tr>
        <tr><th>Payment reference given to client</th><td><code><?= e((string) $booking['reference']) ?></code></td></tr>
        <tr><th>Payment deadline</th><td><?= e(bk_deadline_text($booking) ?: '—') ?></td></tr>
        <tr><th>Client says they paid</th><td>
          <?= (float) $payment['amount_declared'] > 0 ? e(bk_money((float) $payment['amount_declared'], (string) $booking['currency'])) : '—' ?>
          <?php if ((string) $payment['paid_on'] !== ''): ?> on <?= e(bk_date_long((string) $payment['paid_on'])) ?><?php endif; ?>
        </td></tr>
        <tr><th>Client's bank reference</th><td><?= e((string) $payment['bank_reference'] ?: '—') ?></td></tr>
        <?php if (trim((string) $payment['client_notes']) !== ''): ?>
          <tr><th>Client's note</th><td><?= nl2br(e((string) $payment['client_notes'])) ?></td></tr>
        <?php endif; ?>
        <?php if ((string) $payment['verified_at'] !== ''): ?>
          <tr><th>Verified</th><td><?= e(date('d M Y, H:i', strtotime((string) $payment['verified_at']) ?: time())) ?>
              by <?= e((string) $payment['verified_by_name']) ?></td></tr>
        <?php endif; ?>
        <?php if (trim((string) $payment['decline_reason']) !== ''): ?>
          <tr><th>Declined because</th><td class="a-bad"><?= nl2br(e((string) $payment['decline_reason'])) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <h3 class="a-sub">Proof of payment</h3>
    <?php if (!$proofs): ?>
      <p class="a-empty a-empty-tight">Nothing uploaded yet.</p>
    <?php else: ?>
      <ul class="a-proofs">
        <?php foreach ($proofs as $proof): ?>
          <li class="is-<?= e((string) $proof['status']) ?>">
            <div>
              <strong><?= e((string) ($proof['original_name'] ?: $proof['stored_name'])) ?></strong>
              <small>
                <?= e(strtoupper((string) $proof['extension'])) ?> · <?= e(bk_filesize((int) $proof['size'])) ?> ·
                <?= e(date('d M Y, H:i', strtotime((string) $proof['created_at']) ?: time())) ?> ·
                uploaded by <?= e((string) $proof['uploaded_by']) ?>
                <?php if ((float) $proof['amount'] > 0): ?> · declared <?= e(bk_money((float) $proof['amount'])) ?><?php endif; ?>
                <?php if ((string) $proof['bank_reference'] !== ''): ?> · ref <?= e((string) $proof['bank_reference']) ?><?php endif; ?>
              </small>
              <?php if (trim((string) $proof['client_notes']) !== ''): ?>
                <small class="a-quote-inline"><?= e((string) $proof['client_notes']) ?></small>
              <?php endif; ?>
              <?php if (trim((string) $proof['review_reason']) !== ''): ?>
                <small class="a-bad">Declined: <?= e((string) $proof['review_reason']) ?></small>
              <?php endif; ?>
            </div>
            <span class="a-proof-state"><?= e(ucfirst((string) $proof['status'])) ?></span>
            <a class="a-btn a-btn-small" href="<?= e(url('booking/download.php')) ?>?kind=proof&amp;id=<?= (int) $proof['id'] ?>&amp;view=1" target="_blank" rel="noopener">View</a>
            <a class="a-btn a-btn-small" href="<?= e(url('booking/download.php')) ?>?kind=proof&amp;id=<?= (int) $proof['id'] ?>">↓</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($canReview): ?>
      <h3 class="a-sub">Verify this payment</h3>
      <?php if (!$latestProof): ?>
        <p class="a-note">No proof of payment has been uploaded yet. You can still approve the payment if you have confirmed the money in the bank yourself — record the details below.</p>
      <?php endif; ?>

      <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="eft_approve">
        <input type="hidden" name="id" value="<?= $id ?>">

        <div class="a-field-row">
          <div class="a-field">
            <label for="amount_received">Amount received</label>
            <input type="text" id="amount_received" name="amount_received" inputmode="decimal"
                   value="<?= e(number_format((float) $booking['amount_due'], 2, '.', '')) ?>">
          </div>
          <div class="a-field">
            <label for="verified_on">Date received</label>
            <input type="date" id="verified_on" name="verified_on" value="<?= e(date('Y-m-d')) ?>">
          </div>
        </div>

        <div class="a-field">
          <label for="bank_reference">Reference on our bank statement</label>
          <input type="text" id="bank_reference" name="bank_reference" maxlength="120"
                 value="<?= e((string) ($payment['admin_bank_reference'] ?: $payment['bank_reference'])) ?>">
        </div>

        <div class="a-field">
          <label for="verify_notes">Verification note</label>
          <textarea id="verify_notes" name="notes" rows="2" placeholder="What you checked, and where"><?= e((string) $payment['admin_notes']) ?></textarea>
          <p class="a-help">Kept on the payment record and printed on the receipt.</p>
        </div>

        <div class="a-form-actions">
          <button class="a-btn a-btn-primary" type="submit"
                  data-confirm="Approve this EFT payment? The booking will be confirmed and the client emailed their ticket and receipt.">
            Approve payment &amp; confirm booking
          </button>
        </div>
      </form>

      <?php if ((string) $booking['status'] === 'proof_submitted'): ?>
        <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>" class="a-inline-form">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="eft_under_review">
          <input type="hidden" name="id" value="<?= $id ?>">
          <div class="a-form-actions">
            <button class="a-btn a-btn-small" type="submit">Mark as under review</button>
            <p class="a-help">Tells the client you are checking it, and shows your colleagues it is being dealt with.</p>
          </div>
        </form>
      <?php endif; ?>

      <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>" class="a-decline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="eft_decline">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="a-field">
          <label for="decline_reason">Or decline it — the reason is emailed to the client <b>*</b></label>
          <textarea id="decline_reason" name="reason" rows="2" required
                    placeholder="For example: the amount does not match, or the reference is missing"></textarea>
        </div>
        <div class="a-form-actions">
          <button class="a-btn a-btn-danger" type="submit"
                  data-confirm="Decline this proof of payment and email the reason to the client?">Decline proof of payment</button>
        </div>
      </form>
    <?php elseif ((string) $booking['status'] === 'declined'): ?>
      <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="eft_reopen">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="a-form-actions">
          <button class="a-btn" type="submit">Reopen for a new proof of payment</button>
        </div>
      </form>
    <?php endif; ?>

    <h3 class="a-sub">Upload a proof on the client's behalf</h3>
    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_admin_proof">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="a-field">
        <label for="admin_proof">File emailed or handed in by the client</label>
        <input type="file" id="admin_proof" name="proof" accept=".pdf,.jpg,.jpeg,.png">
        <p class="a-help"><?= e(strtoupper(implode(', ', bk_allowed_extensions()))) ?>, up to <?= bk_int('bk_upload_max_mb', 6) ?> MB. Stored privately, exactly like a client upload.</p>
      </div>
      <div class="a-form-actions">
        <button class="a-btn a-btn-small" type="submit">Attach this proof</button>
      </div>
    </form>
  </section>

  <!-- ---------------------------------------------- booking details -->
  <section class="a-card">
    <h2>Booking</h2>

    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_booking_save">
      <input type="hidden" name="id" value="<?= $id ?>">

      <div class="a-field">
        <label for="b_status">Status</label>
        <select id="b_status" name="status">
          <?php foreach (bk_statuses() as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= (string) $booking['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="a-help">Setting this by hand does not send a confirmation email or make a ticket — use <strong>Approve payment</strong> for that.</p>
      </div>

      <div class="a-field-row">
        <div class="a-field"><label for="b_name">Client name</label>
          <input type="text" id="b_name" name="contact_name" value="<?= e((string) $booking['contact_name']) ?>"></div>
        <div class="a-field"><label for="b_email">Email</label>
          <input type="email" id="b_email" name="contact_email" value="<?= e((string) $booking['contact_email']) ?>"></div>
      </div>
      <div class="a-field-row">
        <div class="a-field"><label for="b_phone">Phone</label>
          <input type="text" id="b_phone" name="contact_phone" value="<?= e((string) $booking['contact_phone']) ?>"></div>
        <div class="a-field"><label for="b_company">Company</label>
          <input type="text" id="b_company" name="company" value="<?= e((string) $booking['company']) ?>"></div>
      </div>
      <div class="a-field-row">
        <div class="a-field"><label for="b_guests">Guests</label>
          <input type="number" id="b_guests" name="guests" min="1" max="500" value="<?= (int) $booking['guests'] ?>"></div>
        <div class="a-field"><label for="b_amount">Amount due</label>
          <input type="text" id="b_amount" name="amount_due" inputmode="decimal"
                 value="<?= e(number_format((float) $booking['amount_due'], 2, '.', '')) ?>"></div>
      </div>

      <div class="a-field">
        <label for="b_location">Location on the ticket</label>
        <input type="text" id="b_location" name="location" value="<?= e((string) $booking['location']) ?>">
      </div>

      <div class="a-field">
        <label for="b_notes">Internal notes</label>
        <textarea id="b_notes" name="admin_notes" rows="3"><?= e((string) $booking['admin_notes']) ?></textarea>
        <p class="a-help">Only staff see these.</p>
      </div>

      <div class="a-form-actions">
        <button class="a-btn a-btn-primary" type="submit">Save booking</button>
      </div>
    </form>

    <h3 class="a-sub">Move this booking</h3>
    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_reschedule">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="a-field-row">
        <div class="a-field"><label for="r_date">New date</label>
          <input type="date" id="r_date" name="date" value="<?= e((string) $booking['booking_date']) ?>"></div>
        <div class="a-field"><label for="r_time">New time</label>
          <input type="time" id="r_time" name="time" step="60" value="<?= e((string) $booking['start_time']) ?>"></div>
      </div>
      <div class="a-form-actions">
        <button class="a-btn" type="submit">Move booking</button>
      </div>
      <?php if ($service): ?>
        <p class="a-help">Open slots are checked before the move. Moved <?= (int) $booking['reschedules'] ?> time(s) so far.</p>
      <?php endif; ?>
    </form>

    <h3 class="a-sub">Send something to the client</h3>
    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>" class="a-inline-form">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_resend">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="a-form-actions a-wrap">
        <button class="a-btn a-btn-small" type="submit" name="what" value="instructions">Payment instructions</button>
        <button class="a-btn a-btn-small" type="submit" name="what" value="confirmation"
                <?= in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true) ? '' : 'disabled' ?>>
          Confirmation, ticket &amp; receipt
        </button>
        <button class="a-btn a-btn-small" type="submit" name="what" value="regenerate"
                <?= in_array((string) $booking['status'], ['confirmed', 'payment_confirmed', 'completed'], true) ? '' : 'disabled' ?>>
          Rebuild the PDFs
        </button>
      </div>
    </form>

    <h3 class="a-sub">Cancel</h3>
    <form method="post" action="?p=eft_bookings&amp;action=view&amp;id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_cancel">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="a-field">
        <label for="c_reason">Reason, emailed to the client</label>
        <input type="text" id="c_reason" name="reason" maxlength="400">
      </div>
      <div class="a-form-actions">
        <button class="a-btn a-btn-danger" type="submit"
                data-confirm="Cancel booking <?= e((string) $booking['reference']) ?>? Any ticket issued becomes invalid."
                <?= (string) $booking['status'] === 'cancelled' ? 'disabled' : '' ?>>Cancel this booking</button>
      </div>
    </form>
  </section>
</div>

<div class="a-grid-2">
  <section class="a-card">
    <h2>Client &amp; booking record</h2>
    <table class="a-table">
      <tbody>
        <tr><th>Account</th><td>
          <?php if ($client): ?>
            <a href="?p=eft_clients&amp;action=view&amp;id=<?= (int) $client['id'] ?>"><?= e((string) $client['full_name']) ?></a>
            <small><?= e((string) $client['email']) ?>
              <?= trim((string) $client['email_verified_at']) !== '' ? '· confirmed' : '· not confirmed' ?></small>
          <?php else: ?>
            <em>Created in the control panel — no client account</em>
          <?php endif; ?>
        </td></tr>
        <tr><th>Service</th><td><?= e((string) $booking['service_name']) ?></td></tr>
        <tr><th>Date &amp; time</th><td><?= e(bk_date_long((string) $booking['booking_date'])) ?>,
            <?= e(bk_time_range((string) $booking['start_time'], (string) $booking['end_time'])) ?></td></tr>
        <tr><th>Guests</th><td><?= (int) $booking['guests'] ?><?= $guests ? ' — ' . e(implode(', ', array_column($guests, 'full_name'))) : '' ?></td></tr>
        <?php if (trim((string) $booking['extra_details']) !== ''): ?>
          <tr><th><?= e($service ? (string) $service['extra_field_label'] : 'Extra details') ?></th>
              <td><?= nl2br(e((string) $booking['extra_details'])) ?></td></tr>
        <?php endif; ?>
        <?php if (trim((string) $booking['client_notes']) !== ''): ?>
          <tr><th>Client's notes</th><td><?= nl2br(e((string) $booking['client_notes'])) ?></td></tr>
        <?php endif; ?>
        <tr><th>Ticket</th><td>
          <?php if ($ticket): ?>
            <?= e((string) $ticket['ticket_number']) ?> · code <code><?= e((string) $ticket['verification_code']) ?></code>
            <small><?= e(ucfirst((string) $ticket['status'])) ?><?php
              if ((string) $ticket['checked_in_at'] !== '') {
                  echo ' · checked in ' . e(date('d M Y, H:i', strtotime((string) $ticket['checked_in_at']) ?: time()));
              } ?></small>
          <?php else: ?>—<?php endif; ?>
        </td></tr>
        <tr><th>Receipt</th><td><?= $receipt ? e((string) $receipt['receipt_number']) : '—' ?></td></tr>
        <tr><th>Source</th><td><?= e((string) $booking['source'] === 'admin' ? 'Created in the control panel' : 'Booked online') ?>
            <?php if ((string) $booking['ip'] !== ''): ?><small>from <?= e((string) $booking['ip']) ?></small><?php endif; ?></td></tr>
      </tbody>
    </table>

    <?php if ($requests): ?>
      <h3 class="a-sub">Requests from the client</h3>
      <table class="a-table">
        <tbody>
        <?php foreach ($requests as $request): ?>
          <tr>
            <th><?= e($request['type'] === 'cancel' ? 'Cancellation' : 'New date') ?></th>
            <td><?= e(date('d M Y', strtotime((string) $request['created_at']) ?: time())) ?> ·
                <?= e(ucfirst((string) $request['status'])) ?>
                <?php if ((string) $request['requested_date'] !== ''): ?>
                  <small>wanted <?= e(bk_date_long((string) $request['requested_date'])) ?> at <?= e((string) $request['requested_time']) ?></small>
                <?php endif; ?>
                <?php if (trim((string) $request['reason']) !== ''): ?><small><?= e((string) $request['reason']) ?></small><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2>History</h2>
    <ul class="a-timeline">
      <?php foreach ($audit as $entry): ?>
        <li>
          <strong><?= e((string) $entry['action']) ?></strong>
          <span><?= e((string) $entry['detail']) ?></span>
          <small><?= e(date('d M Y, H:i', strtotime((string) $entry['created_at']) ?: time())) ?>
            · <?= e((string) $entry['actor_name']) ?> (<?= e((string) $entry['actor_type']) ?>)</small>
        </li>
      <?php endforeach; ?>
      <?php if (!$audit): ?><li><span>Nothing recorded yet.</span></li><?php endif; ?>
    </ul>

    <h3 class="a-sub">Emails sent</h3>
    <?php if (!$emails): ?>
      <p class="a-empty a-empty-tight">No emails yet.</p>
    <?php else: ?>
      <table class="a-table">
        <tbody>
        <?php foreach ($emails as $email): ?>
          <tr>
            <th><?= e(date('d M, H:i', strtotime((string) $email['created_at']) ?: time())) ?></th>
            <td>
              <?= e((string) $email['subject']) ?>
              <small>
                to <?= e((string) $email['to_address']) ?> ·
                <span class="<?= (string) $email['status'] === 'sent' ? 'a-good' : 'a-bad' ?>"><?= e((string) $email['status']) ?></span>
                <?php if ((string) $email['attachments'] !== ''): ?> · <?= e((string) $email['attachments']) ?><?php endif; ?>
              </small>
              <?php if ((string) $email['error'] !== ''): ?><small class="a-bad"><?= e((string) $email['error']) ?></small><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
    <?php
}

/* ================================================ 6. MANUAL NEW BOOKING */

function eft_admin_new_booking_view(): void
{
    $services = bk_services(false);
    $serviceId = (int) ($_GET['service'] ?? 0);
    $service = $serviceId > 0 ? bk_service($serviceId) : null;
    $date = (string) ($_GET['date'] ?? bk_now()->format('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = bk_now()->format('Y-m-d');
    }
    ?>
<div class="a-head">
  <div>
    <h1>New booking</h1>
    <p>Create a booking for somebody who phoned, emailed or walked in. It follows exactly the same EFT workflow.</p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=eft_bookings">← All bookings</a>
</div>

<?php if (!$services): ?>
  <div class="a-card"><p class="a-empty">Add a service first, under <a href="?p=services">Services</a>.</p></div>
<?php else: ?>

<form class="a-filter" method="get">
  <input type="hidden" name="p" value="eft_bookings">
  <input type="hidden" name="action" value="new">
  <label for="ns">Service</label>
  <select id="ns" name="service" onchange="this.form.submit()">
    <option value="">— choose —</option>
    <?php foreach ($services as $row): ?>
      <option value="<?= (int) $row['id'] ?>"<?= $serviceId === (int) $row['id'] ? ' selected' : '' ?>><?= e((string) $row['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <label for="nd">Date</label>
  <input type="date" id="nd" name="date" value="<?= e($date) ?>" onchange="this.form.submit()">
  <noscript><button class="a-btn a-btn-small" type="submit">Show slots</button></noscript>
</form>

<?php if ($service): ?>
  <?php $slots = bk_slots($service, $date); ?>
  <div class="a-card">
    <h2><?= e((string) $service['name']) ?> · <?= e(bk_date_long($date)) ?></h2>

    <?php if (!$slots): ?>
      <p class="a-empty a-empty-tight">No slots that day — check the opening hours and blocked dates for this service.</p>
    <?php else: ?>
      <form method="post" action="?p=eft_bookings&amp;action=new">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="eft_new_booking">
        <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
        <input type="hidden" name="date" value="<?= e($date) ?>">

        <div class="a-field">
          <label for="n_time">Time</label>
          <select id="n_time" name="time" required>
            <?php foreach ($slots as $slot): ?>
              <option value="<?= e($slot['start']) ?>"<?= $slot['available'] ? '' : ' disabled' ?>>
                <?= e($slot['start']) ?> — <?= $slot['available'] ? (int) $slot['free'] . ' free' : e($slot['reason']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="a-field-row">
          <div class="a-field"><label for="n_name">Client name <b>*</b></label>
            <input type="text" id="n_name" name="contact_name" required maxlength="190"></div>
          <div class="a-field"><label for="n_email">Email <b>*</b></label>
            <input type="email" id="n_email" name="contact_email" required maxlength="190">
            <p class="a-help">If an account already uses this address, the booking is linked to it.</p></div>
        </div>
        <div class="a-field-row">
          <div class="a-field"><label for="n_phone">Phone <b>*</b></label>
            <input type="text" id="n_phone" name="contact_phone" required maxlength="60"></div>
          <div class="a-field"><label for="n_company">Company</label>
            <input type="text" id="n_company" name="company" maxlength="190"></div>
        </div>
        <div class="a-field-row">
          <div class="a-field"><label for="n_guests">Guests</label>
            <input type="number" id="n_guests" name="guests" min="<?= max(1, (int) $service['min_guests']) ?>"
                   max="<?= max(1, (int) $service['max_guests']) ?>" value="<?= max(1, (int) $service['min_guests']) ?>"></div>
          <div class="a-field"><label for="n_notes">Notes</label>
            <input type="text" id="n_notes" name="client_notes" maxlength="500"></div>
        </div>

        <div class="a-field">
          <label class="a-switch">
            <input type="hidden" name="send_email" value="0">
            <input type="checkbox" name="send_email" value="1" checked>
            <span class="a-switch-track"></span>
            <span class="a-switch-label">Email the client their booking reference and the EFT details</span>
          </label>
        </div>

        <div class="a-form-actions">
          <button class="a-btn a-btn-primary" type="submit">Create the booking</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php endif; ?>
    <?php
}

/* ========================================================== 7. CALENDAR */

function eft_admin_calendar_view(): void
{
    $month = (string) ($_GET['m'] ?? bk_now()->format('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = bk_now()->format('Y-m');
    }
    $serviceId = (int) ($_GET['service'] ?? 0);

    $first = new DateTimeImmutable($month . '-01');
    $last  = $first->modify('last day of this month');
    $days  = bk_calendar($first->format('Y-m-d'), $last->format('Y-m-d'), $serviceId);

    $lead = ((int) $first->format('N')) - 1;          // Monday-first grid
    $totalCells = (int) ceil(($lead + (int) $last->format('j')) / 7) * 7;
    $today = bk_now()->format('Y-m-d');
    ?>
<div class="a-head">
  <div>
    <h1>Booking diary</h1>
    <p>Confirmed and pending bookings, day by day.</p>
  </div>
  <div class="a-head-actions">
    <a class="a-btn a-btn-ghost" href="?p=eft_calendar&amp;m=<?= e($first->modify('-1 month')->format('Y-m')) ?>&amp;service=<?= $serviceId ?>">← <?= e($first->modify('-1 month')->format('M Y')) ?></a>
    <a class="a-btn a-btn-ghost" href="?p=eft_calendar&amp;m=<?= e($first->modify('+1 month')->format('Y-m')) ?>&amp;service=<?= $serviceId ?>"><?= e($first->modify('+1 month')->format('M Y')) ?> →</a>
  </div>
</div>

<form class="a-filter" method="get">
  <input type="hidden" name="p" value="eft_calendar">
  <input type="hidden" name="m" value="<?= e($month) ?>">
  <label for="cs">Service</label>
  <select id="cs" name="service" onchange="this.form.submit()">
    <option value="0">All services</option>
    <?php foreach (bk_services(false) as $service): ?>
      <option value="<?= (int) $service['id'] ?>"<?= $serviceId === (int) $service['id'] ? ' selected' : '' ?>><?= e((string) $service['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <noscript><button class="a-btn a-btn-small" type="submit">Filter</button></noscript>
</form>

<div class="a-card">
  <h2><?= e($first->format('F Y')) ?></h2>
  <div class="a-cal">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label): ?>
      <div class="a-cal-head"><?= e($label) ?></div>
    <?php endforeach; ?>

    <?php for ($cell = 0; $cell < $totalCells; $cell++):
        $dayNumber = $cell - $lead + 1;
        if ($dayNumber < 1 || $dayNumber > (int) $last->format('j')): ?>
          <div class="a-cal-cell is-empty"></div>
        <?php continue; endif;

        $date = $first->modify('+' . ($dayNumber - 1) . ' days')->format('Y-m-d');
        $entries = $days[$date] ?? [];
        ?>
        <div class="a-cal-cell<?= $date === $today ? ' is-today' : '' ?>">
          <span class="a-cal-day"><?= $dayNumber ?></span>
          <?php foreach (array_slice($entries, 0, 4) as $entry): ?>
            <a class="a-cal-item is-<?= e(bk_status_tone((string) $entry['status'])) ?>"
               href="?p=eft_bookings&amp;action=view&amp;id=<?= (int) $entry['id'] ?>"
               title="<?= e((string) $entry['reference'] . ' · ' . $entry['contact_name'] . ' · ' . bk_status_label((string) $entry['status'])) ?>">
              <b><?= e((string) $entry['start_time']) ?></b> <?= e(mb_strimwidth((string) $entry['contact_name'], 0, 16, '…')) ?>
            </a>
          <?php endforeach; ?>
          <?php if (count($entries) > 4): ?>
            <a class="a-cal-more" href="?p=eft_bookings&amp;from=<?= e($date) ?>&amp;to=<?= e($date) ?>">+<?= count($entries) - 4 ?> more</a>
          <?php endif; ?>
        </div>
    <?php endfor; ?>
  </div>

  <p class="a-cal-key">
    <span class="a-cal-item is-wait">Awaiting payment</span>
    <span class="a-cal-item is-info">Under review</span>
    <span class="a-cal-item is-good">Confirmed</span>
    <span class="a-cal-item is-off">Cancelled</span>
  </p>
</div>
    <?php
}

/* ========================================================== 8. PAYMENTS */

function eft_admin_payments_view(): void
{
    $status = (string) ($_GET['s'] ?? '');
    $allowed = ['awaiting', 'submitted', 'under_review', 'confirmed', 'declined', 'refunded'];

    $sql = 'SELECT p.*, b.contact_name, b.service_name, b.booking_date, b.id AS booking_row_id
              FROM eft_payments p JOIN service_bookings b ON b.id = p.booking_id';
    $params = [];
    if (in_array($status, $allowed, true)) {
        $sql .= ' WHERE p.status = :s';
        $params[':s'] = $status;
    }
    $rows = db_all($sql . ' ORDER BY p.id DESC LIMIT 400', $params);

    $totals = db_one("SELECT COALESCE(SUM(amount_received),0) AS received, COUNT(*) AS confirmed
                        FROM eft_payments WHERE status = 'confirmed'") ?: [];
    ?>
<div class="a-head">
  <div>
    <h1>EFT payments</h1>
    <p>Every payment record, with what the client declared and what was actually banked.</p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=eft_payments&amp;action=export<?= $status !== '' ? '&amp;s=' . e($status) : '' ?>">Download CSV ↓</a>
</div>

<div class="a-stats">
  <div class="a-stat a-stat-primary">
    <span class="a-stat-num" style="font-size:1.4rem"><?= e(bk_money((float) ($totals['received'] ?? 0))) ?></span>
    <span class="a-stat-label">Verified and banked</span>
  </div>
  <div class="a-stat">
    <span class="a-stat-num"><?= (int) ($totals['confirmed'] ?? 0) ?></span>
    <span class="a-stat-label">Confirmed payments</span>
  </div>
</div>

<div class="a-tabs">
  <a href="?p=eft_payments"<?= $status === '' ? ' class="on"' : '' ?>>All</a>
  <?php foreach (['awaiting' => 'Awaiting', 'submitted' => 'Proof submitted', 'confirmed' => 'Confirmed', 'declined' => 'Declined', 'refunded' => 'Refunded'] as $key => $label): ?>
    <a href="?p=eft_payments&amp;s=<?= e($key) ?>"<?= $status === $key ? ' class="on"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">No payment records in this list.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead><tr><th>Reference</th><th>Client</th><th>Due</th><th>Declared</th><th>Received</th><th>Status</th><th>Verified</th><th class="a-right"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><strong><?= e((string) $row['reference']) ?></strong><small><?= e((string) $row['service_name']) ?></small></td>
          <td><?= e((string) $row['contact_name']) ?><small><?= e(bk_date_long((string) $row['booking_date'])) ?></small></td>
          <td><?= e(bk_money((float) $row['amount_due'], (string) $row['currency'])) ?></td>
          <td><?= (float) $row['amount_declared'] > 0 ? e(bk_money((float) $row['amount_declared'], (string) $row['currency'])) : '—' ?></td>
          <td><strong><?= (float) $row['amount_received'] > 0 ? e(bk_money((float) $row['amount_received'], (string) $row['currency'])) : '—' ?></strong></td>
          <td><span class="a-pill a-pill-bk-<?= e((string) $row['status'] === 'confirmed' ? 'good' : ((string) $row['status'] === 'declined' ? 'bad' : 'wait')) ?>"><?= e(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?></span></td>
          <td><?= (string) $row['verified_at'] !== '' ? e(date('d M Y', strtotime((string) $row['verified_at']) ?: time())) : '—' ?>
              <?php if ((string) $row['verified_by_name'] !== ''): ?><small><?= e((string) $row['verified_by_name']) ?></small><?php endif; ?></td>
          <td class="a-right"><a class="a-btn a-btn-small" href="?p=eft_bookings&amp;action=view&amp;id=<?= (int) $row['booking_row_id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
}

/* =========================================================== 9. CLIENTS */

function eft_admin_clients_view(): void
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM clients';
    $params = [];
    if ($search !== '') {
        $sql .= ' WHERE email LIKE :q OR full_name LIKE :q OR company LIKE :q OR phone LIKE :q';
        $params[':q'] = '%' . $search . '%';
    }
    $rows = db_all($sql . ' ORDER BY id DESC LIMIT 400', $params);
    ?>
<div class="a-head">
  <div>
    <h1>Client accounts</h1>
    <p>People who have registered to book online.</p>
  </div>
</div>

<form class="a-filter" method="get">
  <input type="hidden" name="p" value="eft_clients">
  <label for="cq">Search</label>
  <input type="search" id="cq" name="q" value="<?= e($search) ?>" placeholder="Name, email, company or phone">
  <button class="a-btn a-btn-small a-btn-primary" type="submit">Search</button>
  <a class="a-btn a-btn-small" href="?p=eft_clients">Clear</a>
</form>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">No client accounts<?= $search !== '' ? ' match that search' : ' yet' ?>.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Bookings</th><th>Status</th><th>Joined</th><th class="a-right"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
          $count = db_one('SELECT COUNT(*) AS c FROM service_bookings WHERE client_id = :c', [':c' => (int) $row['id']]);
          ?>
        <tr<?= (string) $row['status'] !== 'active' ? ' class="a-off"' : '' ?>>
          <td><strong><?= e((string) $row['full_name']) ?></strong><?php if ((string) $row['company'] !== ''): ?><small><?= e((string) $row['company']) ?></small><?php endif; ?></td>
          <td><?= e((string) $row['email']) ?><small><?= trim((string) $row['email_verified_at']) !== '' ? 'confirmed' : 'not confirmed' ?></small></td>
          <td><?= e((string) $row['phone']) ?></td>
          <td><?= (int) ($count['c'] ?? 0) ?></td>
          <td><?= e(ucfirst((string) $row['status'])) ?></td>
          <td><?= e(date('d M Y', strtotime((string) $row['created_at']) ?: time())) ?></td>
          <td class="a-right"><a class="a-btn a-btn-small" href="?p=eft_clients&amp;action=view&amp;id=<?= (int) $row['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
}

function eft_admin_client_view(int $id): void
{
    $client = db_one('SELECT * FROM clients WHERE id = :id', [':id' => $id]);
    if (!$client) {
        echo '<div class="a-card"><p class="a-empty">That client account no longer exists.</p></div>';
        return;
    }
    $bookings = db_all('SELECT * FROM service_bookings WHERE client_id = :c ORDER BY booking_date DESC LIMIT 200', [':c' => $id]);
    ?>
<div class="a-head">
  <div>
    <h1><?= e((string) $client['full_name']) ?></h1>
    <p><?= e((string) $client['email']) ?> · joined <?= e(date('d M Y', strtotime((string) $client['created_at']) ?: time())) ?></p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=eft_clients">← All clients</a>
</div>

<div class="a-grid-2">
  <section class="a-card">
    <h2>Account</h2>
    <form method="post" action="?p=eft_clients&amp;action=view&amp;id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="eft_client_save">
      <input type="hidden" name="id" value="<?= $id ?>">

      <div class="a-field-row">
        <div class="a-field"><label for="cl_name">Full name</label>
          <input type="text" id="cl_name" name="full_name" value="<?= e((string) $client['full_name']) ?>"></div>
        <div class="a-field"><label for="cl_phone">Phone</label>
          <input type="text" id="cl_phone" name="phone" value="<?= e((string) $client['phone']) ?>"></div>
      </div>
      <div class="a-field-row">
        <div class="a-field"><label for="cl_company">Company</label>
          <input type="text" id="cl_company" name="company" value="<?= e((string) $client['company']) ?>"></div>
        <div class="a-field"><label for="cl_city">Town or city</label>
          <input type="text" id="cl_city" name="city" value="<?= e((string) $client['city']) ?>"></div>
      </div>

      <div class="a-field">
        <label for="cl_status">Status</label>
        <select id="cl_status" name="status">
          <option value="active"<?= (string) $client['status'] === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="suspended"<?= (string) $client['status'] === 'suspended' ? ' selected' : '' ?>>Suspended — cannot sign in</option>
        </select>
      </div>

      <div class="a-field">
        <label for="cl_notes">Internal notes</label>
        <textarea id="cl_notes" name="admin_notes" rows="3"><?= e((string) $client['admin_notes']) ?></textarea>
      </div>

      <div class="a-field">
        <label class="a-switch">
          <input type="hidden" name="mark_verified" value="0">
          <input type="checkbox" name="mark_verified" value="1"<?= trim((string) $client['email_verified_at']) !== '' ? ' checked' : '' ?>>
          <span class="a-switch-track"></span>
          <span class="a-switch-label">Email address confirmed</span>
        </label>
      </div>

      <div class="a-form-actions">
        <button class="a-btn a-btn-primary" type="submit">Save</button>
        <button class="a-btn" type="submit" name="send_reset" value="1"
                data-confirm="Email this client a password-reset link?">Send a password-reset link</button>
      </div>
    </form>
  </section>

  <section class="a-card a-card-flush">
    <h2 style="padding:1.1rem 1.25rem 0">Bookings</h2>
    <?php if (!$bookings): ?>
      <p class="a-empty">No bookings yet.</p>
    <?php else: ?>
      <table class="a-table a-table-list">
        <thead><tr><th>Reference</th><th>Service</th><th>Date</th><th>Status</th><th class="a-right"></th></tr></thead>
        <tbody>
        <?php foreach ($bookings as $row): ?>
          <tr>
            <td><strong><?= e((string) $row['reference']) ?></strong></td>
            <td><?= e((string) $row['service_name']) ?></td>
            <td><?= e(date('d M Y', strtotime((string) $row['booking_date']) ?: time())) ?></td>
            <td><?= eft_pill((string) $row['status']) ?></td>
            <td class="a-right"><a class="a-btn a-btn-small" href="?p=eft_bookings&amp;action=view&amp;id=<?= (int) $row['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>
    <?php
}

/* =========================================================== 10. REPORTS */

function eft_admin_reports_view(): void
{
    $from = (string) ($_GET['from'] ?? bk_now()->modify('-90 days')->format('Y-m-d'));
    $to   = (string) ($_GET['to'] ?? bk_now()->modify('+90 days')->format('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = bk_now()->modify('-90 days')->format('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = bk_now()->modify('+90 days')->format('Y-m-d');
    }
    $range = [':from' => $from, ':to' => $to];

    $byStatus = db_all(
        'SELECT status, COUNT(*) AS c, COALESCE(SUM(amount_due),0) AS due, COALESCE(SUM(amount_paid),0) AS paid
           FROM service_bookings WHERE booking_date BETWEEN :from AND :to GROUP BY status',
        $range
    );
    $byService = db_all(
        'SELECT service_name, COUNT(*) AS c, COALESCE(SUM(amount_due),0) AS due, COALESCE(SUM(amount_paid),0) AS paid,
                SUM(guests) AS guests
           FROM service_bookings WHERE booking_date BETWEEN :from AND :to GROUP BY service_name ORDER BY c DESC',
        $range
    );
    $byDate = db_all(
        'SELECT booking_date, COUNT(*) AS c, COALESCE(SUM(amount_paid),0) AS paid
           FROM service_bookings WHERE booking_date BETWEEN :from AND :to
          GROUP BY booking_date ORDER BY booking_date',
        $range
    );
    $review = eft_pending_count();
    ?>
<div class="a-head">
  <div>
    <h1>Reports</h1>
    <p>Bookings and EFT payments over a period.</p>
  </div>
  <div class="a-head-actions">
    <a class="a-btn a-btn-ghost" href="?p=eft_bookings&amp;action=export&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">Bookings CSV ↓</a>
    <a class="a-btn a-btn-ghost" href="?p=eft_payments&amp;action=export">Payments CSV ↓</a>
  </div>
</div>

<form class="a-filter" method="get">
  <input type="hidden" name="p" value="eft_reports">
  <label for="rf">From</label><input type="date" id="rf" name="from" value="<?= e($from) ?>">
  <label for="rt">To</label><input type="date" id="rt" name="to" value="<?= e($to) ?>">
  <button class="a-btn a-btn-small a-btn-primary" type="submit">Update</button>
</form>

<?php if ($review > 0): ?>
  <div class="a-alert a-alert-warn">
    <?= $review ?> proof<?= $review === 1 ? '' : 's' ?> of payment waiting to be verified.
    <a href="?p=eft_bookings&amp;s=proof_submitted">Review them now →</a>
  </div>
<?php endif; ?>

<div class="a-grid-2">
  <section class="a-card a-card-flush">
    <h2 style="padding:1.1rem 1.25rem 0">By status</h2>
    <table class="a-table a-table-list">
      <thead><tr><th>Status</th><th>Bookings</th><th>Value</th><th>Received</th></tr></thead>
      <tbody>
      <?php if (!$byStatus): ?>
        <tr><td colspan="4" class="a-empty">Nothing in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($byStatus as $row): ?>
        <tr>
          <td><?= eft_pill((string) $row['status']) ?></td>
          <td><?= (int) $row['c'] ?></td>
          <td><?= e(bk_money((float) $row['due'])) ?></td>
          <td><strong><?= e(bk_money((float) $row['paid'])) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="a-card a-card-flush">
    <h2 style="padding:1.1rem 1.25rem 0">By service</h2>
    <table class="a-table a-table-list">
      <thead><tr><th>Service</th><th>Bookings</th><th>Guests</th><th>Received</th></tr></thead>
      <tbody>
      <?php if (!$byService): ?>
        <tr><td colspan="4" class="a-empty">Nothing in this period.</td></tr>
      <?php endif; ?>
      <?php foreach ($byService as $row): ?>
        <tr>
          <td><?= e((string) $row['service_name']) ?></td>
          <td><?= (int) $row['c'] ?></td>
          <td><?= (int) $row['guests'] ?></td>
          <td><strong><?= e(bk_money((float) $row['paid'])) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<div class="a-card a-card-flush">
  <h2 style="padding:1.1rem 1.25rem 0">By date</h2>
  <table class="a-table a-table-list">
    <thead><tr><th>Date</th><th>Bookings</th><th>Received</th></tr></thead>
    <tbody>
    <?php if (!$byDate): ?>
      <tr><td colspan="3" class="a-empty">Nothing in this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($byDate as $row): ?>
      <tr>
        <td><?= e(bk_date_long((string) $row['booking_date'])) ?></td>
        <td><?= (int) $row['c'] ?></td>
        <td><?= e(bk_money((float) $row['paid'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
    <?php
}

/* ======================================================== 11. EMAIL LOG */

function eft_admin_emails_view(): void
{
    $status = (string) ($_GET['s'] ?? '');
    $sql = 'SELECT * FROM email_log';
    $params = [];
    if (in_array($status, ['sent', 'failed'], true)) {
        $sql .= ' WHERE status = :s';
        $params[':s'] = $status;
    }
    $rows = db_all($sql . ' ORDER BY id DESC LIMIT 300', $params);

    $failed = (int) (db_one("SELECT COUNT(*) AS c FROM email_log WHERE status = 'failed'")['c'] ?? 0);
    ?>
<div class="a-head">
  <div>
    <h1>Email log</h1>
    <p>Every booking email the site has tried to send, and whether it went out.</p>
  </div>
</div>

<?php if ($failed > 0): ?>
  <div class="a-alert a-alert-warn">
    <?= $failed ?> message<?= $failed === 1 ? '' : 's' ?> could not be sent.
    Check <a href="?p=settings&amp;g=email">Site settings → Email delivery</a>.
  </div>
<?php endif; ?>

<div class="a-tabs">
  <a href="?p=eft_emails"<?= $status === '' ? ' class="on"' : '' ?>>All</a>
  <a href="?p=eft_emails&amp;s=sent"<?= $status === 'sent' ? ' class="on"' : '' ?>>Sent</a>
  <a href="?p=eft_emails&amp;s=failed"<?= $status === 'failed' ? ' class="on"' : '' ?>>Failed</a>
</div>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">Nothing logged yet.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Sent via</th><th>Status</th><th class="a-right"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr<?= (string) $row['status'] === 'failed' ? ' class="a-off"' : '' ?>>
          <td><?= e(date('d M Y', strtotime((string) $row['created_at']) ?: time())) ?><small><?= e(date('H:i', strtotime((string) $row['created_at']) ?: time())) ?></small></td>
          <td><?= e((string) $row['to_address']) ?></td>
          <td><?= e((string) $row['subject']) ?>
            <?php if ((string) $row['attachments'] !== ''): ?><small>📎 <?= e((string) $row['attachments']) ?></small><?php endif; ?>
            <?php if ((string) $row['error'] !== ''): ?><small class="a-bad"><?= e((string) $row['error']) ?></small><?php endif; ?></td>
          <td><?= e((string) $row['transport']) ?></td>
          <td><span class="<?= (string) $row['status'] === 'sent' ? 'a-good' : 'a-bad' ?>"><?= e((string) $row['status']) ?></span></td>
          <td class="a-right">
            <?php if ((int) $row['booking_id'] > 0): ?>
              <a class="a-btn a-btn-small" href="?p=eft_bookings&amp;action=view&amp;id=<?= (int) $row['booking_id'] ?>">Booking</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
}

/* ================================================= 13. READINESS CHECKS */

/**
 * Extra dashboard checks for the booking platform. Each returns the same
 * shape the control panel already uses: label, ok, hint.
 */
function eft_admin_health(): array
{
    $checks = [];

    /* --- private storage --------------------------------------------- */
    $folders = ['proofs', 'tickets', 'receipts'];
    $missing = [];
    $unguarded = [];
    foreach ($folders as $folder) {
        $path = DATA_PATH . '/' . $folder;
        if (!is_dir($path) || !is_writable($path)) {
            $missing[] = $folder;
        }
        if (!is_file($path . '/.htaccess') || !is_file($path . '/web.config')) {
            $unguarded[] = $folder;
        }
    }

    $checks[] = [
        'label' => 'Private storage for proofs, tickets and receipts',
        'ok'    => $missing === [],
        'hint'  => 'The web server needs write permission on data/' . implode(', data/', $missing ?: ['proofs']) . '.',
    ];
    $checks[] = [
        'label' => 'Deny rules in place on the private folders',
        'ok'    => $unguarded === [],
        'hint'  => 'The .htaccess and web.config files are missing from data/' . implode(', data/', $unguarded ?: ['proofs']) . '.',
    ];

    /* --- one thing only a person can confirm --------------------------- */
    $checks[] = [
        'label' => 'Confirm by hand: the data folder is not served to the web',
        'ok'    => setting('bk_storage_verified') === '1',
        'hint'  => 'Open ' . bk_url('storage/proofs/') . ' in a browser. You should get an error page, not a file '
            . 'listing or a download. Once you have checked, tick "Private storage confirmed" in '
            . 'Site settings → Bookings & EFT.',
    ];

    /* --- the PDF generator -------------------------------------------- */
    $checks[] = [
        'label' => 'PDF generator available in the Core folder',
        'ok'    => bk_dompdf_available(),
        'hint'  => 'Core/dompdf is missing. Tickets and receipts cannot be produced without it.',
    ];

    /* --- banking details ---------------------------------------------- */
    $checks[] = [
        'label' => 'Banking details entered for EFT payments',
        'ok'    => trim(setting('bank_account_number')) !== '' && trim(setting('bank_account_name')) !== '',
        'hint'  => 'Fill in Site settings → Registration & payment. Clients cannot pay without them.',
    ];

    /* --- email --------------------------------------------------------- */
    $transport = strtolower(bk('bk_mail_transport', 'mail'));
    $smtpReady = $transport === 'smtp' && bk('bk_smtp_host') !== '';

    // Clients receive their tickets and receipts by email, so "it might work"
    // is not good enough here. Only SMTP counts as ready.
    $checks[] = [
        'label' => $smtpReady ? 'Email is sent over SMTP' : 'Set up SMTP for reliable email',
        'ok'    => $smtpReady,
        'hint'  => 'Booking confirmations, tickets and receipts all go out by email. PHP mail() is '
            . 'unavailable on a local machine and silently dropped by many hosts, so clients would '
            . 'never receive them. Fill in Site settings → Email delivery.',
    ];

    $failed = (int) (db_one("SELECT COUNT(*) AS c FROM email_log WHERE status = 'failed'")['c'] ?? 0);
    if ($failed > 0) {
        $checks[] = [
            'label' => 'Booking emails are being delivered',
            'ok'    => false,
            'hint'  => $failed . ' message(s) could not be sent. See the Email log, then check Site settings → Email delivery.',
        ];
    }

    /* --- is there anything to book? ------------------------------------ */
    $services = (int) (db_one('SELECT COUNT(*) AS c FROM services WHERE is_active = 1')['c'] ?? 0);
    $checks[] = [
        'label' => 'At least one service is open for booking',
        'ok'    => $services > 0,
        'hint'  => 'Add a service under Bookings → Services, then give it opening hours.',
    ];

    if ($services > 0 && setting('bk_capacity_reviewed') !== '1') {
        $checks[] = [
            'label' => 'Check how many of each service you can actually sell',
            'ok'    => false,
            'hint'  => 'The services built from your rate list were given a placeholder capacity. Open each one under '
                . 'Bookings, Services and set "Capacity per time slot" to the real number, then tick '
                . '"Capacity reviewed" in Site settings, Bookings & EFT.',
        ];
    }

    if ($services > 0) {
        $withHours = 0;
        foreach (bk_services() as $service) {
            if (bk_service_hours((int) $service['id'])) {
                $withHours++;
            }
        }
        $checks[] = [
            'label' => 'Every open service has opening hours',
            'ok'    => $withHours === $services,
            'hint'  => 'Some services have no bookable times. Add rules under Bookings → Opening hours.',
        ];
    }

    return $checks;
}

/**
 * The "build services from my rate list" button, shown above the Services
 * list. It only appears while there are bookable rates that have no service
 * yet, so it quietly disappears once everything has been imported.
 *
 * @param array $rows the services already in the list
 */
function eft_import_button(array $rows): void
{
    $rates = db_all('SELECT category FROM stalls WHERE is_active = 1 AND bookable = 1');
    if (!$rates) {
        return;
    }

    $existing = [];
    foreach (db_all('SELECT name FROM services') as $service) {
        $existing[mb_strtolower(trim((string) $service['name']))] = true;
    }

    $waiting = 0;
    foreach ($rates as $rate) {
        if (!isset($existing[mb_strtolower(trim((string) $rate['category']))])) {
            $waiting++;
        }
    }
    if ($waiting === 0) {
        return;
    }

    ?>
<form method="post" action="?p=services" class="a-inline-form" style="margin:0">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="eft_import_rates">
  <button class="a-btn" type="submit"
          data-confirm="Create <?= $waiting ?> bookable service(s) from your rate list? Prices and names are copied exactly; you can edit or delete any of them afterwards.">
    Build <?= $waiting ?> service(s) from my rate list
  </button>
</form>
    <?php
}

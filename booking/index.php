<?php
declare(strict_types=1);

/**
 * The public booking flow.
 *
 *   1  choose a service
 *   2  choose a date, then a time from the slots still open
 *   3  give the booking details (sign-in required from here on)
 *   4  review everything
 *   5  confirm — the booking is created and the EFT instructions are shown
 *
 * Availability is recalculated on every step, and again inside the write
 * transaction, so a slot that fills up while somebody is typing is caught.
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/client-ui.php';

start_session();
bk_require_platform();

$step      = (string) ($_GET['step'] ?? '');
$serviceId = (int) ($_GET['service'] ?? ($_POST['service_id'] ?? 0));
$date      = trim((string) ($_GET['date'] ?? ($_POST['date'] ?? '')));
$time      = trim((string) ($_GET['time'] ?? ($_POST['time'] ?? '')));

$service = $serviceId > 0 ? bk_service($serviceId) : null;
if ($service && (int) $service['is_active'] !== 1) {
    $service = null;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}
if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    $time = '';
}

/**
 * A slot chosen a few minutes ago may have gone. When that happens we drop
 * back to the date step and say so, rather than failing at the last moment.
 */
$slotGone = false;
if ($service && $date !== '' && $time !== '' && !bk_slot_open($service, $date, $time)) {
    $slotGone = true;
    $time = '';
}

/* ---------------------------------------------------- POST: review/confirm */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        bk_flash('error', 'Your session expired before the form was sent. Please check your details and try again.');
        redirect('booking/');
    }

    $client = require_client();
    if (client_needs_verification($client)) {
        bk_flash('warn', 'Please confirm your email address before booking. We have sent you a link.');
        redirect('auth/verify-email.php');
    }
    if (!$service || $date === '' || $time === '') {
        bk_flash('error', 'Please choose a service, a date and a time.');
        redirect('booking/');
    }

    $input = [
        'service_id'    => (int) $service['id'],
        'date'          => $date,
        'time'          => $time,
        'guests'        => (int) ($_POST['guests'] ?? 1),
        'contact_name'  => trim((string) ($_POST['contact_name'] ?? '')),
        'contact_email' => strtolower(trim((string) ($_POST['contact_email'] ?? ''))),
        'contact_phone' => trim((string) ($_POST['contact_phone'] ?? '')),
        'company'       => trim((string) ($_POST['company'] ?? '')),
        'extra_details' => trim((string) ($_POST['extra_details'] ?? '')),
        'client_notes'  => trim((string) ($_POST['client_notes'] ?? '')),
        'guest_names'   => array_map('strval', (array) ($_POST['guest_names'] ?? [])),
    ];

    if ($step === 'confirm') {
        if (!isset($_POST['agreed'])) {
            bk_flash('error', 'Please accept the booking terms before confirming.');
            $step = 'review';
        } elseif (($trap = bot_trap_problem(4)) !== null) {
            // A form filled faster than any person could, or with the hidden
            // field completed. Logged, then answered exactly like a success so
            // a script learns nothing from the reply.
            bot_trap_log('booking', $trap);
            bk_flash('ok', 'Thank you. Your booking request has been received.');
            redirect('booking/');
        } elseif (!rate_limit('booknew:' . client_ip(), 12, 3600)) {
            bk_flash('error', 'Too many bookings have been started from this connection. Please try again later.');
            redirect('booking/');
        } elseif (bot_trap_text_problem($input['extra_details'] . ' ' . $input['client_notes']) !== null) {
            bot_trap_log('booking', 'advertising text');
            bk_flash('ok', 'Thank you. Your booking request has been received.');
            redirect('booking/');
        } else {
            $result = bk_create_booking($input, $client);
            if (!$result['ok']) {
                bk_flash('error', $result['message']);
                redirect('booking/?service=' . (int) $service['id'] . '&date=' . rawurlencode($date));
            }

            bk_flash('ok', 'Booking ' . $result['booking']['reference'] . ' created. Please pay by EFT to confirm it.');
            redirect('booking/pay.php?id=' . (int) $result['booking']['id']);
        }
    } else {
        $step = 'review';
    }
}

/* ------------------------------------------------------------------ render */

$pageMeta = page_meta('booking');
$page = site_head('booking');
site_header('booking');
page_hero($pageMeta);
?>

<section class="section">
  <div class="shell">
    <?php bk_flash_render(); ?>

    <?php if ($slotGone): ?>
      <div class="alert alert-warn" role="status">
        That time is no longer available — somebody booked it while you were choosing. Please pick another slot below.
      </div>
    <?php endif; ?>

    <?php
    $stepIndex = 1;
    if ($service) { $stepIndex = 2; }
    if ($service && $date !== '' && $time !== '') { $stepIndex = 3; }
    if ($step === 'review') { $stepIndex = 4; }
    ?>
    <ol class="bk-steps">
      <?php foreach (['Service', 'Date & time', 'Your details', 'Review'] as $i => $label): ?>
        <li class="<?= $i + 1 < $stepIndex ? 'is-done' : ($i + 1 === $stepIndex ? 'is-now' : '') ?>">
          <span><?= $i + 1 ?></span><?= e($label) ?>
        </li>
      <?php endforeach; ?>
    </ol>

<?php
/* ============================================================== STEP 1 */
if (!$service):
    $services = bk_services();
    ?>
    <?php if (!$services): ?>
      <div class="bk-panel bk-panel-centred">
        <h2 class="bk-panel-title">No services are open for booking yet</h2>
        <p>Our online booking diary is being set up. Please contact us on
          <a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a>
          <?php if (setting('phone_1') !== ''): ?> or <?= e(setting('phone_1')) ?><?php endif; ?>
          and we will book you in directly.</p>
      </div>
    <?php else: ?>
      <div class="bk-service-grid">
        <?php foreach ($services as $row):
            $open = bk_open_dates($row, 1);
            $next = $open ? $open[0]['date'] : '';
            ?>
          <article class="bk-service-card">
            <?php if (trim((string) $row['image']) !== ''): ?>
              <div class="bk-service-media"><img src="<?= e(site_image((string) $row['image'], 420)) ?>" alt="" loading="lazy"></div>
            <?php endif; ?>
            <div class="bk-service-body">
              <h3><?= e((string) $row['name']) ?></h3>
              <?php if (trim((string) $row['summary']) !== ''): ?>
                <p class="bk-service-summary"><?= e((string) $row['summary']) ?></p>
              <?php endif; ?>

              <ul class="bk-service-meta">
                <li><strong><?= e(bk_money((float) $row['price'])) ?></strong>
                    <?= ($row['price_mode'] ?? 'booking') === 'person' ? 'per person' : 'per booking' ?></li>
                <li><?= e(bk_duration_label((int) $row['duration_minutes'])) ?></li>
                <?php if ((int) $row['max_guests'] > 1): ?>
                  <li>Up to <?= (int) $row['max_guests'] ?> guests</li>
                <?php endif; ?>
                <?php if (trim((string) $row['location']) !== ''): ?>
                  <li><?= e((string) $row['location']) ?></li>
                <?php endif; ?>
              </ul>

              <p class="bk-service-next">
                <?php if ($next !== ''): ?>
                  Next available: <strong><?= e(bk_date_long($next)) ?></strong>
                <?php else: ?>
                  <span class="bk-muted">No dates open at the moment</span>
                <?php endif; ?>
              </p>

              <a class="btn <?= $next !== '' ? 'btn-primary' : 'btn-ghost' ?>"
                 href="<?= e(url('booking/')) ?>?service=<?= (int) $row['id'] ?>">
                <?= $next !== '' ? 'Choose a date' : 'See availability' ?> <span aria-hidden="true">→</span>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

<?php
/* ============================================================== STEP 4 */
elseif ($step === 'review'):
    $client = client_user();
    $guests = max((int) $service['min_guests'], min((int) $service['max_guests'], (int) ($_POST['guests'] ?? 1)));
    $price  = bk_service_price($service, $guests);
    $names  = array_values(array_filter(array_map('trim', (array) ($_POST['guest_names'] ?? []))));
    $post   = static fn (string $key): string => e(trim((string) ($_POST[$key] ?? '')));
    ?>
    <div class="bk-review">
      <div class="bk-panel">
        <h2 class="bk-panel-title">Please check your booking</h2>
        <p class="bk-panel-lead">Nothing is booked until you press confirm. After that you will be shown how to pay by EFT.</p>

        <?php
        $facts = [
            ['Service', (string) $service['name']],
            ['Date', bk_date_long($date)],
            ['Time', $time . ' – ' . bk_slot_end($service, $time)],
            ['Guests / participants', (string) $guests],
            ['Location', (string) $service['location']],
            ['Booked for', trim((string) ($_POST['contact_name'] ?? ''))],
            ['Email', trim((string) ($_POST['contact_email'] ?? ''))],
            ['Phone', trim((string) ($_POST['contact_phone'] ?? ''))],
            ['Company', trim((string) ($_POST['company'] ?? ''))],
        ];
        if (trim((string) $service['extra_field_label']) !== '') {
            $facts[] = [(string) $service['extra_field_label'], trim((string) ($_POST['extra_details'] ?? ''))];
        }
        if ($names) {
            $facts[] = ['Guest names', implode("\n", $names)];
        }
        if (trim((string) ($_POST['client_notes'] ?? '')) !== '') {
            $facts[] = ['Your notes', trim((string) ($_POST['client_notes'] ?? ''))];
        }
        bk_facts_render($facts);
        ?>

        <div class="bk-total">
          <div><small>Amount payable by EFT</small><strong><?= e(bk_money($price)) ?></strong></div>
          <p><?= ($service['price_mode'] ?? 'booking') === 'person'
                ? e(bk_money((float) $service['price'])) . ' per person × ' . $guests
                : 'Flat rate for this booking' ?></p>
        </div>

        <form method="post" action="<?= e(url('booking/?step=confirm')) ?>" class="bk-form">
          <?= csrf_field() ?>
          <?= bot_trap_relay() ?>
          <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
          <input type="hidden" name="date" value="<?= e($date) ?>">
          <input type="hidden" name="time" value="<?= e($time) ?>">
          <input type="hidden" name="guests" value="<?= $guests ?>">
          <?php foreach (['contact_name', 'contact_email', 'contact_phone', 'company', 'extra_details', 'client_notes'] as $field): ?>
            <input type="hidden" name="<?= e($field) ?>" value="<?= $post($field) ?>">
          <?php endforeach; ?>
          <?php foreach ($names as $name): ?>
            <input type="hidden" name="guest_names[]" value="<?= e($name) ?>">
          <?php endforeach; ?>

          <?php if (trim(bk('bk_terms')) !== ''): ?>
            <div class="bk-terms">
              <h3>Booking terms</h3>
              <ul>
                <?php foreach (lines(bk('bk_terms')) as $line): ?><li><?= e($line) ?></li><?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <label class="consent">
            <input type="checkbox" name="agreed" value="1" required>
            <span>I have checked these details and accept the booking terms. I understand my booking is only confirmed once my EFT payment has been verified.</span>
          </label>

          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Confirm this booking <span aria-hidden="true">→</span></button>
            <p><a class="text-link" href="<?= e(url('booking/')) ?>?service=<?= (int) $service['id'] ?>&amp;date=<?= e($date) ?>&amp;time=<?= e($time) ?>">← Change the details</a></p>
          </div>
        </form>
      </div>

      <aside class="bk-aside">
        <h3>How payment works</h3>
        <ol class="bk-mini-steps">
          <li>You confirm the booking and get a reference straight away.</li>
          <li>You transfer the amount by EFT, using that reference.</li>
          <li>You upload your proof of payment.</li>
          <li>Our team verifies it and your booking is confirmed.</li>
          <li>Your ticket and receipt arrive by email.</li>
        </ol>
        <p class="bk-muted">We do not take card payments online. EFT only.</p>
      </aside>
    </div>

<?php
/* ============================================================== STEP 3 */
elseif ($date !== '' && $time !== ''):
    $client = client_user();
    if (!$client) {
        $_SESSION['client_after_login'] = 'booking/?service=' . (int) $service['id'] . '&date=' . rawurlencode($date) . '&time=' . rawurlencode($time);
    }
    $minGuests = max(1, (int) $service['min_guests']);
    $maxGuests = max($minGuests, (int) $service['max_guests']);
    ?>
    <div class="bk-review">
      <div class="bk-panel">
        <h2 class="bk-panel-title">Your details</h2>
        <p class="bk-panel-lead">
          <strong><?= e((string) $service['name']) ?></strong> ·
          <?= e(bk_date_long($date)) ?> at <?= e($time) ?>
          &nbsp;<a class="text-link" href="<?= e(url('booking/')) ?>?service=<?= (int) $service['id'] ?>&amp;date=<?= e($date) ?>">change</a>
        </p>

        <?php if (!$client): ?>
          <div class="alert alert-warn" role="status">
            Please <a href="<?= e(url('auth/login.php')) ?>">sign in</a> or <a href="<?= e(url('auth/register.php')) ?>">create an account</a> to finish this booking.
            We will bring you straight back here.
          </div>
          <p>
            <a class="btn btn-primary" href="<?= e(url('auth/login.php')) ?>">Sign in <span aria-hidden="true">→</span></a>
            <a class="btn btn-ghost" href="<?= e(url('auth/register.php')) ?>">Create an account</a>
          </p>
        <?php elseif (client_needs_verification($client)): ?>
          <div class="alert alert-warn" role="status">
            Please confirm your email address before booking — check your inbox for the link we sent when you registered.
          </div>
          <p><a class="btn btn-primary" href="<?= e(url('auth/verify-email.php')) ?>">Resend the confirmation link</a></p>
        <?php else: ?>

        <form method="post" action="<?= e(url('booking/?step=review')) ?>" class="bk-form" novalidate>
          <?= csrf_field() ?>
          <?= bot_trap_fields() ?>
          <input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>">
          <input type="hidden" name="date" value="<?= e($date) ?>">
          <input type="hidden" name="time" value="<?= e($time) ?>">

          <div class="field-row">
            <label class="field"><span>Booking name *</span>
              <input type="text" name="contact_name" required maxlength="190" autocomplete="name"
                     value="<?= e((string) $client['full_name']) ?>">
            </label>
            <label class="field"><span>Email *</span>
              <input type="email" name="contact_email" required maxlength="190" autocomplete="email"
                     value="<?= e((string) $client['email']) ?>">
            </label>
          </div>

          <div class="field-row">
            <label class="field"><span>Phone / WhatsApp *</span>
              <input type="tel" name="contact_phone" required maxlength="60" autocomplete="tel"
                     value="<?= e((string) $client['phone']) ?>">
            </label>
            <label class="field"><span>Company or organisation</span>
              <input type="text" name="company" maxlength="190" autocomplete="organization"
                     value="<?= e((string) $client['company']) ?>">
            </label>
          </div>

          <?php if ($maxGuests > 1): ?>
            <label class="field bk-field-narrow"><span>Number of people *</span>
              <select name="guests" required>
                <?php for ($n = $minGuests; $n <= $maxGuests; $n++): ?>
                  <option value="<?= $n ?>"><?= $n ?><?= $n === 1 ? ' person' : ' people' ?></option>
                <?php endfor; ?>
              </select>
              <span class="field-hint">
                <?= ($service['price_mode'] ?? 'booking') === 'person'
                    ? e(bk_money((float) $service['price'])) . ' per person.'
                    : 'Flat rate of ' . e(bk_money((float) $service['price'])) . ' for the booking.' ?>
              </span>
            </label>

            <?php if ((int) $service['collect_guest_names'] === 1): ?>
              <fieldset class="bk-guest-names">
                <legend>Names of everyone attending</legend>
                <p class="field-hint">Optional, but it helps us print name badges and check people in quickly.</p>
                <div class="bk-guest-list">
                  <?php for ($n = 1; $n <= $maxGuests; $n++): ?>
                    <input type="text" name="guest_names[]" maxlength="190" placeholder="Guest <?= $n ?>">
                  <?php endfor; ?>
                </div>
              </fieldset>
            <?php endif; ?>
          <?php else: ?>
            <input type="hidden" name="guests" value="<?= $minGuests ?>">
          <?php endif; ?>

          <?php if (trim((string) $service['extra_field_label']) !== ''): ?>
            <label class="field"><span><?= e((string) $service['extra_field_label']) ?><?= (int) $service['extra_field_required'] === 1 ? ' *' : '' ?></span>
              <textarea name="extra_details" rows="3" maxlength="1000"<?= (int) $service['extra_field_required'] === 1 ? ' required' : '' ?>></textarea>
              <?php if (trim((string) $service['extra_field_help']) !== ''): ?>
                <span class="field-hint"><?= e((string) $service['extra_field_help']) ?></span>
              <?php endif; ?>
            </label>
          <?php endif; ?>

          <label class="field"><span>Anything else we should know?</span>
            <textarea name="client_notes" rows="3" maxlength="1000" placeholder="Access needs, dietary requirements, anything we should plan for"></textarea>
          </label>

          <div class="bk-form-actions">
            <button class="btn btn-primary" type="submit">Review my booking <span aria-hidden="true">→</span></button>
          </div>
        </form>

        <?php endif; ?>
      </div>

      <aside class="bk-aside">
        <h3><?= e((string) $service['name']) ?></h3>
        <?php if (trim((string) $service['description']) !== ''): ?>
          <div class="bk-aside-text"><?= rich((string) $service['description']) ?></div>
        <?php endif; ?>
        <?php bk_facts_render([
            ['Date', bk_date_long($date)],
            ['Time', $time . ' – ' . bk_slot_end($service, $time)],
            ['Duration', bk_duration_label((int) $service['duration_minutes'])],
            ['Location', (string) $service['location']],
            ['Price', bk_money((float) $service['price']) . (($service['price_mode'] ?? 'booking') === 'person' ? ' per person' : '')],
        ], 'detail-list bk-aside-facts'); ?>
      </aside>
    </div>

<?php
/* ============================================================== STEP 2 */
else:
    if ($date === '') {
        $openDates = bk_open_dates($service, 45);
        $date = $openDates ? $openDates[0]['date'] : bk_now()->format('Y-m-d');
    } else {
        $openDates = bk_open_dates($service, 45);
    }
    $slots = bk_slots($service, $date);
    $blockReason = bk_block_reason((int) $service['id'], $date);
    ?>
    <div class="bk-review">
      <div class="bk-panel">
        <h2 class="bk-panel-title">Choose a date and time</h2>
        <p class="bk-panel-lead">
          <strong><?= e((string) $service['name']) ?></strong>
          &nbsp;<a class="text-link" href="<?= e(url('booking/')) ?>">change service</a>
        </p>

        <?php if (!$openDates): ?>
          <div class="alert alert-warn" role="status">
            There are no open dates for this service at the moment. Please
            <a href="<?= e(url('contact.php')) ?>">contact us</a> and we will help you directly.
          </div>
        <?php else: ?>
          <div class="bk-dates" role="list">
            <?php
            $currentMonth = '';
            foreach ($openDates as $entry):
                $month = date('F Y', strtotime($entry['date']));
                if ($month !== $currentMonth):
                    $currentMonth = $month; ?>
                    <p class="bk-dates-month"><?= e($month) ?></p>
                <?php endif; ?>
                <a role="listitem"
                   class="bk-date<?= $entry['date'] === $date ? ' is-on' : '' ?>"
                   href="<?= e(url('booking/')) ?>?service=<?= (int) $service['id'] ?>&amp;date=<?= e($entry['date']) ?>">
                  <span class="bk-date-day"><?= e(date('D', strtotime($entry['date']))) ?></span>
                  <strong><?= e(date('j', strtotime($entry['date']))) ?></strong>
                  <span class="bk-date-open"><?= (int) $entry['open'] ?> open</span>
                </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="bk-slots-head">
          <h3><?= e(bk_date_long($date)) ?></h3>
          <?php $relative = bk_relative_day($date); ?>
          <?php if ($relative !== ''): ?><span class="bk-muted"><?= e($relative) ?></span><?php endif; ?>
        </div>

        <?php if ($blockReason !== ''): ?>
          <div class="alert alert-warn" role="status">We are closed on this date<?= $blockReason !== '' ? ': ' . e($blockReason) : '' ?>.</div>
        <?php elseif (!$slots): ?>
          <div class="alert alert-warn" role="status">We do not take bookings for this service on that day. Please pick another date above.</div>
        <?php else: ?>
          <div class="bk-slots">
            <?php foreach ($slots as $slot): ?>
              <?php if ($slot['available']): ?>
                <a class="bk-slot" href="<?= e(url('booking/')) ?>?service=<?= (int) $service['id'] ?>&amp;date=<?= e($date) ?>&amp;time=<?= e($slot['start']) ?>">
                  <strong><?= e($slot['start']) ?></strong>
                  <span><?= (int) $slot['free'] ?> left</span>
                </a>
              <?php else: ?>
                <span class="bk-slot is-off" aria-disabled="true">
                  <strong><?= e($slot['start']) ?></strong>
                  <span><?= e($slot['reason']) ?></span>
                </span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <aside class="bk-aside">
        <h3><?= e((string) $service['name']) ?></h3>
        <?php if (trim((string) $service['description']) !== ''): ?>
          <div class="bk-aside-text"><?= rich((string) $service['description']) ?></div>
        <?php elseif (trim((string) $service['summary']) !== ''): ?>
          <div class="bk-aside-text"><p><?= e((string) $service['summary']) ?></p></div>
        <?php endif; ?>
        <?php bk_facts_render(array_filter([
            ['Price', bk_money((float) $service['price']) . (($service['price_mode'] ?? 'booking') === 'person' ? ' per person' : '')],
            ['Duration', bk_duration_label((int) $service['duration_minutes'])],
            (int) $service['max_guests'] > 1 ? ['Guests', $service['min_guests'] . ' to ' . $service['max_guests'] . ' people'] : ['', ''],
            ['Location', (string) $service['location']],
            ['Booking notice', (int) $service['lead_time_hours'] . ' hours'],
        ], static fn (array $r): bool => $r[0] !== ''), 'detail-list bk-aside-facts'); ?>
      </aside>
    </div>
<?php endif; ?>

  </div>
</section>

<?php site_footer(); ?>

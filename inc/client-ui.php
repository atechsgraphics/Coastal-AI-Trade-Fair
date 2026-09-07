<?php
declare(strict_types=1);

/**
 * EFT booking platform — shared front-end pieces.
 * ---------------------------------------------------------------------------
 * Small helpers the public booking screens and the client dashboard share:
 * page chrome built on the site's existing layout, flash messages, status
 * pills and form repopulation. No new visual language is introduced; every
 * component uses the classes already defined in assets/site.css.
 */

require_once __DIR__ . '/eft.php';

/* ============================================================== 1. CHROME */

/**
 * Stop the request politely when the booking platform is switched off.
 */
function bk_require_platform(): void
{
    if (bk_enabled()) {
        return;
    }

    $page = site_head('booking', 'Online booking');
    site_header('booking');
    bk_hero('ONLINE BOOKINGS', 'Online booking is {closed.}', '');
    ?>
    <section class="section">
      <div class="shell">
        <div class="bk-panel bk-panel-centred">
          <p class="lede">Online booking is not available at the moment.</p>
          <p>Please contact us on <a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a><?php
            if (setting('phone_1') !== '') { echo ' or ' . e(setting('phone_1')); } ?> and we will help you directly.</p>
          <p><a class="btn btn-primary" href="index.php">Back to the website</a></p>
        </div>
      </div>
    </section>
    <?php
    site_footer();
    exit;
}

/**
 * The standard inner-page hero, for screens that have no row in the pages
 * table. It renders exactly the same markup as page_hero().
 */
function bk_hero(string $label, string $title, string $intro): void
{
    page_hero([
        'hero_label' => $label,
        'hero_title' => $title,
        'hero_intro' => $intro,
        'title'      => $title,
    ]);
}

/* =============================================================== 2. FLASH */

/** Remember a message across a redirect. */
function bk_flash(string $type, string $message): void
{
    start_session();
    $_SESSION['bk_flash'][] = ['type' => $type, 'message' => $message];
}

/** Take every stored message and clear the queue. */
function bk_flash_take(): array
{
    start_session();
    $items = $_SESSION['bk_flash'] ?? [];
    unset($_SESSION['bk_flash']);
    return is_array($items) ? $items : [];
}

/** Print any waiting messages. */
function bk_flash_render(): void
{
    foreach (bk_flash_take() as $flash) {
        $class = $flash['type'] === 'ok' ? 'alert-ok' : ($flash['type'] === 'warn' ? 'alert-warn' : 'alert-error');
        echo '<div class="alert ' . $class . '" role="alert">' . e((string) $flash['message']) . '</div>';
    }
}

/* ============================================================ 3. FORM STATE */

/** Keep what somebody typed when a form comes back with an error. */
function bk_keep_input(array $input): void
{
    start_session();
    $_SESSION['bk_old'] = $input;
}

function bk_old_take(): array
{
    start_session();
    $old = $_SESSION['bk_old'] ?? [];
    unset($_SESSION['bk_old']);
    return is_array($old) ? $old : [];
}

/** Escaped value of a remembered field. */
function bk_old(array $old, string $key, string $default = ''): string
{
    $value = $old[$key] ?? $default;
    return e(is_scalar($value) ? (string) $value : '');
}

/* ============================================================= 4. DISPLAY */

/** The coloured status chip used everywhere a booking is listed. */
function bk_pill(string $status): string
{
    return '<span class="bk-pill bk-pill-' . e(bk_status_tone($status)) . '">' . e(bk_status_label($status)) . '</span>';
}

/** A short line describing where a booking has got to. */
function bk_progress_note(array $booking): string
{
    switch ((string) $booking['status']) {
        case 'awaiting_eft':
            $deadline = bk_deadline_text($booking);
            return $deadline !== ''
                ? 'Pay by EFT and upload your proof of payment before ' . $deadline . '.'
                : 'Pay by EFT and upload your proof of payment to confirm this booking.';
        case 'proof_submitted':
            return 'We have your proof of payment. Our team will verify it against our bank account.';
        case 'under_review':
            return 'Your payment is being checked by our team right now.';
        case 'payment_confirmed':
            return 'Your payment has been verified. Your confirmation is on its way.';
        case 'confirmed':
            return 'Confirmed. Your ticket and receipt are ready to download.';
        case 'declined':
            return 'We could not accept the proof of payment you sent. Please upload a corrected one.';
        case 'cancelled':
            return 'This booking has been cancelled.';
        case 'completed':
            return 'This booking is complete. Thank you.';
        case 'refunded':
            return 'This booking has been refunded.';
    }
    return '';
}

/**
 * The five steps of the EFT journey, with the current position marked.
 *
 * @return array<int, array{label: string, state: string}>
 */
function bk_journey(array $booking): array
{
    $order = ['awaiting_eft' => 0, 'proof_submitted' => 1, 'under_review' => 2, 'payment_confirmed' => 3, 'confirmed' => 4, 'completed' => 4];
    $status = (string) $booking['status'];

    $steps = [
        'Booking placed',
        'Proof uploaded',
        'Payment reviewed',
        'Payment confirmed',
        'Booking confirmed',
    ];

    if (in_array($status, ['cancelled', 'declined', 'refunded'], true)) {
        $current = $status === 'declined' ? 1 : -1;
    } else {
        $current = $order[$status] ?? 0;
    }

    $out = [];
    foreach ($steps as $index => $label) {
        $state = 'todo';
        if ($current >= 0 && $index < $current) {
            $state = 'done';
        } elseif ($current >= 0 && $index === $current) {
            $state = $status === 'declined' ? 'stopped' : 'now';
        }
        if ($current < 0) {
            $state = 'stopped';
        }
        $out[] = ['label' => $label, 'state' => $state];
    }

    return $out;
}

/** Render the journey strip. */
function bk_journey_render(array $booking): void
{
    echo '<ol class="bk-journey">';
    foreach (bk_journey($booking) as $step) {
        echo '<li class="is-' . e($step['state']) . '"><span></span>' . e($step['label']) . '</li>';
    }
    echo '</ol>';
}

/** A definition list of label/value pairs. */
function bk_facts_render(array $facts, string $class = 'detail-list'): void
{
    echo '<dl class="' . e($class) . '">';
    foreach ($facts as $fact) {
        [$label, $value] = $fact;
        if (trim((string) $value) === '') {
            continue;
        }
        echo '<div><dt>' . e((string) $label) . '</dt><dd>' . nl2br(e((string) $value)) . '</dd></div>';
    }
    echo '</dl>';
}

/** A friendly "3 days from now" style hint. */
function bk_relative_day(string $date): string
{
    $today = bk_now()->format('Y-m-d');
    if ($date === $today) {
        return 'Today';
    }
    if ($date === bk_now()->modify('+1 day')->format('Y-m-d')) {
        return 'Tomorrow';
    }

    $days = (int) floor((strtotime($date) - strtotime($today)) / 86400);
    if ($days > 0 && $days < 7) {
        return 'In ' . $days . ' days';
    }
    if ($days < 0) {
        return abs($days) === 1 ? 'Yesterday' : abs($days) . ' days ago';
    }
    return '';
}

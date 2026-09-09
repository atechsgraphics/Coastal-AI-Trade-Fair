<?php
declare(strict_types=1);

/**
 * Coastal AI Summit & SME Trade Fair — control panel.
 * A single entry point; ?p= chooses the screen. All editing behaviour lives in
 * app/admin-lib.php so this file stays a thin router plus the views.
 */

require __DIR__ . '/../app/bootstrap.php';
require_installed();
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/admin-lib.php';
require __DIR__ . '/../app/booking.php';
require __DIR__ . '/../app/eft-actions.php';   // pulls in eft-admin.php and the platform

start_session();

$resources = admin_resources();
$route     = (string) ($_GET['p'] ?? 'dashboard');
$action    = (string) ($_GET['action'] ?? '');
$id        = (int) ($_GET['id'] ?? 0);

/* ============================================================= LOGIN/OUT */

if ($route === 'logout') {
    admin_logout();
    redirect('admin/?p=login');
}

if ($route === 'login') {
    if (admin_user()) {
        redirect('admin/');
    }
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!csrf_check()) {
            $error = 'Your session expired. Please try again.';
        } elseif (!rate_limit('login:' . client_ip(), 8, 900)) {
            $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
        } elseif (!admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $error = 'That username or password is not correct.';
            usleep(400000);
        } else {
            redirect('admin/');
        }
    }
    admin_login_view($error);
    exit;
}

$user = require_admin();

/* ================================================ FIRST-RUN PASSWORD GATE */
/* An account whose password was handed to its owner rather than chosen by
   them is flagged in the database. Until they replace it, the control panel
   shows one screen and nothing else — signing out is the only other way past,
   so a password that has travelled through a message or an email cannot quietly
   stay in use. */

if (!empty($user['must_change_password'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'first_password') {
        if (!csrf_check()) {
            admin_flash('error', 'Your session expired. Please try again.');
            redirect('admin/?p=first-password');
        }

        $current = (string) ($_POST['current'] ?? '');
        $new     = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        $problem = client_password_problem($new);

        if (!password_verify($current, $user['password_hash'])) {
            admin_flash('error', 'The password you signed in with is not correct.');
        } elseif ($problem !== null) {
            admin_flash('error', $problem);
        } elseif ($new !== $confirm) {
            admin_flash('error', 'The two new passwords do not match.');
        } elseif (password_verify($new, $user['password_hash'])) {
            admin_flash('error', 'Please choose a password you have not used here before.');
        } else {
            db_run(
                'UPDATE users SET password_hash = :h, must_change_password = 0 WHERE id = :id',
                [':h' => password_hash($new, PASSWORD_DEFAULT), ':id' => (int) $user['id']]
            );
            admin_log('set their own password');
            admin_flash('ok', 'Your password has been changed. Welcome to the control panel.');
            redirect('admin/');
        }
        redirect('admin/?p=first-password');
    }

    admin_first_password_view($user);
    exit;
}

/* ========================================================= POST HANDLERS */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check()) {
        admin_flash('error', 'Your session expired. Please sign in again.');
        redirect('admin/?p=login');
    }

    $do = (string) ($_POST['do'] ?? '');

    /* ---- EFT booking platform: approvals, declines, edits, resends ---- */
    if (str_starts_with($do, 'eft_')) {
        eft_admin_handle_post($user);
    }

    /* ---- save a resource record ---- */
    if ($do === 'save' && isset($resources[$route])) {
        $definition = $resources[$route];
        if ($id === 0 && !empty($definition['no_create'])) {
            admin_flash('error', 'New items cannot be added to that list.');
            redirect('admin/?p=' . urlencode($route));
        }
        $savedId = admin_save($route, $definition, $id);
        admin_flash('ok', ucfirst($definition['singular']) . ' saved.');
        redirect('admin/?p=' . urlencode($route) . '&action=edit&id=' . $savedId);
    }

    /* ---- save a settings tab ---- */
    if ($do === 'settings') {
        $groups = admin_setting_groups();
        $key = (string) ($_POST['group'] ?? 'identity');
        if (isset($groups[$key])) {
            admin_settings_save($groups[$key]);
            admin_flash('ok', $groups[$key]['label'] . ' settings saved.');
        }
        redirect('admin/?p=settings&g=' . urlencode($key));
    }

    /* ---- booking: status, notes and applicant corrections ---- */
    if ($do === 'booking_save') {
        $bookingId = (int) ($_POST['id'] ?? 0);
        $booking = booking_find($bookingId);
        if (!$booking) {
            admin_flash('error', 'That booking no longer exists.');
            redirect('admin/?p=bookings');
        }

        $newStatus = (string) ($_POST['status'] ?? $booking['status']);
        if (!array_key_exists($newStatus, booking_statuses())) {
            $newStatus = $booking['status'];
        }

        db_run(
            'UPDATE bookings SET company = :company, contact_person = :contact, phone = :phone, email = :email,
                    product = :product, special_requirements = :special, status = :status,
                    admin_notes = :notes, updated_at = :updated
             WHERE id = :id',
            [
                ':company' => trim((string) ($_POST['company'] ?? $booking['company'])),
                ':contact' => trim((string) ($_POST['contact_person'] ?? $booking['contact_person'])),
                ':phone'   => trim((string) ($_POST['phone'] ?? $booking['phone'])),
                ':email'   => trim((string) ($_POST['email'] ?? $booking['email'])),
                ':product' => trim((string) ($_POST['product'] ?? $booking['product'])),
                ':special' => trim((string) ($_POST['special_requirements'] ?? $booking['special_requirements'])),
                ':status'  => $newStatus,
                ':notes'   => trim((string) ($_POST['admin_notes'] ?? '')),
                ':updated' => date('Y-m-d H:i:s'),
                ':id'      => $bookingId,
            ]
        );

        admin_log('updated booking', $booking['reference'] . ' → ' . $newStatus);
        admin_flash('ok', 'Booking ' . $booking['reference'] . ' saved.');
        redirect('admin/?p=bookings&action=view&id=' . $bookingId);
    }

    /* ---- enquiry status ---- */
    if ($do === 'enquiry_status') {
        $status = (string) ($_POST['status'] ?? 'new');
        if (in_array($status, ['new', 'read', 'replied', 'archived'], true)) {
            db_run('UPDATE enquiries SET status = :s WHERE id = :id', [':s' => $status, ':id' => (int) $_POST['id']]);
            admin_flash('ok', 'Enquiry marked as ' . $status . '.');
        }
        redirect('admin/?p=enquiries');
    }

    /* ---- users ---- */
    if ($do === 'user_save') {
        require_owner();
        $uid      = (int) ($_POST['id'] ?? 0);
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $name     = trim((string) ($_POST['name'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $role     = ($_POST['role'] ?? 'editor') === 'admin' ? 'admin' : 'editor';
        $password = (string) ($_POST['password'] ?? '');

        if ($username === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
            admin_flash('error', 'Usernames need 3–40 characters: letters, numbers, dot, dash or underscore.');
            redirect('admin/?p=users');
        }
        $clash = db_one('SELECT id FROM users WHERE username = :u AND id != :id', [':u' => $username, ':id' => $uid]);
        if ($clash) {
            admin_flash('error', 'That username is already taken.');
            redirect('admin/?p=users');
        }

        if ($uid > 0) {
            db_run('UPDATE users SET username = ?, name = ?, email = ?, role = ? WHERE id = ?', [$username, $name, $email, $role, $uid]);
            if ($password !== '') {
                if (strlen($password) < 8) {
                    admin_flash('error', 'Passwords must be at least 8 characters. The other details were saved.');
                    redirect('admin/?p=users');
                }
                db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $uid]);
            }
            admin_flash('ok', 'User updated.');
        } else {
            if (strlen($password) < 8) {
                admin_flash('error', 'Please give the new user a password of at least 8 characters.');
                redirect('admin/?p=users');
            }
            db_run(
                'INSERT INTO users (username, name, email, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$username, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]
            );
            admin_flash('ok', 'User created.');
        }
        admin_log('saved user', $username);
        redirect('admin/?p=users');
    }

    /* ---- own password ---- */
    if ($do === 'account') {
        $current = (string) ($_POST['current'] ?? '');
        $new     = (string) ($_POST['new'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!password_verify($current, $user['password_hash'])) {
            admin_flash('error', 'Your current password is not correct.');
        } elseif (strlen($new) < 8) {
            admin_flash('error', 'The new password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            admin_flash('error', 'The two new passwords do not match.');
        } else {
            db_run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            admin_log('changed own password');
            admin_flash('ok', 'Your password has been changed.');
        }
        redirect('admin/?p=account');
    }

    /* ---- media upload ---- */
    if ($do === 'media_upload') {
        $saved = admin_upload('upload__file');
        admin_flash($saved ? 'ok' : 'error', $saved ? 'Uploaded ' . $saved : 'Nothing was uploaded.');
        redirect('admin/?p=media');
    }
}

/* ========================================================== GET ACTIONS */

/* EFT booking platform: CSV exports, streamed rather than rendered. */
if (eft_admin_handle_get($route, $action)) {
    exit;
}

if ($action !== '' && $id > 0 && isset($resources[$route])) {
    if ($action === 'delete') {
        if (!empty($resources[$route]['no_delete'])) {
            admin_flash('error', 'Items in that list cannot be deleted.');
        } elseif (!hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) {
            admin_flash('error', 'That delete link has expired. Please try again.');
        } else {
            admin_delete($route, $id);
            admin_flash('ok', 'Deleted.');
        }
        redirect('admin/?p=' . urlencode($route));
    }
    if ($action === 'up' || $action === 'down') {
        admin_reorder($route, $id, $action);
        redirect('admin/?p=' . urlencode($route));
    }
    if ($action === 'toggle') {
        admin_toggle($route, $id);
        redirect('admin/?p=' . urlencode($route));
    }
}

if ($route === 'users' && $action === 'delete' && $id > 0) {
    require_owner();
    if ($id === (int) $user['id']) {
        admin_flash('error', 'You cannot delete the account you are signed in with.');
    } elseif (!hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) {
        admin_flash('error', 'That delete link has expired.');
    } else {
        $remaining = db_one("SELECT COUNT(*) AS c FROM users WHERE role = 'admin' AND id != :id", [':id' => $id]);
        $target = db_one('SELECT * FROM users WHERE id = :id', [':id' => $id]);
        if ($target && $target['role'] === 'admin' && (int) $remaining['c'] === 0) {
            admin_flash('error', 'There must always be at least one administrator.');
        } else {
            db_run('DELETE FROM users WHERE id = :id', [':id' => $id]);
            admin_log('deleted user', (string) ($target['username'] ?? $id));
            admin_flash('ok', 'User deleted.');
        }
    }
    redirect('admin/?p=users');
}

if ($route === 'media' && $action === 'delete') {
    $file = (string) ($_GET['file'] ?? '');
    if (!hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) {
        admin_flash('error', 'That delete link has expired.');
    } elseif (!str_starts_with($file, 'uploads/') || str_contains($file, '..')) {
        admin_flash('error', 'Only files inside the uploads folder can be deleted here.');
    } else {
        $path = ROOT_PATH . '/' . $file;
        if (is_file($path) && @unlink($path)) {
            db_run('DELETE FROM media WHERE filename = :f', [':f' => $file]);
            admin_log('deleted media', $file);
            admin_flash('ok', 'File deleted.');
        } else {
            admin_flash('error', 'That file could not be deleted.');
        }
    }
    redirect('admin/?p=media');
}

/* Bookings: spreadsheet export and deletion. */
if ($route === 'bookings' && $action === 'export') {
    $filter = (string) ($_GET['s'] ?? '');
    $sql = 'SELECT * FROM bookings';
    $params = [];
    if (array_key_exists($filter, booking_statuses())) {
        $sql .= ' WHERE status = :s';
        $params[':s'] = $filter;
    }
    booking_export_csv(db_all($sql . ' ORDER BY created_at DESC', $params));
    admin_log('exported bookings');
    exit;
}

if ($route === 'bookings' && $action === 'delete' && $id > 0) {
    if (hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) {
        $booking = booking_find($id);
        db_run('DELETE FROM bookings WHERE id = :id', [':id' => $id]);
        admin_log('deleted booking', (string) ($booking['reference'] ?? $id));
        admin_flash('ok', 'Booking deleted.');
    } else {
        admin_flash('error', 'That delete link has expired.');
    }
    redirect('admin/?p=bookings');
}

if ($route === 'enquiries' && $action === 'delete' && $id > 0) {
    if (hash_equals(csrf_token(), (string) ($_GET['t'] ?? ''))) {
        db_run('DELETE FROM enquiries WHERE id = :id', [':id' => $id]);
        admin_log('deleted enquiry', '#' . $id);
        admin_flash('ok', 'Enquiry deleted.');
    }
    redirect('admin/?p=enquiries');
}

/* CSV export of every enquiry — streamed, not rendered as a page. */
if ($route === 'enquiries' && $action === 'export') {
    $rows = db_all('SELECT * FROM enquiries ORDER BY created_at DESC');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="enquiries-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads the accents correctly
    fputcsv($out, ['Date', 'Name', 'Company', 'Email', 'Phone', 'Interest', 'Option', 'Status', 'Message']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['created_at'], $row['name'], $row['company'], $row['email'], $row['phone'],
            $row['interest'], $row['option_key'], $row['status'], $row['message'],
        ]);
    }
    fclose($out);
    admin_log('exported enquiries');
    exit;
}

/* ================================================================ VIEWS */

// Permission checks must run before any HTML is sent, so redirects still work.
if ($route === 'users' && !is_owner()) {
    admin_flash('error', 'Only an administrator can open that section.');
    redirect('admin/');
}

admin_head($route);

switch (true) {
    case $route === 'dashboard':
        admin_dashboard();
        break;

    case $route === 'settings':
        admin_settings_view();
        break;

    /* ------------------------------------------- EFT booking platform */
    case $route === 'eft_bookings':
        if ($action === 'view' && $id > 0) {
            eft_admin_booking_view($id);
        } elseif ($action === 'new') {
            eft_admin_new_booking_view();
        } else {
            eft_admin_bookings_view();
        }
        break;

    case $route === 'eft_calendar':
        eft_admin_calendar_view();
        break;

    case $route === 'eft_payments':
        eft_admin_payments_view();
        break;

    case $route === 'eft_clients':
        if ($action === 'view' && $id > 0) {
            eft_admin_client_view($id);
        } else {
            eft_admin_clients_view();
        }
        break;

    case $route === 'eft_reports':
        eft_admin_reports_view();
        break;

    case $route === 'eft_emails':
        eft_admin_emails_view();
        break;

    case $route === 'bookings':
        if ($action === 'view' && $id > 0) {
            admin_booking_view($id);
        } else {
            admin_bookings_view();
        }
        break;

    case $route === 'enquiries':
        admin_enquiries_view();
        break;

    case $route === 'media':
        admin_media_view();
        break;

    case $route === 'users':
        admin_users_view();
        break;

    case $route === 'account':
        admin_account_view($user);
        break;

    case $route === 'activity':
        admin_activity_view();
        break;

    case isset($resources[$route]):
        if ($action === 'edit' || $action === 'new') {
            admin_edit_view($route, $resources[$route], $id);
        } else {
            admin_list_view($route, $resources[$route]);
        }
        break;

    default:
        echo '<div class="a-card"><h2>Screen not found</h2><p>That page does not exist. <a href="./">Return to the dashboard</a>.</p></div>';
}

admin_foot();

/* ============================================================ VIEW CODE */

function admin_head(string $route): void
{
    $user = admin_user();
    $groups = [];
    foreach (admin_resources() as $key => $definition) {
        $groups[$definition['group']][$key] = $definition;
    }
    $newEnquiries = db_one("SELECT COUNT(*) AS c FROM enquiries WHERE status = 'new'");
    $newBookings = db_one("SELECT COUNT(*) AS c FROM bookings WHERE status = 'pending'");
    $eftReview = function_exists('eft_pending_count') && booking_schema_current() ? eft_pending_count() : 0;
    $eftRequests = function_exists('eft_open_requests_count') && booking_schema_current() ? eft_open_requests_count() : 0;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Control panel · <?= e(setting('site_name')) ?></title>
<link rel="icon" href="<?= e(site_image(setting('logo'), 32)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/admin.css')) ?>?v=<?= e(asset_version('assets/admin.css')) ?>">
</head>
<body>
<div class="a-shell">
  <aside class="a-side" id="aSide">
    <a class="a-brand" href="./">
      <img src="<?= e(site_image(setting('logo'), 44)) ?>" alt="">
      <span><?= e(setting('site_name')) ?><small>Control panel</small></span>
    </a>

    <nav class="a-nav">
      <p class="a-nav-group">Overview</p>
      <a href="./"<?= $route === 'dashboard' ? ' class="on"' : '' ?>><i>◉</i> Dashboard</a>
      <a href="?p=bookings"<?= $route === 'bookings' ? ' class="on"' : '' ?>>
        <i>▤</i> Bookings
        <?php if ((int) $newBookings['c'] > 0): ?><b class="a-badge"><?= (int) $newBookings['c'] ?></b><?php endif; ?>
      </a>
      <a href="?p=enquiries"<?= $route === 'enquiries' ? ' class="on"' : '' ?>>
        <i>✉</i> Enquiries
        <?php if ((int) $newEnquiries['c'] > 0): ?><b class="a-badge"><?= (int) $newEnquiries['c'] ?></b><?php endif; ?>
      </a>

      <?php if (booking_schema_current()): ?>
        <p class="a-nav-group">Online bookings</p>
        <a href="?p=eft_bookings"<?= $route === 'eft_bookings' ? ' class="on"' : '' ?>>
          <i>▤</i> Bookings &amp; EFT
          <?php if ($eftReview + $eftRequests > 0): ?><b class="a-badge"><?= $eftReview + $eftRequests ?></b><?php endif; ?>
        </a>
        <a href="?p=eft_calendar"<?= $route === 'eft_calendar' ? ' class="on"' : '' ?>><i>▦</i> Diary</a>
        <a href="?p=eft_payments"<?= $route === 'eft_payments' ? ' class="on"' : '' ?>><i>◎</i> Payments</a>
        <a href="?p=eft_clients"<?= $route === 'eft_clients' ? ' class="on"' : '' ?>><i>◍</i> Clients</a>
        <a href="?p=eft_reports"<?= $route === 'eft_reports' ? ' class="on"' : '' ?>><i>◔</i> Reports</a>
        <a href="?p=eft_emails"<?= $route === 'eft_emails' ? ' class="on"' : '' ?>><i>✉</i> Email log</a>
        <a href="<?= e(url('booking/verify-ticket.php')) ?>" target="_blank" rel="noopener"><i>✓</i> Verify a ticket ↗</a>
      <?php endif; ?>

      <?php foreach ($groups as $groupName => $items): ?>
        <p class="a-nav-group"><?= e($groupName) ?></p>
        <?php foreach ($items as $key => $definition): ?>
          <a href="?p=<?= e($key) ?>"<?= $route === $key ? ' class="on"' : '' ?>>
            <i><?= e($definition['icon'] ?? '•') ?></i> <?= e($definition['label']) ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>

      <p class="a-nav-group">Setup</p>
      <a href="?p=settings"<?= $route === 'settings' ? ' class="on"' : '' ?>><i>⚙</i> Site settings</a>
      <a href="?p=media"<?= $route === 'media' ? ' class="on"' : '' ?>><i>▤</i> Media library</a>
      <?php if (is_owner()): ?>
        <a href="?p=users"<?= $route === 'users' ? ' class="on"' : '' ?>><i>◍</i> Users</a>
      <?php endif; ?>
      <a href="?p=activity"<?= $route === 'activity' ? ' class="on"' : '' ?>><i>≡</i> Activity</a>
    </nav>

    <div class="a-side-foot">
      <a class="a-user" href="?p=account">
        <span class="a-avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?: $user['username'], 0, 1))) ?></span>
        <span><?= e($user['name'] ?: $user['username']) ?><small><?= e($user['role'] === 'admin' ? 'Administrator' : 'Editor') ?></small></span>
      </a>
      <a class="a-signout" href="?p=logout">Sign out</a>
    </div>
  </aside>

  <div class="a-main">
    <header class="a-top">
      <button class="a-burger" type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
      <div class="a-top-actions">
        <a class="a-btn a-btn-ghost" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener">View website ↗</a>
      </div>
    </header>

    <div class="a-content">
      <?php foreach (admin_flash_take() as $flash): ?>
        <div class="a-alert a-alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endforeach; ?>
    <?php
}

function admin_foot(): void
{
    ?>
    </div>
  </div>
</div>
<script src="<?= e(url('assets/admin.js')) ?>?v=<?= e(asset_version('assets/admin.js')) ?>"></script>
</body>
</html>
    <?php
}

/**
 * The one screen a flagged account sees until it has chosen its own password.
 * Deliberately a page of its own rather than a banner: there is nothing else
 * to click, so the step cannot be skipped past.
 */
function admin_first_password_view(array $user): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Choose your password | <?= e(setting('event_name')) ?></title>
<link rel="icon" href="<?= e(site_image(setting('logo'), 32)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/admin.css')) ?>?v=<?= e(asset_version('assets/admin.css')) ?>">
</head>
<body class="a-login-body">
<main class="a-login">
  <img class="a-login-logo" src="<?= e(site_image(setting('logo'), 100)) ?>" alt="<?= e(setting('event_name')) ?>">
  <h1>Choose your own password</h1>
  <p class="a-login-intro">
    Hello <?= e($user['name'] ?: $user['username']) ?>. The password you signed in with was
    set up for you, so please replace it with one only you know before you carry on.
  </p>

  <?php foreach (admin_flash_take() as $note): ?>
    <div class="a-alert a-alert-<?= $note['type'] === 'ok' ? 'ok' : 'error' ?>"><?= e($note['message']) ?></div>
  <?php endforeach; ?>

  <form method="post" action="<?= e(url('admin/?p=first-password')) ?>" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="first_password">

    <div class="a-field">
      <label for="fp_current">The password you just signed in with</label>
      <input type="password" id="fp_current" name="current" autocomplete="current-password" required autofocus>
    </div>
    <div class="a-field">
      <label for="fp_new">Your new password</label>
      <input type="password" id="fp_new" name="new" autocomplete="new-password" minlength="10" required>
      <small>At least 10 characters, with letters as well as numbers.</small>
    </div>
    <div class="a-field">
      <label for="fp_confirm">Type it once more</label>
      <input type="password" id="fp_confirm" name="confirm" autocomplete="new-password" minlength="10" required>
    </div>

    <button class="a-btn" type="submit">Save my password and continue</button>
  </form>

  <p class="a-login-foot">
    <a href="<?= e(url('admin/?p=logout')) ?>">Sign out instead</a>
  </p>
</main>
</body>
</html>
    <?php
}

function admin_login_view(string $error): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · <?= e(setting('site_name')) ?></title>
<link rel="icon" href="<?= e(site_image(setting('logo'), 32)) ?>">
<link rel="stylesheet" href="<?= e(url('assets/admin.css')) ?>?v=<?= e(asset_version('assets/admin.css')) ?>">
</head>
<body class="a-login-body">
  <main class="a-login">
    <img class="a-login-logo" src="<?= e(site_image(setting('logo'), 100)) ?>" alt="<?= e(setting('event_name')) ?>">
    <h1>Control panel</h1>
    <p class="a-login-sub">Sign in to manage <?= e(setting('site_name')) ?>.</p>

    <?php if ($error !== ''): ?><div class="a-alert a-alert-error"><?= e($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="a-field">
        <label for="username">Username or email</label>
        <input type="text" id="username" name="username" autocomplete="username" required autofocus>
      </div>
      <div class="a-field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>
      <button class="a-btn a-btn-primary a-btn-block" type="submit">Sign in</button>
    </form>

    <p class="a-login-foot"><a href="index.php">← Back to the website</a></p>
  </main>
</body>
</html>
    <?php
}

/* ------------------------------------------------------------ dashboard */

function admin_dashboard(): void
{
    $counts = [];
    foreach (admin_resources() as $key => $definition) {
        $row = db_one("SELECT COUNT(*) AS c FROM {$key}");
        $counts[$key] = ['label' => $definition['label'], 'count' => (int) $row['c'], 'icon' => $definition['icon'] ?? '•'];
    }
    $newCount   = (int) (db_one("SELECT COUNT(*) AS c FROM enquiries WHERE status = 'new'")['c'] ?? 0);
    $allCount   = (int) (db_one('SELECT COUNT(*) AS c FROM enquiries')['c'] ?? 0);
    $recent     = db_all('SELECT * FROM enquiries ORDER BY created_at DESC LIMIT 5');

    $bookingSummary = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM bookings WHERE status != 'cancelled'");
    $bookingCount   = (int) ($bookingSummary['c'] ?? 0);
    $bookingValue   = (float) ($bookingSummary['t'] ?? 0);
    $bookingPending = (int) (db_one("SELECT COUNT(*) AS c FROM bookings WHERE status = 'pending'")['c'] ?? 0);
    $recentBookings = db_all('SELECT * FROM bookings ORDER BY created_at DESC LIMIT 5');
    $phase      = event_phase();
    $daysToGo   = (int) (new DateTimeImmutable('today'))->diff(event_start())->format('%r%a');
    ?>
<div class="a-head">
  <div>
    <h1>Dashboard</h1>
    <p>Everything on the website is edited from here — no code, no developer.</p>
  </div>
</div>

<div class="a-countdown">
  <div>
    <p class="a-countdown-label">
      <?= $phase === 'upcoming' ? 'Time until the doors open' : ($phase === 'running' ? 'The event is running now' : 'The event has finished') ?>
    </p>
    <p class="a-countdown-value">
      <?php if ($phase === 'upcoming'): ?>
        <strong><?= $daysToGo ?></strong> <?= $daysToGo === 1 ? 'day' : 'days' ?> to go
      <?php elseif ($phase === 'running'): ?>
        <strong>Live</strong> right now
      <?php else: ?>
        <strong>Closed</strong>
      <?php endif; ?>
    </p>
    <p class="a-countdown-meta"><?= e(event_date_range()) ?> · <?= e(setting('venue_name')) ?>, <?= e(setting('venue_city')) ?></p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=settings&amp;g=event">Change the dates</a>
</div>

<div class="a-stats">
  <a class="a-stat a-stat-primary" href="?p=bookings">
    <span class="a-stat-num"><?= $bookingPending ?></span>
    <span class="a-stat-label">Bookings awaiting payment</span>
    <small><?= $bookingCount ?> bookings · <?= e(money_format($bookingValue)) ?> booked</small>
  </a>
  <a class="a-stat" href="?p=enquiries">
    <span class="a-stat-num"><?= $newCount ?></span>
    <span class="a-stat-label">New enquiries</span>
    <small><?= $allCount ?> received in total</small>
  </a>
  <?php foreach (['partners', 'stalls', 'programme_days'] as $key): ?>
    <?php if (isset($counts[$key])): ?>
      <a class="a-stat" href="?p=<?= e($key) ?>">
        <span class="a-stat-num"><?= $counts[$key]['count'] ?></span>
        <span class="a-stat-label"><?= e($counts[$key]['label']) ?></span>
      </a>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<div class="a-grid-2">
  <section class="a-card">
    <h2>Latest bookings</h2>
    <?php if (!$recentBookings): ?>
      <p class="a-empty">No bookings yet. They arrive here as soon as someone completes the booking form.</p>
    <?php else: ?>
      <table class="a-table">
        <tbody>
        <?php foreach ($recentBookings as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['company']) ?></strong>
              <small><?= e($row['reference']) ?> · <?= e(money_format((float) $row['total'])) ?> · <?= e(date('d M, H:i', strtotime($row['created_at']))) ?></small>
            </td>
            <td class="a-right">
              <span class="a-pill a-pill-<?= e($row['status']) ?>"><?= e(booking_statuses()[$row['status']] ?? $row['status']) ?></span>
              <a class="a-btn a-btn-small" href="?p=bookings&amp;action=view&amp;id=<?= (int) $row['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2>Latest enquiries</h2>
    <?php if (!$recent): ?>
      <p class="a-empty">No enquiries yet. They will appear here the moment someone uses the form on the website.</p>
    <?php else: ?>
      <table class="a-table">
        <tbody>
        <?php foreach ($recent as $row): ?>
          <tr>
            <td>
              <strong><?= e($row['name']) ?></strong>
              <small><?= e($row['interest']) ?> · <?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></small>
            </td>
            <td class="a-right">
              <?php if ($row['status'] === 'new'): ?><span class="a-pill a-pill-new">New</span><?php endif; ?>
              <a class="a-btn a-btn-small" href="?p=enquiries#e<?= (int) $row['id'] ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="a-card">
    <h2>Quick actions</h2>
    <div class="a-quick">
      <a href="?p=bookings">See all bookings</a>
      <a href="?p=stalls">Update stall rates &amp; booking options</a>
      <a href="?p=settings&amp;g=home">Edit the home page text</a>
      <a href="?p=partners&amp;action=new">Add a sponsor or partner</a>
      <a href="?p=packages">Update sponsorship prices</a>
      <a href="?p=stalls">Update stall rates</a>
      <a href="?p=programme_days">Change the programme</a>
      <a href="?p=speakers&amp;action=new">Add a speaker</a>
      <a href="?p=gallery&amp;action=new">Add a photo to the gallery</a>
      <a href="?p=settings&amp;g=contact">Change contact details</a>
    </div>
  </section>
</div>

<section class="a-card">
  <h2>Site health</h2>
  <ul class="a-health">
    <?php foreach (admin_health() as $check): ?>
      <li class="<?= $check['ok'] ? 'ok' : 'warn' ?>">
        <span><?= $check['ok'] ? '✓' : '!' ?></span>
        <div><strong><?= e($check['label']) ?></strong><?php if (!$check['ok']): ?><small><?= e($check['hint']) ?></small><?php endif; ?></div>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
    <?php
}

/* ----------------------------------------------------------- list & edit */

function admin_list_view(string $key, array $definition): void
{
    $filterColumn = null;
    $filterValue  = (string) ($_GET['f'] ?? '');
    if (!empty($definition['filter'])) {
        $filterColumn = array_key_first($definition['filter']);
    }

    $sql = "SELECT * FROM {$key}";
    $params = [];
    if ($filterColumn && $filterValue !== '') {
        if ($key === 'blocks' && $filterColumn === 'section') {
            [$p, $s] = array_pad(explode('|', $filterValue), 2, '');
            $sql .= ' WHERE page = :p AND section = :s';
            $params = [':p' => $p, ':s' => $s];
        } else {
            $sql .= " WHERE {$filterColumn} = :v";
            $params = [':v' => $filterValue];
        }
    }
    $sql .= isset($definition['fields']['position']) ? ' ORDER BY position, id' : ' ORDER BY id';
    $rows = db_all($sql, $params);

    $visibleColumn = isset($definition['fields']['is_active']) ? 'is_active'
        : (isset($definition['fields']['is_published']) ? 'is_published' : null);
    ?>
<div class="a-head">
  <div>
    <h1><?= e($definition['label']) ?></h1>
    <?php if (!empty($definition['intro'])): ?><p><?= e($definition['intro']) ?></p><?php endif; ?>
  </div>
  <div class="a-head-actions">
    <?php if (!empty($definition['extra_action']) && function_exists($definition['extra_action'])): ?>
      <?php ($definition['extra_action'])($rows); ?>
    <?php endif; ?>
    <?php if (empty($definition['no_create'])): ?>
      <a class="a-btn a-btn-primary" href="?p=<?= e($key) ?>&amp;action=new">+ Add <?= e($definition['singular']) ?></a>
    <?php endif; ?>
  </div>
</div>

<?php if ($filterColumn): ?>
  <form class="a-filter" method="get">
    <input type="hidden" name="p" value="<?= e($key) ?>">
    <label for="filter"><?= e($definition['filter'][$filterColumn]) ?></label>
    <select id="filter" name="f" onchange="this.form.submit()">
      <option value="">All</option>
      <?php
      // A resource may name its own option list; otherwise fall back to the
      // two the panel has always used.
      $options = !empty($definition['filter_options'])
          ? admin_field_options(['options' => $definition['filter_options']])
          : ($key === 'blocks' ? admin_block_sections() : partner_categories());
      foreach ($options as $optKey => $optLabel): ?>
        <option value="<?= e((string) $optKey) ?>"<?= $filterValue === (string) $optKey ? ' selected' : '' ?>><?= e($optLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="a-btn a-btn-small" type="submit">Filter</button></noscript>
  </form>
<?php endif; ?>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">Nothing here yet.<?php if (empty($definition['no_create'])): ?> Use the <strong>Add <?= e($definition['singular']) ?></strong> button to create the first one.<?php endif; ?></p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead>
        <tr>
          <?php foreach ($definition['list'] as $column => $heading): ?>
            <th><?= e($heading) ?></th>
          <?php endforeach; ?>
          <th class="a-right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr<?= $visibleColumn && !(int) $row[$visibleColumn] ? ' class="a-off"' : '' ?>>
          <?php foreach ($definition['list'] as $column => $heading): ?>
            <td>
              <?php if (in_array($column, ['logo', 'image', 'photo'], true)): ?>
                <?php if (($row[$column] ?? '') !== ''): ?>
                  <img class="a-thumb" src="<?= e(rawurlencode_path($row[$column])) ?>" alt="">
                <?php else: ?>
                  <span class="a-thumb a-thumb-empty">—</span>
                <?php endif; ?>
              <?php elseif ($column === 'category' && $key === 'partners'): ?>
                <?= e(partner_categories()[$row[$column]] ?? $row[$column]) ?>
              <?php elseif ($column === 'kind'): ?>
                <?= e(admin_stall_kinds()[$row[$column]] ?? $row[$column]) ?>
              <?php elseif ($column === 'section'): ?>
                <?= e(admin_block_sections()[$row['page'] . '|' . $row['section']] ?? ($row['page'] . ' / ' . $row['section'])) ?>
              <?php elseif (isset($definition['list_format'][$column]) && function_exists($definition['list_format'][$column])): ?>
                <?= e(($definition['list_format'][$column])($row[$column] ?? '', $row)) ?>
              <?php else: ?>
                <?= e(mb_strimwidth((string) ($row[$column] ?? ''), 0, 80, '…')) ?>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
          <td class="a-right a-actions">
            <?php if (isset($definition['fields']['position'])): ?>
              <a class="a-icon" href="?p=<?= e($key) ?>&amp;action=up&amp;id=<?= (int) $row['id'] ?>" title="Move up">↑</a>
              <a class="a-icon" href="?p=<?= e($key) ?>&amp;action=down&amp;id=<?= (int) $row['id'] ?>" title="Move down">↓</a>
            <?php endif; ?>
            <?php if ($visibleColumn): ?>
              <a class="a-icon<?= (int) $row[$visibleColumn] ? ' on' : '' ?>"
                 href="?p=<?= e($key) ?>&amp;action=toggle&amp;id=<?= (int) $row['id'] ?>"
                 title="<?= (int) $row[$visibleColumn] ? 'Visible — click to hide' : 'Hidden — click to show' ?>">
                <?= (int) $row[$visibleColumn] ? '👁' : '🚫' ?>
              </a>
            <?php endif; ?>
            <a class="a-btn a-btn-small" href="?p=<?= e($key) ?>&amp;action=edit&amp;id=<?= (int) $row['id'] ?>">Edit</a>
            <?php if (empty($definition['no_delete'])): ?>
              <a class="a-btn a-btn-small a-btn-danger"
                 href="?p=<?= e($key) ?>&amp;action=delete&amp;id=<?= (int) $row['id'] ?>&amp;t=<?= e(csrf_token()) ?>"
                 data-confirm="Delete this <?= e($definition['singular']) ?>? This cannot be undone.">Delete</a>
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

function admin_edit_view(string $key, array $definition, int $id): void
{
    $record = $id > 0 ? admin_record($key, $id) : null;
    if ($id > 0 && !$record) {
        echo '<div class="a-card"><p class="a-empty">That item no longer exists.</p></div>';
        return;
    }
    if ($id === 0 && !empty($definition['no_create'])) {
        echo '<div class="a-card"><p class="a-empty">New items cannot be added to this list.</p></div>';
        return;
    }
    ?>
<div class="a-head">
  <div>
    <h1><?= $id > 0 ? 'Edit' : 'Add' ?> <?= e($definition['singular']) ?></h1>
    <?php if (!empty($definition['intro'])): ?><p><?= e($definition['intro']) ?></p><?php endif; ?>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=<?= e($key) ?>">← Back to <?= e(strtolower($definition['label'])) ?></a>
</div>

<form class="a-card" method="post" enctype="multipart/form-data"
      action="?p=<?= e($key) ?>&amp;action=edit&amp;id=<?= $id ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">

  <div class="a-form-grid">
    <?php foreach ($definition['fields'] as $name => $field): ?>
      <?php
      $value = $record[$name] ?? ($field['type'] === 'bool' ? '1' : '');
      // "Content cards" store the location as two columns; the form uses one control.
      if ($key === 'blocks' && $name === 'section') {
          $value = $record ? $record['page'] . '|' . $record['section'] : 'home|focus';
      }
      $wide = in_array($field['type'] ?? 'text', ['textarea', 'list', 'image'], true);
      echo '<div class="a-col' . ($wide ? ' a-col-wide' : '') . '">';
      admin_field($name, $field, $value);
      echo '</div>';
      ?>
    <?php endforeach; ?>
  </div>

  <div class="a-form-actions">
    <button class="a-btn a-btn-primary" type="submit">Save <?= e($definition['singular']) ?></button>
    <a class="a-btn a-btn-ghost" href="?p=<?= e($key) ?>">Cancel</a>
    <?php if ($id > 0 && empty($definition['no_delete'])): ?>
      <a class="a-btn a-btn-danger a-push-right"
         href="?p=<?= e($key) ?>&amp;action=delete&amp;id=<?= $id ?>&amp;t=<?= e(csrf_token()) ?>"
         data-confirm="Delete this <?= e($definition['singular']) ?>? This cannot be undone.">Delete</a>
    <?php endif; ?>
  </div>
</form>
    <?php
}

/* -------------------------------------------------------------- settings */

function admin_settings_view(): void
{
    $groups = admin_setting_groups();
    $active = (string) ($_GET['g'] ?? 'identity');
    if (!isset($groups[$active])) {
        $active = 'identity';
    }
    $group = $groups[$active];
    $values = settings(true);
    ?>
<div class="a-head">
  <div>
    <h1>Site settings</h1>
    <p>The details that appear across every page of the website.</p>
  </div>
</div>

<div class="a-tabs">
  <?php foreach ($groups as $key => $item): ?>
    <a href="?p=settings&amp;g=<?= e($key) ?>"<?= $key === $active ? ' class="on"' : '' ?>><?= e($item['label']) ?></a>
  <?php endforeach; ?>
</div>

<form class="a-card" method="post" enctype="multipart/form-data" action="?p=settings&amp;g=<?= e($active) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="settings">
  <input type="hidden" name="group" value="<?= e($active) ?>">

  <?php if (!empty($group['intro'])): ?><p class="a-intro"><?= e($group['intro']) ?></p><?php endif; ?>

  <div class="a-form-grid">
    <?php foreach ($group['fields'] as $key => $field): ?>
      <?php
      $wide = in_array($field['type'] ?? 'text', ['textarea', 'list', 'image'], true);
      echo '<div class="a-col' . ($wide ? ' a-col-wide' : '') . '">';
      admin_field($key, $field, $values[$key] ?? '');
      echo '</div>';
      ?>
    <?php endforeach; ?>
  </div>

  <div class="a-form-actions">
    <button class="a-btn a-btn-primary" type="submit">Save <?= e(strtolower($group['label'])) ?></button>
    <a class="a-btn a-btn-ghost" href="<?= e(url('index.php')) ?>" target="_blank" rel="noopener">Preview the site ↗</a>
  </div>
</form>
    <?php
}

/* -------------------------------------------------------------- bookings */

function admin_bookings_view(): void
{
    $filter = (string) ($_GET['s'] ?? '');
    $statuses = booking_statuses();

    $sql = 'SELECT * FROM bookings';
    $params = [];
    if (array_key_exists($filter, $statuses)) {
        $sql .= ' WHERE status = :s';
        $params[':s'] = $filter;
    }
    $rows = db_all($sql . ' ORDER BY created_at DESC LIMIT 500', $params);

    $totals = db_one("SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM bookings WHERE status != 'cancelled'");
    $paid = db_one("SELECT COALESCE(SUM(total), 0) AS t FROM bookings WHERE status = 'paid'");
    ?>
<div class="a-head">
  <div>
    <h1>Bookings</h1>
    <p>Every online registration, with the full form exactly as the applicant completed it.</p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=bookings&amp;action=export<?= $filter !== '' ? '&amp;s=' . e($filter) : '' ?>">Download CSV ↓</a>
</div>

<div class="a-stats">
  <div class="a-stat a-stat-primary">
    <span class="a-stat-num"><?= (int) $totals['c'] ?></span>
    <span class="a-stat-label">Live bookings</span>
    <small>Cancelled bookings excluded</small>
  </div>
  <div class="a-stat">
    <span class="a-stat-num" style="font-size:1.35rem"><?= e(money_format((float) $totals['t'])) ?></span>
    <span class="a-stat-label">Value booked</span>
  </div>
  <div class="a-stat">
    <span class="a-stat-num" style="font-size:1.35rem"><?= e(money_format((float) $paid['t'])) ?></span>
    <span class="a-stat-label">Received (marked paid)</span>
  </div>
</div>

<div class="a-tabs">
  <a href="?p=bookings"<?= $filter === '' ? ' class="on"' : '' ?>>All</a>
  <?php foreach ($statuses as $key => $label): ?>
    <a href="?p=bookings&amp;s=<?= e($key) ?>"<?= $filter === $key ? ' class="on"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">No bookings in this list yet. They appear here the moment somebody completes the form on the website.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead>
        <tr><th>Reference</th><th>Company</th><th>Contact</th><th>Booked</th><th>Total</th><th>Status</th><th class="a-right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr<?= $row['status'] === 'cancelled' ? ' class="a-off"' : '' ?>>
          <td><strong><?= e($row['reference']) ?></strong></td>
          <td><?= e($row['company']) ?></td>
          <td><?= e($row['contact_person']) ?><small><?= e($row['email']) ?><?= $row['phone'] !== '' ? ' · ' . e($row['phone']) : '' ?></small></td>
          <td><?= e(date('d M Y', strtotime($row['created_at']))) ?><small><?= e(date('H:i', strtotime($row['created_at']))) ?></small></td>
          <td><strong><?= e(money_format((float) $row['total'])) ?></strong></td>
          <td><span class="a-pill a-pill-<?= e($row['status']) ?>"><?= e($statuses[$row['status']] ?? $row['status']) ?></span></td>
          <td class="a-right a-actions">
            <a class="a-btn a-btn-small" href="?p=bookings&amp;action=view&amp;id=<?= (int) $row['id'] ?>">Open</a>
            <a class="a-btn a-btn-small" href="booking-pdf.php?id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener">PDF ↓</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
}

function admin_booking_view(int $id): void
{
    $booking = booking_find($id);
    if (!$booking) {
        echo '<div class="a-card"><p class="a-empty">That booking no longer exists.</p></div>';
        return;
    }

    $items = booking_items($booking);
    $statuses = booking_statuses();
    ?>
<div class="a-head">
  <div>
    <h1>Booking <?= e($booking['reference']) ?></h1>
    <p>Received <?= e(date('d F Y \a\t H:i', strtotime($booking['created_at']))) ?><?= $booking['updated_at'] !== $booking['created_at'] ? ' · last updated ' . e(date('d M Y, H:i', strtotime($booking['updated_at']))) : '' ?></p>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="a-btn a-btn-primary" href="booking-pdf.php?id=<?= $id ?>" target="_blank" rel="noopener">Download PDF ↓</a>
    <a class="a-btn a-btn-ghost" href="booking-pdf.php?id=<?= $id ?>&amp;view=1" target="_blank" rel="noopener">Preview</a>
    <a class="a-btn a-btn-ghost" href="?p=bookings">← All bookings</a>
  </div>
</div>

<div class="a-grid-2">
  <section class="a-card">
    <h2>What was booked</h2>
    <table class="a-table">
      <thead><tr><th>Option</th><th>Qty</th><th class="a-right">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td>
            <strong><?= e($item['category']) ?></strong>
            <small><?= e($item['details']) ?><?= ($item['note'] ?? '') !== '' ? ' — ' . e($item['note']) : '' ?></small>
          </td>
          <td><?= (int) $item['quantity'] ?></td>
          <td class="a-right"><?= e(money_format((float) $item['amount'])) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <td><strong>Total</strong></td>
          <td></td>
          <td class="a-right"><strong><?= e(money_format((float) $booking['total'])) ?></strong></td>
        </tr>
      </tbody>
    </table>

    <h2 style="margin-top:1.5rem">Signature</h2>
    <p style="font-size:1.05rem;font-weight:600;margin:0"><?= e($booking['signature']) ?></p>
    <p style="color:var(--a-muted);font-size:.84rem;margin:.2rem 0 0">
      Terms accepted online on <?= e(date('d F Y', strtotime($booking['created_at']))) ?><?= $booking['ip'] !== '' ? ' from ' . e($booking['ip']) : '' ?>.
    </p>
  </section>

  <section class="a-card">
    <h2>Applicant details &amp; status</h2>
    <form method="post" action="?p=bookings&amp;action=view&amp;id=<?= $id ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="booking_save">
      <input type="hidden" name="id" value="<?= $id ?>">

      <div class="a-field"><label for="b_status">Status</label>
        <select id="b_status" name="status">
          <?php foreach ($statuses as $key => $label): ?>
            <option value="<?= e($key) ?>"<?= $booking['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php
      $fields = [
          'company'              => 'Company / participant name',
          'contact_person'       => 'Contact person',
          'phone'                => 'Phone / WhatsApp',
          'email'                => 'Email',
          'product'              => 'Product',
      ];
      foreach ($fields as $name => $label): ?>
        <div class="a-field">
          <label for="b_<?= e($name) ?>"><?= e($label) ?></label>
          <input type="text" id="b_<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($booking[$name]) ?>">
        </div>
      <?php endforeach; ?>

      <div class="a-field">
        <label for="b_special">Special stall size or requirements</label>
        <textarea id="b_special" name="special_requirements" rows="3"><?= e($booking['special_requirements']) ?></textarea>
      </div>

      <div class="a-field">
        <label for="b_notes">Event team notes</label>
        <textarea id="b_notes" name="admin_notes" rows="4" placeholder="Payment received, stall allocated, anything the team should know"><?= e($booking['admin_notes']) ?></textarea>
        <p class="a-help">These notes appear at the bottom of the PDF, so keep them suitable for the applicant to read.</p>
      </div>

      <div class="a-form-actions">
        <button class="a-btn a-btn-primary" type="submit">Save booking</button>
        <a class="a-btn a-btn-ghost" href="mailto:<?= e($booking['email']) ?>?subject=<?= rawurlencode('Booking ' . $booking['reference'] . ' - ' . setting('event_name')) ?>">Email applicant</a>
        <a class="a-btn a-btn-danger a-push-right"
           href="?p=bookings&amp;action=delete&amp;id=<?= $id ?>&amp;t=<?= e(csrf_token()) ?>"
           data-confirm="Delete booking <?= e($booking['reference']) ?> permanently? This cannot be undone.">Delete</a>
      </div>
    </form>
  </section>
</div>
    <?php
}

/* ------------------------------------------------------------- enquiries */

function admin_enquiries_view(): void
{
    $filter = (string) ($_GET['s'] ?? '');
    $sql = 'SELECT * FROM enquiries';
    $params = [];
    if (in_array($filter, ['new', 'read', 'replied', 'archived'], true)) {
        $sql .= ' WHERE status = :s';
        $params[':s'] = $filter;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT 400';
    $rows = db_all($sql, $params);
    ?>
<div class="a-head">
  <div>
    <h1>Enquiries</h1>
    <p>Every message sent through the website. These are stored here even if the server cannot send email.</p>
  </div>
  <a class="a-btn a-btn-ghost" href="?p=enquiries&amp;action=export">Download CSV ↓</a>
</div>

<div class="a-tabs">
  <a href="?p=enquiries"<?= $filter === '' ? ' class="on"' : '' ?>>All</a>
  <?php foreach (['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived'] as $key => $label): ?>
    <a href="?p=enquiries&amp;s=<?= e($key) ?>"<?= $filter === $key ? ' class="on"' : '' ?>><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="a-card"><p class="a-empty">No enquiries in this list.</p></div>
<?php else: ?>
  <?php foreach ($rows as $row): ?>
    <article class="a-enquiry<?= $row['status'] === 'new' ? ' is-new' : '' ?>" id="e<?= (int) $row['id'] ?>">
      <header>
        <div>
          <h3><?= e($row['name']) ?><?= $row['company'] !== '' ? ' · ' . e($row['company']) : '' ?></h3>
          <p class="a-enquiry-meta">
            <?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?>
            · <a href="mailto:<?= e($row['email']) ?>"><?= e($row['email']) ?></a>
            <?php if ($row['phone'] !== ''): ?> · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $row['phone'])) ?>"><?= e($row['phone']) ?></a><?php endif; ?>
          </p>
        </div>
        <span class="a-pill a-pill-<?= e($row['status']) ?>"><?= e(ucfirst($row['status'])) ?></span>
      </header>

      <p class="a-enquiry-tags">
        <span><?= e($row['interest']) ?></span>
        <?php if ($row['option_key'] !== ''): ?><span><?= e($row['option_key']) ?></span><?php endif; ?>
      </p>

      <p class="a-enquiry-body"><?= nl($row['message']) ?></p>

      <footer>
        <form method="post" action="?p=enquiries">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="enquiry_status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <select name="status" onchange="this.form.submit()" aria-label="Change status">
            <?php foreach (['new' => 'New', 'read' => 'Read', 'replied' => 'Replied', 'archived' => 'Archived'] as $key => $label): ?>
              <option value="<?= e($key) ?>"<?= $row['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="a-btn a-btn-small" type="submit">Update</button></noscript>
        </form>
        <a class="a-btn a-btn-small" href="mailto:<?= e($row['email']) ?>?subject=<?= rawurlencode('Re: ' . $row['interest'] . ' — ' . setting('event_name')) ?>">Reply by email</a>
        <a class="a-btn a-btn-small a-btn-danger"
           href="?p=enquiries&amp;action=delete&amp;id=<?= (int) $row['id'] ?>&amp;t=<?= e(csrf_token()) ?>"
           data-confirm="Delete this enquiry permanently?">Delete</a>
      </footer>
    </article>
  <?php endforeach; ?>
<?php endif; ?>
    <?php
}

/* ----------------------------------------------------------------- media */

function admin_media_view(): void
{
    $files = admin_media_files();
    ?>
<div class="a-head">
  <div>
    <h1>Media library</h1>
    <p>Images and PDFs used across the site. Uploaded files are stored in the <code>uploads</code> folder.</p>
  </div>
</div>

<form class="a-card a-upload-card" method="post" enctype="multipart/form-data" action="?p=media">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="media_upload">
  <div class="a-field">
    <label for="mediaFile">Upload an image or PDF (max <?= (int) round(MAX_UPLOAD_BYTES / 1048576) ?> MB)</label>
    <input type="file" id="mediaFile" name="upload__file" accept="image/*,.pdf" required>
  </div>
  <button class="a-btn a-btn-primary" type="submit">Upload</button>
</form>

<div class="a-media-grid">
  <?php foreach ($files as $path): ?>
    <?php $isPdf = str_ends_with(strtolower($path), '.pdf'); ?>
    <figure class="a-media">
      <div class="a-media-thumb">
        <?php if ($isPdf): ?>
          <span class="a-media-pdf">PDF</span>
        <?php else: ?>
          <img src="<?= e(rawurlencode_path($path)) ?>" alt="" loading="lazy">
        <?php endif; ?>
      </div>
      <figcaption>
        <input type="text" value="<?= e($path) ?>" readonly onfocus="this.select()" aria-label="File path">
        <div class="a-media-actions">
          <a class="a-btn a-btn-small" href="<?= e(rawurlencode_path($path)) ?>" target="_blank" rel="noopener">Open</a>
          <?php if (str_starts_with($path, 'uploads/')): ?>
            <a class="a-btn a-btn-small a-btn-danger"
               href="?p=media&amp;action=delete&amp;file=<?= rawurlencode($path) ?>&amp;t=<?= e(csrf_token()) ?>"
               data-confirm="Delete this file permanently? Any page still using it will show a broken image.">Delete</a>
          <?php else: ?>
            <span class="a-media-locked">Original file</span>
          <?php endif; ?>
        </div>
      </figcaption>
    </figure>
  <?php endforeach; ?>
</div>
    <?php
}

/* ----------------------------------------------------------------- users */

function admin_users_view(): void
{
    $users = db_all('SELECT * FROM users ORDER BY role, username');
    $editId = (int) ($_GET['id'] ?? 0);
    $editing = $editId > 0 ? db_one('SELECT * FROM users WHERE id = :id', [':id' => $editId]) : null;
    ?>
<div class="a-head">
  <div>
    <h1>Users</h1>
    <p>Administrators can change everything. Editors can manage content but not users.</p>
  </div>
</div>

<div class="a-grid-2">
  <section class="a-card a-card-flush">
    <table class="a-table a-table-list">
      <thead><tr><th>User</th><th>Role</th><th>Last sign-in</th><th class="a-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $row): ?>
        <tr>
          <td><strong><?= e($row['name'] ?: $row['username']) ?></strong><small><?= e($row['username']) ?><?= $row['email'] !== '' ? ' · ' . e($row['email']) : '' ?></small></td>
          <td><?= $row['role'] === 'admin' ? 'Administrator' : 'Editor' ?></td>
          <td><?= $row['last_login'] !== '' ? e(date('d M Y, H:i', strtotime($row['last_login']))) : '—' ?></td>
          <td class="a-right a-actions">
            <a class="a-btn a-btn-small" href="?p=users&amp;id=<?= (int) $row['id'] ?>">Edit</a>
            <a class="a-btn a-btn-small a-btn-danger"
               href="?p=users&amp;action=delete&amp;id=<?= (int) $row['id'] ?>&amp;t=<?= e(csrf_token()) ?>"
               data-confirm="Delete this user account?">Delete</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="a-card">
    <h2><?= $editing ? 'Edit user' : 'Add a user' ?></h2>
    <form method="post" action="?p=users">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="user_save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <div class="a-field"><label for="u_username">Username</label>
        <input type="text" id="u_username" name="username" value="<?= e($editing['username'] ?? '') ?>" required>
      </div>
      <div class="a-field"><label for="u_name">Full name</label>
        <input type="text" id="u_name" name="name" value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="a-field"><label for="u_email">Email</label>
        <input type="email" id="u_email" name="email" value="<?= e($editing['email'] ?? '') ?>">
      </div>
      <div class="a-field"><label for="u_role">Role</label>
        <select id="u_role" name="role">
          <option value="editor"<?= ($editing['role'] ?? '') === 'editor' ? ' selected' : '' ?>>Editor</option>
          <option value="admin"<?= ($editing['role'] ?? '') === 'admin' ? ' selected' : '' ?>>Administrator</option>
        </select>
      </div>
      <div class="a-field"><label for="u_password">Password<?= $editing ? ' (leave blank to keep the current one)' : '' ?></label>
        <input type="password" id="u_password" name="password" autocomplete="new-password"<?= $editing ? '' : ' required' ?>>
        <p class="a-help">At least 8 characters.</p>
      </div>

      <div class="a-form-actions">
        <button class="a-btn a-btn-primary" type="submit"><?= $editing ? 'Save user' : 'Create user' ?></button>
        <?php if ($editing): ?><a class="a-btn a-btn-ghost" href="?p=users">Cancel</a><?php endif; ?>
      </div>
    </form>
  </section>
</div>
    <?php
}

function admin_account_view(array $user): void
{
    ?>
<div class="a-head">
  <div>
    <h1>Your account</h1>
    <p>Signed in as <?= e($user['username']) ?> (<?= e($user['role'] === 'admin' ? 'administrator' : 'editor') ?>).</p>
  </div>
</div>

<section class="a-card" style="max-width:520px">
  <h2>Change your password</h2>
  <form method="post" action="?p=account">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="account">
    <div class="a-field"><label for="c_current">Current password</label>
      <input type="password" id="c_current" name="current" autocomplete="current-password" required>
    </div>
    <div class="a-field"><label for="c_new">New password</label>
      <input type="password" id="c_new" name="new" autocomplete="new-password" required>
      <p class="a-help">At least 8 characters. Use something you do not use anywhere else.</p>
    </div>
    <div class="a-field"><label for="c_confirm">Repeat the new password</label>
      <input type="password" id="c_confirm" name="confirm" autocomplete="new-password" required>
    </div>
    <button class="a-btn a-btn-primary" type="submit">Change password</button>
  </form>
</section>
    <?php
}

function admin_activity_view(): void
{
    $rows = db_all('SELECT * FROM activity_log ORDER BY id DESC LIMIT 200');
    ?>
<div class="a-head">
  <div>
    <h1>Activity</h1>
    <p>A record of the last 200 changes made in the control panel.</p>
  </div>
</div>

<div class="a-card a-card-flush">
  <?php if (!$rows): ?>
    <p class="a-empty">Nothing recorded yet.</p>
  <?php else: ?>
    <table class="a-table a-table-list">
      <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= e(date('d M Y, H:i', strtotime($row['created_at']))) ?></td>
          <td><?= e($row['user_name']) ?></td>
          <td><?= e($row['action']) ?><?= $row['detail'] !== '' ? ' <small>' . e($row['detail']) . '</small>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
    <?php
}

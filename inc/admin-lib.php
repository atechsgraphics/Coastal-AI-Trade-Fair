<?php
declare(strict_types=1);

/**
 * Admin panel library.
 * ---------------------------------------------------------------------------
 * Everything the control panel needs: authentication, the definitions that
 * describe each editable resource, a generic CRUD engine driven by those
 * definitions, form-field rendering, media uploads and the settings groups.
 *
 * Adding a new editable list to the site means adding one entry to
 * admin_resources() — no new pages or queries required.
 */

/* ================================================================ 1. AUTH */

function admin_user(): ?array
{
    start_session();
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $user = db_one('SELECT * FROM users WHERE id = :id', [':id' => $_SESSION['admin_id']]);
    }
    return $user ?: null;
}

function require_admin(): array
{
    $user = admin_user();
    if (!$user) {
        redirect('admin.php?p=login');
    }
    return $user;
}

function is_owner(): bool
{
    $user = admin_user();
    return $user !== null && $user['role'] === 'admin';
}

function require_owner(): void
{
    if (!is_owner()) {
        admin_flash('error', 'Only an administrator can open that section.');
        redirect('admin.php');
    }
}

function admin_login(string $username, string $password): bool
{
    $user = db_one('SELECT * FROM users WHERE username = :u OR email = :u', [':u' => trim($username)]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Refresh the hash if PHP's default cost has moved on.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_run('UPDATE users SET password_hash = :h WHERE id = :id', [
            ':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id'],
        ]);
    }

    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $user['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    db_run('UPDATE users SET last_login = :t WHERE id = :id', [':t' => date('Y-m-d H:i:s'), ':id' => $user['id']]);
    admin_log('signed in', $user['username']);

    return true;
}

function admin_logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function admin_log(string $action, string $detail = ''): void
{
    $user = admin_user();
    db_run(
        'INSERT INTO activity_log (user_name, action, detail, created_at) VALUES (?, ?, ?, ?)',
        [$user['username'] ?? 'system', $action, mb_substr($detail, 0, 300), date('Y-m-d H:i:s')]
    );
}

/* =============================================================== 2. FLASH */

function admin_flash(string $type, string $message): void
{
    start_session();
    $_SESSION['admin_flash'][] = ['type' => $type, 'message' => $message];
}

function admin_flash_take(): array
{
    start_session();
    $items = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);
    return $items;
}

/* ========================================================== 3. RESOURCES */

/**
 * Every editable list in the site. Keys become ?p= routes in admin.php.
 *
 * fields:  key => [label, type, required, help, options, rows, placeholder]
 * list:    column key => heading shown in the table
 */
function admin_resources(): array
{
    $resources = [
        'pages' => [
            'label'     => 'Pages',
            'singular'  => 'page',
            'group'     => 'Site structure',
            'icon'      => '▤',
            'intro'     => 'Page titles, hero headlines, navigation labels and search-engine descriptions. Use {curly braces} to highlight words in a headline and a | to force a line break.',
            'no_create' => true,
            'no_delete' => true,
            'list'      => ['nav_label' => 'Nav label', 'title' => 'Page title', 'slug' => 'Address'],
            'fields'    => [
                'nav_label'        => ['label' => 'Navigation label', 'type' => 'text', 'required' => true, 'help' => 'Short label shown in the menu.'],
                'title'            => ['label' => 'Page title', 'type' => 'text', 'required' => true, 'help' => 'Used in the browser tab and search results.'],
                'hero_label'       => ['label' => 'Small label above the headline', 'type' => 'text'],
                'hero_title'       => ['label' => 'Hero headline', 'type' => 'textarea', 'rows' => 2, 'help' => 'Example: Bring your business {to the coast.}'],
                'hero_intro'       => ['label' => 'Hero intro paragraph', 'type' => 'textarea', 'rows' => 3],
                'meta_description' => ['label' => 'Search description', 'type' => 'textarea', 'rows' => 2, 'help' => 'Around 150 characters. Shown by Google under the page title.'],
                'slug'             => ['label' => 'Address', 'type' => 'readonly', 'help' => 'The file name of this page. Fixed so links never break.'],
                'show_in_nav'      => ['label' => 'Show in the menu', 'type' => 'bool'],
                'is_published'     => ['label' => 'Published', 'type' => 'bool', 'help' => 'Turn off to hide the page from the menu.'],
                'position'         => ['label' => 'Menu order', 'type' => 'number'],
            ],
        ],

        'blocks' => [
            'label'    => 'Content cards',
            'singular' => 'card',
            'group'    => 'Site structure',
            'icon'     => '▣',
            'intro'    => 'The small repeating cards across the site: hero statistics, the Learn / Connect / Grow trio, the About numbers, "who you will meet" and the masterclass tracks.',
            'list'     => ['title' => 'Title', 'subtitle' => 'Subtitle', 'section' => 'Where it appears'],
            'filter'   => ['section' => 'Section'],
            'fields'   => [
                'section'   => ['label' => 'Where it appears', 'type' => 'select', 'required' => true, 'options' => 'admin_block_sections'],
                'title'     => ['label' => 'Title / big number', 'type' => 'text', 'required' => true],
                'subtitle'  => ['label' => 'Subtitle / small label', 'type' => 'text'],
                'body'      => ['label' => 'Description', 'type' => 'textarea', 'rows' => 3],
                'icon'      => ['label' => 'Icon character', 'type' => 'text', 'help' => 'A single symbol such as ✦ ◈ ↗ ⚡ ★. Leave blank for none.'],
                'position'  => ['label' => 'Order', 'type' => 'number'],
                'is_active' => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'programme_days' => [
            'label'    => 'Programme days',
            'singular' => 'day',
            'group'    => 'Event content',
            'icon'     => '◷',
            'intro'    => 'The day-by-day programme shown on the home page and the programme page.',
            'list'     => ['day_label' => 'Day', 'title' => 'Theme', 'date_text' => 'Date'],
            'fields'   => [
                'day_label' => ['label' => 'Day label', 'type' => 'text', 'required' => true, 'placeholder' => 'DAY 01'],
                'date_text' => ['label' => 'Date', 'type' => 'text', 'placeholder' => '30 September 2026'],
                'title'     => ['label' => 'Theme / title', 'type' => 'text', 'required' => true],
                'summary'   => ['label' => 'Short summary', 'type' => 'text', 'help' => 'One line, shown on the home page card.'],
                'details'   => ['label' => 'Full description', 'type' => 'textarea', 'rows' => 4, 'help' => 'Shown on the programme page.'],
                'position'  => ['label' => 'Order', 'type' => 'number'],
                'is_active' => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'packages' => [
            'label'    => 'Sponsorship packages',
            'singular' => 'package',
            'group'    => 'Event content',
            'icon'     => '◆',
            'intro'    => 'Sponsorship tiers shown on the home page and the packages page.',
            'list'     => ['name' => 'Package', 'tier_label' => 'Tier', 'price' => 'Price'],
            'fields'   => [
                'tier_label'  => ['label' => 'Tier label', 'type' => 'text', 'placeholder' => 'GOLD · MASTERCLASS SPONSOR'],
                'name'        => ['label' => 'Package name', 'type' => 'text', 'required' => true],
                'price'       => ['label' => 'Price', 'type' => 'text', 'placeholder' => 'N$ 25,000'],
                'summary'     => ['label' => 'One-line summary', 'type' => 'textarea', 'rows' => 2],
                'features'    => ['label' => 'What is included', 'type' => 'list', 'rows' => 8, 'help' => 'One benefit per line.'],
                'is_featured' => ['label' => 'Highlight this package', 'type' => 'bool'],
                'position'    => ['label' => 'Order', 'type' => 'number'],
                'is_active'   => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'stalls' => [
            'label'    => 'Stalls, tickets & rates',
            'singular' => 'rate',
            'group'    => 'Event content',
            'icon'     => '▦',
            'intro'    => 'The rate table on the exhibit and packages pages, and the options people choose from on the online booking form.',
            'list'     => ['category' => 'Category', 'details' => 'Details', 'rate' => 'Rate', 'kind' => 'Type'],
            'fields'   => [
                'category'        => ['label' => 'Category', 'type' => 'text', 'required' => true, 'placeholder' => 'Indoor Corporate Stall'],
                'details'         => ['label' => 'Details / specification', 'type' => 'text', 'placeholder' => '3m x 3m exhibition space'],
                'rate'            => ['label' => 'Rate', 'type' => 'text', 'required' => true, 'placeholder' => 'N$ 9,999', 'help' => 'The number in here is what the booking form charges, so keep the figure accurate.'],
                'note'            => ['label' => 'Extra note', 'type' => 'text', 'help' => 'Shown in gold under the details, e.g. a free-of-charge condition.'],
                'kind'            => ['label' => 'Type', 'type' => 'select', 'required' => true, 'options' => 'admin_stall_kinds', 'help' => 'Booking an item marked "Exhibition space" is what unlocks the free masterclass rule below.'],
                'free_with_stall' => ['label' => 'Free when a stall is also booked', 'type' => 'bool', 'help' => 'Set on the SME masterclass ticket, exactly as the official registration form states.'],
                'bookable'        => ['label' => 'Offer on the online booking form', 'type' => 'bool', 'help' => 'Turn off to show the rate on the website but not accept online bookings for it.'],
                'position'        => ['label' => 'Order', 'type' => 'number'],
                'is_active'       => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'partners' => [
            'label'    => 'Sponsors & partners',
            'singular' => 'organisation',
            'group'    => 'Event content',
            'icon'     => '❖',
            'intro'    => 'Every logo on the site, grouped by the role each organisation plays.',
            'list'     => ['logo' => 'Logo', 'name' => 'Organisation', 'role_label' => 'Role shown', 'category' => 'Group'],
            'filter'   => ['category' => 'Group'],
            'fields'   => [
                'name'       => ['label' => 'Organisation name', 'type' => 'text', 'required' => true],
                'role_label' => ['label' => 'Role shown on the card', 'type' => 'text', 'placeholder' => 'Corporate Exhibitor'],
                'category'   => ['label' => 'Group', 'type' => 'select', 'required' => true, 'options' => 'partner_categories'],
                'logo'       => ['label' => 'Logo', 'type' => 'image'],
                'dark_logo'  => ['label' => 'Logo needs a dark background', 'type' => 'bool', 'help' => 'Switch on for white or light-coloured logos.'],
                'website'    => ['label' => 'Website', 'type' => 'url'],
                'position'   => ['label' => 'Order', 'type' => 'number'],
                'is_active'  => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'speakers' => [
            'label'    => 'Speakers',
            'singular' => 'speaker',
            'group'    => 'Event content',
            'icon'     => '☺',
            'intro'    => 'Speakers and facilitators. The section stays hidden on the site until you add at least one.',
            'list'     => ['photo' => 'Photo', 'name' => 'Name', 'role' => 'Role', 'organisation' => 'Organisation'],
            'fields'   => [
                'name'         => ['label' => 'Full name', 'type' => 'text', 'required' => true],
                'role'         => ['label' => 'Role / job title', 'type' => 'text'],
                'organisation' => ['label' => 'Organisation', 'type' => 'text'],
                'photo'        => ['label' => 'Photo', 'type' => 'image'],
                'bio'          => ['label' => 'Short bio', 'type' => 'textarea', 'rows' => 3],
                'link_url'     => ['label' => 'Profile link', 'type' => 'url'],
                'position'     => ['label' => 'Order', 'type' => 'number'],
                'is_active'    => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'gallery' => [
            'label'    => 'Photo gallery',
            'singular' => 'photo',
            'group'    => 'Event content',
            'icon'     => '▩',
            'intro'    => 'Photos shown on the home page. The gallery stays hidden until you add at least one image.',
            'list'     => ['image' => 'Image', 'caption' => 'Caption'],
            'fields'   => [
                'image'     => ['label' => 'Image', 'type' => 'image', 'required' => true],
                'caption'   => ['label' => 'Caption', 'type' => 'text'],
                'position'  => ['label' => 'Order', 'type' => 'number'],
                'is_active' => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],

        'faqs' => [
            'label'    => 'Questions & answers',
            'singular' => 'question',
            'group'    => 'Event content',
            'icon'     => '?',
            'intro'    => 'Shown on the packages page and the contact page.',
            'list'     => ['question' => 'Question'],
            'fields'   => [
                'question'  => ['label' => 'Question', 'type' => 'text', 'required' => true],
                'answer'    => ['label' => 'Answer', 'type' => 'textarea', 'rows' => 5],
                'position'  => ['label' => 'Order', 'type' => 'number'],
                'is_active' => ['label' => 'Visible', 'type' => 'bool'],
            ],
        ],
    ];

    // The booking platform adds its own lists (services, opening hours and
    // blocked dates) to the same engine.
    if (function_exists('eft_admin_resources') && booking_schema_current()) {
        $resources += eft_admin_resources();
    }

    return $resources;
}

/** How a rate behaves on the booking form. */
function admin_stall_kinds(): array
{
    return [
        'stall'  => 'Exhibition space / stall',
        'ticket' => 'Masterclass ticket or pass',
        'other'  => 'Other',
    ];
}

/** The page/section pairs used by the "Content cards" resource. */
function admin_block_sections(): array
{
    return [
        'home|stats'        => 'Home — hero statistics',
        'home|focus'        => 'Home — Learn / Connect / Grow cards',
        'about|stats'       => 'About — key numbers',
        'about|features'    => 'About — what makes it different',
        'exhibit|audience'  => 'Exhibit — who you will meet',
        'programme|tracks'  => 'Programme — masterclass tracks',
    ];
}

/* ============================================================== 4. FIELDS */

/** Resolve an "options" definition, which may be a callable name or an array. */
function admin_field_options(array $field): array
{
    $options = $field['options'] ?? [];
    if (is_string($options) && function_exists($options)) {
        return $options();
    }
    return is_array($options) ? $options : [];
}

/**
 * Render one form field. $value is the current value.
 */
function admin_field(string $name, array $field, $value): void
{
    $type     = $field['type'] ?? 'text';
    $label    = $field['label'] ?? $name;
    $required = !empty($field['required']);
    $help     = $field['help'] ?? '';
    $id       = 'f_' . $name;
    $value    = (string) ($value ?? '');

    echo '<div class="a-field">';

    if ($type === 'bool') {
        $on = in_array($value, ['1', 'yes', 'true', 'on'], true);
        echo '<label class="a-switch"><input type="hidden" name="' . e($name) . '" value="0">';
        echo '<input type="checkbox" id="' . e($id) . '" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '>';
        echo '<span class="a-switch-track"></span><span class="a-switch-label">' . e($label) . '</span></label>';
        if ($help !== '') {
            echo '<p class="a-help">' . e($help) . '</p>';
        }
        echo '</div>';
        return;
    }

    echo '<label for="' . e($id) . '">' . e($label) . ($required ? ' <b>*</b>' : '') . '</label>';

    switch ($type) {
        case 'readonly':
            echo '<input type="text" id="' . e($id) . '" value="' . e($value) . '" readonly>';
            break;

        case 'textarea':
        case 'list':
            $rows = (int) ($field['rows'] ?? 4);
            echo '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="' . $rows . '"'
                . ($required ? ' required' : '') . '>' . e($value) . '</textarea>';
            break;

        case 'select':
            echo '<select id="' . e($id) . '" name="' . e($name) . '"' . ($required ? ' required' : '') . '>';
            if (!$required) {
                echo '<option value="">— none —</option>';
            }
            foreach (admin_field_options($field) as $key => $text) {
                echo '<option value="' . e((string) $key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . e($text) . '</option>';
            }
            echo '</select>';
            break;

        case 'image':
            admin_image_field($name, $id, $value, $required);
            break;

        case 'color':
            echo '<div class="a-colour"><input type="color" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value ?: '#000000') . '">'
                . '<input type="text" class="a-colour-text" value="' . e($value) . '" aria-label="' . e($label) . ' hex value"></div>';
            break;

        case 'number':
            echo '<input type="number" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '" step="1">';
            break;

        case 'decimal':
            echo '<input type="text" inputmode="decimal" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"'
                . (isset($field['placeholder']) ? ' placeholder="' . e($field['placeholder']) . '"' : '')
                . ($required ? ' required' : '') . '>';
            break;

        case 'password':
            // Never echo a stored secret back into the page.
            echo '<input type="password" id="' . e($id) . '" name="' . e($name) . '" value="" autocomplete="new-password"'
                . ' placeholder="' . ($value !== '' ? 'Saved — leave blank to keep it' : 'Not set') . '">';
            break;

        default:
            $inputType = in_array($type, ['email', 'url', 'date', 'time', 'tel', 'password'], true) ? $type : 'text';
            echo '<input type="' . $inputType . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"'
                . (isset($field['placeholder']) ? ' placeholder="' . e($field['placeholder']) . '"' : '')
                . ($required ? ' required' : '') . '>';
    }

    if ($help !== '') {
        echo '<p class="a-help">' . e($help) . '</p>';
    }
    echo '</div>';
}

/** Image chooser: existing file picker, upload box and live preview. */
function admin_image_field(string $name, string $id, string $value, bool $required): void
{
    echo '<div class="a-image" data-image-field>';
    echo '<div class="a-image-preview"><img src="' . e($value !== '' ? rawurlencode_path($value) : '') . '" alt=""'
        . ($value === '' ? ' hidden' : '') . ' data-preview></div>';
    echo '<div class="a-image-controls">';
    echo '<input type="text" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '" placeholder="images/example.png" data-path'
        . ($required ? ' required' : '') . '>';

    $library = admin_media_files();
    if ($library) {
        echo '<select data-picker aria-label="Choose an existing image"><option value="">— choose from the library —</option>';
        foreach ($library as $path) {
            echo '<option value="' . e($path) . '"' . ($path === $value ? ' selected' : '') . '>' . e($path) . '</option>';
        }
        echo '</select>';
    }

    echo '<label class="a-upload"><span>Upload a new image</span>'
        . '<input type="file" name="upload__' . e($name) . '" accept="image/*,.pdf" data-upload></label>';
    echo '</div></div>';
}

/* =============================================================== 5. MEDIA */

/** Every usable image/PDF under images/ and uploads/, as relative paths. */
function admin_media_files(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $found = [];
    foreach (['images', 'uploads'] as $dir) {
        $base = ROOT_PATH . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'pdf'], true)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(ROOT_PATH) + 1));
            $found[] = $relative;
        }
    }

    sort($found, SORT_NATURAL | SORT_FLAG_CASE);
    $cache = $found;
    return $cache;
}

/**
 * Store one uploaded file in /uploads and return its relative path,
 * or null when nothing valid was uploaded. Errors are flashed to the user.
 */
function admin_upload(string $inputName): ?string
{
    if (empty($_FILES[$inputName]) || !is_array($_FILES[$inputName])) {
        return null;
    }

    $file = $_FILES[$inputName];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        admin_flash('error', 'That file could not be uploaded (error code ' . (int) $file['error'] . ').');
        return null;
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        admin_flash('error', 'That file is larger than ' . round(MAX_UPLOAD_BYTES / 1048576) . ' MB. Please use a smaller version.');
        return null;
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif', 'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
    ];

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($file['tmp_name']);
    }
    if (!isset($allowed[$mime])) {
        admin_flash('error', 'Only JPG, PNG, GIF, WebP, AVIF, SVG images and PDF files can be uploaded.');
        return null;
    }

    // Bitmap images must actually parse as images.
    if (str_starts_with($mime, 'image/') && !in_array($mime, ['image/svg+xml', 'image/avif'], true)) {
        if (@getimagesize($file['tmp_name']) === false) {
            admin_flash('error', 'That image file appears to be damaged.');
            return null;
        }
    }

    if (!is_dir(UPLOAD_PATH) && !@mkdir(UPLOAD_PATH, 0775, true) && !is_dir(UPLOAD_PATH)) {
        admin_flash('error', 'The uploads folder could not be created. Please check folder permissions.');
        return null;
    }

    $base = pathinfo((string) $file['name'], PATHINFO_FILENAME);
    $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $base), '-'));
    if ($base === '') {
        $base = 'file';
    }
    $name = mb_substr($base, 0, 60) . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $allowed[$mime];
    $target = UPLOAD_PATH . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        admin_flash('error', 'The file could not be saved to the uploads folder.');
        return null;
    }
    @chmod($target, 0644);

    db_run(
        'INSERT INTO media (filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?)',
        ['uploads/' . $name, mb_substr((string) $file['name'], 0, 190), $mime, (int) $file['size'], date('Y-m-d H:i:s')]
    );

    return 'uploads/' . $name;
}

/* ================================================================ 6. CRUD */

/** Read one record of a resource. */
function admin_record(string $table, int $id): ?array
{
    if (!array_key_exists($table, admin_resources())) {
        return null;
    }
    return db_one("SELECT * FROM {$table} WHERE id = :id", [':id' => $id]);
}

/**
 * Save a create/update for a resource from $_POST and $_FILES.
 * Returns the record id.
 */
function admin_save(string $key, array $definition, int $id): int
{
    $table  = $key;
    $values = [];

    foreach ($definition['fields'] as $name => $field) {
        $type = $field['type'] ?? 'text';

        if ($type === 'readonly') {
            continue;
        }

        // The "Content cards" section field packs page|section into one control.
        if ($key === 'blocks' && $name === 'section') {
            $pair = explode('|', (string) ($_POST['section'] ?? 'home|focus'));
            $values['page'] = $pair[0] ?? 'home';
            $values['section'] = $pair[1] ?? 'general';
            continue;
        }

        if ($type === 'image') {
            $uploaded = admin_upload('upload__' . $name);
            $values[$name] = $uploaded ?? trim((string) ($_POST[$name] ?? ''));
            continue;
        }

        if ($type === 'bool') {
            $values[$name] = isset($_POST[$name]) && $_POST[$name] === '1' ? 1 : 0;
            continue;
        }

        if ($type === 'number') {
            $values[$name] = (int) ($_POST[$name] ?? 0);
            continue;
        }

        if ($type === 'decimal') {
            $values[$name] = round((float) str_replace([',', ' '], ['.', ''], trim((string) ($_POST[$name] ?? '0'))), 2);
            continue;
        }

        $values[$name] = trim((string) ($_POST[$name] ?? ''));
    }

    if ($id > 0) {
        $sets = [];
        $params = [':id' => $id];
        foreach ($values as $column => $value) {
            $sets[] = "{$column} = :{$column}";
            $params[":{$column}"] = $value;
        }
        db_run("UPDATE {$table} SET " . implode(', ', $sets) . ' WHERE id = :id', $params);
        admin_log('updated ' . $definition['singular'], $table . '#' . $id);
        admin_after_save($definition, $id, $values);
        return $id;
    }

    $columns = array_keys($values);
    $holders = array_map(static fn ($c) => ':' . $c, $columns);
    $params = [];
    foreach ($values as $column => $value) {
        $params[':' . $column] = $value;
    }
    db_run(
        "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $holders) . ')',
        $params
    );
    $newId = (int) db()->lastInsertId();
    admin_log('created ' . $definition['singular'], $table . '#' . $newId);
    admin_after_save($definition, $newId, $values);
    return $newId;
}

/**
 * Let a resource finish its own save — generating a slug, stamping a
 * timestamp, and so on. A resource opts in with 'after_save' => 'function_name'.
 */
function admin_after_save(array $definition, int $id, array $values): void
{
    $hook = $definition['after_save'] ?? null;
    if (is_string($hook) && $hook !== '' && function_exists($hook)) {
        $hook($id, $values);
    }
}

function admin_delete(string $table, int $id): void
{
    if (!array_key_exists($table, admin_resources())) {
        return;
    }
    db_run("DELETE FROM {$table} WHERE id = :id", [':id' => $id]);
    admin_log('deleted from ' . $table, '#' . $id);
}

/** Move a record one place up or down within its list. */
function admin_reorder(string $table, int $id, string $direction): void
{
    $resources = admin_resources();
    if (!isset($resources[$table]) || !isset($resources[$table]['fields']['position'])) {
        return;
    }

    $rows = db_all("SELECT id, position FROM {$table} ORDER BY position, id");
    $index = null;
    foreach ($rows as $i => $row) {
        if ((int) $row['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        return;
    }

    $swap = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swap < 0 || $swap >= count($rows)) {
        return;
    }

    // Rewrite the whole list so positions stay clean even if they had gaps.
    $order = array_column($rows, 'id');
    [$order[$index], $order[$swap]] = [$order[$swap], $order[$index]];
    foreach ($order as $position => $rowId) {
        db_run("UPDATE {$table} SET position = :p WHERE id = :id", [':p' => $position, ':id' => $rowId]);
    }
}

/** Flip the visible flag of a record. */
function admin_toggle(string $table, int $id): void
{
    $resources = admin_resources();
    $column = isset($resources[$table]['fields']['is_active']) ? 'is_active'
        : (isset($resources[$table]['fields']['is_published']) ? 'is_published' : null);
    if ($column === null) {
        return;
    }
    db_run("UPDATE {$table} SET {$column} = CASE {$column} WHEN 1 THEN 0 ELSE 1 END WHERE id = :id", [':id' => $id]);
}

/* ============================================================ 7. SETTINGS */

/**
 * Settings grouped into the tabs of the Site settings screen.
 */
function admin_setting_groups(): array
{
    $groups = [
        'identity' => [
            'label'  => 'Identity',
            'intro'  => 'The name, logo and organisation behind the event.',
            'fields' => [
                'site_name'         => ['label' => 'Short site name', 'type' => 'text'],
                'event_name'        => ['label' => 'Full event name', 'type' => 'text'],
                'event_tagline'     => ['label' => 'Tagline', 'type' => 'text'],
                'logo'              => ['label' => 'Logo', 'type' => 'image'],
                'org_name'          => ['label' => 'Host organisation', 'type' => 'text'],
                'org_reg_no'        => ['label' => 'Registration number', 'type' => 'text'],
                'org_collaboration' => ['label' => 'Collaboration note', 'type' => 'textarea', 'rows' => 2],
                'footer_note'       => ['label' => 'Footer note', 'type' => 'text'],
            ],
        ],

        'event' => [
            'label'  => 'Event & countdown',
            'intro'  => 'Dates drive the live countdown clock on every page. Change them here and the whole site follows.',
            'fields' => [
                'event_start_date'  => ['label' => 'Start date', 'type' => 'date', 'required' => true],
                'event_start_time'  => ['label' => 'Start time', 'type' => 'time'],
                'event_end_date'    => ['label' => 'End date', 'type' => 'date', 'required' => true],
                'event_end_time'    => ['label' => 'End time', 'type' => 'time'],
                'event_timezone'    => ['label' => 'Time zone', 'type' => 'select', 'options' => 'admin_timezones'],
                'countdown_enabled' => ['label' => 'Show the countdown clock', 'type' => 'bool'],
                'countdown_label'   => ['label' => 'Countdown label', 'type' => 'text', 'help' => 'Shown above the clock while the event is still ahead.'],
                'countdown_live'    => ['label' => 'Message while the event is running', 'type' => 'text'],
                'countdown_done'    => ['label' => 'Message after the event', 'type' => 'text'],
                'venue_name'        => ['label' => 'Venue name', 'type' => 'text'],
                'venue_address'     => ['label' => 'Venue address', 'type' => 'text'],
                'venue_city'        => ['label' => 'City / country', 'type' => 'text'],
                'venue_map_url'     => ['label' => 'Map link', 'type' => 'url'],
                'venue_image'       => ['label' => 'Venue photo', 'type' => 'image'],
            ],
        ],

        'contact' => [
            'label'  => 'Contact details',
            'intro'  => 'Used in the header, footer, contact page and on every enquiry notification.',
            'fields' => [
                'email_primary'    => ['label' => 'Main email address', 'type' => 'email'],
                'email_secondary'  => ['label' => 'Second email address', 'type' => 'email'],
                'email_form_to'    => ['label' => 'Send enquiries to', 'type' => 'text', 'help' => 'One or more addresses, separated by commas.'],
                'phone_1'          => ['label' => 'Phone 1', 'type' => 'text'],
                'phone_2'          => ['label' => 'Phone 2', 'type' => 'text'],
                'phone_3'          => ['label' => 'Phone 3', 'type' => 'text'],
                'whatsapp'         => ['label' => 'WhatsApp number', 'type' => 'text'],
                'postal_address'   => ['label' => 'Postal address', 'type' => 'text'],
                'physical_address' => ['label' => 'Physical address', 'type' => 'textarea', 'rows' => 2],
            ],
        ],

        'home' => [
            'label'  => 'Home page',
            'intro'  => 'Every headline and paragraph on the home page. Wrap words in {curly braces} to colour them, and use | to force a line break.',
            'fields' => [
                'hero_eyebrow'    => ['label' => 'Hero small label', 'type' => 'text'],
                'hero_title'      => ['label' => 'Hero headline', 'type' => 'textarea', 'rows' => 2],
                'hero_text'       => ['label' => 'Hero paragraph', 'type' => 'textarea', 'rows' => 3],
                'hero_cta_text'   => ['label' => 'Main button text', 'type' => 'text'],
                'hero_cta_link'   => ['label' => 'Main button link', 'type' => 'text'],
                'hero_alt_text'   => ['label' => 'Second button text', 'type' => 'text'],
                'hero_alt_link'   => ['label' => 'Second button link', 'type' => 'text'],
                'hero_image'      => ['label' => 'Hero image', 'type' => 'image'],
                'hero_image_alt'  => ['label' => 'Hero image description', 'type' => 'text', 'help' => 'Read aloud by screen readers.'],
                'about_label'     => ['label' => 'About — small label', 'type' => 'text'],
                'about_title'     => ['label' => 'About — headline', 'type' => 'textarea', 'rows' => 2],
                'about_text'      => ['label' => 'About — paragraph', 'type' => 'textarea', 'rows' => 4],
                'showcase_label'  => ['label' => 'Showcase — small label', 'type' => 'text'],
                'showcase_title'  => ['label' => 'Showcase — headline', 'type' => 'textarea', 'rows' => 2],
                'showcase_text'   => ['label' => 'Showcase — paragraph', 'type' => 'textarea', 'rows' => 3],
                'showcase_image'  => ['label' => 'Showcase — image', 'type' => 'image'],
                'showcase_tags'   => ['label' => 'Showcase — tags', 'type' => 'list', 'rows' => 4, 'help' => 'One tag per line.'],
                'programme_label' => ['label' => 'Programme — small label', 'type' => 'text'],
                'programme_title' => ['label' => 'Programme — headline', 'type' => 'textarea', 'rows' => 2],
                'exhibit_label'   => ['label' => 'Exhibit — small label', 'type' => 'text'],
                'exhibit_title'   => ['label' => 'Exhibit — headline', 'type' => 'textarea', 'rows' => 2],
                'exhibit_text'    => ['label' => 'Exhibit — paragraph', 'type' => 'textarea', 'rows' => 3],
                'venue_label'     => ['label' => 'Venue — small label', 'type' => 'text'],
                'venue_title'     => ['label' => 'Venue — headline', 'type' => 'textarea', 'rows' => 2],
                'venue_text'      => ['label' => 'Venue — paragraph', 'type' => 'textarea', 'rows' => 3],
                'partners_label'  => ['label' => 'Partners — small label', 'type' => 'text'],
                'partners_title'  => ['label' => 'Partners — headline', 'type' => 'textarea', 'rows' => 2],
                'partners_text'   => ['label' => 'Partners — paragraph', 'type' => 'textarea', 'rows' => 3],
                'packages_label'  => ['label' => 'Packages — small label', 'type' => 'text'],
                'packages_title'  => ['label' => 'Packages — headline', 'type' => 'textarea', 'rows' => 2],
                'packages_text'   => ['label' => 'Packages — paragraph', 'type' => 'textarea', 'rows' => 3],
                'cta_title'       => ['label' => 'Closing banner — headline', 'type' => 'textarea', 'rows' => 2],
                'cta_text'        => ['label' => 'Closing banner — paragraph', 'type' => 'textarea', 'rows' => 2],
                'contact_label'   => ['label' => 'Contact — small label', 'type' => 'text'],
                'contact_title'   => ['label' => 'Contact — headline', 'type' => 'textarea', 'rows' => 2],
                'contact_text'    => ['label' => 'Contact — paragraph', 'type' => 'textarea', 'rows' => 3],
            ],
        ],

        'registration' => [
            'label'  => 'Registration & payment',
            'intro'  => 'The banking details and terms shown on the packages page, taken from the official registration form.',
            'fields' => [
                'registration_open'   => ['label' => 'Registration is open', 'type' => 'bool', 'help' => 'Turn off to hide the Register button in the menu.'],
                'registration_form'   => ['label' => 'Registration form (PDF)', 'type' => 'image'],
                'bank_account_name'   => ['label' => 'Account name', 'type' => 'text'],
                'bank_name'           => ['label' => 'Bank', 'type' => 'text'],
                'bank_account_number' => ['label' => 'Account number', 'type' => 'text', 'help' => 'Leave blank to hide the whole payment section from the site.'],
                'bank_branch_code'    => ['label' => 'Branch code', 'type' => 'text'],
                'bank_account_type'   => ['label' => 'Account type', 'type' => 'text'],
                'payment_reference'   => ['label' => 'Payment reference', 'type' => 'text'],
                'payment_proof_email' => ['label' => 'Send proof of payment to', 'type' => 'email'],
                'terms_confirmation'  => ['label' => 'Terms — confirmation', 'type' => 'textarea', 'rows' => 2],
                'terms_allocations'   => ['label' => 'Terms — allocations', 'type' => 'textarea', 'rows' => 2],
                'terms_cancellations' => ['label' => 'Terms — cancellations', 'type' => 'textarea', 'rows' => 2],
            ],
        ],

        'social' => [
            'label'  => 'Social links',
            'intro'  => 'Leave a field blank to hide that link. Paste the full address including https://',
            'fields' => [
                'facebook'  => ['label' => 'Facebook', 'type' => 'url'],
                'instagram' => ['label' => 'Instagram', 'type' => 'url'],
                'twitter'   => ['label' => 'X (Twitter)', 'type' => 'url'],
                'linkedin'  => ['label' => 'LinkedIn', 'type' => 'url'],
                'youtube'   => ['label' => 'YouTube', 'type' => 'url'],
            ],
        ],

        'appearance' => [
            'label'  => 'Appearance & SEO',
            'intro'  => 'Brand colours and the information search engines and social networks use.',
            'fields' => [
                'theme_accent'     => ['label' => 'Accent colour', 'type' => 'color'],
                'theme_gold'       => ['label' => 'Highlight colour', 'type' => 'color'],
                'theme_ink'        => ['label' => 'Dark background colour', 'type' => 'color'],
                'meta_description' => ['label' => 'Default search description', 'type' => 'textarea', 'rows' => 3],
                'og_image'         => ['label' => 'Sharing image', 'type' => 'image', 'help' => 'Shown when the site is shared on WhatsApp, Facebook or LinkedIn.'],
                'analytics_code'   => ['label' => 'Analytics / tracking code', 'type' => 'textarea', 'rows' => 5, 'help' => 'Advanced: pasted into the page head exactly as entered. Leave blank if unsure.'],
            ],
        ],
    ];

    // The booking platform adds its own tabs.
    if (function_exists('eft_admin_setting_groups') && booking_schema_current()) {
        $groups += eft_admin_setting_groups();
    }

    return $groups;
}

function admin_timezones(): array
{
    $zones = [];
    foreach (['Africa/Windhoek', 'Africa/Johannesburg', 'Africa/Gaborone', 'Africa/Lusaka', 'Africa/Luanda', 'UTC', 'Europe/London', 'Europe/Berlin'] as $zone) {
        $zones[$zone] = $zone;
    }
    return $zones;
}

/** Persist one settings tab from $_POST / $_FILES. */
function admin_settings_save(array $group): void
{
    foreach ($group['fields'] as $key => $field) {
        $type = $field['type'] ?? 'text';

        if ($type === 'image') {
            $uploaded = admin_upload('upload__' . $key);
            setting_save($key, $uploaded ?? trim((string) ($_POST[$key] ?? '')));
            continue;
        }
        if ($type === 'bool') {
            setting_save($key, isset($_POST[$key]) && $_POST[$key] === '1' ? '1' : '0');
            continue;
        }
        if ($type === 'password') {
            // Blank means "leave the stored secret alone".
            $secret = (string) ($_POST[$key] ?? '');
            if ($secret !== '') {
                setting_save($key, $secret);
            }
            continue;
        }
        if (!array_key_exists($key, $_POST)) {
            continue;
        }
        // The analytics field is intentionally raw HTML; every other field is plain text.
        $value = (string) $_POST[$key];
        setting_save($key, $key === 'analytics_code' ? $value : trim($value));
    }

    settings(true);
    admin_log('updated settings', $group['label']);
}

/* ============================================================== 8. HEALTH */

/** Deployment checks shown on the dashboard. */
function admin_health(): array
{
    $checks = [];

    $checks[] = [
        'label' => 'Uploads folder is writable',
        'ok'    => is_dir(UPLOAD_PATH) && is_writable(UPLOAD_PATH),
        'hint'  => 'Give the web server write permission on the /uploads folder so images can be added.',
    ];
    $checks[] = [
        'label' => 'Database folder is writable',
        'ok'    => is_writable(DATA_PATH) && is_writable(DB_FILE),
        'hint'  => 'The /data folder must be writable or content changes cannot be saved.',
    ];
    $checks[] = [
        'label' => 'Installer has been locked',
        'ok'    => is_file(LOCK_FILE),
        'hint'  => 'Run setup.php once to lock the installer.',
    ];
    $checks[] = [
        'label' => 'setup.php removed from the server',
        'ok'    => !is_file(ROOT_PATH . '/setup.php'),
        'hint'  => 'Optional but recommended once the site is live: delete setup.php.',
    ];
    $checks[] = [
        'label' => 'Email sending is available',
        'ok'    => function_exists('mail'),
        'hint'  => 'Enquiries are always stored here in the panel, even when the server cannot send email.',
    ];
    $checks[] = [
        'label' => 'Secure connection (HTTPS)',
        'ok'    => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true),
        'hint'  => 'Ask your host to enable an SSL certificate before going live.',
    ];

    // The booking platform adds its own readiness checks.
    if (function_exists('eft_admin_health') && booking_schema_current()) {
        $checks = array_merge($checks, eft_admin_health());
    }

    return $checks;
}

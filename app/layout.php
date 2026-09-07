<?php
declare(strict_types=1);

/**
 * Shared public-site chrome: <head>, header/navigation, countdown and footer.
 * Every value rendered here comes from the database, so the admin panel
 * controls the whole frame of the site.
 */

/**
 * Opens the document and prints everything up to <body>.
 *
 * The two optional arguments let a page that has no row in the pages table
 * (the booking screens, the client account area) supply its own title and
 * description. Existing pages call this exactly as before.
 */
function site_head(string $slug, string $titleOverride = '', string $descriptionOverride = '', bool $noIndex = false): array
{
    $page = page_meta($slug);
    $title = $slug === 'home'
        ? setting('event_name')
        : ($page['title'] ?: ucfirst($slug)) . ' | ' . setting('site_name');
    if ($titleOverride !== '') {
        $title = $titleOverride . ' | ' . setting('site_name');
    }
    $description = $descriptionOverride !== ''
        ? $descriptionOverride
        : ($page['meta_description'] ?: setting('meta_description'));
    $ogImage = img_src(setting('og_image'), img_src(setting('logo')));
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if ($noIndex): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:image" content="<?= e($ogImage) ?>">
<meta name="theme-color" content="<?= e(setting('theme_ink', '#04121f')) ?>">
<link rel="icon" href="<?= e(site_image(setting('logo'), 32)) ?>">
<link rel="apple-touch-icon" href="<?= e(site_image(setting('logo'), 180)) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/site.css')) ?>?v=<?= e(asset_version('assets/site.css')) ?>">
<style>:root{--accent:<?= e(setting('theme_accent', '#22c9f0')) ?>;--gold:<?= e(setting('theme_gold', '#f2b544')) ?>;--ink:<?= e(setting('theme_ink', '#04121f')) ?>;}</style>
<script>document.documentElement.classList.add('js');</script>
<?= setting('analytics_code') ?>
</head>
<body class="page-<?= e($slug) ?><?= $slug === 'home' ? '' : ' subpage' ?>">
<a class="skip-link" href="#main">Skip to content</a>
    <?php
    return $page;
}

/** Cache-busting token so CSS/JS edits show up immediately. */
function asset_version(string $relative): string
{
    $file = ROOT_PATH . '/' . ltrim($relative, '/');
    return is_file($file) ? (string) filemtime($file) : '1';
}

function site_header(string $slug): void
{
    $logo = site_image(setting('logo'), 110);
    ?>
<header class="site-header" id="siteHeader">
  <div class="header-inner">
    <a class="brand" href="<?= e(url('index.php')) ?>" aria-label="<?= e(setting('event_name')) ?> — home">
      <img src="<?= e($logo) ?>" alt="<?= e(setting('event_name')) ?>">
    </a>
    <button class="menu-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="mainNav">
      <span></span><span></span><span></span>
    </button>
    <nav class="main-nav" id="mainNav" aria-label="Main navigation">
      <?php foreach (nav_pages() as $item): ?>
        <a href="<?= e(page_url((string) $item['slug'])) ?>"<?= $item['slug'] === $slug ? ' class="active" aria-current="page"' : '' ?>><?= e($item['nav_label']) ?></a>
      <?php endforeach; ?>
      <?php if (setting_bool('registration_open', true) && setting('nav_cta_text') !== ''): ?>
        <a class="nav-cta" href="<?= e(url(setting('nav_cta_link', 'booking/'))) ?>"><?= e(setting('nav_cta_text', 'Book online')) ?> <span aria-hidden="true">↗</span></a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main id="main">
    <?php
}

/**
 * The live countdown clock. `$variant` is "hero" on the home page and
 * "bar" for the compact strip used on inner pages.
 */
function countdown(string $variant = 'hero'): void
{
    if (!setting_bool('countdown_enabled', true)) {
        return;
    }
    $phase = event_phase();
    ?>
<div class="countdown countdown-<?= e($variant) ?> is-<?= e($phase) ?>"
     data-countdown
     data-start="<?= e(event_start()->format(DateTimeInterface::ATOM)) ?>"
     data-end="<?= e(event_end()->format(DateTimeInterface::ATOM)) ?>"
     data-live="<?= e(setting('countdown_live', 'The fair is live right now')) ?>"
     data-done="<?= e(setting('countdown_done', 'Thank you for joining us')) ?>">
  <p class="countdown-label"><span class="pulse" aria-hidden="true"></span><span data-countdown-label><?= e(setting('countdown_label', 'Doors open in')) ?></span></p>
  <div class="countdown-clock" data-countdown-clock role="timer" aria-live="off">
    <?php foreach (['days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'] as $key => $label): ?>
      <div class="cd-unit">
        <strong data-cd="<?= e($key) ?>">--</strong>
        <span><?= e($label) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <p class="countdown-message" data-countdown-message hidden></p>
  <p class="countdown-date"><?= e(event_date_range()) ?> · <?= e(setting('venue_city')) ?></p>
</div>
    <?php
}

function site_footer(): void
{
    $logo = site_image(setting('logo'), 110);
    $socials = array_filter([
        'Facebook'  => setting('facebook'),
        'Instagram' => setting('instagram'),
        'X'         => setting('twitter'),
        'LinkedIn'  => setting('linkedin'),
        'YouTube'   => setting('youtube'),
    ]);
    $phones = array_filter([setting('phone_1'), setting('phone_2'), setting('phone_3')]);
    ?>
</main>
<footer class="site-footer">
  <div class="shell footer-grid">
    <div class="footer-brand">
      <a class="brand" href="<?= e(url('index.php')) ?>"><img src="<?= e($logo) ?>" alt="<?= e(setting('event_name')) ?>"></a>
      <p><?= e(setting('event_tagline')) ?></p>
      <?php if ($socials): ?>
        <div class="footer-social">
          <?php foreach ($socials as $label => $url): ?>
            <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($label) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="footer-col">
      <h3>The event</h3>
      <p><strong><?= e(event_date_range()) ?></strong></p>
      <p><?= e(setting('venue_name')) ?><br><?= e(setting('venue_address')) ?></p>
      <?php if (setting('venue_map_url') !== ''): ?>
        <p><a href="<?= e(setting('venue_map_url')) ?>" target="_blank" rel="noopener">View on the map ↗</a></p>
      <?php endif; ?>
    </div>

    <div class="footer-col">
      <h3>Talk to us</h3>
      <p><a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a></p>
      <?php if (setting('email_secondary') !== ''): ?>
        <p><a href="mailto:<?= e(setting('email_secondary')) ?>"><?= e(setting('email_secondary')) ?></a></p>
      <?php endif; ?>
      <?php foreach ($phones as $phone): ?>
        <p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></p>
      <?php endforeach; ?>
    </div>

    <div class="footer-col">
      <h3>Quick links</h3>
      <?php foreach (nav_pages() as $item): ?>
        <p><a href="<?= e(page_url((string) $item['slug'])) ?>"><?= e($item['nav_label']) ?></a></p>
      <?php endforeach; ?>
      <?php if (setting('registration_form') !== ''): ?>
        <p><a href="<?= e(url(rawurlencode_path(setting('registration_form')))) ?>" download>Registration form ↓</a></p>
      <?php endif; ?>
      <?php if (function_exists('bk_enabled') && bk_enabled() && setting_bool('bk_nav_enabled', true)): ?>
        <p><a href="<?= e(url('booking/')) ?>">Book online</a></p>
        <p><a href="<?= e(url(client_logged_in() ? 'booking/account.php' : 'auth/login.php')) ?>"><?= client_logged_in() ? 'My bookings' : 'Sign in' ?></a></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="shell footer-bottom">
    <span>© <?= date('Y') ?> <?= e(setting('event_name')) ?></span>
    <span><?= e(setting('org_name')) ?><?= setting('org_reg_no') !== '' ? ' · Reg. ' . e(setting('org_reg_no')) : '' ?></span>
    <span><?= e(setting('venue_city')) ?></span>
  </div>
  <div class="shell footer-note"><?= e(setting('footer_note')) ?></div>
</footer>
<script src="<?= e(url('assets/site.js')) ?>?v=<?= e(asset_version('assets/site.js')) ?>"></script>
</body>
</html>
    <?php
}

/**
 * A site image at a sensible size for where it is shown.
 * ---------------------------------------------------------------------------
 * The photographs and logos in this site are full print resolution — the logo
 * alone is over 1.5 MB, and it appears in the header, the footer and the
 * browser tab of every single page. Sending that to somebody on a phone is a
 * waste of their data and makes the site feel slow.
 *
 * This returns the address of a smaller copy, generated once and cached under
 * uploads/cache. The original file is never touched, and if anything at all
 * goes wrong — no GD, an unreadable file, an unwritable folder — the original
 * address is returned and the page still works.
 *
 * @param int $width the widest the image is ever shown, in CSS pixels;
 *                   the copy is made at twice that, so it stays sharp on
 *                   high-resolution screens.
 */
function site_image(string $path, int $width): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^(https?:)?//#i', $path) || preg_match('#^data:#i', $path)) {
        return rawurlencode_path($path);
    }

    $cached = image_resized(ltrim($path, '/'), max(16, $width) * 2);
    return url(rawurlencode_path($cached ?? ltrim($path, '/')));
}

/**
 * Make (or reuse) a scaled copy of a site image.
 *
 * @return string|null the new path relative to the site root, or null to use
 *                     the original
 */
function image_resized(string $relative, int $maxWidth): ?string
{
    static $memo = [];
    $key = $relative . '|' . $maxWidth;
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    $memo[$key] = null;

    if (str_contains($relative, '..') || !function_exists('imagecreatetruecolor')) {
        return null;
    }

    $source = realpath(ROOT_PATH . '/' . $relative);
    $root   = realpath(ROOT_PATH);
    if ($source === false || $root === false || !str_starts_with($source, $root) || !is_file($source)) {
        return null;
    }

    $info = @getimagesize($source);
    if ($info === false || (int) $info[0] <= $maxWidth) {
        return null;                                                        // already small enough
    }

    $type = (int) $info[2];
    if (!in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return null;                                                        // GIF, SVG, anything else: leave alone
    }

    // Several of the photographs on this site were saved as PNG, which stores
    // them several times larger than they need to be. When a PNG has no
    // transparency to lose, the copy is written as a JPEG instead. Logos and
    // anything with an alpha channel stay PNG.
    $output = $type;
    if ($type === IMAGETYPE_PNG && !image_has_alpha($source)) {
        $output = IMAGETYPE_JPEG;
    }

    $extension = match ($output) {
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
        default        => 'png',
    };

    $cacheDir = UPLOAD_PATH . '/cache';
    $stamp = (string) @filemtime($source);
    $name = 'img-' . substr(hash('sha256', $relative . '|' . $stamp . '|' . $maxWidth), 0, 20) . '.' . $extension;
    $target = $cacheDir . '/' . $name;
    $public = 'uploads/cache/' . $name;

    if (is_file($target)) {
        return $memo[$key] = $public;
    }

    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return null;
    }
    if (!is_writable($cacheDir)) {
        return null;
    }

    $image = match ($type) {
        IMAGETYPE_PNG  => @imagecreatefrompng($source),
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
        default        => false,
    };
    if (!$image) {
        return null;
    }

    $sourceWidth  = imagesx($image);
    $sourceHeight = imagesy($image);
    $targetWidth  = $maxWidth;
    $targetHeight = max(1, (int) round($sourceHeight * ($targetWidth / $sourceWidth)));

    $small = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($output === IMAGETYPE_PNG || $output === IMAGETYPE_WEBP) {
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, (int) imagecolorallocatealpha($small, 0, 0, 0, 127));
    } else {
        // JPEG has no transparency: start from white rather than black.
        imagefill($small, 0, 0, (int) imagecolorallocate($small, 255, 255, 255));
    }
    imagecopyresampled($small, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    $written = match ($output) {
        IMAGETYPE_JPEG => @imagejpeg($small, $target, 82),
        IMAGETYPE_WEBP => @imagewebp($small, $target, 82),
        default        => @imagepng($small, $target, 9),
    };

    imagedestroy($image);
    imagedestroy($small);

    if (!$written || !is_file($target)) {
        return null;
    }
    @chmod($target, 0644);

    return $memo[$key] = $public;
}

/**
 * Does this PNG actually carry transparency?
 *
 * Read from the file's own header rather than by scanning pixels: byte 25 is
 * the IHDR colour type — 4 and 6 always have an alpha channel, and a palette
 * image (3) only does when a tRNS chunk is present.
 */
function image_has_alpha(string $file): bool
{
    $handle = @fopen($file, 'rb');
    if ($handle === false) {
        return true;                                                        // unsure: keep the safe format
    }

    $header = (string) fread($handle, 26);
    if (strlen($header) < 26 || substr($header, 1, 3) !== 'PNG') {
        fclose($handle);
        return true;
    }

    $colourType = ord($header[25]);
    if ($colourType === 4 || $colourType === 6) {
        fclose($handle);
        return true;
    }
    if ($colourType !== 3) {
        fclose($handle);
        return false;                                                       // plain greyscale or RGB
    }

    // Palette image: look for a tRNS chunk in the first part of the file.
    $chunk = (string) fread($handle, 262144);
    fclose($handle);

    return str_contains($chunk, 'tRNS');
}

/** URL-encode each path segment so filenames with spaces still resolve. */
function rawurlencode_path(string $path): string
{
    if (preg_match('#^(https?:)?//#i', $path)) {
        return $path;
    }
    $parts = explode('/', ltrim($path, '/'));
    return implode('/', array_map('rawurlencode', $parts));
}

/** Small reusable "section heading" block. */
function section_head(string $label, string $title, string $text = ''): void
{
    ?>
<div class="section-head reveal">
  <?php if ($label !== ''): ?><p class="label"><?= e($label) ?></p><?php endif; ?>
  <h2><?= headline($title) ?></h2>
  <?php if ($text !== ''): ?><div class="section-head-text"><?= rich($text) ?></div><?php endif; ?>
</div>
    <?php
}

/** Standard inner-page hero. */
function page_hero(array $page): void
{
    ?>
<section class="page-hero">
  <div class="hero-glow" aria-hidden="true"></div>
  <div class="shell">
    <?php if ($page['hero_label'] !== ''): ?><p class="label"><?= e($page['hero_label']) ?></p><?php endif; ?>
    <h1><?= headline($page['hero_title'] ?: $page['title']) ?></h1>
    <?php if ($page['hero_intro'] !== ''): ?><p class="lede"><?= nl($page['hero_intro']) ?></p><?php endif; ?>
    <?php countdown('bar'); ?>
  </div>
</section>
    <?php
}

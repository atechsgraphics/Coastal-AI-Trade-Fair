<?php
declare(strict_types=1);

/**
 * The page shown when an address does not exist.
 *
 * Wired up by .htaccess (ErrorDocument) so a mistyped or out-of-date link
 * lands somewhere branded and useful instead of the web server's default.
 * Kept deliberately light: no database work beyond the site's own settings,
 * so it still renders when something else is wrong.
 */

require __DIR__ . '/inc/bootstrap.php';

if (!is_installed()) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title>'
        . '<body style="font-family:system-ui,sans-serif;margin:4rem auto;max-width:32rem;padding:0 1.25rem">'
        . '<h1>Page not found</h1><p>That address does not exist.</p></body>';
    exit;
}

require __DIR__ . '/inc/layout.php';

http_response_code(404);

$page = site_head('404', 'Page not found', 'That address does not exist on this site.', true);
site_header('404');
?>

<section class="page-hero">
  <div class="hero-glow" aria-hidden="true"></div>
  <div class="shell">
    <p class="label">404</p>
    <h1><?= headline('This page has {moved on.}') ?></h1>
    <p class="lede">
      The address you followed does not exist. It may have been renamed, or the link
      that brought you here may be out of date.
    </p>
  </div>
</section>

<section class="section">
  <div class="shell bk-narrow">
    <div class="bk-panel">
      <h2 class="bk-panel-title">Try one of these instead</h2>
      <ul class="terms-list">
        <li><a class="text-link" href="index.php">The home page</a></li>
        <?php foreach (nav_pages() as $item): ?>
          <li><a class="text-link" href="<?= e($item['slug']) ?>.php"><?= e($item['nav_label']) ?></a></li>
        <?php endforeach; ?>
        <?php if (function_exists('bk_enabled') && bk_enabled()): ?>
          <li><a class="text-link" href="booking.php">Book online</a></li>
          <li><a class="text-link" href="account.php">My bookings</a></li>
        <?php endif; ?>
      </ul>

      <p class="bk-muted" style="margin-top:1.5rem">
        Still stuck? Email <a href="mailto:<?= e(setting('email_primary')) ?>"><?= e(setting('email_primary')) ?></a><?php
        if (setting('phone_1') !== ''): ?> or call <?= e(setting('phone_1')) ?><?php endif; ?>.
      </p>

      <p style="margin-top:1.5rem">
        <a class="btn btn-primary" href="index.php">Back to the home page <span aria-hidden="true">→</span></a>
      </p>
    </div>
  </div>
</section>

<?php site_footer(); ?>

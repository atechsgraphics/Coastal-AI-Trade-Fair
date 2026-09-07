<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';

$page    = site_head('partners');
$grouped = partners_by_category();
$roles   = partner_categories();

site_header('partners');
page_hero($page);

/** One role section: heading plus a grid of logo cards. */
function partner_block(string $heading, array $items, bool $featured = false): void
{
    if (!$items) {
        return;
    }
    ?>
  <div class="role-block">
    <div class="role-head">
      <h3><?= e($heading) ?></h3>
      <strong><?= count($items) ?> <?= count($items) === 1 ? 'organisation' : 'organisations' ?></strong>
    </div>
    <div class="logo-grid<?= $featured ? ' featured' : '' ?>">
      <?php foreach ($items as $p): ?>
        <article class="logo-card<?= $featured ? ' featured' : '' ?><?= (int) $p['dark_logo'] ? ' on-dark-logo' : '' ?> reveal">
          <div class="logo-media">
            <?php if ($p['logo'] !== ''): ?>
              <img src="<?= e(site_image($p['logo'], 240)) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            <?php endif; ?>
          </div>
          <div>
            <?php if ($p['role_label'] !== ''): ?><span><?= e($p['role_label']) ?></span><?php endif; ?>
            <h4><?= e($p['name']) ?></h4>
            <?php if ($p['website'] !== ''): ?>
              <p style="margin-top:.5rem"><a class="text-link" href="<?= e($p['website']) ?>" target="_blank" rel="noopener">Visit site <span aria-hidden="true">↗</span></a></p>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
    <?php
}
?>

<section class="section">
  <div class="shell light-panel">
    <?php
    partner_block('Main sponsor & sponsors', array_merge($grouped['sponsor_main'] ?? [], $grouped['sponsor'] ?? []), true);
    partner_block('Partners & collaboration', $grouped['partner'] ?? []);
    partner_block('Corporate exhibitors', $grouped['exhibitor'] ?? []);
    partner_block('Host foundation', $grouped['foundation'] ?? [], true);
    ?>
  </div>
</section>

<section class="section on-dark cta-band">
  <div class="shell reveal">
    <p class="label" style="justify-content:center">JOIN THE NETWORK</p>
    <h2>There is still room for <em>your logo.</em></h2>
    <p>Sponsorship, partnership and corporate exhibitor places for <?= e(date('Y', strtotime(setting('event_start_date')))) ?> are open.</p>
    <div class="btn-row">
      <a class="btn btn-primary" href="contact.php?interest=<?= rawurlencode('Sponsorship') ?>#enquiry">Become a sponsor <span aria-hidden="true">→</span></a>
      <a class="btn btn-ghost" href="packages.php">See the packages <span aria-hidden="true">↗</span></a>
    </div>
  </div>
</section>

<?php site_footer(); ?>

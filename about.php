<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';

$page     = site_head('about');
$stats    = blocks('about', 'stats');
$features = blocks('about', 'features');

site_header('about');
page_hero($page);
?>

<section class="section">
  <div class="shell">
    <div class="intro-grid">
      <h2 class="reveal">One flagship gathering.<br><em>Real regional impact.</em></h2>
      <div class="reveal reveal-delay-1 lede">
        <p>Hosted at the <?= e(setting('venue_name')) ?> in <?= e(setting('venue_city')) ?>, the fair connects corporate leaders, tech innovators, policy-makers and local entrepreneurs under one roof.</p>
        <p>Designed to bridge the gap between emerging technology and regional commerce, it turns big ideas into practical tools, partnerships and sustainable value chains along Namibia's coast.</p>
      </div>
    </div>

    <?php if ($stats): ?>
      <div class="stat-strip reveal">
        <?php foreach ($stats as $stat): ?>
          <div><strong><?= e($stat['title']) ?></strong><span><?= e($stat['subtitle']) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($features): ?>
  <section class="section on-dark">
    <div class="shell">
      <?php section_head('WHAT MAKES IT DIFFERENT', 'Built to be {useful} long after the doors close.'); ?>
      <div class="feature-list">
        <?php foreach ($features as $i => $item): ?>
          <article class="reveal reveal-delay-<?= min($i, 3) ?>">
            <h3><?= e($item['title']) ?></h3>
            <p><?= nl($item['body']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="section">
  <div class="shell">
    <div class="intro-grid">
      <div class="reveal">
        <p class="label">THE HOST FOUNDATION</p>
        <h2><?= e(setting('org_name')) ?></h2>
      </div>
      <div class="reveal reveal-delay-1 lede">
        <p><?= e(setting('org_collaboration')) ?></p>
        <?php if (setting('org_reg_no') !== ''): ?>
          <p><strong>Registration number:</strong> <?= e(setting('org_reg_no')) ?></p>
        <?php endif; ?>
        <?php if (setting('postal_address') !== ''): ?>
          <p><strong>Postal address:</strong> <?= e(setting('postal_address')) ?></p>
        <?php endif; ?>
        <?php if (setting('physical_address') !== ''): ?>
          <p><strong>Physical address:</strong> <?= e(setting('physical_address')) ?></p>
        <?php endif; ?>
        <p style="margin-top:1.5rem"><a class="text-link" href="contact.php#enquiry">Contact the foundation <span aria-hidden="true">→</span></a></p>
      </div>
    </div>
  </div>
</section>

<section class="section on-dark cta-band">
  <div class="shell reveal">
    <h2>Be part of the <em>2026 story.</em></h2>
    <p>Exhibitor stalls, sponsorship packages and masterclass seats are open now.</p>
    <div class="btn-row">
      <a class="btn btn-primary" href="packages.php">See packages <span aria-hidden="true">→</span></a>
      <a class="btn btn-ghost" href="programme.php">View the programme <span aria-hidden="true">↗</span></a>
    </div>
  </div>
</section>

<?php site_footer(); ?>

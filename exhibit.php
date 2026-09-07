<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_installed();
require __DIR__ . '/app/layout.php';

$page     = site_head('exhibit');
$audience = blocks('exhibit', 'audience');
$stalls   = active('stalls');

site_header('exhibit');
page_hero($page);
?>

<section class="section">
  <div class="shell">
    <div class="intro-grid">
      <h2 class="reveal">Your best work deserves <em>the right audience.</em></h2>
      <div class="reveal reveal-delay-1 lede">
        <p>The SME Exhibition &amp; Trade Fair is a high-foot-traffic corporate and small-business marketplace for local products, enterprise services and digital solutions.</p>
        <p>Secure a place to demonstrate what you do, meet new customers, develop B2B relationships and make an unforgettable impression.</p>
        <p style="margin-top:1.5rem">
          <a class="btn btn-dark" href="book.php">Book a stand <span aria-hidden="true">→</span></a>
        </p>
      </div>
    </div>

    <?php if ($audience): ?>
      <div class="audience-grid">
        <?php foreach ($audience as $i => $item): ?>
          <article class="reveal reveal-delay-<?= min($i, 3) ?>">
            <strong><?= e($item['title']) ?></strong>
            <p><?= nl($item['body']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($stalls): ?>
  <section class="section tight">
    <div class="shell">
      <?php section_head('AT A GLANCE', 'Spaces and {rates.}', 'Full details, corporate stall sizes and payment instructions are in the official registration form.'); ?>
      <div class="rate-table reveal">
        <div class="rate-row head"><span>Category</span><span>Details</span><span>Rate</span></div>
        <?php foreach ($stalls as $s): ?>
          <div class="rate-row">
            <strong><?= e($s['category']) ?></strong>
            <span class="detail"><?= e($s['details']) ?><?php if ($s['note'] !== ''): ?><em><?= e($s['note']) ?></em><?php endif; ?></span>
            <b><?= e($s['rate']) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:1.75rem"><a class="text-link" href="packages.php">See sponsorship packages and payment details <span aria-hidden="true">→</span></a></p>
    </div>
  </section>
<?php endif; ?>

<section class="section on-dark">
  <div class="shell split">
    <div class="split-art reveal" aria-hidden="true">
      <span class="ring"></span><span class="ring"></span><span class="ring"></span>
      <span class="core">↗</span>
    </div>
    <div class="reveal reveal-delay-1">
      <p class="label">SHOW UP. STAND OUT.</p>
      <h2>Tell us what you want to <em>put on the floor.</em></h2>
      <p class="lede" style="margin-top:1.35rem">Send your product, service or partnership idea to the event team and we will help you find the right space, size and package.</p>
      <div class="hero-actions" style="margin-top:2rem">
        <a class="btn btn-primary" href="book.php">Book your space <span aria-hidden="true">→</span></a>
        <?php if (setting('registration_form') !== ''): ?>
          <a class="btn btn-ghost" href="<?= e(rawurlencode_path(setting('registration_form'))) ?>" download>Registration form <span aria-hidden="true">↓</span></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<?php site_footer(); ?>

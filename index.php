<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_installed();
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/enquiry.php';

$page       = site_head('home');
$stats      = blocks('home', 'stats');
$focus      = blocks('home', 'focus');
$days       = active('programme_days');
$packages   = active('packages');
$speakers   = active('speakers');
$galleryImg = active('gallery');
$grouped    = partners_by_category();
$roles      = partner_categories();

site_header('home');
?>

<!-- ============================================================ HERO -->
<section class="hero" id="top">
  <div class="shell hero-grid">
    <div class="hero-text">
      <p class="hero-eyebrow"><span class="pulse" aria-hidden="true"></span><?= e(setting('hero_eyebrow')) ?></p>
      <h1><?= headline(setting('hero_title')) ?></h1>
      <p class="hero-copy"><?= nl(setting('hero_text')) ?></p>

      <div class="hero-actions">
        <?php if (setting('hero_cta_text') !== ''): ?>
          <a class="btn btn-primary" href="<?= e(setting('hero_cta_link', 'contact.php#enquiry')) ?>">
            <?= e(setting('hero_cta_text')) ?> <span aria-hidden="true">→</span>
          </a>
        <?php endif; ?>
        <?php if (setting('hero_alt_text') !== ''): ?>
          <a class="btn btn-ghost" href="<?= e(setting('hero_alt_link', '#about')) ?>">
            <?= e(setting('hero_alt_text')) ?> <span aria-hidden="true">↓</span>
          </a>
        <?php endif; ?>
      </div>

      <?php countdown('hero'); ?>

      <?php if ($stats): ?>
        <div class="hero-stats">
          <?php foreach ($stats as $stat): ?>
            <div><strong><?= e($stat['title']) ?></strong><span><?= e($stat['subtitle']) ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if (setting('hero_image') !== ''): ?>
      <div class="hero-visual">
        <div class="frame">
          <img src="<?= e(site_image(setting('hero_image'), 760)) ?>" alt="<?= e(setting('hero_image_alt')) ?>" fetchpriority="high">
        </div>
        <div class="hero-badge"><img src="<?= e(site_image(setting('logo'), 130)) ?>" alt="<?= e(setting('event_name')) ?> logo"></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="shell">
    <a class="scroll-cue" href="#about"><i aria-hidden="true"></i> Scroll to discover</a>
  </div>
</section>

<!-- =========================================================== ABOUT -->
<section class="section" id="about">
  <div class="shell">
    <div class="intro-grid">
      <h2 class="reveal"><?= headline(setting('about_title')) ?></h2>
      <div class="reveal reveal-delay-1">
        <p class="label"><?= e(setting('about_label')) ?></p>
        <div class="lede"><?= rich(setting('about_text')) ?></div>
        <p style="margin-top:1.5rem"><a class="text-link" href="about.php">Why attend <span aria-hidden="true">→</span></a></p>
      </div>
    </div>

    <?php if ($focus): ?>
      <div class="focus-grid">
        <?php foreach ($focus as $i => $card): ?>
          <article class="focus-card reveal reveal-delay-<?= min($i + 1, 3) ?>">
            <?php if ($card['icon'] !== ''): ?><span class="icon" aria-hidden="true"><?= e($card['icon']) ?></span><?php endif; ?>
            <h3><?= e($card['title']) ?></h3>
            <p><?= nl($card['body']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ======================================================== SHOWCASE -->
<?php if (setting('showcase_image') !== '' || setting('showcase_title') !== ''): ?>
  <section class="showcase on-dark">
    <?php if (setting('showcase_image') !== ''): ?>
      <div class="showcase-media">
        <img src="<?= e(site_image(setting('showcase_image'), 700)) ?>" alt="" loading="lazy">
      </div>
    <?php endif; ?>
    <div class="shell showcase-body reveal">
      <p class="label"><?= e(setting('showcase_label')) ?></p>
      <h2><?= headline(setting('showcase_title')) ?></h2>
      <p><?= nl(setting('showcase_text')) ?></p>
      <?php if (lines(setting('showcase_tags'))): ?>
        <div class="tag-row">
          <?php foreach (lines(setting('showcase_tags')) as $tag): ?><span><?= e($tag) ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<!-- ======================================================= PROGRAMME -->
<?php if ($days): ?>
  <section class="section on-dark" id="programme">
    <div class="shell">
      <?php section_head(setting('programme_label'), setting('programme_title')); ?>
      <div class="programme-grid">
        <?php foreach ($days as $i => $day): ?>
          <article class="day-card reveal reveal-delay-<?= min($i, 3) ?>">
            <span class="day-no"><?= e($day['day_label']) ?></span>
            <h3><?= e($day['title']) ?></h3>
            <p class="date"><?= e($day['date_text']) ?></p>
            <?php if ($day['summary'] !== ''): ?><p class="summary"><?= e($day['summary']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <p style="margin-top:2.5rem"><a class="btn btn-ghost" href="programme.php">See the full programme <span aria-hidden="true">→</span></a></p>
    </div>
  </section>
<?php endif; ?>

<!-- ========================================================= EXHIBIT -->
<section class="section" id="exhibit">
  <div class="shell split">
    <div class="split-art reveal" aria-hidden="true">
      <span class="ring"></span><span class="ring"></span><span class="ring"></span>
      <span class="core">3×3</span>
    </div>
    <div class="reveal reveal-delay-1">
      <p class="label"><?= e(setting('exhibit_label')) ?></p>
      <h2><?= headline(setting('exhibit_title')) ?></h2>
      <p class="lede" style="margin-top:1.35rem"><?= nl(setting('exhibit_text')) ?></p>
      <div class="hero-actions" style="margin-top:2rem">
        <a class="btn btn-dark" href="exhibit.php">Exhibit with us <span aria-hidden="true">→</span></a>
        <a class="btn btn-ghost" href="packages.php">See stall rates <span aria-hidden="true">↗</span></a>
      </div>
    </div>
  </div>
</section>

<!-- =========================================================== VENUE -->
<section class="section tight" id="venue">
  <div class="shell venue-grid">
    <div class="reveal">
      <p class="label"><?= e(setting('venue_label')) ?></p>
      <h2><?= headline(setting('venue_title')) ?></h2>
      <p class="lede" style="margin-top:1.35rem"><?= nl(setting('venue_text')) ?></p>
      <p class="venue-address">
        <?= e(setting('venue_name')) ?><br>
        <?= e(setting('venue_address')) ?><br>
        <?= e(setting('venue_city')) ?>
      </p>
      <?php if (setting('venue_map_url') !== ''): ?>
        <p style="margin-top:1.35rem"><a class="text-link" href="<?= e(setting('venue_map_url')) ?>" target="_blank" rel="noopener">Open in maps <span aria-hidden="true">↗</span></a></p>
      <?php endif; ?>
    </div>
    <?php if (setting('venue_image') !== ''): ?>
      <figure class="venue-photo reveal reveal-delay-1">
        <img src="<?= e(site_image(setting('venue_image'), 700)) ?>" alt="<?= e(setting('venue_name')) ?>" loading="lazy">
        <figcaption><span>Your meeting place on the coast</span><span aria-hidden="true">↗</span></figcaption>
      </figure>
    <?php endif; ?>
  </div>
</section>

<!-- ======================================================== SPEAKERS -->
<?php if ($speakers): ?>
  <section class="section tight" id="speakers">
    <div class="shell">
      <?php section_head('SPEAKERS & FACILITATORS', 'The people on the {main stage.}'); ?>
      <div class="speaker-grid">
        <?php foreach ($speakers as $i => $s): ?>
          <article class="speaker-card reveal reveal-delay-<?= min($i, 3) ?>">
            <div class="photo">
              <?php if ($s['photo'] !== ''): ?>
                <img src="<?= e(site_image($s['photo'], 320)) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
              <?php else: ?>
                <span class="initials"><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span>
              <?php endif; ?>
            </div>
            <h3><?= e($s['name']) ?></h3>
            <?php if ($s['role'] !== ''): ?><p><?= e($s['role']) ?></p><?php endif; ?>
            <?php if ($s['organisation'] !== ''): ?><p class="org"><?= e($s['organisation']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- ======================================================== PARTNERS -->
<section class="section on-dark" id="partners">
  <div class="shell">
    <?php section_head(setting('partners_label'), setting('partners_title'), setting('partners_text')); ?>

    <?php
    $sponsors = array_merge($grouped['sponsor_main'] ?? [], $grouped['sponsor'] ?? []);
    if ($sponsors):
        ?>
      <div class="role-block">
        <div class="role-head"><h3>Event sponsors</h3><strong><?= count($sponsors) ?> organisations</strong></div>
        <div class="logo-grid featured">
          <?php foreach ($sponsors as $p): ?>
            <article class="logo-card featured<?= (int) $p['dark_logo'] ? ' on-dark-logo' : '' ?> reveal">
              <div class="logo-media"><img src="<?= e(site_image($p['logo'], 220)) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></div>
              <div><span><?= e($p['role_label']) ?></span><h4><?= e($p['name']) ?></h4></div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php foreach (['partner', 'exhibitor'] as $cat): ?>
      <?php if (!empty($grouped[$cat])): ?>
        <div class="role-block">
          <div class="role-head">
            <h3><?= e($roles[$cat]) ?><?= $cat === 'exhibitor' ? 's' : 's' ?></h3>
            <strong><?= count($grouped[$cat]) ?> organisations</strong>
          </div>
          <div class="logo-grid">
            <?php foreach ($grouped[$cat] as $p): ?>
              <article class="logo-card<?= (int) $p['dark_logo'] ? ' on-dark-logo' : '' ?> reveal">
                <div class="logo-media"><img src="<?= e(site_image($p['logo'], 220)) ?>" alt="<?= e($p['name']) ?>" loading="lazy"></div>
                <div><span><?= e($p['role_label']) ?></span><h4><?= e($p['name']) ?></h4></div>
              </article>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php foreach ($grouped['foundation'] ?? [] as $host): ?>
      <div class="host-strip reveal">
        <?php if ($host['logo'] !== ''): ?>
          <img src="<?= e(site_image($host['logo'], 66)) ?>" alt="<?= e($host['name']) ?>" loading="lazy">
        <?php endif; ?>
        <div>
          <p class="host-role"><?= e($host['role_label']) ?></p>
          <p class="host-name"><?= e($host['name']) ?></p>
        </div>
        <a class="text-link" href="partners.php">View the complete network <span aria-hidden="true">→</span></a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ======================================================== PACKAGES -->
<?php if ($packages): ?>
  <section class="section on-dark" id="packages" style="padding-top:0">
    <div class="shell">
      <?php section_head(setting('packages_label'), setting('packages_title'), setting('packages_text')); ?>
      <div class="mini-grid">
        <?php foreach ($packages as $i => $p): ?>
          <article class="mini-card<?= (int) $p['is_featured'] ? ' featured' : '' ?> reveal reveal-delay-<?= min($i, 3) ?>">
            <small><?= e($p['tier_label']) ?></small>
            <h3><?= e($p['name']) ?></h3>
            <p class="price"><?= e($p['price']) ?></p>
            <p><?= e($p['summary']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="hero-actions" style="margin-top:2.5rem">
        <a class="btn btn-primary" href="packages.php">Explore all packages <span aria-hidden="true">→</span></a>
        <a class="btn btn-ghost" href="contact.php#enquiry">Talk to our team <span aria-hidden="true">↗</span></a>
      </div>

      <?php if (setting('registration_form') !== ''): ?>
        <div class="download-panel reveal">
          <div>
            <strong>Ready to reserve your space?</strong>
            <span>Download the official <?= date('Y', strtotime(setting('event_start_date'))) ?> registration form, complete it and return it to the event team.</span>
          </div>
          <a class="btn btn-gold" href="<?= e(rawurlencode_path(setting('registration_form'))) ?>" download>Download registration form <span aria-hidden="true">↓</span></a>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<!-- ========================================================= GALLERY -->
<?php if ($galleryImg): ?>
  <section class="section tight" id="gallery">
    <div class="shell">
      <?php section_head('GALLERY', 'Moments from the {fair.}'); ?>
      <div class="gallery-grid">
        <?php foreach ($galleryImg as $item): ?>
          <figure class="reveal">
            <img src="<?= e(site_image($item['image'], 480)) ?>" alt="<?= e($item['caption']) ?>" loading="lazy">
            <?php if ($item['caption'] !== ''): ?><figcaption><?= e($item['caption']) ?></figcaption><?php endif; ?>
          </figure>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- ============================================================= CTA -->
<?php $ctaPhoto = section_photo('cta_bg_image'); ?>
<section class="section on-dark cta-band<?= $ctaPhoto !== '' ? ' photo-back' : '' ?>">
  <?php if ($ctaPhoto !== ''): ?>
    <div class="photo-back-media" aria-hidden="true"><img src="<?= e($ctaPhoto) ?>" alt="" loading="lazy"></div>
  <?php endif; ?>
  <div class="shell reveal">
    <p class="label" style="justify-content:center"><?= e(setting('event_name')) ?></p>
    <h2><?= headline(setting('cta_title')) ?></h2>
    <p><?= nl(setting('cta_text')) ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="<?= e(url('booking/')) ?>">Book online <span aria-hidden="true">→</span></a>
      <a class="btn btn-ghost" href="<?= e(url('packages.php')) ?>">See what's on offer <span aria-hidden="true">↗</span></a>
    </div>
  </div>
</section>

<!-- ========================================================= CONTACT -->
<section class="section on-dark" id="contact" style="padding-top:0">
  <div class="shell contact-grid">
    <div class="reveal">
      <p class="label"><?= e(setting('contact_label')) ?></p>
      <h2><?= headline(setting('contact_title')) ?></h2>
      <p class="lede" style="margin-top:1.35rem"><?= nl(setting('contact_text')) ?></p>
      <div class="contact-cards">
        <a href="mailto:<?= e(setting('email_primary')) ?>"><small>Event email</small><strong><?= e(setting('email_primary')) ?></strong></a>
        <?php foreach (array_filter([setting('phone_1'), setting('phone_2'), setting('phone_3')]) as $i => $phone): ?>
          <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><small>Phone <?= $i + 1 ?></small><strong><?= e($phone) ?></strong></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="reveal reveal-delay-1" id="enquiry">
      <?php enquiry_form('compact'); ?>
    </div>
  </div>
</section>

<?php site_footer(); ?>

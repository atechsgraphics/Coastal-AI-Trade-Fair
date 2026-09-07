<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/enquiry.php';

start_session();

/* Post / Redirect / Get: process the submission, stash the result, reload. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    [$status, $notice] = enquiry_handle();
    $_SESSION['enquiry_flash'] = ['status' => $status, 'notice' => $notice];
    redirect('contact.php#enquiry');
}

$flash  = $_SESSION['enquiry_flash'] ?? ['status' => '', 'notice' => ''];
unset($_SESSION['enquiry_flash']);
$status = (string) $flash['status'];
$notice = (string) $flash['notice'];

$page   = site_head('contact');
$phones = array_filter([setting('phone_1'), setting('phone_2'), setting('phone_3')]);
$faqs   = active('faqs');

site_header('contact');
page_hero($page);
?>

<section class="section on-dark" style="padding-top:clamp(3rem,6vw,4.5rem)">
  <div class="shell contact-grid" id="enquiry">
    <div class="reveal">
      <p class="label">REACH THE EVENT TEAM</p>
      <h2>Let's create your <em>next connection.</em></h2>
      <p class="lede" style="margin-top:1.35rem">
        Tell us what you need — a stall, a sponsorship package, a masterclass seat or simply more information — and the right person will come back to you.
      </p>

      <div class="contact-cards">
        <a href="mailto:<?= e(setting('email_primary')) ?>">
          <small>General event email</small><strong><?= e(setting('email_primary')) ?></strong>
        </a>
        <?php if (setting('email_secondary') !== ''): ?>
          <a href="mailto:<?= e(setting('email_secondary')) ?>">
            <small>Trade fair email</small><strong><?= e(setting('email_secondary')) ?></strong>
          </a>
        <?php endif; ?>
        <?php foreach ($phones as $i => $phone): ?>
          <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>">
            <small><?= $i === 0 ? 'Call or WhatsApp' : 'Alternative line' ?></small><strong><?= e($phone) ?></strong>
          </a>
        <?php endforeach; ?>
        <div>
          <small>Venue</small>
          <strong><?= e(setting('venue_name')) ?></strong>
          <p style="margin:.4rem 0 0;font-size:.9rem;color:rgba(255,255,255,.62)">
            <?= e(setting('venue_address')) ?><br>
            <?= e(event_date_range()) ?>
          </p>
        </div>
        <?php if (setting('physical_address') !== ''): ?>
          <div>
            <small>Foundation office</small>
            <strong><?= e(setting('org_name')) ?></strong>
            <p style="margin:.4rem 0 0;font-size:.9rem;color:rgba(255,255,255,.62)">
              <?= e(setting('physical_address')) ?><br>
              <?= e(setting('postal_address')) ?>
            </p>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="reveal reveal-delay-1">
      <?php enquiry_form('full', $status, $notice); ?>
    </div>
  </div>
</section>

<?php if (setting('venue_map_url') !== ''): ?>
  <section class="section tight">
    <div class="shell split">
      <div class="reveal">
        <p class="label">FIND US</p>
        <h2>Mondesa, <em>Swakopmund.</em></h2>
        <p class="lede" style="margin-top:1.35rem">
          The fair takes place at the <?= e(setting('venue_name')) ?> — a central, accessible venue for exhibitors, delegates and visitors from across the Erongo region.
        </p>
        <p class="venue-address">
          <?= e(setting('venue_name')) ?><br>
          <?= e(setting('venue_address')) ?><br>
          <?= e(setting('venue_city')) ?>
        </p>
        <p style="margin-top:1.35rem">
          <a class="btn btn-dark" href="<?= e(setting('venue_map_url')) ?>" target="_blank" rel="noopener">Open in Google Maps <span aria-hidden="true">↗</span></a>
        </p>
      </div>
      <?php if (setting('venue_image') !== ''): ?>
        <figure class="venue-photo reveal reveal-delay-1">
          <img src="<?= e(rawurlencode_path(setting('venue_image'))) ?>" alt="<?= e(setting('venue_name')) ?>" loading="lazy">
          <figcaption><span><?= e(setting('venue_city')) ?></span><span aria-hidden="true">↗</span></figcaption>
        </figure>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($faqs): ?>
  <section class="section tight">
    <div class="shell">
      <?php section_head('BEFORE YOU WRITE', 'Answers to the {common questions.}'); ?>
      <div class="faq-list reveal">
        <?php foreach ($faqs as $faq): ?>
          <details class="faq-item">
            <summary><?= e($faq['question']) ?></summary>
            <div class="faq-body"><?= rich($faq['answer']) ?></div>
          </details>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php site_footer(); ?>

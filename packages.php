<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_installed();
require __DIR__ . '/inc/layout.php';

$page     = site_head('packages');
$packages = active('packages');
$stalls   = active('stalls');
$faqs     = active('faqs');

site_header('packages');
page_hero($page);
?>

<?php if ($packages): ?>
  <section class="section">
    <div class="shell">
      <?php section_head('SPONSORSHIP OPPORTUNITIES', 'Choose your level of {impact.}'); ?>
      <div class="tier-grid">
        <?php foreach ($packages as $i => $p): ?>
          <article class="tier-card<?= (int) $p['is_featured'] ? ' featured' : '' ?> reveal reveal-delay-<?= min($i, 3) ?>">
            <?php if ((int) $p['is_featured']): ?><span class="flag">MOST VISIBLE</span><?php endif; ?>
            <span class="tier"><?= e($p['tier_label']) ?></span>
            <h3><?= e($p['name']) ?></h3>
            <p class="price"><?= e($p['price']) ?></p>
            <?php if ($p['summary'] !== ''): ?><p class="summary"><?= e($p['summary']) ?></p><?php endif; ?>
            <?php if (lines($p['features'])): ?>
              <ul>
                <?php foreach (lines($p['features']) as $feature): ?><li><?= e($feature) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>
            <a class="btn <?= (int) $p['is_featured'] ? 'btn-gold' : 'btn-ghost' ?>"
               href="contact.php?interest=<?= rawurlencode('Sponsorship') ?>&amp;option=<?= rawurlencode($p['name'] . ' — ' . $p['price']) ?>#enquiry">
              Enquire about this <span aria-hidden="true">→</span>
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($stalls): ?>
  <section class="section on-dark">
    <div class="shell">
      <?php section_head('EXHIBITOR OPTIONS, TICKETS & PASSES', 'A space for every {kind of enterprise.}', 'From food vendors and family activations to large corporate exhibition footprints — every rate below is taken from the official 2026 registration form.'); ?>
      <div class="rate-table reveal" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.14)">
        <div class="rate-row head"><span>Category</span><span>Details</span><span>Rate</span></div>
        <?php foreach ($stalls as $s): ?>
          <div class="rate-row" style="border-color:rgba(255,255,255,.1);color:#fff">
            <strong><?= e($s['category']) ?></strong>
            <span class="detail" style="color:rgba(255,255,255,.65)">
              <?= e($s['details']) ?><?php if ($s['note'] !== ''): ?><em style="color:var(--gold)"><?= e($s['note']) ?></em><?php endif; ?>
            </span>
            <b style="color:#fff"><?= e($s['rate']) ?></b>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="hero-actions" style="margin-top:2.25rem">
        <a class="btn btn-primary" href="book.php">Book online now <span aria-hidden="true">→</span></a>
        <?php if (setting('registration_form') !== ''): ?>
          <a class="btn btn-ghost" href="<?= e(rawurlencode_path(setting('registration_form'))) ?>" download>Download the form <span aria-hidden="true">↓</span></a>
        <?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<!-- ================================================= PAYMENT & TERMS -->
<?php if (setting('bank_account_number') !== ''): ?>
  <section class="section">
    <div class="shell">
      <?php section_head('HOW TO CONFIRM YOUR BOOKING', 'Payment {details.}', 'Pay by direct bank transfer and send your proof of payment to the event team. Your space is confirmed once payment is received.'); ?>

      <div class="info-panel reveal">
        <div>
          <h3>Banking details</h3>
          <dl class="detail-list">
            <div><dt>Account name</dt><dd><?= e(setting('bank_account_name')) ?></dd></div>
            <div><dt>Bank</dt><dd><?= e(setting('bank_name')) ?></dd></div>
            <div><dt>Account number</dt><dd><?= e(setting('bank_account_number')) ?></dd></div>
            <div><dt>Branch code</dt><dd><?= e(setting('bank_branch_code')) ?></dd></div>
            <div><dt>Account type</dt><dd><?= e(setting('bank_account_type')) ?></dd></div>
            <div><dt>Payment reference</dt><dd><?= e(setting('payment_reference')) ?></dd></div>
          </dl>
          <?php if (setting('payment_proof_email') !== ''): ?>
            <p style="margin-top:1.25rem"><a class="text-link" href="mailto:<?= e(setting('payment_proof_email')) ?>">Send proof of payment <span aria-hidden="true">→</span></a></p>
          <?php endif; ?>
        </div>
        <div>
          <h3>Terms &amp; conditions</h3>
          <?php foreach (['Confirmation' => 'terms_confirmation', 'Allocations' => 'terms_allocations', 'Cancellations' => 'terms_cancellations'] as $label => $key): ?>
            <?php if (setting($key) !== ''): ?>
              <p><strong><?= e($label) ?>:</strong> <?= e(setting($key)) ?></p>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (setting('registration_form') !== ''): ?>
        <div class="download-panel reveal">
          <div>
            <strong>Official registration form</strong>
            <span>Corporate stall sizes, masterclass options, payment instructions and terms in one PDF.</span>
          </div>
          <a class="btn btn-gold" href="<?= e(rawurlencode_path(setting('registration_form'))) ?>" download>Download PDF <span aria-hidden="true">↓</span></a>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($faqs): ?>
  <section class="section tight">
    <div class="shell">
      <?php section_head('QUESTIONS', 'Good to {know.}'); ?>
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

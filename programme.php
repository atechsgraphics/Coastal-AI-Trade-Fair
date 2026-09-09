<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require_installed();
require __DIR__ . '/app/layout.php';

$page     = site_head('programme');
$days     = active('programme_days');
$tracks   = blocks('programme', 'tracks');
$speakers = active('speakers');

site_header('programme');
page_hero($page);
?>

<section class="section">
  <div class="shell">
    <div class="intro-grid">
      <h2 class="reveal">A summit that opens <em>doors.</em></h2>
      <div class="reveal reveal-delay-1 lede">
        <p>The high-level summit brings together keynotes, policy panels and sector-specific roundtables on AI integration in Namibian industry, trade readiness and regional investment.</p>
        <p>Alongside it, the four-day SME Exhibition &amp; Trade Fair creates a high-foot-traffic space for local products, digital solutions and enterprise services.</p>
      </div>
    </div>

    <?php if ($days): ?>
      <div class="agenda agenda-full reveal" style="margin-top:clamp(2.5rem,5vw,4rem)">
        <?php foreach ($days as $day): ?>
          <?php
          $topics   = lines((string) ($day['topics'] ?? ''));
          $sessions = programme_sessions((int) $day['id']);
          ?>
          <article>
            <time><?= e($day['day_label']) ?><small><?= e($day['date_text']) ?></small></time>
            <div>
              <h3><?= e($day['title']) ?></h3>

              <?php if (trim((string) ($day['focus'] ?? '')) !== ''): ?>
                <p class="day-focus"><span>Focus</span><?= e($day['focus']) ?></p>
              <?php elseif ($day['summary'] !== ''): ?>
                <p><strong><?= e($day['summary']) ?></strong></p>
              <?php endif; ?>

              <?php if ($day['details'] !== ''): ?><p><?= nl($day['details']) ?></p><?php endif; ?>

              <?php if ($topics): ?>
                <ul class="day-topics">
                  <?php foreach ($topics as $topic): ?>
                    <li><?= e($topic) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($sessions): ?>
                <ol class="day-sessions">
                  <?php foreach ($sessions as $session): ?>
                    <?php $when = programme_time($session); $who = $session['person']; ?>
                    <li>
                      <?php if ($when !== ''): ?><span class="ds-time"><?= e($when) ?></span><?php endif; ?>
                      <div class="ds-body">
                        <strong><?= e($session['title']) ?></strong>
                        <?php if (trim((string) $session['description']) !== ''): ?>
                          <p><?= nl($session['description']) ?></p>
                        <?php endif; ?>
                        <?php if ($who !== null): ?>
                          <p class="ds-who">
                            <?php $photo = $who['photo'] !== '' ? section_photo_path($who['photo'], 120) : ''; ?>
                            <?php if ($photo !== ''): ?><img src="<?= e($photo) ?>" alt=""><?php endif; ?>
                            <span><strong><?= e($who['name']) ?></strong><?= $who['role'] !== '' ? '<small>' . e($who['role']) . '</small>' : '' ?></span>
                          </p>
                        <?php endif; ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($tracks): ?>
  <section class="section on-dark">
    <div class="shell">
      <?php section_head('FEATURED TRACK', 'The AI &amp; Digital {Masterclass.}', 'A practical, hands-on training track for MSMEs — free for exhibitors who have booked a stall or who are sponsored.'); ?>
      <div class="track-grid">
        <?php foreach ($tracks as $i => $track): ?>
          <article class="track-card reveal reveal-delay-<?= min($i, 3) ?>">
            <?php if ($track['subtitle'] !== ''): ?><small><?= e($track['subtitle']) ?></small><?php endif; ?>
            <h3><?= e($track['title']) ?></h3>
            <p><?= nl($track['body']) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
      <div class="hero-actions" style="margin-top:2.5rem">
        <a class="btn btn-primary" href="book.php">Book a masterclass seat <span aria-hidden="true">→</span></a>
        <a class="btn btn-ghost" href="packages.php">See ticket prices <span aria-hidden="true">↗</span></a>
      </div>
    </div>
  </section>
<?php endif; ?>

<?php if ($speakers): ?>
  <section class="section">
    <div class="shell">
      <?php section_head('SPEAKERS & FACILITATORS', 'Who you will {learn from.}'); ?>
      <div class="speaker-grid">
        <?php foreach ($speakers as $i => $s): ?>
          <article class="speaker-card reveal reveal-delay-<?= min($i, 3) ?>">
            <div class="photo">
              <?php if ($s['photo'] !== ''): ?>
                <img src="<?= e(rawurlencode_path($s['photo'])) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
              <?php else: ?>
                <span class="initials"><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span>
              <?php endif; ?>
            </div>
            <h3><?= e($s['name']) ?></h3>
            <?php if ($s['role'] !== ''): ?><p><?= e($s['role']) ?></p><?php endif; ?>
            <?php if ($s['organisation'] !== ''): ?><p class="org"><?= e($s['organisation']) ?></p><?php endif; ?>
            <?php if ($s['bio'] !== ''): ?><p style="margin-top:.6rem"><?= e($s['bio']) ?></p><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="section on-dark cta-band">
  <div class="shell reveal">
    <h2>Four days.<br><em>One shared horizon.</em></h2>
    <p><?= e(event_date_range()) ?> · <?= e(setting('venue_name')) ?>, <?= e(setting('venue_city')) ?></p>
    <div class="btn-row">
      <a class="btn btn-primary" href="book.php">Book your place <span aria-hidden="true">→</span></a>
    </div>
  </div>
</section>

<?php site_footer(); ?>

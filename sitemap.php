<?php
declare(strict_types=1);

/**
 * XML sitemap, built from the published pages in the control panel.
 * Submit the address of this file to Google Search Console once the site is live.
 */

require __DIR__ . '/inc/bootstrap.php';
require_installed();

header('Content-Type: application/xml; charset=UTF-8');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$dir    = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$base   = $scheme . '://' . $host . $dir . '/';

$pages = db_all('SELECT slug FROM pages WHERE is_published = 1 ORDER BY position, id');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($pages as $page): ?>
  <url>
    <loc><?= e($base . ($page['slug'] === 'home' ? 'index.php' : $page['slug'] . '.php')) ?></loc>
    <changefreq><?= $page['slug'] === 'home' ? 'weekly' : 'monthly' ?></changefreq>
    <priority><?= $page['slug'] === 'home' ? '1.0' : '0.8' ?></priority>
  </url>
<?php endforeach; ?>
</urlset>

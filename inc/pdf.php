<?php
declare(strict_types=1);

/**
 * A small, dependency-free PDF writer.
 * ---------------------------------------------------------------------------
 * Enough to produce a clean A4 business document: the two standard Helvetica
 * faces, wrapped text, rules, filled boxes and JPEG images. No Composer, no
 * extensions beyond GD (and GD is only needed for the logo).
 *
 * Coordinates are given top-down in points, like a word processor: (0, 0) is
 * the top-left corner of the page. The class converts to PDF's bottom-up space.
 */
final class SimplePdf
{
    public const PAGE_W = 595.28;   // A4 width in points
    public const PAGE_H = 841.89;   // A4 height in points

    public float $margin = 44.0;
    public float $y = 44.0;         // current cursor, measured from the top

    /**
     * Optional page furniture, run as each page is finished.
     * Signature: function (SimplePdf $pdf, int $pageNumber): void
     * @var callable|null
     */
    public $pageFooter = null;

    /** @var string[] finished page content streams */
    private array $pages = [];
    private string $buffer = '';
    private int $pageNumber = 1;
    private bool $finalised = false;
    /** @var array<string, array{data:string,w:int,h:int,name:string}> */
    private array $images = [];

    /** Widths of the WinAnsi character set, in 1/1000 em. */
    private const W_REGULAR = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];
    private const W_BOLD = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    public function __construct()
    {
        $this->y = $this->margin;
    }

    /* ------------------------------------------------------------- layout */

    public function contentWidth(): float
    {
        return self::PAGE_W - ($this->margin * 2);
    }

    /** Start a new page and reset the cursor to the top margin. */
    public function addPage(): void
    {
        $this->finishPage();
        $this->pages[] = $this->buffer;
        $this->buffer = '';
        $this->pageNumber++;
        $this->y = $this->margin;
    }

    /** Draw the footer onto the page that is about to be closed. */
    private function finishPage(): void
    {
        if ($this->pageFooter !== null) {
            ($this->pageFooter)($this, $this->pageNumber);
        }
    }

    /** Break to a new page when less than $needed points remain. */
    public function need(float $needed): void
    {
        if ($this->y + $needed > self::PAGE_H - $this->margin) {
            $this->addPage();
        }
    }

    /* --------------------------------------------------------------- text */

    /**
     * Draw a single line of text. $font is 'R' (regular) or 'B' (bold).
     */
    public function text(float $x, float $y, string $string, float $size = 10, string $font = 'R', array $rgb = [0, 0, 0]): void
    {
        $encoded = $this->escape($this->encode($string));
        $this->buffer .= sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $font === 'B' ? 'F2' : 'F1',
            $size,
            $rgb[0], $rgb[1], $rgb[2],
            $x,
            self::PAGE_H - $y - $size * 0.78,
            $encoded
        );
    }

    /** Draw text ending at $right instead of starting at $x. */
    public function textRight(float $right, float $y, string $string, float $size = 10, string $font = 'R', array $rgb = [0, 0, 0]): void
    {
        $this->text($right - $this->widthOf($string, $size, $font), $y, $string, $size, $font, $rgb);
    }

    /** Width of a string in points at a given size. */
    public function widthOf(string $string, float $size, string $font = 'R'): float
    {
        $table = $font === 'B' ? self::W_BOLD : self::W_REGULAR;
        $fallback = $font === 'B' ? 611 : 556;
        $total = 0;

        $bytes = $this->encode($string);
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $code = ord($bytes[$i]);
            $total += ($code >= 32 && $code <= 126) ? $table[$code - 32] : $fallback;
        }

        return $total * $size / 1000;
    }

    /**
     * Split a string into lines that fit inside $width.
     *
     * @return string[]
     */
    public function wrap(string $string, float $width, float $size, string $font = 'R'): array
    {
        $lines = [];
        foreach (preg_split('/\R/', trim($string)) ?: [] as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph)) ?: [];
            $current = '';
            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($this->widthOf($candidate, $size, $font) <= $width || $current === '') {
                    $current = $candidate;
                } else {
                    $lines[] = $current;
                    $current = $word;
                }
            }
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Draw wrapped text at the cursor and advance it. Returns the height used.
     */
    public function paragraph(float $x, float $width, string $string, float $size = 10, string $font = 'R', ?float $lead = null, array $rgb = [0, 0, 0]): float
    {
        $lead ??= $size * 1.45;
        $start = $this->y;

        foreach ($this->wrap($string, $width, $size, $font) as $line) {
            $this->need($lead);
            $this->text($x, $this->y, $line, $size, $font, $rgb);
            $this->y += $lead;
        }

        return $this->y - $start;
    }

    /* ------------------------------------------------------------ drawing */

    public function line(float $x1, float $y1, float $x2, float $y2, float $weight = 0.5, array $rgb = [0, 0, 0]): void
    {
        $this->buffer .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $rgb[0], $rgb[1], $rgb[2], $weight,
            $x1, self::PAGE_H - $y1,
            $x2, self::PAGE_H - $y2
        );
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb = [0, 0, 0], bool $fill = true, float $weight = 0.5): void
    {
        if ($fill) {
            $this->buffer .= sprintf(
                "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
                $rgb[0], $rgb[1], $rgb[2], $x, self::PAGE_H - $y - $h, $w, $h
            );
            return;
        }
        $this->buffer .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F %.2F %.2F re S\n",
            $rgb[0], $rgb[1], $rgb[2], $weight, $x, self::PAGE_H - $y - $h, $w, $h
        );
    }

    /** A horizontal rule across the content width at the cursor. */
    public function rule(float $gapBefore = 6, float $gapAfter = 10, array $rgb = [0.82, 0.85, 0.88]): void
    {
        $this->y += $gapBefore;
        $this->line($this->margin, $this->y, self::PAGE_W - $this->margin, $this->y, 0.7, $rgb);
        $this->y += $gapAfter;
    }

    /**
     * Place an image. Any GD-readable file is converted to JPEG on a white
     * background, so PNG logos with transparency work. Silently does nothing
     * if GD or the file is unavailable.
     */
    public function image(string $file, float $x, float $y, float $w, float $h): bool
    {
        if (!is_file($file) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $key = md5($file . $w . $h);
        if (!isset($this->images[$key])) {
            $source = @imagecreatefromstring((string) @file_get_contents($file));
            if ($source === false) {
                return false;
            }

            $sw = imagesx($source);
            $sh = imagesy($source);
            $canvas = imagecreatetruecolor($sw, $sh);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $sw, $sh, $white);
            imagecopy($canvas, $source, 0, 0, 0, 0, $sw, $sh);

            ob_start();
            imagejpeg($canvas, null, 88);
            $data = (string) ob_get_clean();

            imagedestroy($source);
            imagedestroy($canvas);

            if ($data === '') {
                return false;
            }
            $this->images[$key] = [
                'data' => $data, 'w' => $sw, 'h' => $sh,
                'name' => 'I' . (count($this->images) + 1),
            ];
        }

        $name = $this->images[$key]['name'];
        $this->buffer .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n",
            $w, $h, $x, self::PAGE_H - $y - $h, $name
        );

        return true;
    }

    /* ------------------------------------------------------------- output */

    /** Assemble the finished document. */
    public function output(): string
    {
        if (!$this->finalised) {
            $this->finishPage();
            $this->finalised = true;
        }

        $pages = $this->pages;
        $pages[] = $this->buffer;

        $objects = [];
        $next = 5 + count($this->images);

        $imageObj = [];
        $index = 1;
        foreach ($this->images as $key => $image) {
            $imageObj[$key] = 4 + $index;
            $index++;
        }

        $pageObjects = [];
        $contentObjects = [];
        foreach ($pages as $i => $ignored) {
            $pageObjects[$i] = $next++;
            $contentObjects[$i] = $next++;
        }

        // 1 catalog, 2 page tree, 3 + 4 fonts
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = implode(' ', array_map(static fn ($n) => $n . ' 0 R', $pageObjects));
        $objects[2] = "<< /Type /Pages /Kids [{$kids}] /Count " . count($pages) . " >>";
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $xobjects = '';
        foreach ($this->images as $key => $image) {
            $objects[$imageObj[$key]] = "<< /Type /XObject /Subtype /Image /Width {$image['w']} /Height {$image['h']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data'])
                . " >>\nstream\n" . $image['data'] . "\nendstream";
            $xobjects .= '/' . $image['name'] . ' ' . $imageObj[$key] . ' 0 R ';
        }

        $resources = '<< /Font << /F1 3 0 R /F2 4 0 R >>'
            . ($xobjects !== '' ? ' /XObject << ' . $xobjects . '>>' : '')
            . ' /ProcSet [/PDF /Text /ImageC] >>';

        foreach ($pages as $i => $stream) {
            $objects[$pageObjects[$i]] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                . sprintf('%.2F %.2F', self::PAGE_W, self::PAGE_H) . "] /Resources {$resources}"
                . " /Contents {$contentObjects[$i]} 0 R >>";
            $objects[$contentObjects[$i]] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $count = count($objects) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /** Send the document to the browser. */
    public function download(string $filename, bool $inline = false): void
    {
        $body = $this->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $filename) . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $body;
    }

    /* ------------------------------------------------------------ helpers */

    /** UTF-8 to WinAnsi, with sensible fallbacks for typographic characters. */
    private function encode(string $string): string
    {
        $string = strtr($string, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2026}" => '...', "\u{00D7}" => 'x', "\u{2022}" => '-', "\u{00A0}" => ' ',
            "\u{2713}" => 'x', "\u{2714}" => 'x',
        ]);

        $previous = mb_substitute_character();
        mb_substitute_character(0x3F); // '?'
        $converted = mb_convert_encoding($string, 'Windows-1252', 'UTF-8');
        mb_substitute_character($previous);

        return $converted;
    }

    private function escape(string $string): string
    {
        return strtr($string, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}

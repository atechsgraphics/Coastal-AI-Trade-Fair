<?php
declare(strict_types=1);

/**
 * A small QR Code encoder (ISO/IEC 18004), byte mode, versions 1–10.
 * ---------------------------------------------------------------------------
 * Written for the booking tickets so a QR code can be drawn without an
 * internet connection, an image service or an extra library. Ten versions is
 * ample: version 10 at error-correction level M holds 213 bytes, and a ticket
 * verification link is around sixty characters.
 *
 * The only external requirement is GD, which the site already relies on.
 * If GD is missing, qr_png_data_uri() returns an empty string and the ticket
 * falls back to printing the verification code on its own.
 */
final class QrCode
{
    /** Error-correction level bits, as they appear in the format information. */
    private const EC_BITS = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10];

    /** Total codewords (data + error correction) for versions 1–10. */
    private const TOTAL_CODEWORDS = [
        1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
        6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
    ];

    /**
     * Block layout per version and level:
     *   [ec codewords per block, blocks in group 1, data per block in group 1,
     *    blocks in group 2, data per block in group 2]
     */
    private const BLOCKS = [
        1  => ['L' => [7, 1, 19, 0, 0],   'M' => [10, 1, 16, 0, 0],  'Q' => [13, 1, 13, 0, 0],  'H' => [17, 1, 9, 0, 0]],
        2  => ['L' => [10, 1, 34, 0, 0],  'M' => [16, 1, 28, 0, 0],  'Q' => [22, 1, 22, 0, 0],  'H' => [28, 1, 16, 0, 0]],
        3  => ['L' => [15, 1, 55, 0, 0],  'M' => [26, 1, 44, 0, 0],  'Q' => [18, 2, 17, 0, 0],  'H' => [22, 2, 13, 0, 0]],
        4  => ['L' => [20, 1, 80, 0, 0],  'M' => [18, 2, 32, 0, 0],  'Q' => [26, 2, 24, 0, 0],  'H' => [16, 4, 9, 0, 0]],
        5  => ['L' => [26, 1, 108, 0, 0], 'M' => [24, 2, 43, 0, 0],  'Q' => [18, 2, 15, 2, 16], 'H' => [22, 2, 11, 2, 12]],
        6  => ['L' => [18, 2, 68, 0, 0],  'M' => [16, 4, 27, 0, 0],  'Q' => [24, 4, 19, 0, 0],  'H' => [28, 4, 15, 0, 0]],
        7  => ['L' => [20, 2, 78, 0, 0],  'M' => [18, 4, 31, 0, 0],  'Q' => [18, 2, 14, 4, 15], 'H' => [26, 4, 13, 1, 14]],
        8  => ['L' => [24, 2, 97, 0, 0],  'M' => [22, 2, 38, 2, 39], 'Q' => [22, 4, 18, 2, 19], 'H' => [26, 4, 14, 2, 15]],
        9  => ['L' => [30, 2, 116, 0, 0], 'M' => [22, 3, 36, 2, 37], 'Q' => [20, 4, 16, 4, 17], 'H' => [24, 4, 12, 4, 13]],
        10 => ['L' => [18, 2, 68, 2, 69], 'M' => [26, 4, 43, 1, 44], 'Q' => [24, 6, 19, 2, 20], 'H' => [28, 6, 15, 2, 16]],
    ];

    /** Centres of the alignment patterns for versions 1–10. */
    private const ALIGNMENT = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** Bits of padding after the last codeword. */
    private const REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0];

    private int $version;
    private string $level;
    private int $size;

    /** @var array<int, array<int, int>> 0 light, 1 dark */
    private array $modules = [];

    /** @var array<int, array<int, bool>> true where the module is function patterning */
    private array $reserved = [];

    /** @var int[] antilog table for GF(256) */
    private static array $exp = [];
    /** @var int[] log table for GF(256) */
    private static array $log = [];

    private function __construct(int $version, string $level)
    {
        $this->version = $version;
        $this->level = $level;
        $this->size = 17 + (4 * $version);

        for ($row = 0; $row < $this->size; $row++) {
            $this->modules[$row] = array_fill(0, $this->size, 0);
            $this->reserved[$row] = array_fill(0, $this->size, false);
        }
    }

    /**
     * Encode a string. Returns the finished module matrix, or null when the
     * text is longer than version 10 can hold at the requested level.
     *
     * @return array<int, array<int, int>>|null
     */
    public static function matrix(string $text, string $level = 'M'): ?array
    {
        $level = isset(self::EC_BITS[$level]) ? $level : 'M';
        $length = strlen($text);

        $version = 0;
        for ($candidate = 1; $candidate <= 10; $candidate++) {
            if ($length <= self::dataCapacity($candidate, $level)) {
                $version = $candidate;
                break;
            }
        }
        if ($version === 0) {
            return null;
        }

        $qr = new self($version, $level);
        $codewords = $qr->buildCodewords($text);
        $qr->drawFunctionPatterns();
        $qr->placeData($codewords);

        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $unmasked = $qr->modules;

        for ($mask = 0; $mask < 8; $mask++) {
            $qr->modules = $unmasked;
            $qr->applyMask($mask);
            $qr->drawFormatInfo($mask);
            $penalty = $qr->penalty();
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
        }

        $qr->modules = $unmasked;
        $qr->applyMask($bestMask);
        $qr->drawFormatInfo($bestMask);

        return $qr->modules;
    }

    /** How many bytes fit in one version at one level. */
    private static function dataCapacity(int $version, string $level): int
    {
        [$ecPerBlock, $blocks1, $data1, $blocks2, $data2] = self::BLOCKS[$version][$level];
        $dataCodewords = ($blocks1 * $data1) + ($blocks2 * $data2);
        $countBits = $version <= 9 ? 8 : 16;

        // 4 mode bits + the character count, then whole bytes.
        return $dataCodewords - (int) ceil((4 + $countBits) / 8);
    }

    /* ------------------------------------------------------------ encoding */

    /** Mode indicator, length, payload, padding, then error correction. */
    private function buildCodewords(string $text): array
    {
        [$ecPerBlock, $blocks1, $data1, $blocks2, $data2] = self::BLOCKS[$this->version][$this->level];
        $dataCodewords = ($blocks1 * $data1) + ($blocks2 * $data2);
        $countBits = $this->version <= 9 ? 8 : 16;

        $bits = '';
        $bits .= '0100';                                                    // byte mode
        $bits .= str_pad(decbin(strlen($text)), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $dataCodewords * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));    // terminator
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $data = [];
        foreach (str_split($bits, 8) as $byte) {
            $data[] = bindec($byte);
        }
        $pad = [0xEC, 0x11];
        for ($i = 0; count($data) < $dataCodewords; $i++) {
            $data[] = $pad[$i % 2];
        }

        // Split into blocks, give each its own error-correction codewords.
        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ([[$blocks1, $data1], [$blocks2, $data2]] as [$count, $size]) {
            for ($b = 0; $b < $count; $b++) {
                $block = array_slice($data, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
            }
        }

        // Interleave: first byte of every block, then the second, and so on.
        $out = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }

        return $out;
    }

    /* ---------------------------------------------------- Reed-Solomon (GF 256) */

    private static function initGalois(): void
    {
        if (self::$exp) {
            return;
        }
        self::$exp = array_fill(0, 512, 0);
        self::$log = array_fill(0, 256, 0);

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;                                                // primitive polynomial
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[(self::$log[$a] + self::$log[$b]) % 255];
    }

    /** The generator polynomial of the given degree. */
    private static function generator(int $degree): array
    {
        self::initGalois();
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            // Multiply the polynomial, highest power first, by (x + a^i).
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $index => $coefficient) {
                $next[$index]     ^= $coefficient;                          // the x term
                $next[$index + 1] ^= self::gfMultiply($coefficient, self::$exp[$i]);
            }
            $poly = $next;
        }
        return $poly;
    }

    /** @param int[] $data @return int[] */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGalois();
        $generator = self::generator($ecCount);
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;
            foreach ($generator as $index => $coefficient) {
                if ($index === 0) {
                    continue;
                }
                $remainder[$index - 1] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return $remainder;
    }

    /* ------------------------------------------------------ module drawing */

    private function set(int $row, int $col, int $value, bool $reserve = true): void
    {
        if ($row < 0 || $col < 0 || $row >= $this->size || $col >= $this->size) {
            return;
        }
        $this->modules[$row][$col] = $value;
        if ($reserve) {
            $this->reserved[$row][$col] = true;
        }
    }

    private function drawFunctionPatterns(): void
    {
        $last = $this->size - 7;

        foreach ([[0, 0], [0, $last], [$last, 0]] as [$row, $col]) {
            $this->drawFinder($row, $col);
        }

        // Separators around each finder.
        foreach ([[0, 0], [0, $last], [$last, 0]] as [$row, $col]) {
            for ($i = -1; $i <= 7; $i++) {
                $this->set($row - 1, $col + $i, 0);
                $this->set($row + 7, $col + $i, 0);
                $this->set($row + $i, $col - 1, 0);
                $this->set($row + $i, $col + 7, 0);
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $this->size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;
            $this->set(6, $i, $bit);
            $this->set($i, 6, $bit);
        }

        // Alignment patterns, skipping the three finder corners.
        $centres = self::ALIGNMENT[$this->version];
        foreach ($centres as $rowCentre) {
            foreach ($centres as $colCentre) {
                $nearFinder = ($rowCentre <= 8 && $colCentre <= 8)
                    || ($rowCentre <= 8 && $colCentre >= $this->size - 9)
                    || ($rowCentre >= $this->size - 9 && $colCentre <= 8);
                if ($nearFinder) {
                    continue;
                }
                $this->drawAlignment($rowCentre, $colCentre);
            }
        }

        // The module that is always dark.
        $this->set($this->size - 8, 8, 1);

        // Reserve the two format-information strips. Index 6 is skipped in
        // both directions because that is the timing pattern, not format data.
        for ($i = 0; $i < 9; $i++) {
            if ($i === 6) {
                continue;
            }
            $this->set(8, $i, 0);
            $this->set($i, 8, 0);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set(8, $this->size - 1 - $i, 0);
        }
        for ($i = 0; $i < 7; $i++) {
            $this->set($this->size - 1 - $i, 8, 0);
        }

        if ($this->version >= 7) {
            $this->drawVersionInfo();
        }
    }

    private function drawFinder(int $row, int $col): void
    {
        for ($r = 0; $r < 7; $r++) {
            for ($c = 0; $c < 7; $c++) {
                $edge = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $core = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $this->set($row + $r, $col + $c, ($edge || $core) ? 1 : 0);
            }
        }
    }

    private function drawAlignment(int $row, int $col): void
    {
        for ($r = -2; $r <= 2; $r++) {
            for ($c = -2; $c <= 2; $c++) {
                $ring = (max(abs($r), abs($c)) !== 1);
                $this->set($row + $r, $col + $c, $ring ? 1 : 0);
            }
        }
    }

    /** BCH(18, 6) version information, for versions 7 and above. */
    private function drawVersionInfo(): void
    {
        $value = $this->version;
        $remainder = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 11) & 1) * 0x1F25);
        }
        $bits = ($value << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($bits >> $i) & 1;
            $row = intdiv($i, 3);
            $col = $this->size - 11 + ($i % 3);
            $this->set($row, $col, $bit);
            $this->set($col, $row, $bit);
        }
    }

    /** BCH(15, 5) format information for the chosen level and mask. */
    private function drawFormatInfo(int $mask): void
    {
        $data = (self::EC_BITS[$this->level] << 3) | $mask;
        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }
        $bits = (($data << 10) | $remainder) ^ 0x5412;

        // First copy, wrapped around the top-left finder: the low bits run
        // down column 8, the high bits run left along row 8.
        for ($i = 0; $i <= 5; $i++) {
            $this->set($i, 8, ($bits >> $i) & 1);
        }
        $this->set(7, 8, ($bits >> 6) & 1);
        $this->set(8, 8, ($bits >> 7) & 1);
        $this->set(8, 7, ($bits >> 8) & 1);
        for ($i = 9; $i < 15; $i++) {
            $this->set(8, 14 - $i, ($bits >> $i) & 1);
        }

        // Second copy: bits 0–7 run left from the top-right finder along
        // row 8, bits 8–14 run down column 8 to the bottom-left finder.
        for ($i = 0; $i < 8; $i++) {
            $this->set(8, $this->size - 1 - $i, ($bits >> $i) & 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set($this->size - 15 + $i, 8, ($bits >> $i) & 1);
        }

        $this->set($this->size - 8, 8, 1);                                  // always dark
    }

    /** Zigzag placement, two columns at a time, right to left. */
    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', self::REMAINDER_BITS[$this->version]);

        $index = 0;
        $upward = true;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;                                                 // skip the timing column
            }
            for ($step = 0; $step < $this->size; $step++) {
                $row = $upward ? ($this->size - 1 - $step) : $step;
                foreach ([$right, $right - 1] as $col) {
                    if ($this->reserved[$row][$col]) {
                        continue;
                    }
                    $bit = $index < strlen($bits) ? (int) $bits[$index] : 0;
                    $this->modules[$row][$col] = $bit;
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    private function applyMask(int $mask): void
    {
        for ($row = 0; $row < $this->size; $row++) {
            for ($col = 0; $col < $this->size; $col++) {
                if ($this->reserved[$row][$col]) {
                    continue;
                }
                if (self::maskApplies($mask, $row, $col)) {
                    $this->modules[$row][$col] ^= 1;
                }
            }
        }
    }

    private static function maskApplies(int $mask, int $row, int $col): bool
    {
        switch ($mask) {
            case 0: return ($row + $col) % 2 === 0;
            case 1: return $row % 2 === 0;
            case 2: return $col % 3 === 0;
            case 3: return ($row + $col) % 3 === 0;
            case 4: return (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0;
            case 5: return ((($row * $col) % 2) + (($row * $col) % 3)) === 0;
            case 6: return (((($row * $col) % 2) + (($row * $col) % 3)) % 2) === 0;
            default: return (((($row + $col) % 2) + (($row * $col) % 3)) % 2) === 0;
        }
    }

    /* --------------------------------------------------- mask scoring rules */

    private function penalty(): int
    {
        return $this->penaltyRuns() + $this->penaltyBlocks() + $this->penaltyFinderLike() + $this->penaltyBalance();
    }

    /** Rule 1 — runs of five or more identical modules. */
    private function penaltyRuns(): int
    {
        $score = 0;

        for ($i = 0; $i < $this->size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run = 1;
                for ($j = 1; $j < $this->size; $j++) {
                    $current  = $horizontal ? $this->modules[$i][$j] : $this->modules[$j][$i];
                    $previous = $horizontal ? $this->modules[$i][$j - 1] : $this->modules[$j - 1][$i];
                    if ($current === $previous) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        return $score;
    }

    /** Rule 2 — 2x2 areas of a single colour. */
    private function penaltyBlocks(): int
    {
        $score = 0;
        for ($row = 0; $row < $this->size - 1; $row++) {
            for ($col = 0; $col < $this->size - 1; $col++) {
                $value = $this->modules[$row][$col];
                if ($value === $this->modules[$row][$col + 1]
                    && $value === $this->modules[$row + 1][$col]
                    && $value === $this->modules[$row + 1][$col + 1]
                ) {
                    $score += 3;
                }
            }
        }
        return $score;
    }

    /** Rule 3 — patterns that look like a finder. */
    private function penaltyFinderLike(): int
    {
        $needles = ['10111010000', '00001011101'];
        $score = 0;

        for ($i = 0; $i < $this->size; $i++) {
            $rowText = '';
            $colText = '';
            for ($j = 0; $j < $this->size; $j++) {
                $rowText .= $this->modules[$i][$j];
                $colText .= $this->modules[$j][$i];
            }
            foreach ($needles as $needle) {
                $score += 40 * substr_count($rowText, $needle);
                $score += 40 * substr_count($colText, $needle);
            }
        }

        return $score;
    }

    /** Rule 4 — how far the dark/light balance is from half. */
    private function penaltyBalance(): int
    {
        $dark = 0;
        foreach ($this->modules as $row) {
            $dark += array_sum($row);
        }
        $total = $this->size * $this->size;
        $percent = ($dark * 100) / $total;

        return 10 * (int) floor(abs($percent - 50) / 5);
    }
}

/**
 * Render a QR code as a PNG and return it as a data: URI, ready to drop into
 * an <img src> in the ticket template. Returns '' when a code cannot be made.
 */
function qr_png_data_uri(string $text, int $moduleSize = 4, int $quiet = 4): string
{
    $png = qr_png($text, $moduleSize, $quiet);
    return $png === '' ? '' : 'data:image/png;base64,' . base64_encode($png);
}

/** Raw PNG bytes for a QR code, or '' when it cannot be produced. */
function qr_png(string $text, int $moduleSize = 4, int $quiet = 4): string
{
    if (!function_exists('imagecreatetruecolor')) {
        return '';
    }

    $matrix = QrCode::matrix($text, 'M');
    if ($matrix === null) {
        return '';
    }

    $modules = count($matrix);
    $moduleSize = max(1, min(20, $moduleSize));
    $quiet = max(0, min(8, $quiet));
    $pixels = ($modules + ($quiet * 2)) * $moduleSize;

    $image = imagecreatetruecolor($pixels, $pixels);
    if ($image === false) {
        return '';
    }

    $white = (int) imagecolorallocate($image, 255, 255, 255);
    $black = (int) imagecolorallocate($image, 0, 0, 0);
    imagefilledrectangle($image, 0, 0, $pixels - 1, $pixels - 1, $white);

    foreach ($matrix as $row => $cells) {
        foreach ($cells as $col => $value) {
            if ($value !== 1) {
                continue;
            }
            $x = ($col + $quiet) * $moduleSize;
            $y = ($row + $quiet) * $moduleSize;
            imagefilledrectangle($image, $x, $y, $x + $moduleSize - 1, $y + $moduleSize - 1, $black);
        }
    }

    ob_start();
    imagepng($image, null, 9);
    $png = (string) ob_get_clean();
    imagedestroy($image);

    return $png;
}

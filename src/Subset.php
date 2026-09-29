<?php

declare(strict_types=1);

/**
 * Subset.php
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 */

namespace Com\Tecnick\Pdf\Font;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import\TrueType;

/**
 * Com\Tecnick\Pdf\Font\Subset
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontData from Load
 */
class Subset
{
    /**
     * array of table names to preserve (loca and glyf tables will be added later)
     * the cmap table is not needed and shall not be present,
     * since the mapping from character codes to glyph descriptions is provided separately
     *
     * @var array<string, bool>
     */
    protected const TABLENAMES = [
        'head' => true,
        'hhea' => true,
        'hmtx' => true,
        'maxp' => true,
        'cvt ' => true,
        'fpgm' => true,
        'prep' => true,
        'glyf' => true,
        'loca' => true,
    ];

    /**
     * Largest glyph offset the short format of the loca table can address.
     *
     * The entries are stored halved as 16-bit values, so the last one they reach is
     * 0xFFFF * 2.
     */
    protected const MAX_SHORT_LOCA_OFFSET = 131_070;

    /**
     * Content of the input font file
     */
    protected string $font = '';

    /**
     * Object used to read font bytes
     */
    protected Byte $fbyte;

    /**
     * Extracted font metrics
     *
     * @var TFontData
     */
    protected array $fdt = Load::DEFAULT_DATA;

    /**
     * Array containing subset glyphs indexes of chars from cmap table
     *
     * @var array<int, bool>
     */
    protected array $subglyphs = [];

    /**
     * Glyph index each glyph takes in a compacted subset (original index => subset index).
     *
     * Empty when the subset keeps the original glyph indices.
     *
     * @var array<int, int>
     */
    protected array $gidmap = [];

    /**
     * Tables whose data is rebuilt by the subset rather than copied from the input font.
     *
     * @var array<string, bool>
     */
    protected array $rebuilt = ['glyf' => true, 'loca' => true];

    /**
     * Fields updated in place in the tables copied from the input font.
     *
     * @var array<string, array<int, string>> Table tag => [byte offset => replacement bytes]
     */
    protected array $patches = [];

    /**
     * Subset font
     */
    protected string $subfont = '';

    /**
     * Pointer position on the original font data
     */
    protected int $offset = 0;

    /**
     * File helper used to load font definition files.
     */
    protected ObjFile $fileHelper;

    /**
     * Build the subset of a TrueType font
     *
     * @param string           $font       Content of the input font file
     * @param TFontData        $fdt        Extracted font metrics
     * @param ObjFile          $fileHelper File helper for font loading.
     * @param array<int, bool> $subchars   Array containing subset chars
     * @param ?array<int, int> $usedgids   Glyph indices used by the document (index => codepoint).
     *                                     When given, the subset keeps these glyphs and their
     *                                     components only, renumbered as getCompactGidMap()
     *                                     describes, and $subchars is not used to select them.
     *
     * @throws FontException in case of error
     */
    public function __construct(
        string $font,
        array $fdt,
        ObjFile $fileHelper,
        array $subchars = [],
        ?array $usedgids = null,
    ) {
        $this->fileHelper = $fileHelper;
        $this->font = $font;
        $this->fbyte = new Byte($font);

        try {
            $trueType = new TrueType(
                font: $font,
                fdt: $fdt,
                fileHelper: $this->fileHelper,
                fbyte: $this->fbyte,
                subchars: $subchars,
                // subsetting only needs the glyph program (loca and glyf tables)
                withCbbox: false,
                subsetting: true,
            );
            $this->fdt = $trueType->getFontMetrics();
            if (!$trueType->isSubsettingAllowed()) {
                // the OS/2 fsType carries the "No Subsetting" bit, so the whole program
                // is returned untouched
                $this->subfont = $font;
                return;
            }

            if ($usedgids === null) {
                $this->subglyphs = $trueType->getSubGlyphs();
            } else {
                // a compacted subset keeps exactly the glyphs the content streams name
                $this->gidmap = self::getCompactGidMap($usedgids);
                foreach (\array_keys($this->gidmap) as $gid) {
                    // an index the font does not define stays in the map as an empty glyph,
                    // so the map remains the one computed from the used glyphs alone
                    if ($gid >= $this->fdt['numGlyphs']) {
                        continue;
                    }

                    $this->subglyphs[$gid] = true;
                }
            }

            $this->addCompositeGlyphs();
            if ($this->gidmap === []) {
                $this->addProcessedTables();
            } else {
                $this->addCompactTables();
            }

            $this->removeUnusedTables();
            $this->buildSubsetFont();
        } catch (\RangeException $exc) {
            // the byte reader ran past the end of a truncated or corrupt font program
            throw new FontException('Malformed font program: ' . $exc->getMessage(), 0, $exc);
        }
    }

    /**
     * Returns the subset font program
     */
    public function getSubsetFont(): string
    {
        return $this->subfont;
    }

    /**
     * Returns the glyph index each used glyph takes in a compacted subset.
     *
     * The .notdef glyph keeps index 0 and the used glyphs follow in ascending order of their
     * original index. The map depends only on the set of used glyphs, so it is the same for a
     * subset built now and for one a cache returns. The components of the composite glyphs are
     * appended after them by the subset, and never appear in a content stream.
     *
     * @param array<int, mixed> $usedgids Glyph indices used by the document, as keys.
     *
     * @return array<int, int> Original glyph index => subset glyph index.
     */
    public static function getCompactGidMap(array $usedgids): array
    {
        $gids = \array_keys($usedgids);
        \sort($gids);

        $map = [0 => 0];
        foreach ($gids as $gid) {
            // .notdef is always the first glyph, and a glyph index is a 16-bit value
            if ($gid < 1 || $gid > 0xFFFF) {
                continue;
            }

            $map[$gid] = \count($map);
        }

        return $map;
    }

    /**
     * Returns the checksum of a TTF table.
     *
     * @param string $table  Table to check
     * @param int    $length Length of table in bytes
     *
     * @return int checksum
     */
    protected function getTableChecksum(string $table, int $length): int
    {
        $sum = 0;
        $tlen = (int) \floor(($length + 3) / 4);
        $offset = 0;
        for ($idx = 0; $idx < $tlen; ++$idx) {
            // OpenType checksums use zero-padding for trailing partial words
            $chunk = \str_pad(\substr($table, $offset, 4), 4, "\0", STR_PAD_RIGHT);
            $sum += (\ord($chunk[0]) << 24) | (\ord($chunk[1]) << 16) | (\ord($chunk[2]) << 8) | \ord($chunk[3]);
            // the sum is truncated to 32 bits at every word
            $sum &= 0xFFFF_FFFF;
            $offset += 4;
        }

        return $sum;
    }

    /**
     * Add composite glyphs
     */
    protected function addCompositeGlyphs(): void
    {
        $new_sga = $this->subglyphs;
        while ($new_sga !== []) {
            $sga = \array_keys($new_sga);
            $new_sga = [];
            foreach ($sga as $key) {
                $new_sga = $this->findCompositeGlyphs($new_sga, $key);
            }

            foreach ($new_sga as $gid => $enabled) {
                if (!$enabled) {
                    continue;
                }

                $this->subglyphs[$gid] = true;
            }
        }

        // sort glyphs by key
        \ksort($this->subglyphs);
    }

    /**
     * Find the component glyphs of a composite glyph
     *
     * @param array<int, bool> $new_sga Glyph indexes to add to the subset
     * @param int              $key     Glyph index to inspect
     *
     * @return array<int, bool>
     */
    protected function findCompositeGlyphs(array $new_sga, int $key): array
    {
        if (isset($this->fdt['indexToLoc'][$key])) {
            /**
             * Glyph Header
             * - int16      numberOfContours    Normal glyph if >= 0 or composite glyph if negative (should be -1)
             * - int16      xMin                Minimum x for coordinate data.
             * - int16      yMin                Minimum y for coordinate data.
             * - int16      xMax                Maximum x for coordinate data.
             * - int16      yMax                Maximum y for coordinate data.
             */

            $this->offset = $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$key];
            // the MORE_COMPONENTS chain is bounded by the end of this glyph
            $nextidx = $this->getNextLocaIndex($key + 1);
            $glyphEnd = $nextidx === null
                ? $this->fdt['table']['glyf']['offset'] + $this->fdt['table']['glyf']['length']
                : $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$nextidx];
            $numberOfContours = $this->fbyte->getShort($this->offset);
            $this->offset += 2;
            if ($numberOfContours < 0) { // composite glyph
                /**
                 * ComponentGlyph record
                 * - uint16            flags          Normal glyph if >= 0 or composite glyph if negative (should be -1)
                 * - uint16            glyphIndex     glyph index of component
                 * - u/int8|u/int16    argument1      x-offset for component or point number; type depends on bits 0 and 1 in component flags
                 * - u/int8|u/int16    argument2      y-offset for component or point number; type depends on bits 0 and 1 in component flags
                 * - [transform data]                 optional transform data
                 */
                $this->offset += 8; // skip xMin, yMin, xMax, yMax
                do {
                    if (($this->offset + 4) > $glyphEnd) {
                        break; // the component record does not fit this glyph
                    }

                    $flags = $this->fbyte->getUShort($this->offset);
                    $this->offset += 2;
                    $glyphIndex = $this->fbyte->getUShort($this->offset);
                    $this->offset += 2;
                    if (!isset($this->subglyphs[$glyphIndex])) {
                        // add missing glyphs
                        $new_sga[$glyphIndex] = true;
                    }

                    // skip some bytes by case
                    // ARG_1_AND_2_ARE_WORDS (bit 0): [u]int32 if set and [u]int16 if not set
                    if (($flags & 1) !== 0) {
                        $this->offset += 4;
                    } else {
                        $this->offset += 2;
                    }

                    if (($flags & 8) !== 0) {
                        // WE_HAVE_A_SCALE (bit 3):            Adds 1 * F2DOT14 field
                        $this->offset += 2;
                    } elseif (($flags & 64) !== 0) {
                        // WE_HAVE_AN_X_AND_Y_SCALE (bit 6):   Adds 2 * F2DOT14 fields
                        $this->offset += 4;
                    } elseif (($flags & 128) !== 0) {
                        // WE_HAVE_A_TWO_BY_TWO (bit 7):       Adds 4 * F2DOT14 fields
                        $this->offset += 8;
                    }
                } while ($flags & 32); // MORE_COMPONENTS (bit 5)
            }
        }

        return $new_sga;
    }

    /**
     * Remove unused tables
     *
     * @throws FontException if a preserved table is truncated by the font file.
     */
    protected function removeUnusedTables(): void
    {
        // the sfnt specification requires the table directory to be sorted by tag in
        // ascending order, which is also the order buildSubsetFont() emits
        \ksort($this->fdt['table']);

        // get the tables to preserve
        $this->offset = 12;
        $tabname = \array_keys($this->fdt['table']);
        foreach ($tabname as $tag) {
            if (!isset(self::TABLENAMES[$tag])) {
                // remove the table
                unset($this->fdt['table'][$tag]);
                continue;
            }

            $isSubsetTable = isset($this->rebuilt[$tag]);
            if (!$isSubsetTable) {
                $this->fdt['table'][$tag]['data'] = \substr(
                    $this->font,
                    $this->fdt['table'][$tag]['offset'],
                    $this->fdt['table'][$tag]['length'],
                );
                // the emitted table directory advertises the declared length
                if (\strlen($this->fdt['table'][$tag]['data']) !== $this->fdt['table'][$tag]['length']) {
                    throw new FontException('truncated font table: ' . $tag);
                }

                if ($tag === 'head') {
                    // set the checkSumAdjustment to 0, replacing the four bytes in place
                    $this->fdt['table'][$tag]['data'] = \substr_replace(
                        $this->fdt['table'][$tag]['data'],
                        "\x0\x0\x0\x0",
                        8,
                        4,
                    );
                }

                $this->patchTable($tag);

                // the checksum is computed from the bytes emitted, not copied from the
                // directory of the input font
                $this->fdt['table'][$tag]['checkSum'] = $this->getTableChecksum(
                    $this->fdt['table'][$tag]['data'],
                    $this->fdt['table'][$tag]['length'],
                );
            }

            $this->padTable($tag);
            $this->fdt['table'][$tag]['offset'] = $this->offset;
            $this->offset += \strlen($this->fdt['table'][$tag]['data']);
        }
    }

    /**
     * Pad the data of a table to the next four byte boundary.
     *
     * The gap to the next boundary is filled with zeroes. The table directory keeps
     * advertising the real length of the table.
     *
     * @param string $tag Tag of the table to pad.
     */
    protected function padTable(string $tag): void
    {
        $pad = (4 - (\strlen($this->fdt['table'][$tag]['data']) % 4)) % 4;
        if ($pad !== 0) {
            $this->fdt['table'][$tag]['data'] .= \str_repeat("\x0", $pad);
        }
    }

    /**
     * Add glyf and loca tables
     *
     * @throws FontException if the glyph data does not fit the format of the loca table.
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    protected function addProcessedTables(): void
    {
        // build new glyf and loca tables
        $glyf = '';
        $loca = '';
        $this->offset = 0;
        $glyf_offset = $this->fdt['table']['glyf']['offset'];
        for ($i = 0; $i < $this->fdt['tot_num_glyphs']; ++$i) {
            $nextidx = $this->getNextLocaIndex($i + 1);
            $length = 0;
            if (isset($this->subglyphs[$i], $this->fdt['indexToLoc'][$i]) && $nextidx !== null) {
                // a non-monotonic loca table would give a negative length
                $length = \max(0, $this->fdt['indexToLoc'][$nextidx] - $this->fdt['indexToLoc'][$i]);
                $glyf .= \substr($this->font, $glyf_offset + $this->fdt['indexToLoc'][$i], $length);
            }

            if ($this->fdt['short_offset']) {
                if ($this->offset > self::MAX_SHORT_LOCA_OFFSET) {
                    throw new FontException('The subset glyph data is too large for a short loca table');
                }

                $loca .= \pack('n', \intdiv($this->offset, 2));
            } else {
                $loca .= \pack('N', $this->offset);
            }

            $this->offset += $length;
        }

        // add loca and glyf; removeUnusedTables() assigns their offsets
        $this->fdt['table']['loca']['data'] = $loca;
        $this->fdt['table']['loca']['length'] = \strlen($loca);
        $this->padTable('loca');
        $this->fdt['table']['loca']['checkSum'] = $this->getTableChecksum(
            $this->fdt['table']['loca']['data'],
            \strlen($this->fdt['table']['loca']['data']),
        );

        $this->fdt['table']['glyf']['data'] = $glyf;
        $this->fdt['table']['glyf']['length'] = \strlen($glyf);
        $this->padTable('glyf');
        $this->fdt['table']['glyf']['checkSum'] = $this->getTableChecksum(
            $this->fdt['table']['glyf']['data'],
            \strlen($this->fdt['table']['glyf']['data']),
        );
    }

    /**
     * Apply the in-place field updates recorded for a table copied from the input font.
     *
     * @param string $tag Tag of the table to update.
     *
     * @throws FontException if a field lies past the end of the table.
     */
    protected function patchTable(string $tag): void
    {
        foreach ($this->patches[$tag] ?? [] as $pos => $bytes) {
            if (($pos + \strlen($bytes)) > \strlen($this->fdt['table'][$tag]['data'])) {
                throw new FontException('truncated font table: ' . $tag);
            }

            $this->fdt['table'][$tag]['data'] = \substr_replace(
                $this->fdt['table'][$tag]['data'],
                $bytes,
                $pos,
                \strlen($bytes),
            );
        }
    }

    /**
     * Build the glyf, loca and hmtx tables of a compacted subset.
     *
     * The glyphs are written in the order of their subset index, with the components of the
     * composite glyphs appended after the used glyphs and every component reference renumbered.
     * The numGlyphs (maxp), numberOfHMetrics (hhea) and indexToLocFormat (head) fields are
     * updated to describe the new tables.
     *
     * @throws FontException if a glyph or its metrics cannot be read.
     */
    protected function addCompactTables(): void
    {
        // addCompositeGlyphs() sorted the glyphs, so the components follow in ascending order
        foreach (\array_keys($this->subglyphs) as $gid) {
            $this->gidmap[$gid] ??= \count($this->gidmap);
        }

        $glyf = '';
        $offsets = [];
        $metrics = [];
        // the map was filled in ascending order of subset index
        foreach (\array_keys($this->gidmap) as $gid) {
            $offsets[] = \strlen($glyf);
            $data = $this->renumberComponents($this->getGlyphData($gid));
            // a four byte alignment keeps every offset even, as the short loca format requires
            $glyf .= $data . \str_repeat("\x0", (4 - (\strlen($data) % 4)) % 4);
            $metrics[] = $this->getHorMetric($gid);
        }

        $offsets[] = \strlen($glyf);
        $short = \strlen($glyf) <= self::MAX_SHORT_LOCA_OFFSET;
        $loca = $short
            ? \pack('n*', ...\array_map(static fn(int $offset): int => $offset >> 1, $offsets))
            : \pack('N*', ...$offsets);

        // a trailing run of glyphs sharing one advance width keeps only its left side bearings
        $numGlyphs = \count($metrics);
        $numHMetrics = $numGlyphs;
        while ($numHMetrics > 1 && $metrics[$numHMetrics - 1][0] === $metrics[$numHMetrics - 2][0]) {
            --$numHMetrics;
        }

        $hmtx = '';
        foreach ($metrics as $idx => [$advance, $lsb]) {
            $hmtx .= $idx < $numHMetrics ? \pack('nn', $advance, $lsb & 0xFFFF) : \pack('n', $lsb & 0xFFFF);
        }

        $this->setRebuiltTable('glyf', $glyf);
        $this->setRebuiltTable('loca', $loca);
        $this->setRebuiltTable('hmtx', $hmtx);
        $this->patches = [
            'head' => [50 => \pack('n', $short ? 0 : 1)], // indexToLocFormat
            'hhea' => [34 => \pack('n', $numHMetrics)], // numberOfHMetrics
            'maxp' => [4 => \pack('n', $numGlyphs)], // numGlyphs
        ];
    }

    /**
     * Store the data of a table rebuilt by the subset.
     *
     * @param string $tag  Tag of the table.
     * @param string $data Table data, before padding.
     */
    protected function setRebuiltTable(string $tag, string $data): void
    {
        $this->rebuilt[$tag] = true;
        $this->fdt['table'][$tag]['data'] = $data;
        $this->fdt['table'][$tag]['length'] = \strlen($data);
        $this->padTable($tag);
        $this->fdt['table'][$tag]['checkSum'] = $this->getTableChecksum(
            $this->fdt['table'][$tag]['data'],
            \strlen($this->fdt['table'][$tag]['data']),
        );
    }

    /**
     * Returns the program of a glyph, or an empty string for an empty or missing glyph.
     *
     * @param int $gid Original glyph index.
     */
    protected function getGlyphData(int $gid): string
    {
        $nextidx = $this->getNextLocaIndex($gid + 1);
        if (!isset($this->fdt['indexToLoc'][$gid]) || $nextidx === null) {
            return '';
        }

        // a non-monotonic loca table would give a negative length
        $length = \max(0, $this->fdt['indexToLoc'][$nextidx] - $this->fdt['indexToLoc'][$gid]);
        return \substr($this->font, $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$gid], $length);
    }

    /**
     * Returns the advance width and left side bearing the hmtx table stores for a glyph.
     *
     * A glyph past the last longHorMetric record takes the advance width of that record, and
     * a glyph index the font does not define is reported with zero metrics.
     *
     * @param int $gid Original glyph index.
     *
     * @return array{int, int} Advance width and left side bearing.
     *
     * @throws FontException if the hmtx table has no metrics for the glyph.
     */
    protected function getHorMetric(int $gid): array
    {
        if ($gid >= $this->fdt['numGlyphs']) {
            return [0, 0];
        }

        $numHMetrics = $this->fdt['numHMetrics'];
        $hmtx = $this->fdt['table']['hmtx'];
        $advancePos = $hmtx['offset'] + (4 * \min($gid, $numHMetrics - 1));
        $lsbPos = $gid < $numHMetrics
            ? $advancePos + 2
            : $hmtx['offset'] + (4 * $numHMetrics) + (2 * ($gid - $numHMetrics));
        if (($lsbPos + 2) > ($hmtx['offset'] + $hmtx['length'])) {
            throw new FontException('the hmtx table has no metrics for the glyph ' . $gid);
        }

        return [$this->fbyte->getUShort($advancePos), $this->fbyte->getShort($lsbPos)];
    }

    /**
     * Renumber the component references of a composite glyph with the subset glyph indices.
     *
     * @param string $data Program of the glyph.
     *
     * @throws FontException if a component is not part of the subset.
     */
    protected function renumberComponents(string $data): string
    {
        $end = \strlen($data);
        // a composite glyph has a negative numberOfContours
        if ($end < 10 || (\ord($data[0]) & 0x80) === 0) {
            return $data;
        }

        $pos = 10; // skip numberOfContours, xMin, yMin, xMax and yMax
        do {
            if (($pos + 4) > $end) {
                break; // the component record does not fit the glyph
            }

            $flags = (\ord($data[$pos]) << 8) | \ord($data[$pos + 1]);
            $gid = (\ord($data[$pos + 2]) << 8) | \ord($data[$pos + 3]);
            if (!isset($this->gidmap[$gid])) {
                throw new FontException('the component glyph ' . $gid . ' is not part of the subset');
            }

            $data = \substr_replace($data, \pack('n', $this->gidmap[$gid]), $pos + 2, 2);
            // ARG_1_AND_2_ARE_WORDS (bit 0) selects 16-bit arguments
            $pos += ($flags & 1) !== 0 ? 8 : 6;
            if (($flags & 8) !== 0) {
                $pos += 2; // WE_HAVE_A_SCALE (bit 3): one F2DOT14
            } elseif (($flags & 64) !== 0) {
                $pos += 4; // WE_HAVE_AN_X_AND_Y_SCALE (bit 6): two F2DOT14
            } elseif (($flags & 128) !== 0) {
                $pos += 8; // WE_HAVE_A_TWO_BY_TWO (bit 7): four F2DOT14
            }
        } while (($flags & 32) !== 0); // MORE_COMPONENTS (bit 5)

        return $data;
    }

    /**
     * Returns the first available loca index from $start, or null if none exists.
     */
    protected function getNextLocaIndex(int $start): ?int
    {
        for ($idx = $start; $idx <= $this->fdt['tot_num_glyphs']; ++$idx) {
            if (isset($this->fdt['indexToLoc'][$idx])) {
                return $idx;
            }
        }

        return null;
    }

    /**
     * Build the subset font program
     */
    protected function buildSubsetFont(): void
    {
        $this->subfont = '';
        $this->subfont .= \pack('N', 0x1_0000); // sfnt version
        $numTables = \count($this->fdt['table']);
        $this->subfont .= \pack('n', $numTables); // numTables
        // entrySelector = floor(log2(numTables))
        $entrySelector = 0;
        while ((2 << $entrySelector) <= $numTables) {
            ++$entrySelector;
        }

        $searchRange = (1 << $entrySelector) * 16;
        $rangeShift = ($numTables * 16) - $searchRange;
        $this->subfont .= \pack('n', $searchRange); // searchRange
        $this->subfont .= \pack('n', $entrySelector); // entrySelector
        $this->subfont .= \pack('n', $rangeShift); // rangeShift
        // the offsets stored in $this->fdt start after the 12-byte sfnt header, so they are
        // shifted by the size of the table directory that follows it
        $tableDataBaseOffset = $numTables * 16;
        foreach ($this->fdt['table'] as $tag => $data) {
            $this->subfont .= $tag; // tag
            $this->subfont .= \pack('N', $data['checkSum']); // checkSum
            $this->subfont .= \pack('N', $data['offset'] + $tableDataBaseOffset); // offset
            $this->subfont .= \pack('N', $data['length']); // length
        }

        foreach ($this->fdt['table'] as $data) {
            $this->subfont .= $data['data'];
        }

        // set checkSumAdjustment on head table
        $checkSumAdjustment = 0xB1B0_AFBA - $this->getTableChecksum($this->subfont, \strlen($this->subfont));
        $headAdjustmentPos = $tableDataBaseOffset + $this->fdt['table']['head']['offset'] + 8;
        $this->subfont =
            \substr($this->subfont, 0, $headAdjustmentPos)
            . \pack('N', $checkSumAdjustment)
            . \substr($this->subfont, $headAdjustmentPos + 4);
    }
}

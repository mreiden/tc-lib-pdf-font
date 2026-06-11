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
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 */

namespace Com\Tecnick\Pdf\Font;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\Compression;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Font\Enum\GlyphType;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import\TrueType;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;

/**
 * Com\Tecnick\Pdf\Font\Subset
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontData from \Com\Tecnick\Pdf\Font\Load
 * @phpstan-import-type TFontDataTableItem from \Com\Tecnick\Pdf\Font\Load
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class Subset
{
    private static bool $enableDebug = false;

    public static function enableDebug(?bool $enableDebug = null): bool
    {
        $old = self::$enableDebug;
        if ($enableDebug !== null) {
            self::$enableDebug = $enableDebug;
        }
        return $old;
    }

    public static bool $saveSubsetFile = false;

    use FontDataTrait;

    /**
     * @var array{
     *     gtcdata: array<int,int>,
     *     table: array{
     *         name: null|array{
     *             platformId: 0|1|3,
     *             encodingId: int<0, 10>,
     *             languageId: int<0, 255>,
     *             nameIds: array<int, string>
     *         }
     *     }
     * }
     */
    protected array $fdtExtra = [
        'gtcdata' => [],
        'table' => [
            'name' => null,
        ],
    ];

    /**
     * Array of table names to preserve as-is and those that will be created/modified
     * as part of subsetting (glyf. loca, post).
     *
     * The TTF cmap table is not included since the mapping from character codes
     * to font glyphs is declared in the PDF document using /Encoding in the
     * /BaseFont object.
     *
     * @var array<string, bool>
     */
    protected const TABLENAMES = [
        'cvt ' => true,
        'fpgm' => true,
        'glyf' => true,
        'head' => true,
        'hhea' => true,
        'hmtx' => true,
        'loca' => true,
        'maxp' => true,
        'prep' => true,
    ];

    /**
     * Array of table names to preserve as-is and those that will be created/modified
     * as part of a enableDebug subset.
     *
     * @var array<string, bool>
     */
    protected const DEBUG_TABLENAMES = [
        //"FFTM" => true,
        'GDEF' => true,
        //"GPOS" => true,
        //"GSUB" => true,
        //"gasp" => true,
        //"kern" => true,

        'cmap' => true,
        'name' => true,

        'OS/2' => true,
        'post' => true,
    ];

    /**
     * Object used to read font bytes
     */
    protected readonly Byte $fbyte;

    /**
     * Array containing subset glyphIds from cmap table
     *
     * @var array<int, bool|int|GlyphType::CompositeChildWithoutCharacterCode|GlyphType::NotdefGlyph>
     */
    protected array $subglyphs = [];

    /**
     * Array containing glyphIds of composite glyphs found for the subsetted characters
     *
     * @var array<int, array{ newId?: int, newOffset?: int, children: array<int, list<int>> }>
     */
    protected array $compositeGlyphs = [];

    /**
     * Array containing the glyphIds of the children of composite glyphs.
     *
     * These are needed when reindexing glyphs so the ids in the composite glyphs can
     * be updated to use the new ids.
     *
     * @var array<int, bool>
     */
    protected array $compositeChildren = [];

    /**
     * Subset font (binary)
     */
    protected string $subfont = '';

    /**
     * File helper used to load font definition files.
     */
    protected ObjFile $fileHelper;

    /**
     * Process TrueType font
     *
     * @param string              $font          Content (binary) of the input font file
     * @param TFontData           $fdt           Extracted font metrics
     * @param ObjFile             $fileHelper    Optional file helper for font loading
     * @param array<int, int>     $subchars      Array containing subset chars
     *
     * @throws FileException
     * @throws FontException
     */
    public function __construct(protected readonly string $font, array $fdt, ObjFile $fileHelper, protected array $subchars = [])
    {
        if (empty($fdt['subset'])) {
            throw new FontException('Subset flag in fdt array must equal true to create a font subset.');
        }
        $this->fileHelper = $fileHelper;

        // Use the TrueType class to get information about the base font.
        $this->fbyte = new Byte($font);
        $trueType = new TrueType(
            font: $font,
            fdt: $fdt,
            fileHelper: $this->fileHelper,
            fbyte: $this->fbyte,
            subchars: $subchars,
        );
        $this->fdt = $trueType->getFontMetrics();

        if (empty($this->fdt['tableSubset']['hmtx']['hMetrics'])) {
            // Try to Import the font again to update the font definition JSON
            try {
                $data = \file_get_contents($this->fdt['dir'] . '/' . $this->fdt['file']);
                if (empty($data)) {
                    throw new FontException('Cannot load font file');
                }
                if (\str_ends_with($this->fdt['file'], '.z')) {
                    $data = Compression::uncompress($data);
                }
                $tmpFile = \tmpfile();
                if ($tmpFile) {
                    $tmpFileStream = \stream_get_meta_data($tmpFile);
                    if (!empty($tmpFileStream['uri'])) {
                        \fwrite($tmpFile, $data);
                        \fseek($tmpFile, 0);
                        $import = new Import(
                            $tmpFileStream['uri'],
                            $this->fdt['dir'],
                            $this->fdt['type'],
                            $this->fdt['encoding'] ?? '',
                            $this->fdt['flags'] ?? 32,
                            $this->fdt['platform_id'],
                            $this->fdt['encoding_id'],
                            $this->fdt['linked'],
                            false,
                        );
                        $json = $import->createFontJSON();
                        $json = \json_decode($json, true);
                        /** @var TFontData $json */
                        $json['file'] = $this->fdt['file'];
                        $json['ctg'] = $this->fdt['ctg'];
                        \file_put_contents($fdt['ifile'], \json_encode($json, JSON_THROW_ON_ERROR));

                        $json = \array_replace_recursive($this->fdt, $json);
                        /** @var TFontData $json */
                        $this->fdt = $json;
                    }
                }
            } catch (\Throwable) {
                throw new FontException('Subset table has no hmtx hMetrics.');
            }
        }

        // Use the TrueType class to get information about the base font.
        $this->subglyphs = $trueType->getSubGlyphs();

        $this->addCompositeGlyphs();
        $this->addProcessedTables();
        $this->removeUnusedTables();
        $this->buildSubsetFont();
    }

    /**
     * Get the subset font (binary)
     */
    public function getSubsetFont(): string
    {
        return $this->subfont;
    }

    /**
     * Get the subset font data
     *
     * @return TFontData
     */
    public function getFontData(): array
    {
        $this->fdt['indexToLoc'] = [];
        return $this->fdt;
    }

    /**
     * Add composite glyphs.
     *
     * This adds the children of composite glyphs to the font subset list and
     * creates a list of composite glyphs so they can be updated when the
     * glyf and loca tables are renumbered as part of subsetting.
     */
    protected function addCompositeGlyphs(): void
    {
        // Initialize an empty compositeGlyphs array for findCompositeGlyphs
        // to add glyphs to as composites are found.
        $this->compositeGlyphs = [];

        $this->fdtExtra['gtcdata'] = \array_flip($this->fdt['ctgdata']);

        $new_sga = $this->subglyphs;
        while ($new_sga !== []) {
            $sga = \array_keys($new_sga);
            $new_sga = [];
            foreach ($sga as $key) {
                $new_sga = $this->findCompositeGlyphs($new_sga, $key);
            }

            // Array addition keeps keys intact and removes duplicates
            $this->subglyphs += $new_sga;
        }
    }

    /**
     * Find composite glyphs
     *
     * @param array<int, bool|int|GlyphType::CompositeChildWithoutCharacterCode|GlyphType::NotdefGlyph> $new_sga
     * @param int $key
     *
     * @return array<int, bool|int|GlyphType::CompositeChildWithoutCharacterCode|GlyphType::NotdefGlyph>
     */
    protected function findCompositeGlyphs(array $new_sga, int $key): array
    {
        // Skip processing this glyph if there is not a glyphId for this character code (key)
        if (!isset($this->fdt['indexToLoc'][$key])) {
            return $new_sga;
        }

        // The offset to the glyph in the original font file
        $offset_glyph = $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$key];

        /**
         * Glyph Header
         *   0 - int16             numberOfContours   Normal glyph if >= 0 or composite glyph if negative (should be -1)
         *   2 - int16             xMin               Minimum x for coordinate data.
         *   4 - int16             yMin               Minimum y for coordinate data.
         *   6 - int16             xMax               Maximum x for coordinate data.
         *   8 - int16             yMax               Maximum y for coordinate data.
         *  10 - ComponentGlyphs[]                    Array of ComponentGlyphs if numberOfContours < 0
         *
         * ComponentGlyph (Child Glyph) Record (variable 8-16 bytes depending on flags field):
         *   0 - uint16           flags               component flags; bit array describing ComponentGlyph
         *   2 - uint16           glyphIndex          glyph index of component
         *   4 - u/int8|u/int16   argument1           x-offset for component or point number; type depends on bits 0 and 1 in component flags
         *   6 - u/int8|u/int16   argument2           y-offset for component or point number; type depends on bits 0 and 1 in component flags
         *   8 - [transform data]                     optional transform data
         */
        $numberOfContours = $this->fbyte->getShort($offset_glyph);

        // skip xMin, yMin, xMax, and yMax fields

        if ($numberOfContours < 0) {
            $offset_child = $offset_glyph + 10;
            do {
                $flags = $this->fbyte->getUShort($offset_child);
                $offset_child += 2;

                $glyphChildIndex = $this->fbyte->getUShort($offset_child);
                if (!isset($this->subglyphs[$glyphChildIndex])) {
                    // add missing child glyph
                    $new_sga[$glyphChildIndex] =
                        $this->fdtExtra['gtcdata'][$glyphChildIndex] ?? GlyphType::CompositeChildWithoutCharacterCode;

                    // Save the childId byte-offset from the parent glyph to update the child glyph id when renumbering
                    $this->compositeGlyphs[$key] ??= ['children' => []];
                    $this->compositeGlyphs[$key]['children'][$glyphChildIndex] ??= [];
                    $this->compositeGlyphs[$key]['children'][$glyphChildIndex][] = $offset_child - $offset_glyph;

                    // Add this character code to the list that needs to renumber children
                    $this->compositeChildren[$key] = true;
                }
                $offset_child += 2;

                // ARG_1_AND_2_ARE_WORDS (bit 0): [u]int32 if set and [u]int16 if not set
                $offset_child += $flags & 1 ? 4 : 2;

                if ($flags & 8) {
                    // WE_HAVE_A_SCALE (bit 3):            Adds 1 * F2DOT14 field
                    $offset_child += 2;
                } elseif ($flags & 64) {
                    // WE_HAVE_AN_X_AND_Y_SCALE (bit 6):   Adds 2 * F2DOT14 fields
                    $offset_child += 4;
                } elseif ($flags & 128) {
                    // WE_HAVE_A_TWO_BY_TWO (bit 7):       Adds 4 * F2DOT14 fields
                    $offset_child += 8;
                }
            } while ($flags & 32); // MORE_COMPONENTS (bit 5)
        }

        return $new_sga;
    }

    /**
     * Add processed tables.
     *
     * Creates new cmap, glyf, loca, hmtx, name, and post tables.
     * Updates fields in head, hhea, and maxp tables.
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    protected function addProcessedTables(): void
    {
        $tbl = &$this->fdt['table'];

        // The data in the following tables are modified, so preload the existing data.
        $head = &$this->fdt['table']['head'];
        $head['data'] = \substr($this->font, $head['offset'], $head['length']);
        $hhea = &$this->fdt['table']['hhea'];
        $hhea['data'] = \substr($this->font, $hhea['offset'], $hhea['length']);
        $maxp = &$this->fdt['table']['maxp'];
        $maxp['data'] = \substr($this->font, $maxp['offset'], $maxp['length']);

        $tblSubset = &$this->fdt['tableSubset'];

        // cmap table setup
        $glyphIdArray = [];
        unset($this->fdt['ctgdata'][0xffff]);
        $ctg = [];

        // glyf table reference
        $glyf = &$this->fdt['table']['glyf'];

        // hmtx table setup
        $hmtx = &$this->fdt['table']['hmtx'];
        $newNumHMetrics = 0;
        $hMetrics = &$this->fdt['tableSubset']['hmtx']['hMetrics'];
        $lsbOnly = &$this->fdt['tableSubset']['hmtx']['lsbOnly'];

        // post table setup
        /** @var list<string> $newGlyphNames */
        $newGlyphNames = [];

        // Build new glyf, loca, cmap, hmtx tables
        $offset = 0;
        $loca_vals = [];
        /** @var list<int> $GDEF_segments */
        $GDEF_segments = [];
        $indexToLoc = &$this->fdt['indexToLoc'];

        $cbbox = [];
        $cwNew = [];
        $newHMetrics = [];

        $subGlyphsAsTuples = [
            // Always include the .notdef glyph (glyph id 0)
            [0, 0, GlyphType::NotdefGlyph],
        ];
        $firstUnusedGlyphId = 1 + \count($this->subchars);
        foreach ($this->subglyphs as $gid => $chrCode) {
            if ($chrCode !== GlyphType::NotdefGlyph) {
                $subGlyphsAsTuples[$gid] = [
                    // Original TTF GlyphId
                    $gid,
                    // New Subset GlyphId
                    $chrCode !== GlyphType::CompositeChildWithoutCharacterCode && isset($this->subchars[$chrCode])
                        ? $this->subchars[$chrCode]
                        : $firstUnusedGlyphId++,
                    // Unicode Character Code
                    $chrCode,
                ];
            }
        }

        // Sort the array by the new glyph id (array index 1 which is $numGlyphs below)
        uasort($subGlyphsAsTuples, fn(array $item1, array $item2): int => $item1[1] <=> $item2[1]);
        foreach ($subGlyphsAsTuples as [$gidOld, $numGlyphs, $chrCode]) {
            if (!isset($indexToLoc[$gidOld + 1])) {
                throw new FontException("Cannot find ending offset for glyph number $gidOld");
            }

            $bChildGlyph =
                $chrCode === GlyphType::CompositeChildWithoutCharacterCode || $chrCode === GlyphType::NotdefGlyph;

            // Add glyph to the array of new glyphs
            $glyphIdArray[$gidOld] = $numGlyphs;
            $length = $indexToLoc[$gidOld + 1] - $indexToLoc[$gidOld];
            // Add the bits for the glyph to the new glyf table data
            if ($length > 0) {
                $glyf['data'] .= \substr($this->font, $glyf['offset'] + $indexToLoc[$gidOld], $length);
                if (isset($this->compositeGlyphs[$gidOld])) {
                    $this->compositeGlyphs[$gidOld]['newOffset'] = $offset;
                    $this->compositeGlyphs[$gidOld]['newId'] = $numGlyphs;
                }
            }

            // Store regular glyphs (not children of composite glyphs) to create the GDEF table
            if (!$bChildGlyph) {
                $GDEF_segments[] = $numGlyphs;
                $ctg[$chrCode] = $numGlyphs;
            }

            if (isset($this->fdt['cbbox'][$gidOld])) {
                $cbbox[$numGlyphs] = $this->fdt['cbbox'][$gidOld];
            }

            // The Character Width (cwNew) is stored by Character Code instead of glyphId
            if ($bChildGlyph) {
                // Have to calculate from font table hmtx metrics
                $cwNew[$numGlyphs] = (int) \round(
                    $this->fdt['urk'] *
                        ($gidOld < $this->fdt['numHMetrics']
                            ? $hMetrics[$gidOld][0]
                            : $hMetrics[\count($hMetrics) - 1][0]),
                );
            } elseif (isset($this->fdt['cw'][$chrCode])) {
                $cwNew[$numGlyphs] = $this->fdt['cw'][$chrCode];
            }

            // Add the postscript standard Macintosh character ids (id < 258) or glyph name strings (id >= 258).
            // The Import\TrueType class creates the postscriptGlyphNames array used here.
            if (isset($this->fdt['postscriptGlyphNames'][$gidOld])) {
                $nameOrIdx = $this->fdt['postscriptGlyphNames'][$gidOld];
                // Use 257 instead of 258 since array_push returns array length after adding the name (index+1 instead of index)
                $newGlyphNameIndex[] = \is_int($nameOrIdx) ? $nameOrIdx : 257 + \array_push($newGlyphNames, $nameOrIdx);
            }

            // Add the hmtx entries
            if ($gidOld < $this->fdt['numHMetrics']) {
                if (!isset($hMetrics[$gidOld])) {
                    throw new FontException('TTF hmtx table is missing hMetric data.');
                }
                $hMetric = $hMetrics[$gidOld];
            } else {
                if (!isset($lsbOnly[$gidOld - $this->fdt['numHMetrics']])) {
                    throw new FontException('TTF hmtx table is missing lsb data.');
                }
                // TODO: This could potentially be optimized to use lsbOnly array
                // Create a new hMetric pair.  The last hMetric from the original font is likely not
                // included in the subset, so the number of metrics would need to be recalculated.
                $hMetric = [$hMetrics[\count($hMetrics) - 1][0], $lsbOnly[$gidOld - $this->fdt['numHMetrics']]];
            }
            $newNumHMetrics++;
            $hmtx['data'] .= \pack('n2', ...$hMetric);
            $newHMetrics[] = $hMetric;

            // Add the glyph byte offset to the loca table for this glyph
            $loca_vals[] = $offset;

            // Done with the current glyph, bump the glyph id and offset location
            $offset += $length;
        }

        $this->fdt['cbbox'] = $cbbox;
        $this->fdt['cw'] = $cwNew;
        $this->fdt['ctgdata'] = $ctg;

        $this->fdt['tableSubset']['hmtx'] = [
            'hMetrics' => $newHMetrics,
            'lsbOnly' => [],
        ];

        $numGlyphs = \count($glyphIdArray);

        // OutFont class checks subsetchars to limit the set of character widths embedded in the CIDFontType2 PDF object
        $idArray = \array_values($glyphIdArray);
        $this->fdt['subsetchars'] = \array_combine($idArray, $idArray);

        // The loca table requires an extra entry for the size of the final glyph ($offset[<glyph>+1] - $offset[<glyph>])
        $loca_vals[] = $offset;

        $this->createLocaTable($loca_vals);

        // Update component children ids with the new renumbered ids
        foreach ($this->compositeGlyphs as $gidOld) {
            if (!isset($gidOld['newOffset'])) {
                throw new FontException("Composite glyph's child is missing its new byte offset value.");
            }
            foreach ($gidOld['children'] as $childId => $offsets_childId) {
                foreach ($offsets_childId as $offset_replace) {
                    $glyf['data'] = \substr_replace(
                        $glyf['data'],
                        \pack('n', $subGlyphsAsTuples[$childId][1]),
                        $gidOld['newOffset'] + $offset_replace,
                        2,
                    );
                }
            }
        }

        // Create the GDEF table
        $this->createGDEFTable($GDEF_segments);

        // Update numGlyphs in maxp table
        $tbl['maxp']['data'] = \substr_replace($tbl['maxp']['data'], \pack('n', $numGlyphs), 4, 2);

        // Update numberOfHMetrics in hhea table
        $tbl['hhea']['data'] = \substr_replace($tbl['hhea']['data'], \pack('n', $newNumHMetrics), 34, 2);

        // Build new cmap table
        \ksort($ctg);
        /** @var list<non-empty-array<int,int>> $segments */
        $segments = [];
        foreach ($ctg as $chr => $gid) {
            if (!isset($chr_prev) || $chr - 1 !== $chr_prev || !isset($gid_prev) || $gid - 1 !== $gid_prev) {
                // character starts a new segment since there is a break in character ids
                unset($segment);
                $segment = [];
                $segments[] = &$segment;
            }
            $segment[$chr] = $gid;
            $chr_prev = $chr;
            $gid_prev = $gid;
        }
        $segCount = \count($segments) + 1;
        $searchRange = 1 << ((int) \floor(log($segCount, 2)) + 1);
        $rangeShift = $segCount * 2 - $searchRange;
        $idRangeOffset = \array_fill(0, $segCount, 0);

        $startCode = \array_map(function (array $segment): int {
            /** @var non-empty-array<int,int> $segment */
            return \array_key_first($segment);
        }, $segments);
        $endCode = \array_map(function (array $segment): int {
            /** @var non-empty-array<int,int> $segment */
            return \array_key_last($segment);
        }, $segments);
        $idDelta = \array_map(
            function (array $segment, int $startCode): int {
                /** @var non-empty-array<int,int> $segment */
                return $segment[\array_key_first($segment)] - $startCode;
            },
            $segments,
            $startCode,
        );
        $idDelta[] = 1;
        $startCode[] = 0xffff;
        $endCode[] = 0xffff;

        $length_cmap_subtable =
            // unit16 for fields: format, length, language, segCountX2, searchRange, entrySelector, rangeShift, reservedPad
            2 * 8 +
            // uint16[segCount] for fields: endCode, startCode, idRangeOffset
            2 * (3 * $segCount) +
            // int16[segCount] (not uint16) for field: idDelta
            2 * $segCount +
            // uint16[numGlyphs] for field: glpyphIdArray
            2 * $numGlyphs;
        $tbl['cmap']['data'] =
            // cmap table header (version=0, numTables=2)
            \pack('n', 0) .
            \pack('n', 2) .
            // Table1: Unicode 2.0 / Unicode BMP only (platformId=0, encodingId=3, subtableOffset=20 or 0x14)
            \pack('n', 0) .
            \pack('n', 3) .
            \pack('N', 20) .
            // Table2: Windows / Unicode BMP (platformId=3, encodingId=1, subtableOffset=20)
            \pack('n', 3) .
            \pack('n', 1) .
            \pack('N', 20) .
            // subtable format 4
            \pack('n', 4) .
            // length of subtable in bytes
            \pack('n', $length_cmap_subtable) .
            // language field
            \pack('n', 0) .
            // segCountX2 (segCount*2)
            \pack('n', $segCount * 2) .
            // searchRange
            \pack('n', $searchRange) .
            // entrySelector
            \pack('n', (int) \floor(log($segCount, 2))) .
            // rangeShift
            \pack('n', $rangeShift) .
            // endCode[segCount]
            \pack("n$segCount", ...$endCode) .
            // reservedPad
            \pack('n', 0) .
            // startCode[segCount]
            \pack("n$segCount", ...$startCode) .
            // idDelta[segCount]
            $this->pack_signed_int16_be($idDelta, $segCount) .
            // idRangeOffset[segCount]
            \pack("n$segCount", ...$idRangeOffset) .
            // glyphArray[]
            \pack("n$numGlyphs", ...\array_values($glyphIdArray));

        // Create the Glyph to Character Code CMap to correctly allow copying from generated PDF
        $this->createToUnicodeCMap($ctg);

        // Create the TTF post table of postscript glyph ids/names
        if (!empty($newGlyphNameIndex)) {
            $cntNewGlyphNameIndex = \count($newGlyphNameIndex);
            /** @var list<string> $newGlyphNames */
            $tbl['post']['data'] =
                \substr($tbl['post']['data'], 0, 32) .
                \pack('n', $cntNewGlyphNameIndex) .
                \pack("n$cntNewGlyphNameIndex", ...$newGlyphNameIndex) .
                implode(
                    '',
                    \array_map(function (string $strName): string {
                        $nameLength = \strlen($strName);
                        if ($nameLength > 63) {
                            throw new FontException("Postscript names cannot be longer than 63 characters: $strName");
                        }
                        return chr($nameLength) . $strName;
                    }, $newGlyphNames),
                );
        }

        if (!empty($tblSubset['name']['nameIds'])) {
            $nameRecords = '';
            $stringOffset = 0;
            $stringData = '';
            if (
                !isset(
                    $tblSubset['name']['platformId'],
                    $tblSubset['name']['encodingId'],
                    $tblSubset['name']['languageId'],
                )
            ) {
                throw new FontException('Font name table data found without platformId, encodingId, or languageId');
            }
            foreach ($tblSubset['name']['nameIds'] as $nameId => $text) {
                $text = \mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
                $length = \strlen($text);
                $nameRecords .=
                    // platformID
                    \pack('n', $tblSubset['name']['platformId']) .
                    // encodingID
                    \pack('n', $tblSubset['name']['encodingId']) .
                    // languageID
                    \pack('n', $tblSubset['name']['languageId']) .
                    // nameID
                    \pack('n', $nameId) .
                    // length
                    \pack('n', $length) .
                    // stringOffset
                    \pack('n', $stringOffset);
                $stringOffset += $length;
                $stringData .= $text;
            }

            $numNames = \count($tblSubset['name']['nameIds']);
            $tbl['name']['data'] =
                // version -- Naming table version (0 is simpler than 1)
                \pack('n', 0) .
                // count -- Number of names in table
                \pack('n', $numNames) .
                // storageOffset -- byte offset from start of table to storage data
                \pack('n', 6 + 12 * $numNames) .
                // NameRecord(s) created above
                $nameRecords .
                // Concatenated name string data
                $stringData;
        }
    }

    /**
     * Creates the packed loca table data and updates the indexToLocFormat field in the head table.
     *
     * The short format (uint16 instead of uint32) offsets can be used when the maximum glyf offset
     * is 131,070 bytes or smaller (maximum uint16 value times 2 = 2*0xffff).
     *
     * @param non-empty-list<int> $offsets
     *
     * @return void
     */
    protected function createLocaTable(array $offsets): void
    {
        // Use the short indexToLocFormat if the maximum offset is 131_070 (uint16 maximum) or smaller.
        // (0xffff * 2 is possible since the formula divides by 2).
        if ($offsets[\array_key_last($offsets)] <= 131_070) {
            $loca_format = 0;
            $loca_pack_type = 'n';
        } else {
            $loca_format = 1;
            $loca_pack_type = 'N';
        }

        $loca = &$this->fdt['table']['loca'];
        $loca['data'] = '';
        foreach ($offsets as $val) {
            $loca['data'] .= \pack($loca_pack_type, $loca_format ? $val : (int) \floor($val / 2));
        }

        // Update the indexToLocFormat field in the "head" table
        $this->fdt['table']['head']['data'] = \substr_replace(
            $this->fdt['table']['head']['data'],
            \pack('n', $loca_format),
            50,
            2,
        );
    }

    /**
     * Creates a minimal GDEF table.
     *
     * @param list<int> $GDEF_segments An array of glyph ids.
     *
     * @return void
     */
    protected function createGDEFTable(array $GDEF_segments): void
    {
        $this->fdt['table']['GDEF'] ??= TrueType::TEMPLATE_TABLE;

        // No GDEF segments to create a table with
        if (\count($GDEF_segments) == 0) {
            return;
        }

        // Sort numerically to allow creating contiguous segments
        \sort($GDEF_segments, SORT_NUMERIC);

        // Create the initial segment using the lowest glpyhId ($gid)
        $start = array_shift($GDEF_segments);
        $segment = [$start, $start, 1];
        $segments = [&$segment];
        foreach ($GDEF_segments as $gid) {
            if ($segment[1] + 1 === $gid) {
                // Use current segment since glyph ids are consecutive
                $segment[1]++;
            } else {
                unset($segment);
                $segment = [$gid, $gid, 1];
                $segments[] = &$segment;
            }
        }
        $this->fdt['table']['GDEF']['data'] =
            //// GDEF Header v1.0
            // majorVersion
            \pack('n', 1) .
            // minorVersion
            \pack('n', 0) .
            // glyphClassDefOffset
            \pack('n', 12) .
            // attachListOffset
            \pack('n', 0) .
            // ligCaretListOffset
            \pack('n', 0) .
            // markAttachClassDefOffset
            \pack('n', 0) .
            // ClassDefFormat2
            // table version 2
            \pack('n', 2) .
            // number of ClassRangeRecords (segments)
            \pack('n', \count($segments));
        foreach ($segments as $segment) {
            $this->fdt['table']['GDEF']['data'] .= \pack('n3', ...$segment);
        }
    }

    /**
     * Creates the PDF toUnicode CMap used to map glyphs to Unicode character codes.
     *
     * @param array<int,int> $charCodeToGlyphId
     *
     * @return void
     */
    protected function createToUnicodeCMap(array $charCodeToGlyphId): void
    {
        // Order array by glyphId
        \asort($charCodeToGlyphId, SORT_NUMERIC);

        $sections = [];
        $biggest = '0000';
        if (!empty($charCodeToGlyphId)) {
            $cnt = 0;
            $charMap = '';
            $lastCharCode = \array_key_last($charCodeToGlyphId);
            $biggest = str_pad(\dechex($charCodeToGlyphId[$lastCharCode]), 4, '0', STR_PAD_LEFT);
            foreach ($charCodeToGlyphId as $charCode => $glyphId) {
                $charMap .= vsprintf("<%s> <%s>\n", [
                    \str_pad(\dechex($glyphId), 4, '0', STR_PAD_LEFT),
                    \str_pad(dechex($charCode), 4, '0', STR_PAD_LEFT),
                ]);

                $cnt++;
                if ($cnt === 100 || $charCode === $lastCharCode) {
                    $sections[] = <<<BFCHAR
                    $cnt beginbfchar
                    {$charMap}endbfchar
                    BFCHAR;
                    $cnt = 0;
                    $charMap = '';
                }
            }
        }
        $sections = implode('', $sections);

        $this->fdt['toUnicodeCMap'] = <<<CMAP
        %!PS-Adobe-3.0 Resource-CMap
        %%DocumentNeededResources: procset (CIDInit)
        %%IncludeResource: procset (CIDInit)
        %%BeginResource: CMap (TcPDF-Subset-0)
        %%Title: (TcPDF-Subset-0 TcPDF Subset 0)
        %%Version: 1
        /CIDInit /ProcSet findresource begin
        12 dict begin
        begincmap
        /CIDSystemInfo << /Registry (TcPDF) /Ordering (Subset) /Supplement 0 >> def
        /CMapName /TcPDF-Subset def
        /CMapType 2 def
        /WMode 0 def
        1 begincodespacerange
        <0000> <$biggest>
        endcodespacerange
        $sections
        endcmap
        CMapName currentdict /CMap defineresource pop
        end
        end
        CMAP;
    }

    /**
     * Remove unused tables and set new length and checksum for tables
     *
     * @throws FontException
     */
    protected function removeUnusedTables(): void
    {
        // Remove the unwanted tables first to get a count of remaining tables in the subsetted font
        foreach (\array_keys($this->fdt['table']) as $tag) {
            if (!(isset(self::TABLENAMES[$tag]) || (self::$enableDebug && isset(self::DEBUG_TABLENAMES[$tag])))) {
                // remove the table
                unset($this->fdt['table'][$tag]);
            }
        }

        $offset_file = 12 + 16 * count($this->fdt['table']);

        // Tags of all the remaining tables after unsetting the unwanted tables
        $tags = \array_keys($this->fdt['table']);
        // Tables should be arranged alphabetically by tag per TTF spec
        \sort($tags, SORT_STRING);
        foreach ($tags as $tag) {
            $table = &$this->fdt['table'][$tag];

            // Use the original table data if replacement data is not in $table['data']
            if (empty($table['data'])) {
                $table['data'] = \substr($this->font, $table['offset'], $table['length']);
            }

            // Set the checkSumAdjustment to 0 in the "head" table before calculating its checksum
            if ($tag === 'head') {
                $table['data'] = \substr_replace($table['data'], "\0\0\0\0", 8, 4);
            }

            // Set non-padded data length, pad the data to a multiple of 4 bytes, and set table checksum and byte-offset
            $table['length'] = \strlen($table['data']);
            $this->padTableToFourBytes($table);
            $table['checkSum'] = static::getTableChecksum($table['data']);
            $table['offset'] = $offset_file;

            // Update the file offset to the end of this table data
            $offset_file += $table['length'] + $table['length_nul_padding'];
        }
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
     * build new subset font
     *
     * @throws FontException
     */
    protected function buildSubsetFont(): void
    {
        $numTables = \count($this->fdt['table']);

        // These three values are calculated using $numTables
        $entrySelector = (int) \floor(\log($numTables, 2));
        $searchRange = 1 << ($entrySelector + 4);
        $rangeShift = $numTables * 16 - $searchRange;

        // TTF TableDirectory
        $this->subfont =
            // sfnt version
            \pack('N', 0x1_0000) .
            // numTables
            \pack('n', $numTables) .
            // searchRange
            \pack('n', $searchRange) .
            // entrySelector
            \pack('n', $entrySelector) .
            // rangeShift
            \pack('n', $rangeShift);

        // TTF TableRecords
        foreach ($this->fdt['table'] as $tag => $table) {
            $this->subfont .=
                // tag
                $tag .
                // checkSum
                \pack('N', $table['checkSum']) .
                // offset
                \pack('N', $table['offset']) .
                // length
                \pack('N', $table['length']);
        }

        // TTF Tables follow the TableRecords
        foreach ($this->fdt['table'] as &$table) {
            $this->subfont .= $table['data'];
            $table['data'] = '';
        }

        $this->setChecksumAdjustment($this->subfont, $this->fdt['table']['head']['offset']);

        if (self::$saveSubsetFile) {
            file_put_contents(__DIR__ . '/../subset.ttf', $this->subfont);
        }
    }

    /**
     * Pads table data with null bytes to a multiple of 4 bytes per TTF spec
     *
     * @param TFontDataTableItem $table
     *
     * @return void
     */
    public function padTableToFourBytes(array &$table): void
    {
        /** @var TFontDataTableItem $table */
        $remainder = $table['length'] % 4;
        $table['length_nul_padding'] = $remainder === 0 ? 0 : 4 - $remainder;
        $table['data'] .= \str_repeat("\0", $table['length_nul_padding']);
    }

    /**
     * Returns the checksum of a TTF table.
     *
     * @param string $table  Table data (binary) used to compute a checksum
     *
     * @return int checksum
     *
     * @throws FontException
     */
    public static function getTableChecksum(string $table): int
    {
        $blocksize = 4096;

        $length = \strlen($table);

        // Checksum must be calculated from data padded to a multiple of 4 bytes
        $remainder = $length % 4;
        $length_nul_padding = $remainder === 0 ? 0 : 4 - $remainder;

        $table .= \str_repeat("\0", $length_nul_padding);
        $length += $length_nul_padding;

        $checksum = 0;
        for ($offset = 0; $offset < $length; $offset += $blocksize) {
            $block = \substr($table, $offset, $blocksize);
            $longs = \unpack('N' . strlen($block) / 4, $block);
            if ($longs === false) {
                throw new FontException("Error calculating $table table checksum");
            }
            /** @var non-empty-list<int> $longs */
            $checksum = ($checksum + \array_sum($longs)) & 0xffffffff;
        }

        return $checksum;
    }

    /**
     * Set the checksumAdjustment field in the TTF head table.
     *
     * @param string    $font
     * @param int       $head_table_offset
     *
     * @return void
     *
     * @throws FontException
     */
    protected function setChecksumAdjustment(string &$font, int $head_table_offset): void
    {
        // Set checkSumAdjustment on head table per TTF spec.
        // The 0xb1b0afba value is from the spec and the bitmask keeps only 32 bits.
        $checkSumAdjustment = (0xb1b0afba - static::getTableChecksum($font)) & 0xffffffff;
        $font = \substr_replace($font, \pack('N', $checkSumAdjustment), $head_table_offset + 8, 4);
    }

    /**
     *
     * @param list<int> $data     The array of ints to pack into int16 big-endian
     * @param int       $cnt      The number of ints to pack
     *
     * @return string
     */
    protected function pack_signed_int16_be(array $data, int $cnt): string
    {
        if (\count($data) !== $cnt) {
            throw new \ValueError("Expected an int16[$cnt] but got int16[" . \count($data) . ']');
        }
        $packed = '';
        foreach ($data as $value) {
            // Ensure the value is within the signed 16-bit range (-32768 to 32767)
            if ($value < -32768 || $value > 32767) {
                throw new \ValueError(
                    "Value out of signed 16-bit range: $value  | cnt: $cnt  | data: " . \json_encode($data),
                );
            }

            // For negative values, convert to their unsigned 16-bit representation
            if ($value < 0) {
                $value = 0xffff + $value + 1; // 65535 + value + 1, which is 65536 + value
            }

            // Pack the (potentially converted) value as an unsigned 16-bit big-endian short ('n')
            $packed .= pack('n', $value);
        }
        return $packed;
    }
}

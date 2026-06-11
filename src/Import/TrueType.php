<?php

declare(strict_types=1);

/**
 * TrueType.php
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

namespace Com\Tecnick\Pdf\Font\Import;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\Compression;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Font\Enum\GlyphType;
use Com\Tecnick\Pdf\Font\Enum\SubsetMode;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Subset;

/**
 * Com\Tecnick\Pdf\Font\Import\TrueType
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
 * @phpstan-import-type TFontDataEncTable from \Com\Tecnick\Pdf\Font\Load
 * @phpstan-import-type TFontDataTableItem from \Com\Tecnick\Pdf\Font\Load
 * @phpstan-import-type TFontDataTableSubset from \Com\Tecnick\Pdf\Font\Load
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class TrueType
{
    /**
     * File helper used to load font definition files.
     */
    protected ObjFile $fileHelper;

    /**
     * Minimum byte length of the OS/2 table needed to read through fsType.
     */
    private const OS2_MIN_LENGTH = 10;

    /**
     * Priority-ordered (platformID, encodingID) fallback pairs for cmap subtable selection.
     * Used when no subtable matching the caller-requested pair is found.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    private const CMAP_FALLBACK_PRIORITY = [
        [3, 10], // Windows UCS-4 (full Unicode, format 12)
        [3, 1], // Windows Unicode BMP
        [0, 6], // Unicode platform - full repertoire
        [0, 4], // Unicode platform - 2.0+, BMP + supplementary
        [0, 3], // Unicode platform - 2.0+, BMP only
        [0, 2], // Unicode platform - 1.1
        [0, 1], // Unicode platform - 1.1 (deprecated)
        [0, 0], // Unicode platform - 1.0 (deprecated)
        [1, 0], // Macintosh Roman (legacy)
    ];

    /**
     * Blank template for a table
     *
     * @var TFontDataTableItem TEMPLATE_TABLE
     */
    public const TEMPLATE_TABLE = [
        'checkSum' => 0,
        'offset' => 0,
        'length' => 0,
        'length_nul_padding' => 0,
        'data' => '',
    ];

    /**
     * Array mapping subset glyph ids to character codes from cmap table.
     *
     * @var array<int, int>
     */
    protected array $subglyphs = [];

    /**
     * SubsetMode indicates if this TrueType instance is used to subset rather than import a font.
     */
    private readonly SubsetMode $subsetMode;

    /**
     * Process TrueType font
     *
     * @param string            $font       Content (binary) of the input font file
     * @param TFontData         $fdt        Extracted font metrics
     * @param ObjFile           $fileHelper File helper for font loading.
     * @param Byte              $fbyte      Object used to read font bytes
     * @param array<int, int>   $subchars   Array mapping subset chars to the new subset glyph ids
     *
     * @throws FileException
     * @throws FontException
     */
    public function __construct(
        protected readonly string $font,
        protected array $fdt,
        ObjFile $fileHelper,
        protected readonly Byte $fbyte,
        protected array $subchars = [],
    ) {
        if (empty($fdt['subset']) || !\filter_var($fdt['subset'], FILTER_VALIDATE_BOOLEAN)) {
            $this->subsetMode = SubsetMode::OFF;
        } elseif (Subset::enableDebug()) {
            $this->subsetMode = SubsetMode::DEBUG;
        } else {
            $this->subsetMode = SubsetMode::ON;
        }

        $this->process();
    }

    /**
     * Process TrueType font
     *
     * @throws FileException
     * @throws FontException
     */
    protected function process(): void
    {
        $this->isValidType();
        $this->setFontFile();
        $this->getTables();
        $this->checkMagickNumber();
        $this->getBbox();
        $this->getIndexToLoc();
        $this->getEncodingTables();
        $this->getOS2Metrics();
        $this->getFontName();
        $this->getPostData();
        $this->getHheaData();
        $this->getMaxpData();
        $this->getCIDToGIDMap();
        $this->getHeights();
        $this->getWidths();
    }

    /**
     * Get all the extracted font metrics
     *
     * @return TFontData
     */
    public function getFontMetrics(): array
    {
        return $this->fdt;
    }

    /**
     * Get glyphs in the subset
     *
     * @return array<int, int>
     */
    public function getSubGlyphs(): array
    {
        return $this->subglyphs;
    }

    /**
     * Check if the font has a valid sfnt version header
     *
     * Valid TTF 1.0 files begin with 1.0 in Version16Dot16 format
     *
     * @throws FontException if the font is invalid
     */
    protected function isValidType(): void
    {
        if ($this->fbyte->getULong(0) != 0x0001_0000) {
            throw new FontException('sfnt version must be 0x00010000 for TrueType version 1.0.');
        }
    }

    /**
     * Copy or link the original font file
     *
     * @throws FileException
     */
    protected function setFontFile(): void
    {
        // The font has already been imported if 'MaxWidth' exists and should not be reimported.
        // The Subset class loads the original font and should bypass the import.
        if (!empty($this->fdt['desc']['MaxWidth'])) {
            // subsetting mode
            $this->fdt['Flags'] = $this->fdt['desc']['Flags'];
            return;
        }

        if ($this->fdt['type'] === 'cidfont0') {
            return;
        }

        if ($this->fdt['linked']) {
            // creates a symbolic link to the existing font
            \symlink($this->fdt['input_file'], $this->fdt['dir'] . $this->fdt['file_name']);
            return;
        }

        // store compressed font
        $this->fdt['file'] = $this->fdt['file_name'] . '.z';
        file_put_contents($this->fdt['dir'] . $this->fdt['file'], $this->fdt['file']);

        $file = new File();
        $fpt = $file->fopenLocal($this->fdt['dir'] . $this->fdt['file'], 'wb');
        \fwrite($fpt, Compression::compress($this->font));
        \fclose($fpt);
    }

    /**
     * Get the font tables
     *
     * TableDirectory:
     *   0 - uint32                    sfntVersion    Either 0x00010000 (For TTF font) or 0x4F54544F (which spells OTTO)
     *   4 - uint16                    numTables      Number of tables in font file
     *   6 - uint16                    searchRange    pow(2, floor(log2(numTables))) * 16 OR 1 << (entrySelector+4)
     *   8 - uint16                    entrySelector  floor(log2(numTables))
     *  10 - uint16                    rangeShift     numTables * 16 - searchRange
     *  12 - TableRecord[numTables]    tableRecords
     *
     * TableRecord (16 bytes):
     *   0 - uint8[4]                  tag            4 ascii characters (range from 0x20 tp 0x7E) right padded with 0x20 (space) if len < 4
     *   4 - uint32                    checksum       The checksum for this table
     *   8 - Offset32                  offset         The table offset in bytes from the beginning of the font file
     *  12 - uint32                    length         The size of a table in bytes (excluding padding bytes)
     */
    protected function getTables(): void
    {
        $numTables = $this->fbyte->getUShort(4);

        // Skip unused searchRange, entrySelector and rangeShift fields

        // TableRecords begin at byte-offset 12
        $offset_loop = 12;
        // Get TableRecords
        $this->fdt['table'] = [];
        for ($i = 0; $i < $numTables; $i++) {
            // table tag (name of table)
            $tag = \substr($this->font, $offset_loop, 4);

            $checksum = $this->fbyte->getULong($offset_loop + 4);
            $offset = $this->fbyte->getULong($offset_loop + 8);
            $length = $this->fbyte->getULong($offset_loop + 12);
            $this->fdt['table'][$tag] = [
                'checkSum' => $checksum,
                'offset' => $offset,
                'data' => '',
                'length' => $length,
                'length_nul_padding' => 0,
            ];

            // Increment offset by size of each loop item in bytes
            $offset_loop += 16;
        }
    }

    /**
     * Verify the font file has the mandatory magic number value in its "head" table
     *
     * Valid TTF 1.0 files have the magic number 0x5f0f3cf5 in the "head" table at byte offset 12.
     *
     * @throws FontException if the font is invalid
     */
    protected function checkMagickNumber(): void
    {
        // The file offset of the magic number is the "head" table offset + 12 bytes
        if ($this->fbyte->getULong($this->fdt['table']['head']['offset'] + 12) != 0x5f0f3cf5) {
            throw new FontException('magicNumber must be 0x5f0f3cf5');
        }
    }

    /**
     * Parse Font Header Table (head) for BBox, units and flags
     *
     *  0 - uint16             majorVersion        Major version of font header table (always 1)
     *  2 - uint16             minorVersion        Major version of font header table (always 0)
     *  6 - Fixed (32-bit)     fontRevision        Set by font manufacturer (Fixed = 4 bytes)
     * 10 - uint32             checksumAdjustment
     * 14 - uint32             magicNumber         Always 0x5f0f3cf5
     * 16 - uint16             flags               @Link https://learn.microsoft.com/en-us/typography/opentype/spec/head
     * 18 - uint16             unitsPerEm          Any value from 16 to 16384 (a power of 2 is recommended)
     * 26 - LONGDATETIME       created             64-bit number of seconds since 12:00 midnight 1904/01/01 in GMT/UTC time zone.
     * 34 - LONGDATETIME       modified            64-bit number of seconds since 12:00 midnight 1904/01/01 in GMT/UTC time zone.
     * 36 - int16              xMin                Minimum x coordinate across all glyph bounding boxes.
     * 38 - int16              yMin                Minimum y coordinate across all glyph bounding boxes.
     * 40 - int16              xMax                Maximum x coordinate across all glyph bounding boxes.
     * 42 - int16              yMax                Maximum y coordinate across all glyph bounding boxes.
     * 44 - uint16             macStyle            bits (0:Bold, 1:Italic, 2:Underline, 3:Outline, 4:Shadow, 5:Condensed, 6:Extended, 7-15:Reserved [always 0])
     * 46 - uint16             lowestRecPPEM       Smallest readable size in pixels.
     * 48 - int16              fontDirectionHint   Deprecated -- Set to 2
     * 50 - int16              indexToLocFormat    0 for short offsets (Offset16), 1 for long (Offset32).
     * 52 - int16              glyphDataFormat     0 for current format.
     *
     *  @return void
     */
    protected function getBbox(): void
    {
        $offset_file = $this->fdt['table']['head']['offset'];

        $this->fdt['unitsPerEm'] = $this->fbyte->getUShort($offset_file + 18);
        // units ratio constant
        $this->fdt['urk'] = 1000 / $this->fdt['unitsPerEm'];
        // skip field: created (LONGDATETIME int64)
        // skip field: modified (LONGDATETIME int64)
        $xMin = (int) \round($this->fbyte->getFWord($offset_file + 36) * $this->fdt['urk']);
        $yMin = (int) \round($this->fbyte->getFWord($offset_file + 38) * $this->fdt['urk']);
        $xMax = (int) \round($this->fbyte->getFWord($offset_file + 40) * $this->fdt['urk']);
        $yMax = (int) \round($this->fbyte->getFWord($offset_file + 42) * $this->fdt['urk']);
        $this->fdt['bbox'] = "$xMin $yMin $xMax $yMax";

        // PDF font flags
        $macStyle = $this->fbyte->getUShort($offset_file + 44);
        if (($macStyle & 2) == 2) {
            // italic flag
            $this->fdt['Flags'] |= 64;
        }

        // indexToLocFormat
        $this->fdt['short_offset'] = 0 === $this->fbyte->getShort($offset_file + 50);
    }

    /**
     * Map glyph indexes to their corresponding byte-offset in the glyf table data
     *
     * The loca table is an array of values mapping each glyph id to the glyph's symbol in the TTF glyf table.
     * These offsets will be stored using uint16-be values if the indexToLocFormat flag in the header table is 0 and
     * uint32-be values otherwise.
     */
    protected function getIndexToLoc(): void
    {
        // indexToLocFormat flag in the head table (indexToLocFormat : 0 = short, 1 = long)
        $this->fdt['short_offset'] = 0 === $this->fbyte->getShort($this->fdt['table']['head']['offset'] + 50);

        $locaData = \substr($this->font, $this->fdt['table']['loca']['offset'], $this->fdt['table']['loca']['length']);

        if ($this->fdt['short_offset']) {
            $numGlyphs = (int) \floor($this->fdt['table']['loca']['length'] / 2);
            $unpack_format = 'n' . $numGlyphs;
        } else {
            $numGlyphs = (int) \floor($this->fdt['table']['loca']['length'] / 4);
            $unpack_format = 'N' . $numGlyphs;
        }
        // This includes the one additional offset used to calculate the byte size of the last glyph
        $this->fdt['tot_num_glyphs'] = $numGlyphs;

        // unpack produces an array using one-based indexing. Use array_values to change it to zero-based indexing.
        $indexToLoc = \unpack($unpack_format, $locaData);

        if ($indexToLoc === false) {
            throw new FontException('Unable to unpack TTF loca table data.');
        }
        /** @var array<int, int> $indexToLoc */
        $this->fdt['indexToLoc'] = \array_values($indexToLoc);

        // The stored offset values must be multiplied by 2 when stored in the short format (Offset16)
        if ($this->fdt['short_offset']) {
            foreach ($this->fdt['indexToLoc'] as &$value) {
                $value *= 2;
            }
        }

        // Subtract the one additional offset to match the number of glyphs and not glyphs + 1
        $this->fdt['tot_num_glyphs']--;
    }

    /**
     * Map character encoding ids to the index of the matching glyph (TTF cmap table)
     *
     * cmap table header:
     *  0 - uint16   version            Table version number (Always 0)
     *  2 - uint16   numTables          Number of encoding tables
     *
     * EncodingRecord (8 bytes):
     *  0 - uint16   platformId         Platform ID
     *  2 - uint16   encodingId         Platform-specific encoding ID
     *  4 - Offset32 subtableOffset     Byte offset from beginning of cmap table to the encoding subtable
     *
     * @return void
     */
    protected function getEncodingTables(): void
    {
        $offset_file = $this->fdt['table']['cmap']['offset'];

        // Skip the version field in cmap header since it is always 0

        // Number of EncodingRecords
        $numEncodingTables = $this->fbyte->getUShort($offset_file + 2);

        // EncodingRecords begin at byte-offset 4
        $offset_loop = $offset_file + 4;
        // Get cmap EncodingRecords
        $encTables = [];
        for ($i = 0; $i < $numEncodingTables; $i++) {
            $encTables[] = [
                'platformId' => $this->fbyte->getUShort($offset_loop),
                'encodingId' => $this->fbyte->getUShort($offset_loop + 2),
                'offset' => $this->fbyte->getULong($offset_loop + 4),
            ];

            // Increment offset by size of each loop item in bytes
            $offset_loop += 8;
        }
        $this->fdt['encodingTables'] = $encTables;
    }

    /**
     * Get OS/2 and Windows Metrics Table (TTF OS/2 table)
     *
     * The OS/2 table consists of a set of metrics and other data that are required in OpenType fonts
     *
     * Six versions of the OS/2 table have been defined: versions 0 to 5. All versions are supported,
     * but use of version 4 or later is strongly recommended.
     * @link https://learn.microsoft.com/en-us/typography/opentype/spec/os2
     *
     * OS/2 Table Version 0 (FWORD is an int16 in font design units):
     *   0 - uint16       version         OS/2 table version (0-5)
     *   2 - FWORD        xAvgCharWidth
     *   4 - uint16       usWeightClass
     *   6 - uint16       usWidthClass
     *   8 - uint16       fsType
     *  10 - FWORD        ySubscriptXSize
     *  12 - FWORD        ySubscriptYSize
     *  14 - FWORD        ySubscriptXOffset
     *  16 - FWORD        ySubscriptYOffset
     *  18 - FWORD        ySuperscriptXSize
     *  20 - FWORD        ySuperscriptYSize
     *  22 - FWORD        ySuperscriptXOffset
     *  24 - FWORD        ySuperscriptYOffset
     *  26 - FWORD        yStrikeoutSize
     *  28 - FWORD        yStrikeoutPosition
     *  30 - int16        sFamilyClass
     *  32 - uint8[10]    panose              (@Link https://learn.microsoft.com/en-us/typography/opentype/spec/os2#pan)
     *  34 - uint32       ulUnicodeRange1     Unicode Character Range 1
     *  38 - uint32       ulUnicodeRange2     Unicode Character Range 2
     *  42 - uint32       ulUnicodeRange3     Unicode Character Range 3
     *  46 - uint32       ulUnicodeRange4     Unicode Character Range 4
     *  50 - uint8[4]     tag                 4 * ascii (range from 0x20 to 0x7E) right padded with 0x20 (space) if len < 4
     *  54 - uint16       fsSelection
     *  56 - uint16       usFirstCharIndex
     *  58 - uint16       usLastCharIndex
     *  60 - FWORD        sTypoAscender
     *  62 - FWORD        sTypoDescender
     *  64 - FWORD        sTypoLineGap
     *  66 - UFWORD       usWinAscent
     *  68 - UFWORD       usWinDescent
     *
     * @return void
     *
     * @throws FontException
     */
    protected function getOS2Metrics(): void
    {
        $offset_file = $this->fdt['table']['OS/2']['offset'];

        // skip os2 table version since none of the extra fields in versions > 0 are used

        // xAvgCharWidth
        $this->fdt['AvgWidth'] = (int) \round($this->fbyte->getFWord($offset_file + 2) * $this->fdt['urk']);

        // usWeightClass
        $usWeightClass = \round($this->fbyte->getUShort($offset_file + 4) * $this->fdt['urk']);
        // estimate StemV and StemH (400 = usWeightClass for Normal - Regular font)
        $this->fdt['StemV'] = (int) \round((70 * $usWeightClass) / 400);
        $this->fdt['StemH'] = (int) \round((30 * $usWeightClass) / 400);

        // fsType
        $fsType = $this->fbyte->getUShort($offset_file + 8);
        $this->applyEmbeddingPolicy($fsType);
    }

    /**
     * Apply OS/2 fsType embedding-restrictions policy.
     *
     * fsType bits (OpenType spec §OS/2.fsType):
     *   bit 1  (0x0002) = Restricted License embedding - cannot embed in any PDF.
     *   bit 2  (0x0004) = Preview & Print embedding    - allowed.
     *   bit 3  (0x0008) = Editable embedding           - allowed.
     *   bit 8  (0x0100) = No Subsetting                - embedding allowed, subsetting must be off.
     *   bit 9  (0x0200) = Bitmap Embedding Only        - vector PDF embedding not permitted.
     *
     * When only the Restricted-License bit is set among bits 1-3 the font cannot be embedded.
     * A permissive bit (0x0004 or 0x0008) alongside 0x0002 takes precedence (spec §5.8.1).
     *
     * @throws FontException if the font's license does not permit embedding.
     */
    protected function applyEmbeddingPolicy(int $fsType): void
    {
        // Restricted-license: bit 1 set, no permissive override from bits 2 or 3.
        if (($fsType & 0x000E) === 0x0002) {
            throw new FontException(
                'This Font cannot be modified, embedded or exchanged in any manner'
                . ' without first obtaining permission of the legal owner.',
            );
        }

        // Bitmap-embedding-only: incompatible with vector PDF stream embedding.
        if (($fsType & 0x0200) !== 0) {
            throw new FontException('This font is licensed for bitmap embedding only'
                . ' and cannot be embedded in a vector PDF document.');
        }

        // No-subsetting: embedding is allowed but the font must not be subsetted.
        if (($fsType & 0x0100) !== 0) {
            $this->fdt['subset'] = false;
        }
    }


    /**
     * Get the font name (TTF name table)
     *
     * NameTable Version 0:
     *  0 - uint16            version            Table version number (0; would be 1 for Version 1)
     *  2 - uint16            count              Number of name records
     *  4 - Offset16          storageOffset      Offset to start of string storage (from start of name table)
     *  6 - NameRecord[count] nameRecords        The NameRecords
     *
     * NameRecord (12 bytes):
     *  0 - uint16   platformId         Platform ID
     *  2 - uint16   encodingId         Platform-specific encoding ID
     *  4 - uint16   languageId         Language ID
     *  6 - uint16   nameId             Name ID (See list below in function body)
     *  8 - uint16   length             String length (in bytes)
     * 10 - Offset16 stringOffset       String offset from start of storage area (in bytes)
     *
     *  List of standard Name IDs:
     *   -  0: Copyright notice
     *   -  1: Font Family Name
     *   -  2: Font Subfamily Name
     *   -  3: Unique font identifier
     *   -  4: Full font name reflecting all family and relevant subfamily descriptors
     *   -  5: Version string beginning with "Version <number>.<number>" case-insensitive
     *   -  6: Postscript name for the font.
     *   -  7: Trademark
     *   -  8: Manufacturer Name
     *   -  9: Designer Name
     *   - 10: Description (can be very long and will be dropped in subsetting)
     *   - 11: URL of Vendor
     *   - 12: URL of Designer
     *   - 13: License Description (can be very long and will be dropped in subsetting)
     *   - 14: License Info URL
     *   ...
     *   - 25: Variations PostScript Name Prefix
     *
     * @return void
     *
     * @throws FontException
     */
    protected function getFontName(): void
    {
        $table = &$this->fdt['table']['name'];
        $tableSubset = &$this->fdt['tableSubset']['name'];

        // The sanitized font name
        $this->fdt['name'] = '';

        // skip name table version since none of the V=1 fields are used

        // count -- Number of NameRecords
        $numNameRecords = $this->fbyte->getUShort($table['offset'] + 2);

        // Offset to start of string storage (from start of table).
        $storageOffset = $this->fbyte->getUShort($table['offset'] + 4);

        // NameRecords begin at byte-offset 6
        $offset_loop = $table['offset'] + 6;
        for ($idx = 0; $idx < $numNameRecords; $idx++) {
            $platformId = $this->fbyte->getUShort($offset_loop);
            $encodingId = $this->fbyte->getUShort($offset_loop + 2);
            $languageId = $this->fbyte->getUShort($offset_loop + 4);

            // UTF-16BE Unicode or Windows Unicode platforms
            if (\in_array([$platformId, $encodingId], [[0, 3], [3, 1]], true)) {
                $tableSubset['platformId'] = $platformId;
                $tableSubset['encodingId'] = $encodingId;
                $tableSubset['languageId'] = $languageId;
            }

            $nameID = $this->fbyte->getUShort($offset_loop + 6);
            $fontNameOnly = $nameID == 6 && !$this->subsetMode->debug();
            if (
                !isset($tableSubset['nameIds'][$nameID]) &&
                ($fontNameOnly || \in_array($nameID, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, /*10,*/ 11, 12, /*13,*/ 14]))
            ) {
                // String length (in bytes)
                $stringLength = $this->fbyte->getUShort($offset_loop + 8);
                // String offset from start of storage area (in bytes)
                $stringOffset = $this->fbyte->getUShort($offset_loop + 10);
                // The encoded name string
                $name = \substr($this->font, $table['offset'] + $storageOffset + $stringOffset, $stringLength);
                // The decoded name string
                $name = $this->decodeNameString($name, $platformId, $encodingId);

                // Add the name to be included in font subsetting.
                $tableSubset['nameIds'][$nameID] = $name;

                if ($fontNameOnly) {
                    break;
                }
            }

            // Increment offset by size of each loop item in bytes
            $offset_loop += 12;
        }

        // Throw an exception if no font name was found
        if (empty($tableSubset['nameIds'][6])) {
            throw new FontException('Error getting font name.');
        }

        // Save sanitized font name.
        $this->fdt['name'] =
            \preg_replace('/[^a-zA-Z0-9_\-]/', '', $tableSubset['nameIds'][6]) ?? $tableSubset['nameIds'][6];
    }

    /**
     * Converts a name string to UTF-8 if the iconv or mbstring PHP extensions are available.
     *
     * @param string    $name          The encoded name, usually in UTF-16BE encoding.
     * @param int       $platformId    The platformId from the encoding table.
     * @param int       $encodingId    The encodingId from the encoding table.
     *
     * @return string
     */
    protected function decodeNameString(string $name, int $platformId, int $encodingId): string
    {
        // Convert name string to UTF-8 if iconv or mbstring is available
        if ($platformId == 1) {
            // Legacy Macintosh platform uses 'MacRoman' encoding which is not available in PHP mbstring.
            // Convert with iconv (macintosh = MacRoman) if available or mb_convert_encoding using
            // Windows-1252 (closest substitute for MacRoman) if available.
            if (\function_exists('\iconv')) {
                $newName = \iconv('macintosh', 'UTF-8', $name) ?: null;
            }
            return $newName ?? (\mb_convert_encoding($name, 'UTF-8', 'Windows-1252') ?: $name);
        }

        // All Unicode (platformId=0) strings are UTF-16BE
        $stringEncoding = 'UTF-16BE';

        // Windows platform (platformId=3) uses specific string encodings for encodingIds 3, 4, and 5
        if ($platformId == 3) {
            $stringEncoding = match ($encodingId) {
                3 => 'CP936',
                4 => 'CP950',
                5 => 'CP949',
                default => 'UTF-16BE',
            };
        }
        return \mb_convert_encoding($name, 'UTF-8', $stringEncoding) ?: $name;
    }

    /**
     * Get the PostScript Table (TTF post table)
     *  0             - Version16Dot16     version             1.0=0x00010000, 2.0=0x00020000, (deprecated) 2.5=0x00025000, 3.0=0x00030000
     *  4             - fixed              italicAngle         Italic angle in counter-clockwise degrees from the vertical.
     *                                                         Zero for upright text, negative for text that leans to the right
     *  8             - FWORD              underlinePosition   Suggested y-coordinate of the top of the underline
     * 10             - FWORD              underlineThickness  Suggested values for the underline thickness
     * 12             - uint32             isFixedPitch        0 if the font is proportionally spaced, non-zero if monospaced
     * 16             - uint32             minMemType42        Minimum memory usage when an OpenType font is downloaded
     * 20             - uint32             maxMemType42        Maximum memory usage when an OpenType font is downloaded
     * 24             - uint32             minMemType1         Minimum memory usage when OpenType font is downloaded as a Type 1 font
     * 28             - uint32             maxMemType1         Maximum memory usage when OpenType font is downloaded as a Type 1 font
     *
     * Version 2.0 (continuation of Postscript Table Header):
     * 32             - uint16             numGlyphs           Number of glyphs (this should be the same as numGlyphs in 'maxp' table)
     * 34             - uint16[numGlyphs]  glyphNameIndex      Array of indices into the string data.  For id values 0-257, use the
     *                                                         standard Macintosh glyph name for that id.  For values > 257, use
     *                                                         (258-<id value>) as the index into stringData.
     * 34+2*numGlyphs - uint8[]            stringData          Storage for the string data.  Data is stored in Pascal string format,
     *                                                         meaning that the first byte of a given string is a length: the number
     *                                                         of characters in that string. The length byte is not included; for
     *                                                         example, a length byte of 8 indicates that the 8 bytes following the
     *                                                         length byte comprise the string character data.  Valid characters are
     *                                                         in the set [A-Za-z0-9._]
     * @return void
     *
     * @throws FontException
     */
    protected function getPostData(): void
    {
        $offset_file = $this->fdt['table']['post']['offset'];

        $versionMajor = $this->fbyte->getUShort($offset_file);
        $versionMinor = $this->fbyte->getUShort($offset_file + 2);

        $this->fdt['italicAngle'] = $this->fbyte->getFixed($offset_file + 4);
        $this->fdt['underlinePosition'] = (int) \round($this->fbyte->getFWord($offset_file + 8) * $this->fdt['urk']);
        $this->fdt['underlineThickness'] = (int) \round($this->fbyte->getFWord($offset_file + 10) * $this->fdt['urk']);
        $isFixedPitch = $this->fbyte->getULong($offset_file + 12) !== 0;
        if ($isFixedPitch) {
            $this->fdt['Flags'] |= 1;
        }

        // Return early if this is not a debug font subset.
        if (!$this->subsetMode->debug()) {
            return;
        }

        if ($versionMajor === 2 && $versionMinor === 0) {
            $offset_subtable_v2 = $offset_file + 32;

            $numGlyphs = $this->fbyte->getUShort($offset_subtable_v2);

            $offset_stringData = $offset_subtable_v2 + 2 + 2 * $numGlyphs;
            $glyphNames = [];
            if (
                // Postscript names are Pascal formatted strings meaning they start with a length byte (1-63 characters)
                // followed by the string with a length matching the length byte.  These postscript names are limited
                // to [A-Za-z0-9_.] in preg character class format.
                \preg_match_all(
                    '/[\x01-\x63]([A-Za-z0-9_.]+)/',
                    \substr(
                        $this->font,
                        $offset_stringData,
                        $this->fdt['table']['post']['length'] - (34 + 2 * $numGlyphs),
                    ),
                    $glyphNames,
                )
            ) {
                $glyphNames = $glyphNames[1];
            }

            // Add the standard postscript character ids (id < 258) or entity names (id >= 258)
            $glyphNameIndex = \unpack("n$numGlyphs", \substr($this->font, $offset_subtable_v2 + 2, 2 * $numGlyphs));
            if ($glyphNameIndex === false) {
                throw new FontException('Error unpacking glyph name index data.');
            }
            // array_values resets the keys to start with 0 instead of 1
            $glyphNameIndex = \array_values($glyphNameIndex);
            /** @var list<int|string> $glyphNameIndex */
            for ($i = 0; $i < \count($glyphNameIndex); $i++) {
                if ($glyphNameIndex[$i] >= 258) {
                    $glyphNameIndex[$i] = $glyphNames[((int) $glyphNameIndex[$i]) - 258];
                }
            }
            $this->fdt['postscriptGlyphNames'] = $glyphNameIndex;
        }
    }

    /**
     * Get the Horizontal Header Table (TTF hhea table)
     *  0 - uint16      majorVersion                     hhea Major version (Always 1)
     *  2 - uint16      minorVersion                     hhea Minor version (Always 0)
     *  4 - FWORD       ascender
     *  6 - FWORD       descender
     *  8 - FWORD       lineGap
     * 10 - UFWORD      advanceWidthMax
     * 12 - FWORD       minLeftSideBearing
     * 14 - FWORD       minRightSideBearing
     * 16 - FWORD       xMaxExtent
     * 18 - int16       caretSlopeRise
     * 20 - int16       caretSlopeRun
     * 22 - int16       caretOffset
     * 24 - int16       reserved (set to 0)
     * 26 - int16       reserved (set to 0)
     * 28 - int16       reserved (set to 0)
     * 30 - int16       reserved (set to 0)
     * 32 - int16       metricDataFormat (set to 0)
     * 34 - uint16      numberOfHMetrics (in hmtx table)
     *
     * @return void
     */
    protected function getHheaData(): void
    {
        $offset_file = $this->fdt['table']['hhea']['offset'];

        // skip majorVersion and minorVersion fields (They are always 1 and 0)

        // Ascender
        $this->fdt['Ascent'] = (int) \round($this->fbyte->getFWord($offset_file + 4) * $this->fdt['urk']);
        // Descender
        $this->fdt['Descent'] = (int) \round($this->fbyte->getFWord($offset_file + 6) * $this->fdt['urk']);
        // LineGap
        $this->fdt['Leading'] = (int) \round($this->fbyte->getFWord($offset_file + 8) * $this->fdt['urk']);
        // advanceWidthMax
        $this->fdt['MaxWidth'] = (int) \round($this->fbyte->getUFWord($offset_file + 10) * $this->fdt['urk']);

        // skip several fields...

        // Number of hMetric entries in hmtx table
        $this->fdt['numHMetrics'] = $this->fbyte->getUShort($offset_file + 34);
    }

    /**
     * Get the Maximum Profile Table (TTF maxp table)
     *  0 - Version16Dot16  version          Table version number (0.5=0x00005000, 1.0=0x00010000)
     *  4 - uint16          numGlyphs        The number of glyphs in the font
     *
     * ... There are additional fields in Table Version 1.0...
     *
     * @return void
     */
    protected function getMaxpData(): void
    {
        $offset_file = $this->fdt['table']['maxp']['offset'];

        // get the number of glyphs in the font.
        $this->fdt['numGlyphs'] = $this->fbyte->getUShort($offset_file + 4);
    }

    /**
     * Get font heights
     *
     * @return void
     */
    protected function getHeights(): void
    {
        // get xHeight (Height of Latin Small Letter 'x')
        $this->fdt['XHeight'] = $this->fdt['Ascent'] + $this->fdt['Descent'];
        if (!empty($this->fdt['ctgdata'][120])) {
            $offset_file =
                $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$this->fdt['ctgdata'][120]] + 4;
            $yMin = $this->fbyte->getFWord($offset_file);
            $yMax = $this->fbyte->getFWord($offset_file + 4);
            $this->fdt['XHeight'] = (int) \round(($yMax - $yMin) * $this->fdt['urk']);
        }

        // get CapHeight (Height of Latin Capital Letter 'H')
        $this->fdt['CapHeight'] = (int) $this->fdt['Ascent'];
        if (!empty($this->fdt['ctgdata'][72])) {
            $offset_file =
                $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$this->fdt['ctgdata'][72]] + 4;
            $yMin = $this->fbyte->getFWord($offset_file);
            $yMax = $this->fbyte->getFWord($offset_file + 4);
            $this->fdt['CapHeight'] = (int) \round(($yMax - $yMin) * $this->fdt['urk']);
        }
    }

    /**
     * Get font widths
     *
     * @return void
     */
    protected function getWidths(): void
    {
        $offset_file = $this->fdt['table']['hmtx']['offset'];

        // Skip getting widths for subsetting mode. The Subset class will reuse the data from the import process
        // instead of calculating it all again.
        if ($this->subsetMode !== SubsetMode::OFF) {
            return;
        }

        // Create character widths array when importing a font (SubsetMode::OFF)
        $chw = [];
        for ($i = 0; $i < $this->fdt['numHMetrics']; $i++) {
            // hMetrics are needed for subsetting
            $this->fdt['tableSubset']['hmtx']['hMetrics'][$i] = [
                // advanceWidth
                (($this->fbyte->bytes[$offset_file] << 8) & 0xff00) | ($this->fbyte->bytes[$offset_file + 1] & 0xff),
                // lsb
                (int) \round(
                    (((($this->fbyte->bytes[$offset_file + 2] << 8) & 0xff00) |
                        ($this->fbyte->bytes[$offset_file + 3] & 0xff)) ^
                        0x8000) -
                        0x8000,
                ),
            ];
            // Character width is advanceWidth * urk
            $chw[$i] = (int) \round($this->fdt['tableSubset']['hmtx']['hMetrics'][$i][0] * $this->fdt['urk']);

            // Increment offset by size of each loop item in bytes
            $offset_file += 4;
        }

        $lastIndex = $this->fdt['numHMetrics'] - 1;
        // fill missing widths with the last value
        $chw = \array_pad($chw, $this->fdt['numGlyphs'], $chw[$lastIndex]);

        $numRemain = $this->fdt['numGlyphs'] - $this->fdt['numHMetrics'];
        if ($numRemain > 0) {
            $data = \unpack("n$numRemain", \substr($this->font, $offset_file, 2 * $numRemain));
            if ($data !== false) {
                // array_values resets the keys to start with 0 instead of 1
                $data = \array_values($data);
                // Tell phpstan that $data is an int[] after unpacking... not sure why it does not infer this already
                /** @var list<int> $data */
                $this->fdt['tableSubset']['hmtx']['lsbOnly'] = $data;
            }
        }

        $this->fdt['MissingWidth'] = $chw[0] ?? 0;
        $this->fdt['cw'] = [];
        $this->fdt['cbbox'] = [];
        for ($cid = 0; $cid <= 65535; $cid++) {
            if (!isset($this->fdt['ctgdata'][$cid])) {
                continue;
            }

            if (isset($chw[$this->fdt['ctgdata'][$cid]])) {
                $this->fdt['cw'][$cid] = $chw[$this->fdt['ctgdata'][$cid]];
            }

            if (isset($this->fdt['indexToLoc'][$this->fdt['ctgdata'][$cid]])) {
                $offset_file =
                    $this->fdt['table']['glyf']['offset'] + $this->fdt['indexToLoc'][$this->fdt['ctgdata'][$cid]];

                // The int16 conversion is inlined here to optimize the loop
                $this->fdt['cbbox'][$cid] = [
                    (int) ($this->fdt['urk'] *
                        ((((($this->fbyte->bytes[$offset_file + 2] << 8) & 0xff00) |
                            ($this->fbyte->bytes[$offset_file + 3] & 0xff)) ^
                            0x8000) -
                            0x8000)),
                    (int) ($this->fdt['urk'] *
                        ((((($this->fbyte->bytes[$offset_file + 4] << 8) & 0xff00) |
                            ($this->fbyte->bytes[$offset_file + 5] & 0xff)) ^
                            0x8000) -
                            0x8000)),
                    (int) ($this->fdt['urk'] *
                        ((((($this->fbyte->bytes[$offset_file + 6] << 8) & 0xff00) |
                            ($this->fbyte->bytes[$offset_file + 7] & 0xff)) ^
                            0x8000) -
                            0x8000)),
                    (int) ($this->fdt['urk'] *
                        ((((($this->fbyte->bytes[$offset_file + 8] << 8) & 0xff00) |
                            ($this->fbyte->bytes[$offset_file + 9] & 0xff)) ^
                            0x8000) -
                            0x8000)),
                ];
            }
        }
    }

    /**
     * Add CTG entry to map CID to GID
     *
     * @param int  $cid Character Identifier
     * @param int  $gid Glyph ID (zero-based index of the glyph in the font's glyph collection)
     *
     * @return void
     */
    protected function addCtgItem(int $cid, int $gid): void
    {
        $this->fdt['ctgdata'][$cid] = $gid;
        if (isset($this->subchars[$cid])) {
            $this->subglyphs[$gid] = $cid;
        }
    }

    /**
     * Find the first encoding table record matching the given platformID and encodingID.
     *
     * @return array{platformID: int, encodingID: int, offset: int}|null
     */
    private function findTableEntry(int $platformID, int $encodingID): ?array
    {
        foreach ($this->fdt['encodingTables'] as $enctable) {
            if ($enctable['platformID'] === $platformID && $enctable['encodingID'] === $encodingID) {
                return $enctable;
            }
        }

        return null;
    }

    /**
     * Select the best available cmap encoding subtable.
     *
     * Selection priority:
     *   1. Exact match for the caller-requested (platform_id, encoding_id) pair.
     *   2. Fallback pairs in CMAP_FALLBACK_PRIORITY order.
     *
     * @return array{platformID: int, encodingID: int, offset: int}|null
     *         The chosen encoding-table record, or null when no usable subtable exists.
     */
    protected function selectEncodingTable(): ?array
    {
        // 1. Exact match
        $match = $this->findTableEntry($this->fdt['platform_id'], $this->fdt['encoding_id']);
        if ($match !== null) {
            return $match;
        }

        // 2. Priority fallbacks
        foreach (self::CMAP_FALLBACK_PRIORITY as [$pid, $eid]) {
            if ($pid === $this->fdt['platform_id'] && $eid === $this->fdt['encoding_id']) {
                continue; // already tried as exact match above
            }

            $match = $this->findTableEntry($pid, $eid);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Process the font's cmap encoding table
     *
     * A Character Identifier (CID) is an integer matching the character code from a particular encoding.
     * @link https://www.php.net/mb_ord
     *
     * The Glyph ID (GID) is the zero-based index of the glyph in the font's glyph collection.
     *
     * @return void
     *
     * @throws FontException
     */
    protected function getCIDToGIDMap(): void
    {
        $this->fdt['ctgdata'] = [];

        $enctable = $this->selectEncodingTable();
        if ($enctable === null) {
            throw new FontException(
                'No usable cmap subtable found for this font.'
                . ' Requested platformID='
                . $this->fdt['platform_id']
                . ' encodingID='
                . $this->fdt['encoding_id']
                . '. Available tables: '
                . \implode(', ', \array_map(
                    static fn(array $tbl): string => $tbl['platformID'] . '/' . $tbl['encodingID'],
                    $this->fdt['encodingTables'],
                ))
                . '.',
            );
        }

        $this->offset = $this->fdt['table']['cmap']['offset'] + $enctable['offset'];
        $format = $this->fbyte->getUShort($this->offset);
        $this->offset += 2;
        match ($format) {
                0 => $this->processFormat0($offset_file),
                2 => $this->processFormat2($offset_file),
                4 => $this->processFormat4($offset_file),
                6 => $this->processFormat6($offset_file),
                8 => $this->processFormat8($offset_file),
                10 => $this->processFormat10($offset_file),
                12 => $this->processFormat12($offset_file),
                13 => $this->processFormat13($offset_file),
                14 => $this->processFormat14($offset_file),
                default => throw new FontException("Unsupported cmap format: $format"),
            };

        // Glyph 0 is the .notdef glyph used when the font does not contain a glyph for a character
        if (!isset($this->fdt['ctgdata'][0])) {
            $this->fdt['ctgdata'][0] = 0;
        }

        // Plain TTF (not TrueTypeUnicode) have exactly 256 characters
        if ($this->fdt['type'] == 'TrueTypeUnicode' && \count($this->fdt['ctgdata']) == 256) {
            $this->fdt['type'] = 'TrueType';
        }
    }

    /**
     * Process Format 0: Byte encoding table
     *  0 - uint16        format              (unused) Always 0 for subtable format 0
     *  2 - uint16        length              (unused) The length of the subtable in bytes
     *  4 - uint16        language            (unused)
     *  6 - unit8[256]    glyphIdArray        An array that maps character codes to glyph index values.
     */
    protected function processFormat0(int $offset_file): void
    {
        // uint8 glyphIdArray starts at byte-offset 6
        $offset_loop = $offset_file + 6;
        for ($chr = 0; $chr < 256; $chr++) {
            $gid = $this->fbyte->getByte($offset_loop);
            $this->addCtgItem($chr, $gid);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 1;
        }
    }

    /**
     * Process Format 2: High-byte mapping through table
     *   0 - uint16        format         (unused) Always 2 for subtable format 2
     *   2 - uint16        length         (unused) The length of the subtable in bytes
     *   4 - uint16        language       (unused)
     *   6 - uint16[256]   subHeaderKeys  Array mapping high bytes into the subHeaders array: value is subHeaders index × 8
     * 518 - SubHeader[]   subHeaders     Array of SubHeader records
     *     - unit16[]      glyphIdArray   Array containing sub-arrays used for mapping the low byte of 2-byte character
     *
     * SubHeader Record (8 bytes):
     *   0 - uint16        firstCode      First valid low byte for this SubHeader
     *   2 - uint16        entryCount     Number of valid low bytes for this SubHeader
     *   4 - int16         idDelta
     *   6 - unit16        idRangeOffset
     */
    protected function processFormat2(int $offset_file): void
    {
        // subHeaderKeys array starts at byte-offset: 6
        $offset_loop = $offset_file + 6;

        // Find subHeaderKeys
        $subHeaderKeys = [];
        $numSubHeaders = 0;
        for ($i = 0; $i < 256; $i++) {
            // Array that maps high bytes to subHeaders: value is subHeader index * 8.
            $subHeaderKeys[$i] = $this->fbyte->getUShort($offset_loop) >> 3;

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }
        // the number of subHeaders is equal to the max of subHeaderKeys + 1 because 0 is also an index
        $numSubHeaders = \max($subHeaderKeys) + 1;

        // SubHeader Records start at byte-offset: 518
        $offset_loop = $offset_file + 518;
        // read subHeader structures
        $subHeaders = [];
        $numGlyphIndexArray = 0;
        for ($i = 0; $i < $numSubHeaders; $i++) {
            $subHeaders[$i]['firstCode'] = $this->fbyte->getUShort($offset_loop);
            $subHeaders[$i]['entryCount'] = $this->fbyte->getUShort($offset_loop + 2);
            $subHeaders[$i]['idDelta'] = $this->fbyte->getUShort($offset_loop + 4);
            $subHeaders[$i]['idRangeOffset'] = $this->fbyte->getUShort($offset_loop + 6);

            $subHeaders[$i]['idRangeOffset'] -= 2 + ($numSubHeaders - $i - 1) * 8;
            $subHeaders[$i]['idRangeOffset'] /= 2;
            $subHeaders[$i]['idRangeOffset'] = (int) $subHeaders[$i]['idRangeOffset'];
            $numGlyphIndexArray += $subHeaders[$i]['entryCount'];

            // Increment offset by size of each loop item in bytes
            $offset_loop += 8;
        }

        // GlyphId array starts at byte-offset: 518 + numSubHeaders * 8
        $offset_loop = $offset_file + 518 + $numSubHeaders * 8;
        // Read glyphIdArray
        $glyphIndexArray = [0 => 0];
        for ($i = 0; $i < $numGlyphIndexArray; $i++) {
            $glyphIndexArray[$i] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // Create mapping using the information read from font file above
        for ($chr = 0; $chr < 256; $chr++) {
            $shk = $subHeaderKeys[$chr];
            if ($shk === 0) {
                // one byte code
                $cdx = $chr;
                $gid = $glyphIndexArray[0];
                $this->addCtgItem($cdx, $gid);
            } else {
                if (!isset($subHeaders[$shk])) {
                    throw new FontException("CMap type 2 subheader #$shk is not valid.");
                }
                // two bytes code
                $start_byte = $subHeaders[$shk]['firstCode'];
                $end_byte = $start_byte + $subHeaders[$shk]['entryCount'];
                for ($jdx = $start_byte; $jdx < $end_byte; $jdx++) {
                    // combine high and low bytes
                    $cdx = ($chr << 8) + $jdx;
                    $idRangeOffset = $subHeaders[$shk]['idRangeOffset'] + $jdx - $subHeaders[$shk]['firstCode'];
                    $gid = \max(0, ($glyphIndexArray[$idRangeOffset] + $subHeaders[$shk]['idDelta']) % 65_536);
                    $this->addCtgItem($cdx, $gid);
                }
            }
        }
    }

    /**
     * Process Format 4: Segment mapping to delta values
     *   0            - uint16             format         (unused) Always 4 for subtable format 4
     *   2            - uint16             length         The length of the subtable in bytes
     *   4            - uint16             language       (unused)
     *   6            - uint16             segCountX2     2 × segCount
     *   8            - uint16             searchRange    pow(2, floor(log2(segCount))) * 2 OR 1 << (entrySelector+1)
     *  10            - uint16             entrySelector  floor(log2(segCount)))
     *  12            - uint16             rangeShift     segCount * 2 - searchRange
     *  14            - unit16[segCount]   endCode        End characterCode for each segment; last segment = 0xFFFF
     *  14+2*segCount - uint16             reservedPad    Always 0
     *  16+2*segCount - uint16[segCount]   startCode      Start characterCode for each segment; last segment = 0xFFFF
     *  16+4*segCount - int16[segCount]    idDelta        Delta for all character codes in segment
     *  16+6*segCount - uint16[segCount]   idRangeOffset  Offsets into glyphIdArray or 0
     *  16+8*segCount - uint16[]           glyphIdArray   Glyph index array (arbitrary length)
     */
    protected function processFormat4(int $offset_file): void
    {
        $length = $this->fbyte->getUShort($offset_file + 2);
        $segCount = (int) \floor($this->fbyte->getUShort($offset_file + 6) / 2);

        // endCode array starts at byte-offset: 14
        $offset_loop = $offset_file + 14;
        // array of end character codes for each segment
        $endCount = [];
        for ($i = 0; $i < $segCount; $i++) {
            $endCount[$i] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // startCode array starts at byte-offset: 16 + 2 * segCount
        $offset_loop = $offset_file + 16 + 2 * $segCount;
        // array of start character codes for each segment
        $startCount = [];
        for ($i = 0; $i < $segCount; $i++) {
            $startCount[$i] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // idDelta array starts at byte-offset: 16 + 4 * segCount
        $offset_loop = $offset_file + 16 + 4 * $segCount;
        // delta for all character codes in segment
        $idDelta = [];
        for ($i = 0; $i < $segCount; $i++) {
            $idDelta[$i] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // idRangeOffset array starts at byte-offset: 16 + 6 * segCount
        $offset_loop = $offset_file + 16 + 6 * $segCount;
        // Offsets into glyphIdArray or 0
        $idRangeOffset = [];
        for ($kdx = 0; $kdx < $segCount; $kdx++) {
            $idRangeOffset[$kdx] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // glyphIdArray starts at byte-offset: 16 + 8 * segCount
        $offset_loop = $offset_file + 16 + 8 * $segCount;
        $gidlen = (int) \floor($length / 2) - 8 - 4 * $segCount;
        // glyph index array
        $glyphIdArray = [];
        for ($kdx = 0; $kdx < $gidlen; $kdx++) {
            $glyphIdArray[$kdx] = $this->fbyte->getUShort($offset_loop);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }

        // Create mapping using the information found above
        for ($kdx = 0; $kdx < $segCount; $kdx++) {
            for ($chr = $startCount[$kdx]; $chr <= $endCount[$kdx]; $chr++) {
                if ($idRangeOffset[$kdx] == 0) {
                    $gid = \max(0, ($idDelta[$kdx] + $chr) % 65_536);
                } else {
                    $gid = (int) \floor($idRangeOffset[$kdx] / 2) + ($chr - $startCount[$kdx]) - ($segCount - $kdx);
                    $gid = \max(0, ($glyphIdArray[$gid] + $idDelta[$kdx]) % 65_536);
                }

                $this->addCtgItem($chr, $gid);
            }
        }
    }

    /**
     * Process Format 6: Trimmed table mapping
     *   0 - uint16             format         (unused) Always 6 for subtable format 6
     *   2 - uint16             length         (unused) The length of the subtable in bytes
     *   4 - uint16             language       (unused)
     *   6 - uint16             firstCode      First character code of subrange
     *   8 - uint16             entryCount     Number of character codes in subrange
     *  10 - uint16[entryCount] glyphIdArray   Array of glyph index values for character codes in the range
     */
    protected function processFormat6(int $offset_file): void
    {
        $firstCode = $this->fbyte->getUShort($offset_file + 6);
        $entryCount = $this->fbyte->getUShort($offset_file + 8);

        // glyphIdArray starts at byte-offset 10
        $offset_loop = $offset_file + 10;
        for ($kdx = 0; $kdx < $entryCount; $kdx++) {
            $gid = $this->fbyte->getUShort($offset_loop);

            $chr = $kdx + $firstCode;
            $this->addCtgItem($chr, $gid);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }
    }

    /**
     * Process Format 8: Mixed 16-bit and 32-bit coverage
     *  0      - uint16              format         (unused) Always 8 for subtable format 8
     *  2      - uint16              reserved       (unused) Always 0
     *  4      - uint32              length         (unused) The length of the subtable in bytes
     *  8      - uint32              language       (unused)
     * 12      - uint8[8192]         is32           Bit array indicating a value is the start of a 32-bit character code
     * 12+8192 - uint32              numGroups      Number of groupings which follow
     * 16+8192 - MapGroup[numGroups] glyphIdArray   Array of glyph index values for character codes in the range
     *
     * SequentialMapGroup Record (12 bytes):
     *  0      - uint32              startCharCode  First character code in this group (high byte set to \0 if ia32=0)
     *  4      - uint32              startCharCode  Last character code in this group (high byte set to \0 if ia32=0)
     *  8      - uint32              startGlyphID   Glyph index corresponding to the starting character code
     */
    protected function processFormat8(int $offset_file): void
    {
        $offset_loop = $offset_file + 12;

        // is32 -- This is the bit array indicating if a group (1 << groupIndex) is a 32 bit value
        $is32 = [];
        for ($i = 0; $i < 8192; $i++) {
            $is32[$i] = $this->fbyte->getByte($offset_loop + $i);
        }

        $nGroups = $this->fbyte->getULong($offset_file + 8204);
        $offset_loop = $offset_file + 8208;
        for ($idx = 0; $idx < $nGroups; $idx++) {
            $startCharCode = $this->fbyte->getULong($offset_loop);
            $endCharCode = $this->fbyte->getULong($offset_loop + 4);
            $startGlyphID = $this->fbyte->getULong($offset_loop + 8);

            for ($cpw = $startCharCode; $cpw <= $endCharCode; $cpw++) {
                $is32idx = (int) \floor($cpw / 8);
                if (isset($is32[$is32idx]) && ($is32[$is32idx] & (1 << (7 - ($cpw % 8)))) === 0) {
                    $chr = $cpw;
                } else {
                    // 32 bit format
                    // convert to decimal (http://www.unicode.org/faq//utf_bom.html#utf16-4)
                    // LEAD_OFFSET = (0xD800 - (0x10000 >> 10)) = 55232
                    // SURROGATE_OFFSET = (0x10000 - (0xD800 << 10) - 0xDC00) = -56613888
                    $chr = ((55_232 + ($cpw >> 10)) << 10) + (0xdc00 + ($cpw & 0x3ff)) - 56_613_888;
                }

                $this->addCtgItem($chr, $startGlyphID);
                $this->fdt['ctgdata'][$chr] = 0; // overwrite
                $startGlyphID++;
            }

            // Increment offset by size of each loop item in bytes
            $offset_loop += 12;
        }
    }

    /**
     * Process Format 10: Trimmed array
     *   0 - uint16     format         (unused) Always 10 for subtable format 10
     *   2 - uint16     reserved       (unused) Always 0
     *   4 - uint32     length         (unused) The length of the subtable in bytes
     *   8 - uint32     language       (unused)
     *  12 - unit32     startCharCode  First character code covered
     *  16 - uint32     numChars       Number of character codes covered
     *  20 - uint16[]   glyphIdArray   Array of glyph index values for character codes in the range
     */
    protected function processFormat10(int $offset_file): void
    {
        $startCharCode = $this->fbyte->getULong($offset_file + 12);
        $numChars = $this->fbyte->getULong($offset_file + 16);

        // glyphIdArray starts at byte-offset: 20
        $offset_loop = $offset_file + 20;
        for ($i = 0; $i < $numChars; $i++) {
            $gid = $this->fbyte->getUShort($offset_loop);
            $this->addCtgItem($startCharCode + $i, $gid);

            // Increment offset by size of each loop item in bytes
            $offset_loop += 2;
        }
    }

    /**
     * Process Format 12: Segmented coverage
     *   0 - uint16                         format         (unused) Always 12 for subtable format 12
     *   2 - uint16                         reserved       (unused) Always 0
     *   4 - uint32                         length         (unused) The length of the subtable in bytes
     *   8 - uint32                         language       (unused)
     *  12 - uint32                         numGroups      Number of groupings which follow
     *  16 - SequentialMapGroup[numGroups]  groups         Array of SequentialMapGroup records
     *
     *  SequentialMapGroup Record (12 bytes):
     *   0 - uint32                         startCharCode  First character code in this group
     *   4 - uint32                         endCharCode    Last character code in this group
     *   8 - uint32                         startGlyphID   Glyph index corresponding to the starting character code
     */
    protected function processFormat12(int $offset_file): void
    {
        $nGroups = $this->fbyte->getULong($offset_file + 12);

        // groups start at byte-offset: 16
        $offset_loop = $offset_file + 16;
        for ($i = 0; $i < $nGroups; $i++) {
            $startCharCode = $this->fbyte->getULong($offset_loop);
            $endCharCode = $this->fbyte->getULong($offset_loop + 4);
            $startGlyphCode = $this->fbyte->getULong($offset_loop + 8);

            for ($chr = $startCharCode; $chr <= $endCharCode; $chr++) {
                $this->addCtgItem($chr, $startGlyphCode);
                $startGlyphCode++;
            }

            // Increment offset by size of each loop item in bytes
            $offset_loop += 12;
        }
    }

    /**
     * Process Format 13: Many-to-one range mappings
     *   0 - uint16                         format         (unused) Always 13 for subtable format 13
     *   2 - uint16                         reserved       (unused) Always 0
     *   4 - uint32                         length         (unused) The length of the subtable in bytes
     *   8 - uint32                         language       (unused)
     *  12 - uint32                         numGroups      Number of groupings which follow
     *  16 - ConstantMapGroup[numGroups]    groups         Array of ConstantMapGroup records
     *
     * ConstantMapGroup Record (12 bytes):
     *   0 - uint32                         startCharCode  First character code in this group
     *   4 - uint32                         endCharCode    Last character code in this group
     *   8 - uint32                         glyphID        Glyph index to be used for all characters in the group's range
     */
    protected function processFormat13(int $offset_file): void
    {
        $nGroups = $this->fbyte->getULong($offset_file + 12);

        // groups start at byte-offset: 16
        $offset_loop = $offset_file + 16;
        for ($i = 0; $i < $nGroups; $i++) {
            $startCharCode = $this->fbyte->getULong($offset_loop);
            $endCharCode = $this->fbyte->getULong($offset_loop + 4);
            $constantGlyphCode = $this->fbyte->getULong($offset_loop + 8);

            for ($chr = $startCharCode; $chr <= $endCharCode; $chr++) {
                $this->addCtgItem($chr, $constantGlyphCode);
            }

            // Increment offset by size of each loop item in bytes
            $offset_loop += 12;
        }
    }

    /**
     * Process Format 14: Unicode Variation Sequences
     *
     * 'cmap' Subtable Format 14 (format field already consumed, $this->offset points past it):
     *   0 - uint16                                   format                  (unused) Always 14 for subtable format 14
     *   2 - uint32                                   length                  (unused) Byte length of this subtable (incl. header)
     *   6 - uint32                                   numVarSelectorRecords   Number of VariationSelector records
     *  10 - VariationSelector[numVarSelectorRecords] varSelector (table)     Array of VariationSelector records
     *
     * VariationSelector Record (11 bytes):
     *   0 - uint24     varSelector          (unused) Variation selector value
     *   3 - Offset32   defaultUVSOffset     (unused) Offset from subtable start to Default UVS table; 0 if absent
     *   7 - Offset32   nonDefaultUVSOffset  Offset from subtable start to Non-Default UVS table; 0 if absent
     *
     * NonDefaultUVS Table:
     *   0 - uint32        numUVSMappings  Number of UVS Mapping records
     *   4 - UVSMapping[]  uvsMappings     Array of UVSMapping records
     *
     * UVSMapping Record (5 bytes):
     *   0 - uint24   unicodeValue  Base Unicode code point of the variation sequence
     *   3 - uint16   glyphID       Glyph ID to use for this variation sequence
     *
     * Default UVS sequences reuse the glyph already mapped in the main cmap table; no action required.
     */
    protected function processFormat14(int $offset_file): void
    {
        $numVarSelectors = $this->fbyte->getULong($offset_file + 6);
        $varSelectorTableOffset = $offset_file + 10;

        // Loop to process the VariationSelector Records (table)
        for ($idx = 0; $idx < $numVarSelectors; $idx++) {
            // skipping varSelector (uint24)
            // skipping defaultUVSOffset - default sequences use the main cmap glyph
            $nonDefaultTableOffset = $this->fbyte->getULong($varSelectorTableOffset + 11 * $idx + 7);
            if ($nonDefaultTableOffset === 0) {
                continue;
            }
            // Add the starting file offset to the relative nonDefaultUVSOffset
            $nonDefaultTableOffset += $offset_file;

            // Process the Non-Default UVS table for this variation selector.
            $numUVSMappings = $this->fbyte->getULong($nonDefaultTableOffset);
            for ($jdx = 0; $jdx < $numUVSMappings; $jdx++) {
                $uvsMappingOffset = $nonDefaultTableOffset + 5 * $jdx + 4;

                // unicodeValue: uint24 (3 bytes, big-endian)
                $unicodeValue =
                    ($this->fbyte->getByte($uvsMappingOffset) << 16)
                    | ($this->fbyte->getByte($uvsMappingOffset + 1) << 8)
                    | $this->fbyte->getByte($uvsMappingOffset + 2);

                $glyphID = $this->fbyte->getUShort($uvsMappingOffset + 3);
                $this->addCtgItem($unicodeValue, $glyphID);
            }
        }
    }
}

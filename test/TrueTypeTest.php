<?php

declare(strict_types=1);

/**
 * TrueTypeTest.php
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

namespace Test;

use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import\TrueType;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;
use Com\Tecnick\File\Byte;
use PHPUnit\Framework\Attributes\Test;

/**
 * TrueType Test
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
 *
 * @SuppressWarnings("PHPMD.LongVariable")
 */
class TrueTypeTest extends TestUtil
{
    use FontDataTrait;

    /** TableDirectory:
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
     *
     * Parse Font Header Table (head) for BBox, units and flags
     *  0 - uint16             majorVersion        Major version of font header table (always 1)
     *  2 - uint16             minorVersion        Major version of font header table (always 0)
     *  6 - Fixed (16.16)      fontRevision        Set by font manufacturer (Fixed = 4 bytes)
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
     * 44 - uint16             macStyle            bits (0:Bold, 1:Italic, 2:Underline, 3:Outline, 4:Shadow, 5:Condensed, 6:Extended, 7-15:Reserved)
     * 46 - uint16             lowestRecPPEM       Smallest readable size in pixels.
     * 48 - int16              fontDirectionHint   Deprecated -- Set to 2
     * 50 - int16              indexToLocFormat    0 for short offsets (Offset16), 1 for long (Offset32).
     * 52 - int16              glyphDataFormat     0 for current format.
     *
     *  @return void
     */

    // Verifies isValidType() rejects a font whose sfnt version header is not 0x00010000, throwing before any file I/O.
    #[Test]
    public function testExceptionInvalidType(): void
    {
        $binary = \hex2bin('abc10000');
        if ($binary === false) {
            throw new FontException('Invalid binary string');
        }
        $byte = new Byte($binary);

        $this->expectException(FontException::class);
        new TrueType($binary, $this->fdt, $byte);
    }

    // Verifies checkMagickNumber() throws when the head table's magic number != 0x5f0f3cf5; also guards that
    // setFontFile() runs (storing the font) before the magic-number check, so K_PATH_FONTS must be an allowed path.
    #[Test]
    public function testExceptionInvalidMagicNumber(): void
    {
        $tableDirectory =
            // sfntVersion
            \hex2bin('00010000') .
            // numTables
            ("\0" . \chr(1)) .
            // searchRange
            ("\0" . \chr(16)) .
            // entrySelector
            ("\0" . \chr(0)) .
            // rangeShift
            ("\0" . \chr(0));
        $tableRecordHead = 'head' . "\0\0\0\0" . "\0\0\0" . \chr(28) . "\0\0\0" . \chr(54);

        // Valid magicNumber
        //$magicNumber = \hex2bin('5f0f3cf5');
        // Invalid magicNumber
        $magicNumber = \hex2bin('abcd1234');
        $tableHead =
            \hex2bin('00010000') .
            \hex2bin('0004000F') .
            "\0\0\0\0" .
            $magicNumber; /* .
            \hex2bin('001F') .
            \hex2bin('0800') .
            \str_repeat("\0", 16) .
            "\0\0" .
            "\0\0" .
            \hex2bin('0400') .
            \hex2bin('03b9') .
            \hex2bin('0001') .
            \hex2bin('0013') .
            \hex2bin('0002') .
            "\0\0" .
            "\0\0";*/
        $binary = $tableDirectory . $tableRecordHead . $tableHead;
        $byte = new Byte($binary);

        $this->fdt['dir'] = \defined('K_PATH_FONTS') && \is_string(K_PATH_FONTS) ? K_PATH_FONTS : '';
        $this->fdt['file_name'] = \bin2hex(\random_bytes(20));

        $this->expectException(FontException::class);
        new TrueType($binary, $this->fdt, $byte);
    }

    // Verifies a format 13 subtable with numGroups=0 maps no chars, so getCIDToGIDMap leaves only the
    // .notdef fallback (ctgdata == [0=>0]) and the font type is untouched.
    public function testGetCIDToGIDMapFormat13SetsNotDefGlyph(): void
    {
        // Format 13 subtable with numGroups=0: maps nothing, only .notdef fallback is added.
        $font =
            "\x00\x0d" // format = 13
            . "\x00\x00" // reserved
            . "\x00\x00\x00\x10" // length = 16
            . "\x00\x00\x00\x00" // language
            . "\x00\x00\x00\x00"; // numGroups = 0

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame([0 => 0], $this->getCtgData($fontData));
        $this->assertSame('TrueTypeUnicode', $this->getFontDataString($fontData, 'type'));
    }

    // Verifies format 13 (many-to-one) maps every code in a group's range to the same glyph id:
    // chars 65,66,67 all -> glyph 5, plus the .notdef fallback.
    public function testProcessFormat13MapsCharsToSingleGlyph(): void
    {
        // Binary blob for a Format 13 subtable read after the format field (offset=2):
        //   reserved    : \x00\x00           (2 bytes)
        //   length      : \x00\x00\x00\x1c   (4 bytes, unused)
        //   language    : \x00\x00\x00\x00   (4 bytes, unused)
        //   numGroups   : \x00\x00\x00\x01   (4 bytes → 1 group)
        //   startChar   : \x00\x00\x00\x41   (4 bytes → 65 = 'A')
        //   endChar     : \x00\x00\x00\x43   (4 bytes → 67 = 'C')
        //   glyphID     : \x00\x00\x00\x05   (4 bytes → glyph 5)
        $font =
            "\x00\x0d" // format = 13
            . "\x00\x00" // reserved
            . "\x00\x00\x00\x1c" // length
            . "\x00\x00\x00\x00" // language
            . "\x00\x00\x00\x01" // numGroups = 1
            . "\x00\x00\x00\x41" // startCharCode = 65
            . "\x00\x00\x00\x43" // endCharCode   = 67
            . "\x00\x00\x00\x05"; // glyphID       = 5

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        // Characters 65, 66 and 67 must all map to glyph 5 (many-to-one)
        $this->assertSame(5, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(5, $this->getCtgGlyph($fontData, 66));
        $this->assertSame(5, $this->getCtgGlyph($fontData, 67));
        // .notdef fallback must be present
        $this->assertSame(0, $this->getCtgGlyph($fontData, 0));
    }

    // Verifies format 13 records a subset glyph: when char 65 is flagged in subchars, addCtgItem maps 65->glyph 7
    // and registers the reverse subglyphs[7]=65 entry needed for subsetting.
    public function testProcessFormat13AddsGlyphsToSubset(): void
    {
        $font =
            "\x00\x0d"
            . "\x00\x00"
            . "\x00\x00\x00\x1c"
            . "\x00\x00\x00\x00"
            . "\x00\x00\x00\x01"
            . "\x00\x00\x00\x41" // startCharCode = 65
            . "\x00\x00\x00\x41" // endCharCode   = 65
            . "\x00\x00\x00\x07"; // glyphID       = 7

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        // Mark char 65 as a subset char
        $this->setProperty($instance, 'subchars', [65 => true]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);
        $subGlyphs = $this->getSubglyphs($instance);

        $this->assertSame(7, $this->getCtgGlyph($fontData, 65));
        $this->assertArrayHasKey(7, $subGlyphs);
        $this->assertTrue($subGlyphs[7] ?? false);
    }

    // Verifies format 14 (Unicode Variation Sequences) follows a non-zero nonDefaultUVSOffset and maps the
    // UVS unicodeValue U+0082A6 -> glyph 1142, keeping the .notdef fallback.
    public function testProcessFormat14NonDefaultUVSMapsGlyphs(): void
    {
        // Format 14 subtable with 1 VariationSelector record that has a Non-Default UVS table.
        //
        // Byte layout (subtable starts at offset 0):
        //   0- 1: format              = 14  (0x00,0x0E) — consumed by getCIDToGIDMap before this call
        //   2- 5: length              = 30  (0x00,0x00,0x00,0x1E)
        //   6- 9: numVarSelectorRecs  =  1  (0x00,0x00,0x00,0x01)
        //  10-12: varSelector         = 0x0E0100 (3 bytes)
        //  13-16: defaultUVSOffset    =  0  (absent)
        //  17-20: nonDefaultUVSOffset = 21  (0x00,0x00,0x00,0x15) — relative to subtable start
        //  21-24: numUVSMappings      =  1  (0x00,0x00,0x00,0x01)
        //  25-27: unicodeValue        = U+0082A6 (0x00,0x82,0xA6)
        //  28-29: glyphID             = 1142 (0x04,0x76)
        $font =
            "\x00\x0e" // format = 14
            . "\x00\x00\x00\x1e" // length = 30
            . "\x00\x00\x00\x01" // numVarSelectorRecords = 1
            . "\x0e\x01\x00" // varSelector = U+E0100 (uint24)
            . "\x00\x00\x00\x00" // defaultUVSOffset = 0 (absent)
            . "\x00\x00\x00\x15" // nonDefaultUVSOffset = 21
            . "\x00\x00\x00\x01" // numUVSMappings = 1
            . "\x00\x82\xa6" // unicodeValue = U+0082A6 (uint24)
            . "\x04\x76"; // glyphID = 1142

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        // Non-default UVS mapping: U+0082A6 → glyph 1142
        $this->assertSame(1142, $this->getCtgGlyph($fontData, 0x0082A6));
        // .notdef fallback must still be set
        $this->assertSame(0, $this->getCtgGlyph($fontData, 0));
    }

    // Verifies format 14 with multiple UVS mappings maps each (U+0082A6->7961, U+004E4D->42), and that only the
    // flagged subchar's glyph is tracked in subglyphs (7961 present, 42 absent).
    public function testProcessFormat14NonDefaultUVSTracksSubglyphs(): void
    {
        // Same layout as above but with a second mapping and subchars tracking.
        //
        // Byte layout (subtable starts at offset 0):
        //   0- 1: format              = 14  (0x00,0x0E)
        //   2- 5: length              = 35
        //   6- 9: numVarSelectorRecs  =  1
        //  10-12: varSelector         = 0x0E0101 (3 bytes)
        //  13-16: defaultUVSOffset    =  0
        //  17-20: nonDefaultUVSOffset = 21
        //  21-24: numUVSMappings      =  2
        //  25-27: unicodeValue[0]     = U+0082A6 → glyphID 7961  (0x00,0x82,0xA6 + 0x1F,0x19)
        //  30-32: unicodeValue[1]     = U+004E4D → glyphID 42    (0x00,0x4E,0x4D + 0x00,0x2A)
        $font =
            "\x00\x0e" // format = 14
            . "\x00\x00\x00\x25" // length = 37
            . "\x00\x00\x00\x01" // numVarSelectorRecords = 1
            . "\x0e\x01\x01" // varSelector = U+E0101 (uint24)
            . "\x00\x00\x00\x00" // defaultUVSOffset = 0
            . "\x00\x00\x00\x15" // nonDefaultUVSOffset = 21
            . "\x00\x00\x00\x02" // numUVSMappings = 2
            . "\x00\x82\xa6" // unicodeValue[0] = U+0082A6
            . "\x1f\x19" // glyphID[0] = 7961
            . "\x00\x4e\x4d" // unicodeValue[1] = U+004E4D
            . "\x00\x2a"; // glyphID[1] = 42

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        // Mark U+0082A6 as a subset char to verify subglyphs tracking
        $this->setProperty($instance, 'subchars', [0x0082A6 => true]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);
        $subGlyphs = $this->getSubglyphs($instance);

        $this->assertSame(7961, $this->getCtgGlyph($fontData, 0x0082A6));
        $this->assertSame(42, $this->getCtgGlyph($fontData, 0x004E4D));
        // Glyph 7961 must be in the subset (0x0082A6 was a subchar)
        $this->assertArrayHasKey(7961, $subGlyphs);
        $this->assertTrue($subGlyphs[7961] ?? false);
        // Glyph 42 must NOT be in the subset (U+004E4D was not a subchar)
        $this->assertArrayNotHasKey(42, $subGlyphs);
    }

    // Verifies format 14 ignores Default UVS records (those reuse the main cmap glyph): a record with only a
    // defaultUVSOffset and nonDefaultUVSOffset=0 adds no ctgdata entries beyond .notdef.
    public function testProcessFormat14DefaultUVSOnlyAddsNoCtgEntries(): void
    {
        // Format 14 subtable with 1 VariationSelector record that has only a Default UVS table.
        // Default UVS sequences use the standard cmap glyph — no ctgdata entries should be added.
        //
        // Byte layout (subtable starts at offset 0):
        //   0- 1: format              = 14
        //   2- 5: length              = 26
        //   6- 9: numVarSelectorRecs  =  1
        //  10-12: varSelector         = 0x0E0100
        //  13-16: defaultUVSOffset    = 21  (has a Default UVS table)
        //  17-20: nonDefaultUVSOffset = 0   (absent)
        //  21-24: numUnicodeValueRanges = 1
        //  25-27: startUnicodeValue   = U+004E4D (uint24)
        //  28:    additionalCount     = 2
        $font =
            "\x00\x0e" // format = 14
            . "\x00\x00\x00\x1d" // length = 29
            . "\x00\x00\x00\x01" // numVarSelectorRecords = 1
            . "\x0e\x01\x00" // varSelector = U+E0100 (uint24)
            . "\x00\x00\x00\x15" // defaultUVSOffset = 21
            . "\x00\x00\x00\x00" // nonDefaultUVSOffset = 0 (absent)
            . "\x00\x00\x00\x01" // numUnicodeValueRanges = 1
            . "\x00\x4e\x4d" // startUnicodeValue = U+004E4D (uint24)
            . "\x02"; // additionalCount = 2

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        // Default UVS adds no explicit ctgdata entries beyond .notdef
        $this->assertSame([0 => 0], $this->getCtgData($fontData));
    }

    // Verifies a format 14 subtable with numVarSelectorRecords=0 produces no mappings, leaving only the
    // .notdef fallback (ctgdata == [0=>0]).
    public function testGetCIDToGIDMapFormat14SetsNotDefGlyph(): void
    {
        // Format 14 subtable with numVarSelectorRecords=0: no mappings → only .notdef fallback added.
        $font =
            "\x00\x0e" // format = 14
            . "\x00\x00\x00\x0a" // length = 10
            . "\x00\x00\x00\x00"; // numVarSelectorRecords = 0

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame([0 => 0], $this->getCtgData($fontData));
    }

    // Verifies getCIDToGIDMap throws on an unrecognised cmap subtable format (here 15), via the match() default arm.
    public function testGetCIDToGIDMapThrowsOnUnsupportedFormat(): void
    {
        $instance = $this->buildTrueType("\x00\x0f", [
            'encodingTables' => [
                [
                    'platformId' => 3,
                    'encodingId' => 1,
                    'offset' => 0,
                ],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => [
                    'offset' => 0,
                ],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->bcExpectException(FontException::class);
        $this->invokeMethod($instance, 'getCIDToGIDMap');
    }

    // Verifies addCtgItem maps cid->gid in ctgdata and, when the cid is a flagged subchar, also records the
    // reverse subglyphs[gid]=cid entry (65->7 and subglyphs[7]=65).
    public function testAddCtgItemAddsGlyphToSubset(): void
    {
        $instance = $this->buildTrueType('', [
            'ctgdata' => [],
        ]);

        $this->setProperty($instance, 'subchars', [65 => true]);
        $this->invokeMethod($instance, 'addCtgItem', [65, 7]);

        $fontData = $this->getFontData($instance);
        $subGlyphs = $this->getSubglyphs($instance);

        $this->assertSame(7, $this->getCtgGlyph($fontData, 65));
        $this->assertArrayHasKey(7, $subGlyphs);
        $this->assertTrue($subGlyphs[7] ?? false);
    }

    // -------------------------------------------------------------------------
    // Issue 1: cmap fallback selection
    // -------------------------------------------------------------------------

    // Verifies selectEncodingTable returns the subtable that exactly matches the requested platform/encoding (3/1),
    // preferring it over other available pairs.
    public function testSelectEncodingTableReturnsExactMatch(): void
    {
        $tables = [
            ['platformId' => 3, 'encodingId' => 1, 'offset' => 42],
            ['platformId' => 3, 'encodingId' => 10, 'offset' => 99],
        ];
        $instance = $this->buildTrueType('', [
            'encodingTables' => $tables,
            'platform_id' => 3,
            'encoding_id' => 1,
        ]);

        $result = $this->selectEncodingTable($instance);
        $this->assertNotNull($result);

        $this->assertSame(42, $result['offset']);
        $this->assertSame(3, $result['platformId']);
        $this->assertSame(1, $result['encodingId']);
    }

    // Verifies selectEncodingTable falls back to Windows UCS-4 (3/10), the top CMAP_FALLBACK_PRIORITY entry,
    // when the requested 3/1 subtable is absent.
    public function testSelectEncodingTableFallsBackToWindowsUCS4(): void
    {
        // Requested 3/1 is absent; only 3/10 (UCS-4) is available.
        $instance = $this->buildTrueType('', [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 10, 'offset' => 7],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
        ]);

        $result = $this->selectEncodingTable($instance);
        $this->assertNotNull($result);

        $this->assertSame(3, $result['platformId']);
        $this->assertSame(10, $result['encodingId']);
        $this->assertSame(7, $result['offset']);
    }

    // Verifies selectEncodingTable falls back to Windows BMP (3/1) when the requested 3/0 and higher-priority
    // 3/10 are absent.
    public function testSelectEncodingTableFallsBackToWindowsBMP(): void
    {
        // Neither 3/0 (requested) nor 3/10 present; only 3/1 available.
        $instance = $this->buildTrueType('', [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 5],
            ],
            'platform_id' => 3,
            'encoding_id' => 0,
        ]);

        $result = $this->selectEncodingTable($instance);
        $this->assertNotNull($result);

        $this->assertSame(3, $result['platformId']);
        $this->assertSame(1, $result['encodingId']);
    }

    // Verifies selectEncodingTable falls back to a Unicode platform-0 subtable when no Windows (platform 3)
    // subtable exists, per CMAP_FALLBACK_PRIORITY.
    public function testSelectEncodingTableFallsBackToPlatform0(): void
    {
        // No Windows (platform 3) subtables; should fall back to platform 0.
        $instance = $this->buildTrueType('', [
            'encodingTables' => [
                ['platformId' => 0, 'encodingId' => 3, 'offset' => 11],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
        ]);

        $result = $this->selectEncodingTable($instance);
        $this->assertNotNull($result);

        $this->assertSame(0, $result['platformId']);
        $this->assertSame(3, $result['encodingId']);
    }

    // Verifies selectEncodingTable returns null when neither the requested pair nor any fallback pair is present
    // (only an unrecognised 9/9 subtable available).
    public function testSelectEncodingTableReturnsNullWhenNoTableAvailable(): void
    {
        $instance = $this->buildTrueType('', [
            'encodingTables' => [
                ['platformId' => 9, 'encodingId' => 9, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
        ]);

        $result = $this->selectEncodingTable($instance);

        $this->assertNull($result);
    }

    // Verifies getCIDToGIDMap throws when selectEncodingTable finds no usable subtable (only an unrecognised
    // 9/9 pair present) — the "no usable cmap subtable" failure path.
    public function testGetCIDToGIDMapThrowsWhenNoTableFound(): void
    {
        // encodingTables contains only an unrecognised platform/encoding pair.
        $instance = $this->buildTrueType('', [
            'encodingTables' => [
                ['platformId' => 9, 'encodingId' => 9, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => [
                'cmap' => ['offset' => 0],
            ],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->bcExpectException(FontException::class);
        $this->invokeMethod($instance, 'getCIDToGIDMap');
    }

    // Verifies getCIDToGIDMap throws when encodingTables is empty (no subtables at all to select from).
    public function testGetCIDToGIDMapThrowsWhenEncodingTablesEmpty(): void
    {
        $instance = $this->buildTrueType('', [
            'encodingTables' => [],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->bcExpectException(FontException::class);
        $this->invokeMethod($instance, 'getCIDToGIDMap');
    }

    // Verifies getCIDToGIDMap end-to-end uses a fallback subtable: with 3/1 absent it reads the 3/10 format-13
    // subtable (0 groups), yielding only the .notdef fallback.
    public function testGetCIDToGIDMapUsesFallbackTable(): void
    {
        // Requested 3/1 is absent; 3/10 is present with format 13, 0 groups.
        $font =
            "\x00\x0d" // format = 13
            . "\x00\x00" // reserved
            . "\x00\x00\x00\x10" // length = 16
            . "\x00\x00\x00\x00" // language
            . "\x00\x00\x00\x00"; // numGroups = 0

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 10, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);
        // Only .notdef should be present (0 groups → nothing mapped)
        $this->assertSame([0 => 0], $this->getCtgData($fontData));
    }

    // -------------------------------------------------------------------------
    // Issue 2: fsType embedding-policy enforcement
    // -------------------------------------------------------------------------

    // Verifies applyEmbeddingPolicy throws on fsType 0x0002 (Restricted License only) — the font may not be embedded.
    public function testApplyEmbeddingPolicyThrowsOnRestrictedLicense(): void
    {
        $instance = $this->buildTrueType('', []);
        $this->bcExpectException(FontException::class);
        // 0x0002 = Restricted License only
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0002]);
    }

    // Verifies applyEmbeddingPolicy permits fsType 0x0004 (Preview & Print): no exception and subset stays enabled.
    public function testApplyEmbeddingPolicyAllowsPreviewPrint(): void
    {
        $instance = $this->buildTrueType('', ['subset' => true]);
        // 0x0004 = Preview & Print — allowed
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0004]);
        // No exception means the policy passed; subset should be unchanged
        $fontData = $this->getFontData($instance);
        $this->assertTrue($this->getFontDataBool($fontData, 'subset'));
    }

    // Verifies a permissive bit overrides the Restricted-License bit: fsType 0x0006 (0x0002|0x0004) does not throw
    // and leaves subset enabled (spec precedence, guards the & 0x000E mask).
    public function testApplyEmbeddingPolicyPermissiveBitOverridesRestricted(): void
    {
        $instance = $this->buildTrueType('', ['subset' => true]);
        // 0x0006 = 0x0002 | 0x0004: permissive override, should not throw
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0006]);
        $fontData = $this->getFontData($instance);
        $this->assertTrue($this->getFontDataBool($fontData, 'subset'));
    }

    // Verifies applyEmbeddingPolicy throws on fsType 0x0200 (Bitmap Embedding Only) — incompatible with vector PDF embedding.
    public function testApplyEmbeddingPolicyThrowsOnBitmapOnly(): void
    {
        $instance = $this->buildTrueType('', []);
        $this->bcExpectException(FontException::class);
        // 0x0200 = Bitmap Embedding Only
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0200]);
    }

    // Verifies the Bitmap-Only restriction still applies when combined with a permissive bit: fsType 0x0208
    // (Bitmap Only | Editable) throws.
    public function testApplyEmbeddingPolicyThrowsOnBitmapOnlyWithEditable(): void
    {
        $instance = $this->buildTrueType('', []);
        $this->bcExpectException(FontException::class);
        // 0x0208 = Bitmap Only | Editable — bitmap restriction still applies
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0208]);
    }

    // Verifies fsType 0x0100 (No Subsetting) is allowed to embed but forces fdt['subset'] to false.
    public function testApplyEmbeddingPolicyDisablesSubsetOnNoSubsettingFlag(): void
    {
        $instance = $this->buildTrueType('', ['subset' => true]);
        // 0x0100 = No Subsetting
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0100]);
        $fontData = $this->getFontData($instance);
        $this->assertFalse($this->getFontDataBool($fontData, 'subset'));
    }

    // Verifies fsType 0x0108 (Editable | No Subsetting) embeds without throwing but still disables subset.
    public function testApplyEmbeddingPolicyNoSubsettingWithEditableAllowed(): void
    {
        $instance = $this->buildTrueType('', ['subset' => true]);
        // 0x0108 = Editable | No Subsetting: embed allowed but no subset
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0108]);
        $fontData = $this->getFontData($instance);
        $this->assertFalse($this->getFontDataBool($fontData, 'subset'));
    }

    // Verifies fsType 0x0000 (Installable, no restrictions) imposes no policy: no throw and subset stays enabled.
    public function testApplyEmbeddingPolicyInstallableAllowsSubset(): void
    {
        $instance = $this->buildTrueType('', ['subset' => true]);
        // 0x0000 = Installable — no restrictions
        $this->invokeMethod($instance, 'applyEmbeddingPolicy', [0x0000]);
        $fontData = $this->getFontData($instance);
        $this->assertTrue($this->getFontDataBool($fontData, 'subset'));
    }

    // -------------------------------------------------------------------------
    // Issue 3: OS/2 table resilience
    // -------------------------------------------------------------------------

    // Verifies getOS2Metrics applies conservative defaults (AvgWidth=0, StemV=70, StemH=30) when the optional
    // OS/2 table is missing, rather than failing.
    public function testGetOS2MetricsUsesDefaultsWhenTableAbsent(): void
    {
        // 'table' has no 'OS/2' entry at all.
        $instance = $this->buildTrueType('', [
            'table' => [],
            'urk' => 1.0,
        ]);

        $this->invokeMethod($instance, 'getOS2Metrics');
        $fontData = $this->getFontData($instance);

        $this->assertSame(0, $this->getFontDataInt($fontData, 'AvgWidth'));
        $this->assertSame(70, $this->getFontDataInt($fontData, 'StemV'));
        $this->assertSame(30, $this->getFontDataInt($fontData, 'StemH'));
    }

    // Verifies getOS2Metrics throws when the OS/2 table length is below OS2_MIN_LENGTH (10), guarding against
    // out-of-bounds reads through the fsType field.
    public function testGetOS2MetricsThrowsWhenTableTooShort(): void
    {
        $instance = $this->buildTrueType('', [
            'table' => [
                'OS/2' => ['offset' => 0, 'length' => 5],
            ],
            'urk' => 1.0,
        ]);

        $this->bcExpectException(FontException::class);
        $this->invokeMethod($instance, 'getOS2Metrics');
    }

    // Verifies getOS2Metrics parses a valid OS/2 table: AvgWidth = xAvgCharWidth*urk (1024), StemV/StemH derived
    // from usWeightClass 400 (70/30), and an Editable fsType leaves subset enabled.
    public function testGetOS2MetricsParsesValidTable(): void
    {
        // Minimal valid OS/2 blob: 10 bytes.
        // version(2) + xAvgCharWidth(2) + usWeightClass(2) + usWidthClass(2) + fsType(2)
        $font =
            "\x00\x04" // version = 4
            . "\x04\x00" // xAvgCharWidth = 1024 raw units
            . "\x01\x90" // usWeightClass = 400
            . "\x00\x05" // usWidthClass  = 5 (unused)
            . "\x00\x08"; // fsType = 0x0008 (Editable — allowed)

        $instance = $this->buildTrueType($font, [
            'table' => [
                'OS/2' => ['offset' => 0, 'length' => 10],
            ],
            'urk' => 1.0,
            'subset' => true,
        ]);

        $this->invokeMethod($instance, 'getOS2Metrics');
        $fontData = $this->getFontData($instance);

        // xAvgCharWidth 0x0400 = 1024 raw, * urk 1.0 = 1024
        $this->assertSame(1024, $this->getFontDataInt($fontData, 'AvgWidth'));
        // usWeightClass 0x0190 = 400; StemV = round(70*400/400) = 70
        $this->assertSame(70, $this->getFontDataInt($fontData, 'StemV'));
        // StemH = round(30*400/400) = 30
        $this->assertSame(30, $this->getFontDataInt($fontData, 'StemH'));
        // Editable flag: subset must remain true (no restriction)
        $this->assertTrue($this->getFontDataBool($fontData, 'subset'));
    }

    // Verifies getOS2Metrics reads fsType from the table and applies the policy: a No-Subsetting flag (0x0100)
    // disables fdt['subset'].
    public function testGetOS2MetricsNoSubsettingFlagDisablesSubset(): void
    {
        // fsType = 0x0100 (No Subsetting)
        $font =
            "\x00\x04" // version
            . "\x02\x00" // xAvgCharWidth
            . "\x01\x90" // usWeightClass = 400
            . "\x00\x05" // usWidthClass
            . "\x01\x00"; // fsType = 0x0100

        $instance = $this->buildTrueType($font, [
            'table' => ['OS/2' => ['offset' => 0, 'length' => 10]],
            'urk' => 1.0,
            'subset' => true,
        ]);

        $this->invokeMethod($instance, 'getOS2Metrics');
        $fontData = $this->getFontData($instance);

        $this->assertFalse($this->getFontDataBool($fontData, 'subset'));
    }

    /**
     * @param array<string, mixed> $fdt
     *
     * @return array<string, mixed>
     */
    protected function getFontDefaults(array $fdt = []): array
    {
        $defaults = [
            'Ascender' => 0,
            'Ascent' => 0,
            'AvgWidth' => 0.0,
            'CapHeight' => 0,
            'CharacterSet' => '',
            'Descender' => 0,
            'Descent' => 0,
            'EncodingScheme' => '',
            'FamilyName' => '',
            'Flags' => 0,
            'FontBBox' => [],
            'FontName' => '',
            'FullName' => '',
            'IsFixedPitch' => false,
            'ItalicAngle' => 0,
            'Leading' => 0,
            'MaxWidth' => 0,
            'MissingWidth' => 0,
            'StdHW' => 0,
            'StdVW' => 0,
            'StemH' => 0,
            'StemV' => 0,
            'UnderlinePosition' => 0,
            'UnderlineThickness' => 0,
            'Version' => '',
            'Weight' => '',
            'XHeight' => 0,
            'bbox' => '',
            'cbbox' => [],
            'cidinfo' => ['Ordering' => '', 'Registry' => '', 'Supplement' => 0, 'uni2cid' => []],
            'compress' => false,
            'ctg' => '',
            'ctgdata' => [],
            'cw' => [],
            'cwu' => [],
            'datafile' => '',
            'desc' => [
                'Ascent' => 0,
                'AvgWidth' => 0,
                'CapHeight' => 0,
                'Descent' => 0,
                'Flags' => 0,
                'FontBBox' => '',
                'ItalicAngle' => 0,
                'Leading' => 0,
                'MaxWidth' => 0,
                'MissingWidth' => 0,
                'StemH' => 0,
                'StemV' => 0,
                'XHeight' => 0,
            ],
            'diff' => '',
            'diff_n' => 0,
            'dir' => '',
            'dw' => 0,
            'enc' => '',
            'enc_map' => [],
            'encodingTables' => [],
            'encoding_id' => 0,
            'encrypted' => '',
            'fakestyle' => false,
            'family' => '',
            'file' => '',
            'file_n' => 0,
            'file_name' => '',
            'i' => 0,
            'ifile' => '',
            'indexToLoc' => [],
            'input_file' => '',
            'isUnicode' => false,
            'italicAngle' => 0,
            'key' => '',
            'lenIV' => 0,
            'length1' => 0,
            'length2' => 0,
            'linked' => false,
            'mode' => [
                'bold' => false,
                'italic' => false,
                'linethrough' => false,
                'overline' => false,
                'underline' => false,
            ],
            'n' => 0,
            'name' => '',
            'numGlyphs' => 0,
            'numHMetrics' => 0,
            'originalsize' => 0,
            'pdfa' => false,
            'platform_id' => 0,
            'settype' => '',
            'short_offset' => false,
            'size1' => 0,
            'size2' => 0,
            'style' => '',
            'subset' => false,
            'subsetchars' => [],
            'table' => [],
            'tot_num_glyphs' => 0,
            'type' => '',
            'underlinePosition' => 0,
            'underlineThickness' => 0,
            'unicode' => false,
            'unitsPerEm' => 0,
            'up' => 0,
            'urk' => 0.0,
            'ut' => 0,
            'weight' => '',
        ];

        return \array_replace_recursive($defaults, $fdt);
    }

    /**
     * @param array<string, mixed> $fdt
     */
    protected function buildTrueType(string $font, array $fdt): TrueType
    {
        $class = new \ReflectionClass(TrueType::class);
        $instance = $class->newInstanceWithoutConstructor();
        try {
            $byte = new Byte($font);
        } catch (\RangeException $exception) {
            $this->fail($exception->getMessage());
        }

        $this->setProperty($instance, 'font', $font);
        $this->setProperty($instance, 'fdt', $this->getFontDefaults($fdt));
        $this->setProperty($instance, 'fbyte', $byte);
        $this->setProperty($instance, 'offset', 0);

        return $instance;
    }

    /**
     * @param array<int, mixed> $args
     */
    protected function invokeMethod(TrueType $instance, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod(TrueType::class, $method);

        return $reflection->invokeArgs($instance, $args);
    }

    /** @return array<string, mixed> */
    protected function getFontData(TrueType $instance): array
    {
        return $this->expectFontData($this->getProperty($instance, 'fdt'));
    }

    /** @param array<string, mixed> $fontData */
    protected function getCtgGlyph(array $fontData, int $char): ?int
    {
        $ctgData = $this->getCtgData($fontData);
        $glyph = $ctgData[$char] ?? null;

        if ($glyph !== null) {
            $this->assertIsInt($glyph);
        }

        return $glyph;
    }

    /**
     * @param array<string, mixed> $fontData
     *
     * @return array<int, int>
     */
    protected function getCtgData(array $fontData): array
    {
        if (!isset($fontData['ctgdata']) || !\is_array($fontData['ctgdata'])) {
            $this->fail('Expected ctgdata map.');
        }

        /** @var array<int, int> $ctgData */
        $ctgData = $fontData['ctgdata'];
        return $ctgData;
    }

    /** @param array<string, mixed> $fontData */
    protected function getFontDataString(array $fontData, string $key): string
    {
        if (!isset($fontData[$key]) || !\is_string($fontData[$key])) {
            $this->fail('Expected string font field: ' . $key);
        }

        return $fontData[$key];
    }

    /** @param array<string, mixed> $fontData */
    protected function getFontDataBool(array $fontData, string $key): bool
    {
        if (!isset($fontData[$key]) || !\is_bool($fontData[$key])) {
            $this->fail('Expected bool font field: ' . $key);
        }

        return $fontData[$key];
    }

    /** @param array<string, mixed> $fontData */
    protected function getFontDataInt(array $fontData, string $key): int
    {
        if (!isset($fontData[$key]) || !\is_int($fontData[$key])) {
            $this->fail('Expected int font field: ' . $key);
        }

        return $fontData[$key];
    }

    /**
     * @return array<int, bool>
     */
    protected function getSubglyphs(TrueType $instance): array
    {
        return $this->expectSubglyphs($this->getProperty($instance, 'subglyphs'));
    }

    /**
     * @return array{platformId: int, encodingId: int, offset: int}|null
     */
    protected function selectEncodingTable(TrueType $instance): ?array
    {
        return $this->expectEncodingTable($this->invokeMethod($instance, 'selectEncodingTable'));
    }

    protected function convertStringEncoding(TrueType $instance, string $str, int $platformId, int $encodingId): string
    {
        return $this->expectString(
            $this->invokeMethod($instance, 'convertStringEncoding', [$str, $platformId, $encodingId]),
            'Expected converted string.',
        );
    }

    protected function getProperty(TrueType $instance, string $name): mixed
    {
        $property = new \ReflectionProperty(TrueType::class, $name);

        return $property->getValue($instance);
    }

    protected function setProperty(TrueType $instance, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty(TrueType::class, $name);
        $property->setValue($instance, $value);
    }

    /** @return array<string, mixed> */
    protected function expectFontData(mixed $value): array
    {
        if (!\is_array($value)) {
            $this->fail('Expected font data array.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return array<int, bool> */
    protected function expectSubglyphs(mixed $value): array
    {
        if (!\is_array($value)) {
            $this->fail('Expected subglyph map.');
        }

        /** @var array<int, bool> $value */
        return $value;
    }

    /** @return array{platformId: int, encodingId: int, offset: int}|null */
    protected function expectEncodingTable(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            $this->fail('Expected encoding table array.');
        }

        if (!isset($value['platformId']) || !\is_int($value['platformId'])) {
            $this->fail('Expected platformId.');
        }

        if (!isset($value['encodingId']) || !\is_int($value['encodingId'])) {
            $this->fail('Expected encodingId.');
        }

        if (!isset($value['offset']) || !\is_int($value['offset'])) {
            $this->fail('Expected offset.');
        }

        return [
            'platformId' => $value['platformId'],
            'encodingId' => $value['encodingId'],
            'offset' => $value['offset'],
        ];
    }

    protected function expectString(mixed $value, string $message): string
    {
        if (!\is_string($value)) {
            $this->fail($message);
        }

        return $value;
    }

    // -------------------------------------------------------------------------
    // cmap format 0 – byte encoding table
    // -------------------------------------------------------------------------

    // Verifies cmap format 0 (256-byte direct lookup) maps each byte code to its glyph id (65->99, 90->10) and
    // that filling all 256 slots converts type TrueTypeUnicode -> TrueType.
    public function testProcessFormat0MapsAllGlyphs(): void
    {
        // Format 0: 256-byte direct lookup. After getCIDToGIDMap reads the 2-byte
        // format field, processFormat0 skips 4 bytes (length + language) then reads
        // 256 single-byte glyph IDs.
        $glyphs = str_repeat("\x00", 256);
        $glyphs[65] = "\x63"; // chr 65 → glyph 99
        $glyphs[90] = "\x0A"; // chr 90 → glyph 10

        $font =
            "\x00\x00" // format = 0
            . "\x01\x06" // length (unused)
            . "\x00\x00" // language (unused)
            . $glyphs;

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame(99, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(10, $this->getCtgGlyph($fontData, 90));
        // All 256 slots populated and type was TrueTypeUnicode → converted to TrueType
        $this->assertSame('TrueType', $this->getFontDataString($fontData, 'type'));
    }

    // -------------------------------------------------------------------------
    // cmap format 2 – high-byte mapping through table
    // -------------------------------------------------------------------------

    // Verifies cmap format 2 with all-zero subHeaderKeys (single-byte codes) maps every byte to glyph 99 via the
    // index-0 subHeader, and that 256 entries convert type TrueTypeUnicode -> TrueType.
    public function testProcessFormat2MapsCharsViaSingleByteSubheaders(): void
    {
        // All 256 subHeaderKeys = 0  → single-byte codes, one subHeader at index 0.
        // subHeaders[0]: firstCode=0, entryCount=1, idDelta=0, idRangeOffset=2
        //   Adjusted idRangeOffset = 2 − (2 + (1−0−1)×8) = 0  →  /2 = 0
        // glyphIdArray[0] = 99  →  every single-byte char maps to glyph 99.
        $subHeaderKeys = str_repeat("\x00\x00", 256); // 512 bytes
        $subHeader = "\x00\x00\x00\x01\x00\x00\x00\x02";
        $glyphIdArray = "\x00\x63"; // glyph 99

        $font =
            "\x00\x02" // format = 2
            . "\x02\x18" // length (unused)
            . "\x00\x00" // language (unused)
            . $subHeaderKeys
            . $subHeader
            . $glyphIdArray;

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame(99, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(99, $this->getCtgGlyph($fontData, 0));
        // 256 entries with TrueTypeUnicode → converted to TrueType
        $this->assertSame('TrueType', $this->getFontDataString($fontData, 'type'));
    }

    // -------------------------------------------------------------------------
    // cmap format 6 – trimmed table mapping
    // -------------------------------------------------------------------------

    // Verifies cmap format 6 (trimmed table) maps a contiguous range from firstCode: 65->10, 66->11, 67->12;
    // with only 4 entries the type stays TrueTypeUnicode (no 256-entry conversion).
    public function testProcessFormat6MapsCharRange(): void
    {
        // firstCode=65, entryCount=3, glyphs=[10,11,12]
        $font =
            "\x00\x06" // format = 6
            . "\x00\x0F" // length (unused)
            . "\x00\x00" // language (unused)
            . "\x00\x41" // firstCode = 65
            . "\x00\x03" // entryCount = 3
            . "\x00\x0A" // glyph for chr 65 = 10
            . "\x00\x0B" // glyph for chr 66 = 11
            . "\x00\x0C"; // glyph for chr 67 = 12

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame(10, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(11, $this->getCtgGlyph($fontData, 66));
        $this->assertSame(12, $this->getCtgGlyph($fontData, 67));
        // Only 4 ctgdata entries → not 256 → type stays TrueTypeUnicode
        $this->assertSame('TrueTypeUnicode', $this->getFontDataString($fontData, 'type'));
    }

    // -------------------------------------------------------------------------
    // cmap format 8 – mixed 16-bit and 32-bit coverage
    // -------------------------------------------------------------------------

    // Verifies cmap format 8 with numGroups=0 maps nothing, leaving only the .notdef fallback (ctgdata == [0=>0]).
    public function testProcessFormat8WithNoGroupsAddsOnlyNotdef(): void
    {
        // numGroups = 0 → no character mappings; only the .notdef fallback is present.
        $is32 = str_repeat("\x00", 8192);
        $font =
            "\x00\x08" // format = 8
            . "\x00\x00" // reserved (uint16)
            . "\x00\x00\x20\x14" // length (uint32, unused)
            . "\x00\x00\x00\x00" // language (uint32, unused)
            . $is32 // is32[8192]
            . "\x00\x00\x00\x00"; // numGroups = 0

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame([0 => 0], $this->getCtgData($fontData));
    }

    // Verifies cmap format 8 single-byte handling (is32 bit clear) and pins the overwrite quirk: addCtgItem first
    // records glyph 5 in subglyphs (subchar 65), then ctgdata[65] is overwritten to 0.
    public function testProcessFormat8MapsSingleByteChar(): void
    {
        // numGroups=1: chars 65..65 → glyph 5. is32[8]=0 → single-byte char.
        // After addCtgItem the code overwrites ctgdata[chr] = 0, so the final
        // stored glyph ID is 0, but subglyphs contains glyph 5 when subchars[65] is set.
        $is32 = str_repeat("\x00", 8192);
        $font =
            "\x00\x08" // format = 8
            . "\x00\x00" // reserved
            . "\x00\x00\x20\x26" // length (unused)
            . "\x00\x00\x00\x00" // language (unused)
            . $is32 // is32[8192] all zeros
            . "\x00\x00\x00\x01" // numGroups = 1
            . "\x00\x00\x00\x41" // startCharCode = 65
            . "\x00\x00\x00\x41" // endCharCode   = 65
            . "\x00\x00\x00\x05"; // startGlyphID  = 5

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);
        $this->setProperty($instance, 'subchars', [65 => true]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);
        $subGlyphs = $this->getSubglyphs($instance);

        // The overwrite sets ctgdata[65] = 0 (format 8 spec)
        $this->assertSame(0, $this->getCtgGlyph($fontData, 65));
        // But addCtgItem ran first and recorded glyph 5 in subglyphs
        $this->assertArrayHasKey(5, $subGlyphs);
    }

    // -------------------------------------------------------------------------
    // cmap format 10 – trimmed array
    // -------------------------------------------------------------------------

    // Verifies cmap format 10 (trimmed array) maps numChars codes from startCharCode: 65->10, 66->11, 67->12,
    // plus the .notdef fallback.
    public function testProcessFormat10MapsCharRange(): void
    {
        // startCharCode=65, numChars=3, glyphs=[10,11,12]
        $font =
            "\x00\x0A" // format = 10
            . "\x00\x00" // reserved (uint16)
            . "\x00\x00\x00\x1C" // length (uint32, unused)
            . "\x00\x00\x00\x00" // language (uint32, unused)
            . "\x00\x00\x00\x41" // startCharCode = 65 (uint32)
            . "\x00\x00\x00\x03" // numChars = 3 (uint32)
            . "\x00\x0A" // glyph for chr 65 = 10
            . "\x00\x0B" // glyph for chr 66 = 11
            . "\x00\x0C"; // glyph for chr 67 = 12

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame(10, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(11, $this->getCtgGlyph($fontData, 66));
        $this->assertSame(12, $this->getCtgGlyph($fontData, 67));
        $this->assertSame(0, $this->getCtgGlyph($fontData, 0));
    }

    // -------------------------------------------------------------------------
    // cmap format 12 – segmented coverage
    // -------------------------------------------------------------------------

    // Verifies cmap format 12 (segmented coverage) assigns sequential glyph ids across a group's range:
    // 65->100, 66->101, 67->102, plus the .notdef fallback.
    public function testProcessFormat12MapsSequentialGlyphs(): void
    {
        // 1 group: startCharCode=65, endCharCode=67, startGlyphID=100
        // → ctgdata[65]=100, ctgdata[66]=101, ctgdata[67]=102
        $font =
            "\x00\x0C" // format = 12
            . "\x00\x00" // reserved (uint16)
            . "\x00\x00\x00\x22" // length (uint32, unused)
            . "\x00\x00\x00\x00" // language (uint32, unused)
            . "\x00\x00\x00\x01" // nGroups = 1
            . "\x00\x00\x00\x41" // startCharCode = 65
            . "\x00\x00\x00\x43" // endCharCode   = 67
            . "\x00\x00\x00\x64"; // startGlyphID  = 100

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame(100, $this->getCtgGlyph($fontData, 65));
        $this->assertSame(101, $this->getCtgGlyph($fontData, 66));
        $this->assertSame(102, $this->getCtgGlyph($fontData, 67));
        $this->assertSame(0, $this->getCtgGlyph($fontData, 0));
    }

    // Verifies cmap format 12 with nGroups=0 maps nothing, leaving only the .notdef fallback (ctgdata == [0=>0]).
    public function testProcessFormat12WithZeroGroupsAddsOnlyNotdef(): void
    {
        $font =
            "\x00\x0C" // format = 12
            . "\x00\x00" // reserved
            . "\x00\x00\x00\x16" // length (unused)
            . "\x00\x00\x00\x00" // language (unused)
            . "\x00\x00\x00\x00"; // nGroups = 0

        $instance = $this->buildTrueType($font, [
            'encodingTables' => [
                ['platformId' => 3, 'encodingId' => 1, 'offset' => 0],
            ],
            'platform_id' => 3,
            'encoding_id' => 1,
            'table' => ['cmap' => ['offset' => 0]],
            'type' => 'TrueTypeUnicode',
        ]);

        $this->invokeMethod($instance, 'getCIDToGIDMap');
        $fontData = $this->getFontData($instance);

        $this->assertSame([0 => 0], $this->getCtgData($fontData));
    }

    // -------------------------------------------------------------------------
    // convertStringEncoding
    // -------------------------------------------------------------------------

    // Verifies decodeNameString decodes a Unicode-platform (platformId 0) name string as UTF-16BE:
    // "\x00\x41" -> "A".
    public function testConvertStringEncodingForUnicodePlatformUtf16be(): void
    {
        $instance = $this->buildTrueType('', []);
        // platformId=0 (Unicode) → UTF-16BE. "\x00\x41" = 'A'
        $result = $this->convertStringEncoding($instance, "\x00\x41", 0, 0);
        $this->assertSame('A', $result);
    }

    // Verifies decodeNameString decodes a Windows-platform name with encodingId 0 (default) as UTF-16BE:
    // "\x00\x42" -> "B".
    public function testConvertStringEncodingForWindowsPlatformDefaultUtf16be(): void
    {
        $instance = $this->buildTrueType('', []);
        // platformId=3, encodingId=0 → default UTF-16BE. "\x00\x42" = 'B'
        $result = $this->convertStringEncoding($instance, "\x00\x42", 3, 0);
        $this->assertSame('B', $result);
    }

    // Verifies decodeNameString decodes a Windows-platform name with encodingId 1 (Unicode BMP) as UTF-16BE:
    // "\x00\x43" -> "C".
    public function testConvertStringEncodingForWindowsPlatformEncodingId1Utf16be(): void
    {
        $instance = $this->buildTrueType('', []);
        // platformId=3, encodingId=1 → default UTF-16BE. "\x00\x43" = 'C'
        $result = $this->convertStringEncoding($instance, "\x00\x43", 3, 1);
        $this->assertSame('C', $result);
    }

    // Verifies decodeNameString takes the Macintosh (platformId 1 / MacRoman) branch and decodes ASCII
    // "\x41" -> "A".
    public function testConvertStringEncodingForMacintoshPlatformAsciiChar(): void
    {
        $instance = $this->buildTrueType('', []);
        // platformId=1 (Macintosh/MacRoman). ASCII 0x41 = 'A' in both MacRoman and UTF-8.
        $result = $this->convertStringEncoding($instance, "\x41", 1, 0);
        $this->assertSame('A', $result);
    }

    // Verifies decodeNameString selects CP936 (GBK) for a Windows-platform name with encodingId 3 and decodes
    // ASCII-compatible "\x41" -> "A".
    public function testConvertStringEncodingForWindowsPlatformCp936(): void
    {
        $instance = $this->buildTrueType('', []);
        // platformId=3, encodingId=3 → CP936.
        // CP936/GBK 0x41 (single-byte ASCII-compatible) = 'A'
        $result = $this->convertStringEncoding($instance, "\x41", 3, 3);
        $this->assertSame('A', $result);
    }
}

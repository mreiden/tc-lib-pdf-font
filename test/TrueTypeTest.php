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
 * @SuppressWarnings("PHPMD.LongVariable")
 */
class TrueTypeTest extends TestUtil
{
    use FontDataTrait;

    /*     * TableDirectory:
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
     *     /**
     * Parse Font Header Table (head) for BBox, units and flags
     *
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
}

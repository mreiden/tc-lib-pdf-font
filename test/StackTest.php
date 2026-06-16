<?php

declare(strict_types=1);

/**
 * StackTest.php
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

use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Stack;
use PHPUnit\Framework\Attributes\Test;
use RangeException;

/**
 * Buffer Test
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
class StackTest extends TestUtil
{
    // End-to-end test of the font stack: insert/clone/pop fonts (TrueType, Type1),
    // and verify metrics, char widths/bboxes, char replacement, text measurement, and family-name resolution.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testStack(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(0.75, true, true, true);

        new Import($indir . 'freefont/FreeSans.ttf');
        $cfont = $stack->insert($objnum, 'freesans', '', 12, -0.1, 0.9, '', null);
        $this->assertNotEmpty($cfont);
        $this->assertNotEmpty($cfont['cbbox']);

        $this->assertEqualsWithDelta([0.162, 0.0, 7.0308, 8.748], $stack->getCharBBox(65), 0.0001);

        new Import($indir . 'pdfa/pfb/PDFATimes.pfb');
        $afont = $stack->insert($objnum, 'times', '', 14, 0.3, 1.2, '', null);
        $this->assertNotEmpty($afont);

        new Import($indir . 'pdfa/pfb/PDFAHelveticaBoldOblique.pfb');
        $bfont = $stack->insert($objnum, 'helvetica', 'BIUDO', null, null, null, '', null);
        $this->assertNotEmpty($bfont);

        $this->assertEquals("BT /F3 14.000000 Tf ET\n", $bfont['out']);
        $this->assertEquals('pdfahelveticaBI', $bfont['key']);
        $this->assertEquals('Type1', $bfont['type']);
        $this->assertEqualsWithDelta(14, $bfont['size'], 0.0001);
        $this->assertEqualsWithDelta(0.3, $bfont['spacing'], 0.0001);
        $this->assertEqualsWithDelta(1.2, $bfont['stretching'], 0.0001);
        $this->assertEqualsWithDelta(18.6667, $bfont['usize'], 0.0001);
        $this->assertEqualsWithDelta(0.014, $bfont['cratio'], 0.0001);
        $this->assertEqualsWithDelta(-1.554, $bfont['up'], 0.0001);
        $this->assertEqualsWithDelta(0.966, $bfont['ut'], 0.0001);
        $this->assertEqualsWithDelta(4.6704, $bfont['dw'], 0.0001);
        $this->assertEqualsWithDelta(13.342, $bfont['ascent'], 0.0001);
        $this->assertEqualsWithDelta(-3.08, $bfont['descent'], 0.0001);
        $this->assertEqualsWithDelta(16.422, $bfont['height'], 0.0001);
        $this->assertEqualsWithDelta(5.131, $bfont['midpoint'], 0.0001);
        $this->assertEqualsWithDelta(10.136, $bfont['capheight'], 0.0001);
        $this->assertEqualsWithDelta(7.56, $bfont['xheight'], 0.0001);
        $this->assertEqualsWithDelta(9.492, $bfont['avgwidth'], 0.0001);
        $this->assertEqualsWithDelta(16.8, $bfont['maxwidth'], 0.0001);
        $this->assertEqualsWithDelta(4.6704, $bfont['missingwidth'], 0.0001);
        $this->assertEqualsWithDelta([-1.092, -3.08, 18.5976, 13.342], $bfont['fbbox'], 0.0001);

        $fkey = $stack->getCurrentFontKey();
        $this->assertEquals('pdfahelveticaBI', $fkey);

        $font = $stack->getCurrentFont();
        $this->assertEquals($bfont, $font);

        $this->assertTrue($stack->isCharDefined(65));
        $this->assertFalse($stack->isCharDefined(300));

        $this->assertEquals(75, $stack->replaceChar(65, 75));
        $this->assertEquals(65, $stack->replaceChar(65, 300));

        $this->assertEquals([0, 0, 0, 0], $stack->getCharBBox(300));

        $this->assertEqualsWithDelta(12.1296, $stack->getCharWidth(65), 0.0001);
        $this->assertEqualsWithDelta(0, $stack->getCharWidth(173), 0.0001);
        $this->assertEqualsWithDelta(4.6704, $stack->getCharWidth(300), 0.0001);

        $uniarr = [65, 173, 300];
        $this->assertEqualsWithDelta(17.52, $stack->getOrdArrWidth($uniarr), 0.0001);

        $subs = [
            65 => [400, 75],
            173 => [76, 300],
            300 => [400, 77],
        ];
        $this->assertEquals([65, 173, 77], $stack->replaceMissingChars($uniarr, $subs));

        // A uniarr code not in the font and without a substitute should be left as-is.
        $uniarr = [1806];
        $subs = [];
        $this->assertEquals([1806], $stack->replaceMissingChars($uniarr, $subs));

        $font = $stack->popLastFont();
        $this->assertEquals($bfont, $font);

        $font = $stack->getCurrentFont();
        $this->assertEquals($afont, $font);

        $fkey = $stack->getCurrentFontKey();
        $this->assertEquals('pdfatimes', $fkey);

        $type = $stack->getCurrentFontType();
        $this->assertEquals('Type1', $type);

        $ftype = $stack->isCurrentUnicodeFont();
        $this->assertFalse($ftype);

        $ftype = $stack->isCurrentByteFont();
        $this->assertTrue($ftype);

        $uniarr = [65, 173, 300, 32, 65, 173, 300, 32, 65, 173, 300];
        $widths = $stack->getOrdArrDims($uniarr);
        $this->assertEquals(11, $widths['chars']);
        $this->assertEquals(2, $widths['spaces']);
        $this->assertEqualsWithDelta(60.9384, $widths['totwidth'], 0.0001);
        $this->assertEqualsWithDelta(8.76, $widths['totspacewidth'], 0.0001);
        $this->assertEquals(6, $widths['words']);

        $split = $widths['split'][5] ?? null;
        $this->assertIsArray($split);
        $this->assertEquals(11, $split['pos']);
        $this->assertEquals(8203, $split['ord']);
        $this->assertEquals('BN', $split['septype']);
        $this->assertEqualsWithDelta(4.92, $split['wordwidth'], 0.0001);
        $this->assertEquals(2, $split['spaces']);
        $this->assertEqualsWithDelta(60.9384, $split['totwidth'], 0.0001);
        $this->assertEqualsWithDelta(8.76, $split['totspacewidth'], 0.0001);

        $outfont = $stack->getOutCurrentFont();
        $this->assertEquals("BT /F2 14.000000 Tf ET\n", $outfont);

        $font = $stack->cloneFont($objnum, null, null, 13, 0.3, 0.7);
        $this->assertEquals(13, $font['size']);
        $this->assertEquals(0.3, $font['spacing']);
        $this->assertEquals(0.7, $font['stretching']);

        $font = $stack->cloneFont($objnum, 0, 'BI', 17, 0.7, 1.3);
        $this->assertEquals('BI', $font['style']);
        $this->assertEquals(17, $font['size']);
        $this->assertEquals(0.7, $font['spacing']);
        $this->assertEquals(1.3, $font['stretching']);

        $fname = $stack->getFontFamilyName('unknown');
        $this->assertEquals('freesansBI', $fname);

        new Import($indir . 'pdfa/pfb/PDFACourier.pfb');
        $bfont = $stack->insert($objnum, 'courier', '', null, null, null, '', null);
        $this->assertNotEmpty($bfont);

        $fname = $stack->getFontFamilyName('freesans');
        $this->assertEquals('freesans', $fname);

        $fname = $stack->getFontFamilyName('cursive');
        $this->assertEquals('pdfatimes', $fname);

        $fname = $stack->getFontFamilyName('unknown');
        $this->assertEquals('pdfacourier', $fname);
    }

    // Verifies popLastFont() throws when the stack is empty (no font ever inserted).
    /** @throws FontException */
    #[Test]
    public function testEmptyStack(): void
    {
        $stack = new Stack(1);
        $this->expectException(FontException::class);
        $stack->popLastFont();
    }

    // Verifies insert() throws when the requested font cannot be loaded/found.
    /** @throws FontException */
    #[Test]
    public function testStackMissingFont(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->insert($objnum, 'missing');
    }

    // Verifies cloneFont() rejects a negative font index with a FontException.
    #[Test]
    public function testStackCloneNegativeIndex(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(0.75, true, true, true);

        new Import($indir . 'freefont/FreeSans.ttf');
        $cfont = $stack->insert($objnum, 'freesans', '', 12, -0.1, 0.9, '', null);
        $this->assertNotEmpty($cfont);

        $this->expectException(FontException::class);
        ++$objnum;
        $stack->cloneFont($objnum, -1);
    }

    // Verifies cloneFont() rejects a font index greater than the current top of stack.
    #[Test]
    public function testStackCloneIndexTooBig(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(0.75, true, true, true);

        new Import($indir . 'freefont/FreeSans.ttf');
        $cfont = $stack->insert($objnum, 'freesans', '', 12, -0.1, 0.9, '', null);
        $this->assertNotEmpty($cfont);

        $this->expectException(FontException::class);
        ++$objnum;
        $stack->cloneFont($objnum, 1);
    }

    // Verifies hasCurrentFont()/getStackSize()/getCurrentFontIndex() track stack state correctly
    // as fonts are inserted, cloned, and popped (empty -> populated -> empty).
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testHasCurrentFont(): void
    {
        $this->prepareTestEnvironment();
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(0.75, true, true, true);
        $this->assertFalse($stack->hasCurrentFont());
        $this->assertSame(0, $stack->getStackSize());
        $this->assertSame(-1, $stack->getCurrentFontIndex());

        new \Com\Tecnick\Pdf\Font\Import($indir . 'freefont/FreeSans.ttf');
        $font = $stack->insert($objnum, 'freesans', '', 12);
        $this->assertTrue($stack->hasCurrentFont());
        $this->assertSame(1, $stack->getStackSize());
        $this->assertSame(0, $stack->getCurrentFontIndex());
        $this->assertSame($font['out'], $stack->getOutCurrentFont());

        $stack->cloneFont($objnum, null, null, 13);
        $this->assertSame(2, $stack->getStackSize());
        $this->assertSame(1, $stack->getCurrentFontIndex());

        $stack->popLastFont();
        $this->assertTrue($stack->hasCurrentFont());
        $this->assertSame(1, $stack->getStackSize());
        $this->assertSame(0, $stack->getCurrentFontIndex());

        $stack->popLastFont();
        $this->assertFalse($stack->hasCurrentFont());
        $this->assertSame(0, $stack->getStackSize());
        $this->assertSame(-1, $stack->getCurrentFontIndex());
    }

    // Verifies measuring text via getOrdArrDims() registers non-Latin BMP codepoints (pi, almost-equal)
    // in the font's subsetchars, so they get embedded when the font is subset.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testUnicodeOrdAddedToSubsetChars(): void
    {
        $this->prepareTestEnvironment();
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';
        $objnum = 1;

        $stack = new Stack(0.75, true, true, true);
        new \Com\Tecnick\Pdf\Font\Import($indir . 'freefont/FreeSans.ttf');
        $stack->insert($objnum, 'freesans', '', 12, 0, 1, '', true);

        // Use pi and almost-equal to ensure non-latin BMP code points are tracked.
        $stack->getOrdArrDims([960, 8776]);

        $fonts = $stack->getFonts();
        $fkey = $stack->getCurrentFontKey();
        $currentFont = $fonts[$fkey] ?? null;
        $this->assertIsArray($currentFont);
        $this->assertArrayHasKey(960, $currentFont['subsetchars']);
        $this->assertArrayHasKey(8776, $currentFont['subsetchars']);
    }

    // Verifies fractional font sizes (10.5, 11.25) are preserved through insert()/cloneFont()
    // and rendered correctly into the PDF font-selection 'out' string.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testFractionalFontSize(): void
    {
        $this->prepareTestEnvironment();
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(0.75, true, true, true);

        new \Com\Tecnick\Pdf\Font\Import($indir . 'freefont/FreeSans.ttf');
        $font = $stack->insert($objnum, 'freesans', '', 10.5);

        $this->assertEqualsWithDelta(10.5, $font['size'], 0.0001);
        $this->assertEquals("BT /F1 10.500000 Tf ET\n", $font['out']);

        $clone = $stack->cloneFont($objnum, null, null, 11.25);

        $this->assertEqualsWithDelta(11.25, $clone['size'], 0.0001);
        $this->assertEquals("BT /F1 11.250000 Tf ET\n", $clone['out']);
    }

    // Verifies cloneFont() rejects an out-of-range index (99) when only one font is on the stack.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testCloneFontRejectsOutOfRangeIndex(): void
    {
        $this->prepareTestEnvironment();
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';
        $objnum = 1;

        $stack = new Stack(1);
        new \Com\Tecnick\Pdf\Font\Import($indir . 'core/Helvetica.afm');
        $stack->insert($objnum, 'helvetica');

        $this->expectException(\Com\Tecnick\Pdf\Font\Exception::class);
        $stack->cloneFont($objnum, 99);
    }

    // Verifies replaceMissingChars() leaves a char untouched when no substitutes are supplied,
    // even if that char is undefined in the font.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     */
    #[Test]
    public function testReplaceMissingCharsKeepsOriginalWhenNoSubstitutesProvided(): void
    {
        $this->prepareTestEnvironment();
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';
        $objnum = 1;

        $stack = new Stack(1);
        new \Com\Tecnick\Pdf\Font\Import($indir . 'core/Helvetica.afm');
        $stack->insert($objnum, 'helvetica');

        $this->assertSame([400], $stack->replaceMissingChars([400], []));
    }

    // Verifies getFontFamilyName() throws on an empty family-name string.
    /** @throws FontException */
    #[Test]
    public function testGetFontFamilyNameRejectsEmptyString(): void
    {
        $this->prepareTestEnvironment();
        $stack = new Stack(1);

        $this->expectException(\Com\Tecnick\Pdf\Font\Exception::class);
        $stack->getFontFamilyName('');
    }

    // Verifies getCharWidth() throws when no current font is set (empty stack -> invalid index).
    /** @throws FontException */
    #[Test]
    public function testGetCharWidthFailsWithoutCurrentFont(): void
    {
        $this->prepareTestEnvironment();
        $stack = new Stack(1);

        $this->expectException(\Com\Tecnick\Pdf\Font\Exception::class);
        $stack->getCharWidth(65);
    }

    // Verifies a malformed cbbox entry (only 3 of 4 coords) is ignored, so getCharBBox()
    // falls back to the zero box instead of producing a broken bounding box.
    /** @throws FontException */
    #[Test]
    public function testMalformedCharBoxDataIsIgnored(): void
    {
        $objnum = 1;
        $stack = new Stack(1);

        \file_put_contents(
            $this->getFontPath() . 'badbbox.json',
            '{"type":"Type1","desc":{"FontBBox":"[0 0 0 0]"},"cw":{"65":400},"cbbox":{"65":[1,2,3]}}',
        );

        $stack->insert($objnum, 'badbbox', '', null, null, null, $this->getFontPath() . 'badbbox.json', null);
        $this->assertSame([0.0, 0.0, 0.0, 0.0], $stack->getCharBBox(65));
    }
}

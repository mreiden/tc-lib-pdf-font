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

use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Stack;
use PHPUnit\Framework\Attributes\Test;

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

        $this->assertEquals(11, $widths['split'][5]['pos']);
        $this->assertEquals(8203, $widths['split'][5]['ord']);
        $this->assertEquals('BN', $widths['split'][5]['septype']);
        $this->assertEqualsWithDelta(4.92, $widths['split'][5]['wordwidth'], 0.0001);
        $this->assertEquals(2, $widths['split'][5]['spaces']);
        $this->assertEqualsWithDelta(60.9384, $widths['split'][5]['totwidth'], 0.0001);
        $this->assertEqualsWithDelta(8.76, $widths['split'][5]['totspacewidth'], 0.0001);

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

    #[Test]
    public function testEmptyStack(): void
    {
        $stack = new Stack(1);
        $this->expectException(FontException::class);
        $stack->popLastFont();
    }

    #[Test]
    public function testStackMissingFont(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->insert($objnum, 'missing');
    }

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
        $objnum++;
        $stack->cloneFont($objnum, -1);
    }

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
        $objnum++;
        $stack->cloneFont($objnum, 1);
    }
}

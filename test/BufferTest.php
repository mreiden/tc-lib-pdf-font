<?php

declare(strict_types=1);

/**
 * BufferTest.php
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
class BufferTest extends TestUtil
{
    #[Test]
    public function testStackDefaultSubsetMode(): void
    {
        // Default should be false
        $stack = new Stack(1);
        $this->assertSame(false, $stack->isSubsetMode());

        // Should match parameter given
        $stack = new Stack(1, subset: false);
        $this->assertSame(false, $stack->isSubsetMode());
        $stack = new Stack(1, subset: true);
        $this->assertSame(true, $stack->isSubsetMode());
    }

    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    #[Test]
    public function testAddSubsetCharOnMissingFontThrows(): void
    {
        $this->bcExpectException(\Com\Tecnick\Pdf\Font\Exception::class);
        $this->setupTest();
        $stack = new \Com\Tecnick\Pdf\Font\Stack(1);
        $stack->addSubsetChar('missing', 65);
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackMissingKey(): void
    {
        $stack = new Stack(1);
        $this->expectException(FontException::class);
        $stack->getFont('missing');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackMissingFontName(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, '');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackIFileMissing(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, 'something', '', '/missing/nothere.json');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackIFileNotJson(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, 'something', '', __DIR__ . '/StackTest.php');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackIFileWrongFormat(): void
    {
        \file_put_contents($this->getFontPath() . 'badformat.json', '{"bad":"format"}');

        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, 'something', '', $this->getFontPath() . 'badformat.json');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadFileDoubleDots(): void
    {
        $stack = new Stack(1);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', $this->getFontPath() . '../test.json');
    }

    #[Test]
    public function testLoadFileForbiddenProtocol(): void
    {
        $stack = new Stack(1);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', 'gopher://test.json');
    }

    #[Test]
    public function testLoadFileProtocol(): void
    {
        $filepath = $this->getFontPath() . 'test.json';
        \file_put_contents($filepath, '{"type":"Type1","cw":{"0":100}}');
        $filepath = \realpath($filepath);
        if ($filepath === false) {
            throw new \Exception('Failed to read test file: ' . $filepath);
        }
        $filepath = \str_replace('\\', '/', $filepath);

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', 'file://' . $filepath);
        $font = $stack->getFont('test');
        $this->assertNotEmpty($font);
    }

    #[Test]
    public function testLoadFileProtocolCaseInsensitive(): void
    {
        $filepath = $this->getFontPath() . 'test.json';
        \file_put_contents($filepath, '{"type":"Type1","cw":{"0":100}}');
        $filepath = \realpath($filepath);
        if ($filepath === false) {
            throw new \Exception('Failed to read test file: ' . $filepath);
        }
        $filepath = \str_replace('\\', '/', $filepath);

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', 'FiLe://' . $filepath);
        $font = $stack->getFont('test');
        $this->assertNotEmpty($font);
    }

    #[Test]
    public function testLoadDefaultWidthA(): void
    {
        \file_put_contents($this->getFontPath() . 'test.json', '{"type":"Type1","cw":{"0":100}}');

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json');
        $font = $stack->getFont('test');
        $this->assertEquals(600, $font['dw']);
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadDefaultWidthB(): void
    {
        \file_put_contents($this->getFontPath() . 'test.json', '{"type":"Type1","cw":{"32":123}}');

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json');
        $font = $stack->getFont('test');
        $this->assertEquals(123, $font['dw']);
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadDefaultWidthC(): void
    {
        \file_put_contents(
            $this->getFontPath() . 'test.json',
            '{"type":"Type1","desc":{"MissingWidth":234},"cw":{"0":600}}',
        );

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json');
        $font = $stack->getFont('test');
        $this->assertEquals(234, $font['dw']);
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadWrongType(): void
    {
        \file_put_contents($this->getFontPath() . 'test.json', '{"type":"WRONG","cw":{"0":600}}');

        $stack = new Stack(1);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json');
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadCidOnPdfa(): void
    {
        \file_put_contents($this->getFontPath() . 'test.json', '{"type":"cidfont0","cw":{"0":600}}');

        $stack = new Stack(1, false, true, true);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json', false);
    }

    #[Test]
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadArtificialStyles(): void
    {
        \file_put_contents(
            $this->getFontPath() . 'test.json',
            '{"fakestyle":true,"type":"Core","cw":{"0":600},"mode":{"bold":true,"italic":true}}',
        );

        $stack = new Stack(1);
        $objnum = 1;
        $key = $stack->add($objnum, 'symbol', '', $this->getFontPath() . 'test.json');
        $this->assertNotEmpty($key);
    }

    /**
     * @throws \Com\Tecnick\File\Exception
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \RangeException
     */
    #[Test]
    public function testBuffer(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(1, false, true, false);

        new Import($indir . 'pdfa/pfb/PDFASymbol.pfb', '', 'Type1', 'symbol');
        $stack->add($objnum, 'pdfasymbol');

        new Import($indir . 'core/Helvetica.afm');
        $stack->add($objnum, 'helvetica');

        new Import($indir . 'core/Helvetica-Bold.afm');
        $stack->add($objnum, 'helvetica', 'B');

        new Import($indir . 'core/Helvetica-BoldOblique.afm');
        $stack->add($objnum, 'helveticaBI');

        new Import($indir . 'core/Helvetica-Oblique.afm');
        $stack->add($objnum, 'helvetica', 'I');

        new Import($indir . 'freefont/FreeSans.ttf');
        $stack->add($objnum, 'freesans', '');

        new Import($indir . 'freefont/FreeSansBold.ttf');
        $stack->add($objnum, 'freesans', 'B');

        new Import($indir . 'freefont/FreeSansOblique.ttf');
        $stack->add($objnum, 'freesans', 'I');

        new Import($indir . 'freefont/FreeSansBoldOblique.ttf');
        $stack->add($objnum, 'freesans', 'BIUDO', '', true);

        $fontkey = $stack->add($objnum, 'freesans', 'BI', '', true);
        $this->assertEquals('freesansBI', $fontkey);

        $this->assertEquals(10, $objnum);
        $this->assertCount(9, $stack->getFonts());
        $this->assertCount(1, $stack->getEncDiffs());

        $font = $stack->getFont('freesansB');
        $this->assertNotEmpty($font);
        $this->assertEquals('FreeSansBold', $font['name']);
        $this->assertEquals('TrueTypeUnicode', $font['type']);

        new Import($indir . 'core/ZapfDingbats.afm');
        $stack->add($objnum, 'zapfdingbats', 'BIUDO');
        $font = $stack->getFont('zapfdingbats');
        $this->assertNotEmpty($font);
    }

    #[Test]
    /**
     * @throws \Com\Tecnick\File\Exception
     * @throws \Com\Tecnick\Pdf\Font\Exception
     * @throws \RangeException
     */
    public function testBufferPdfa(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(1, true, false, true);

        new Import($indir . 'pdfa/pfb/PDFAHelveticaBoldOblique.pfb');
        $stack->add($objnum, 'arial', 'BIUDO', '', true);
        $font = $stack->getFont('pdfahelveticaBI');
        $this->assertNotEmpty($font);
    }

    #[Test]
    public function testSubsetChar(): void
    {
        \file_put_contents($this->getFontPath() . 'test.json', '{"type":"Type1","cw":{"0":100}}');

        $stack = new Stack(1);
        $objnum = 1;
        $stack->add($objnum, 'test', '', $this->getFontPath() . 'test.json');
        $font = $stack->getFont('test');
        $this->assertNotEmpty($font);
        $stack->addSubsetChar($font['key'], \ord('A'));
    }

    #[Test]
    public function testSubsetCharOnNotLoadedFont(): void
    {
        $stack = new Stack(1);

        $this->expectException(FontException::class);
        $stack->addSubsetChar('NotLoadedFont', \ord('A'));
    }
}

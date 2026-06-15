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
    // Verifies isSubsetMode() defaults to false and otherwise reflects the subset flag passed to the constructor.
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

    // Verifies addSubsetChar() throws when the target font has not been loaded into the buffer.
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
    // Verifies getFont() throws when asked for a key that is not present in the buffer.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackMissingKey(): void
    {
        $stack = new Stack(1);
        $this->expectException(FontException::class);
        $stack->getFont('missing');
    }

    #[Test]
    // Verifies add() throws when given an empty font family name.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackMissingFontName(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, '');
    }

    #[Test]
    // Verifies add() throws when the explicitly supplied definition file path does not exist.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackIFileMissing(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, 'something', '', '/missing/nothere.json');
    }

    #[Test]
    // Verifies add() throws when the definition file is not valid JSON (here, a PHP source file).
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testStackIFileNotJson(): void
    {
        $stack = new Stack(1);
        $objnum = 1;
        $this->expectException(FontException::class);
        $stack->add($objnum, 'something', '', __DIR__ . '/StackTest.php');
    }

    #[Test]
    // Verifies add() throws when the JSON parses but lacks required font fields (missing 'type').
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
    // Verifies add() rejects a definition path containing '..' traversal segments (path-traversal guard).
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testLoadFileDoubleDots(): void
    {
        $stack = new Stack(1);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', $this->getFontPath() . '../test.json');
    }

    // Verifies add() rejects a disallowed URL scheme (gopher://) for the definition file.
    #[Test]
    public function testLoadFileForbiddenProtocol(): void
    {
        $stack = new Stack(1);
        $objnum = 1;

        $this->expectException(FontException::class);
        $stack->add($objnum, 'test', '', 'gopher://test.json');
    }

    // Verifies a local font definition loads successfully when referenced via the allowed file:// scheme.
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

    // Verifies the file:// scheme is matched case-insensitively (e.g. 'FiLe://') when loading a definition.
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

    // Verifies default width (dw) falls back to 600 when no MissingWidth and no cw[32] are available.
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
    // Verifies default width (dw) is taken from the space glyph width cw[32] when MissingWidth is absent.
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
    // Verifies default width (dw) prefers desc.MissingWidth over cw when it is greater than zero.
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
    // Verifies add() throws when the definition declares an unrecognized font type.
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
    // Verifies add() throws for a non-embedded CID0 font in PDF/A mode, where all fonts must be embedded.
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
    // Verifies a Core font flagged fakestyle with bold+italic loads successfully and returns a non-empty key.
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
    // End-to-end: imports and adds real Type1/Core/TrueTypeUnicode fonts (incl. style variants and subsets),
    // asserting object numbering, font/encoding-diff counts, and resolved name/type for loaded keys.
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
    // Verifies that in PDF/A mode an embedded core-substitute font ('arial' -> pdfahelvetica) loads
    // under its PDF/A-prefixed key, confirming core fonts are remapped for embedding.
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

    // Verifies addSubsetChar() succeeds (no error) for a character on a font that has been loaded.
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

    // Verifies addSubsetChar() throws when invoked for a font key that was never loaded into the buffer.
    #[Test]
    public function testSubsetCharOnNotLoadedFont(): void
    {
        $stack = new Stack(1);

        $this->expectException(FontException::class);
        $stack->addSubsetChar('NotLoadedFont', \ord('A'));
    }
}

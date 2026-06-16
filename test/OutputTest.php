<?php

declare(strict_types=1);

/**
 * OutputTest.php
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
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Encrypt\Exception as EncryptException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Output;
use Com\Tecnick\Pdf\Font\Stack;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;
use PHPUnit\Framework\Attributes\Test;
use RangeException;
use ReflectionException;

/**
 * Output Test
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
 */
class OutputTest extends TestUtil
{
    use FontDataTrait;

    /** @throws \ReflectionException */
    private function createEncrypt(): Encrypt
    {
        $reflector = new \ReflectionClass(Encrypt::class);
        $encrypt = $reflector->newInstanceWithoutConstructor();

        \assert($encrypt instanceof Encrypt, 'Failed to create Encrypt instance');

        return $encrypt;
    }

    // End-to-end smoke test: feeds one font of every supported flavor (Type1/Core, TrueType,
    // subsetted TrueType-Unicode, CID0) through Output and checks the object count and that the
    // fonts block plus both resource-dictionary forms are non-empty.
    /**
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     * @throws \ReflectionException
     */
    #[Test]
    public function testOutput(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(1);

        new Import($indir . 'pdfa/pfb/PDFASymbol.pfb', type: 'Type1', encoding: 'symbol');
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
        $stack->add($objnum, 'freesans', style: 'BIUDO', subset: true);

        new Import($indir . 'cid0/cid0jp.ttf', type: 'CID0JP');
        $stack->add($objnum, 'cid0jp');

        $fonts = $stack->getFonts();
        $this->assertCount(10, $fonts);

        $encrypt = $this->createEncrypt();
        $output = new Output($fonts, $objnum, $encrypt, null);

        $this->assertEquals(35, $output->getObjectNumber());

        $this->assertNotEmpty($output->getFontsBlock());

        $this->assertNotEmpty($output->getOutFontDict());

        $keys = [];
        foreach ($fonts as $font) {
            $keys[] = $font['key'];
        }

        $this->assertNotEmpty($output->getOutFontDictByKeys($keys));
    }

    // Verifies the empty-font-array edge case: the constructor runs without error and every
    // output accessor returns '' since there is nothing to iterate over.
    /**
     * @throws FileException
     * @throws FontException
     * @throws \ReflectionException
     */
    #[Test]
    public function testOutputWithNoFontsReturnsEmptyStrings(): void
    {
        // Empty font array: constructor still runs without error; all output methods
        // return empty strings because there is nothing to iterate over.
        $encrypt = $this->createEncrypt();
        $output = new Output([], 1, $encrypt, null);

        $this->assertSame('', $output->getFontsBlock());
        $this->assertSame('', $output->getOutFontDict());
        $this->assertSame('', $output->getOutFontDictByKeys([]));
    }

    // Verifies getFontDefinitions() rejects an unrecognized font type via the match default
    // branch, throwing FontException rather than silently emitting a malformed object.
    /**
     * @throws FileException
     * @throws FontException
     * @throws \ReflectionException
     */
    #[Test]
    public function testOutputGetFontDefinitionsThrowsOnUnknownFontType(): void
    {
        // A font entry with an unrecognised type triggers the default branch of the
        // match expression inside getFontDefinitions, which throws FontException.
        $this->expectException(FontException::class);

        $encrypt = $this->createEncrypt();

        // Build a minimal font array with an unknown type so that getFontDefinitions
        // reaches the default throw branch.
        $fonts = ['unknown_key' => $this->getFontTemplate()];
        $fonts['unknown_key']['type'] = 'UnknownType';

        new Output($fonts, 1, $encrypt, null);
    }

    // Verifies a subsetted TrueType-Unicode font emits a valid CIDSystemInfo (Adobe/Identity/0),
    // not empty Registry/Ordering, and that the embedded font stream carries a real /Length1 (>1000
    // bytes) so a non-trivial subset glyph program is actually written.
    /**
     * @throws EncryptException
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     * @throws ReflectionException
     */
    #[Test]
    public function testSubsetTrueTypeUnicodeOutputUsesValidCidSystemInfoAndFontStream(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(1);
        new Import($indir . 'freefont/FreeSans.ttf');
        $stack->add($objnum, 'freesans', '', '', true);

        // Ensure at least a few glyphs are included in the subset.
        foreach ([32, 65, 66, 67, 937, 960] as $ord) {
            $stack->addSubsetChar('freesans', $ord);
        }

        $encrypt = $this->createEncrypt();
        $output = new Output($stack->getFonts(), $objnum, $encrypt, null);
        $out = $output->getFontsBlock();

        $this->assertStringNotContainsString('/Registry () /Ordering ()', $out);
        $this->assertStringContainsString('/Registry (Adobe) /Ordering (Identity) /Supplement 0', $out);

        $matches = [];
        \preg_match_all('/\\/Length1\\s+(\\d+)/', $out, $matches);
        $lengthMatches = $matches[1] ?? [];
        $lengths = \array_map('intval', $lengthMatches);
        $this->assertNotEmpty($lengths);
        $this->assertGreaterThan(1000, \max($lengths));
    }

    // Verifies that when two fonts share the same file, their subsetchars are unioned (not
    // array_merge'd) so the integer Unicode keys survive, and that chars flagged 0/false are
    // dropped by array_filter. Inspects the protected $subchars map via reflection.
    /**
     * @throws EncryptException
     * @throws FileException
     * @throws FontException
     * @throws RangeException
     * @throws ReflectionException
     */
    #[Test]
    public function testSubsetCharMergePreservesUnicodeKeys(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        $objnum = 1;
        $stack = new Stack(1);
        new Import($indir . 'freefont/FreeSans.ttf');
        $stack->add($objnum, 'freesans', '', '', true);

        $fonts = $stack->getFonts();
        if (!isset($fonts['freesans'])) {
            $this->fail('Expected freesans font data');
        }

        $base = $fonts['freesans'];
        $base['key'] = 'freesans_dup';
        $base['i'] += 1000;
        $base['n'] += 1000;
        // subsetchars maps char code -> new subset glyph id (>= 1); 0 means notdef/unused.
        $base['subsetchars'] = [8776 => 2, 9999 => 0];
        $primary = $fonts['freesans'];
        $primary['subsetchars'] = [960 => 1];

        $fonts = \array_replace($fonts, [
            'freesans' => $primary,
            'freesans_dup' => $base,
        ]);

        $encrypt = $this->createEncrypt();
        $output = new Output($fonts, $objnum, $encrypt, null);

        $ref = new \ReflectionClass($output);
        $prop = $ref->getProperty('subchars');
        /** @var array<int, array<int, bool>> $subchars */
        $subchars = $prop->getValue($output);

        $this->assertIsArray($subchars);
        $this->assertNotEmpty($subchars);
        $first = \array_values($subchars)[0] ?? null;
        $this->assertIsArray($first);
        $this->assertArrayHasKey(960, $first);
        $this->assertArrayHasKey(8776, $first);
        $this->assertArrayNotHasKey(9999, $first);
    }

    // Verifies uniToCid() remaps char widths from Unicode code points to their mapped CID keys
    // (via cidinfo['uni2cid']), keeping the numeric CID keys and the original widths intact.
    #[Test]
    public function testUniToCidPreservesNumericCidKeys(): void
    {
        $outfont = new OutputTestOutFont();

        $font = $this->getFontTemplate();
        $font['cidinfo'] = [
            'Ordering' => 'Identity',
            'Registry' => 'Adobe',
            'Supplement' => 0,
            'uni2cid' => [960 => 853, 8776 => 3283],
        ];
        $font['cw'] = [32 => 250, 960 => 500, 8776 => 600];
        $font['i'] = 1;
        $font['n'] = 1;
        $font['name'] = 'test';
        $font['subset'] = true;

        $outfont->runUniToCid($font, 0);

        $this->assertArrayHasKey(853, $font['cw']);
        $this->assertArrayHasKey(3283, $font['cw']);
        $this->assertSame(500, $font['cw'][853] ?? null);
        $this->assertSame(600, $font['cw'][3283] ?? null);
    }

    // Verifies getFontFullPath() throws FontException when the named file is not found in any of
    // its search directories, rather than returning a bogus path.
    /** @throws FontException */
    #[Test]
    public function testGetFontFullPathThrowsForMissingFile(): void
    {
        $outfont = new OutputTestOutFont();
        $this->expectException(FontException::class);
        $outfont->runGetFontFullPath($this->getFontPath(), 'not-here.bin');
    }

    // Verifies that subsetting a font whose file data is not gzip-compressed fails: getFontFiles()
    // runs Compression::uncompress() on subset fonts, and bad data surfaces as a FontException.
    /**
     * @throws EncryptException
     * @throws FileException
     * @throws FontException
     * @throws ReflectionException
     */
    #[Test]
    public function testOutputRejectsSubsetFromPlainFileData(): void
    {
        $tmpfile = $this->getFontPath() . 'plain-font.bin';
        \file_put_contents($tmpfile, 'not-gzip-data');

        $font = $this->getFontTemplate();
        $font['key'] = 'plain';
        $font['name'] = 'Plain';
        $font['i'] = 1;
        $font['n'] = 1;
        $font['file'] = 'plain-font.bin';
        $font['dir'] = $this->getFontPath();
        $font['subset'] = true;
        $font['subsetchars'] = [65 => true];

        $encrypt = $this->createEncrypt();
        \set_error_handler(static fn(): bool => true);
        try {
            $this->expectException(FontException::class);
            new Output(['plain' => $font], 1, $encrypt, null);
        } finally {
            \restore_error_handler();
        }
    }

    // Verifies a non-subset TrueType simple font emits /Subtype/TrueType and falls back to
    // /Encoding/WinAnsiEncoding when no encoding Differences object is present.
    /**
     * @throws EncryptException
     * @throws FileException
     * @throws FontException
     * @throws ReflectionException
     */
    #[Test]
    public function testOutputBuildsTrueTypeDefinitionWithDefaultEncoding(): void
    {
        $font = $this->getFontTemplate();
        $font['key'] = 'truetypefont';
        $font['name'] = 'TrueTypeFont';
        $font['type'] = 'TrueType';
        $font['i'] = 1;
        $font['n'] = 1;
        $font['enc'] = 'cp1252';
        $font['dw'] = 600;
        $font['cw'] = [32 => 250, 65 => 700];

        $encrypt = $this->createEncrypt();
        $output = new Output(['truetypefont' => $font], 1, $encrypt, null);
        $block = $output->getFontsBlock();

        $this->assertStringContainsString('/Subtype/TrueType', $block);
        $this->assertStringContainsString('/Encoding/WinAnsiEncoding', $block);
    }

    // Verifies getKeyValOut() renders a float value with %F (fixed 6 decimals, locale-independent),
    // e.g. ItalicAngle 12.5 -> ' /ItalicAngle 12.500000'.
    #[Test]
    public function testGetKeyValOutFormatsFloatValues(): void
    {
        $outfont = new OutputTestOutFont();
        $out = $outfont->runGetKeyValOut('ItalicAngle', 12.5);
        $this->assertSame(' /ItalicAngle 12.500000', $out);
    }

    // Verifies a CIDFont0 font with no width for glyph 1 (which triggers getCid0's cidoffset=31
    // path) still emits the expected Type0 wrapper and CIDFontType0 descendant subtypes.
    /**
     * @throws FileException
     * @throws FontException
     * @throws \ReflectionException
     */
    #[Test]
    public function testOutputBuildsCidFont0WhenGlyphOneIsNotDefined(): void
    {
        $font = $this->getFontTemplate();
        $font['key'] = 'cidfont0';
        $font['name'] = 'CIDFont0Test';
        $font['type'] = 'CIDFont0';
        $font['i'] = 1;
        $font['n'] = 1;
        $font['enc'] = 'Identity-H';
        $font['dw'] = 600;
        $font['cw'] = [32 => 500, 65 => 700];
        $font['cidinfo'] = [
            'Registry' => 'Adobe',
            'Ordering' => 'Identity',
            'Supplement' => 0,
            'uni2cid' => [],
        ];

        $encrypt = $this->createEncrypt();
        $output = new Output(['cidfont0' => $font], 1, $encrypt, null);
        $block = $output->getFontsBlock();

        $this->assertStringContainsString('/Subtype/Type0', $block);
        $this->assertStringContainsString('/Subtype/CIDFontType0', $block);
    }

    // Verifies a font whose char-widths array is empty still produces a non-empty fonts block
    // (getCharWidths returns '' on the empty loop without breaking object generation).
    #[Test]
    public function testOutputNoCharWidths(): void
    {
        $indir = \dirname(__DIR__) . '/util/vendor/tecnickcom/tc-font-mirror/';

        new Import($indir . 'freefont/FreeSans.ttf');
        $jsonPath = $this->getFontPath() . 'freesans.json';
        if (!\file_exists($jsonPath)) {
            throw new \Exception('json file not found');
        }
        $json = \file_get_contents($jsonPath);
        if (empty($json)) {
            throw new \Exception('json file empty');
        }
        $json = \json_decode($json, true);

        // Set an empty character widths array
        /** @var TFontData $json */
        $json['cw'] = [];
        \file_put_contents($jsonPath, \json_encode($json));

        $objnum = 1;
        $stack = new Stack(1);
        $stack->add($objnum, 'freesans', subset: false);

        $objnum++;
        $encrypt = new Encrypt();
        $output = new Output($stack->getFonts(), $objnum, $encrypt);

        $this->assertNotEmpty($output->getFontsBlock());
    }

    // Verifies that pointing a font entry at a non-existent file makes Output throw FontException
    // (getFontFiles can't locate/read the file) instead of emitting a broken font object.
    #[Test]
    public function testOutputMissingFontFile(): void
    {
        $testFont = \array_merge($this->fdt, [
            'dir' => __DIR__,
            'file' => \sha1(\random_bytes(1024)) . '.ttf',
            'subsetchars' => [],
        ]);

        $objnum = 1;
        $encrypt = new Encrypt();

        $this->expectException(FontException::class);
        new Output(['NotReal' => $testFont], $objnum, $encrypt);
    }
}

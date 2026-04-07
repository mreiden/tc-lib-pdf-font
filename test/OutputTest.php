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

use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Output;
use Com\Tecnick\Pdf\Font\Stack;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;
use PHPUnit\Framework\Attributes\Test;

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

        $encrypt = new Encrypt();
        $output = new Output($fonts, $objnum, $encrypt);

        //$this->assertEquals(37, $output->getObjectNumber());
        //$this->assertEquals(36, $output->getObjectNumber());
        $this->assertEquals(35, $output->getObjectNumber());

        $this->assertNotEmpty($output->getFontsBlock());

        $this->assertNotEmpty($output->getOutFontDict());

        $keys = [];
        foreach ($fonts as $font) {
            $keys[] = $font['key'];
        }

        $this->assertNotEmpty($output->getOutFontDictByKeys($keys));
    }

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

<?php

declare(strict_types=1);

/**
 * ImportTest.php
 *
 * @since     2026-01-29
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2026-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 */

namespace Test;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Import\TrueType;
use Com\Tecnick\Pdf\Font\Subset;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Import Test
 *
 * @since     2026-01-29
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2026-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontData from \Com\Tecnick\Pdf\Font\Load
 *
 * @ SuppressWarnings("PHPMD.LongVariable")
 */
final class SubsetTest extends TestUtil
{
    use FontDataTrait;

    /**
     * Helper function to load source fonts to test.
     *
     * @param string $filename  The full pathname of a ttf font to load
     *
     * @return array{string, TFontData}
     *
     * @throws FontException
     * @throws FileException
     */
    protected function importTestFont(string $filename): array
    {
        /** @var array<string, array{string, TFontData}> $fonts */
        static $fonts = [];

        if (!empty($fonts[$filename])) {
            return $fonts[$filename];
        }

        $srcFile = __DIR__ . "/../../tc-font-mirror/$filename";
        $srcFile = \realpath($srcFile);
        if (!$srcFile) {
            throw new \Exception("File $srcFile does not exist");
        }

        $outdir = \dirname(__DIR__) . '/target/tmptest/';
        $this->deleteDirectoryRecursively($outdir, createAfterDelete: true);

        $font = new Import($srcFile, $outdir);
        $fdt = $font->getFontMetrics();

        $strFont = \file_get_contents($srcFile);
        if (!$strFont) {
            throw new \Exception("Cannot read font file: $srcFile");
        }
        $fonts[$filename] = [$strFont, $fdt];

        return $fonts[$filename];
    }

    #[Test]
    #[DataProvider('subsetDataProvider')]
    public function testChecksums(string $fontdir, string $font): void
    {
        // Import the test font
        $srcFile = "$fontdir/$font";
        [$strFont, $fdt] = $this->importTestFont($srcFile);

        // Byte reader for test font
        $fbyte = new Byte($strFont);

        // Verify TTF table checksums from the source file match our calculation
        foreach ($fdt['table'] as $table => $vals) {
            $data = \substr($strFont, $vals['offset'], $vals['length']);
            if ($table === 'head') {
                $data = substr_replace($data, "\0\0\0\0", 8, 4);
            }

            $checksum = Subset::getTableChecksum($data);
            $this->assertSame(
                $vals['checkSum'],
                $checksum,
                "Checksum for table $table does not match value stored in file",
            );
        }

        // Verify the TTF 'head' table checksumAdjustment field
        $adjustFieldFromFile = $fbyte->getULong($fdt['table']['head']['offset'] + 8);
        $checksumFontFile = Subset::getTableChecksum(
            \substr_replace($strFont, "\0\0\0\0", $fdt['table']['head']['offset'] + 8, 4),
        );
        // Bitwise AND to use only the rightmost 32 bits in comparison
        $checksumAdjustment = (0xb1b0afba - $checksumFontFile) & 0xffffffff;

        // Verify the checksum adjustment from the source file binary matches the one calculated
        $this->assertSame(
            $adjustFieldFromFile,
            $checksumAdjustment,
            'Checksum adjustment does not match value in TTF file',
        );
    }

    /**
     * @return array<array<string>>
     */
    public static function subsetDataProvider(): array
    {
        return [
            ['freefont', 'FreeMonoBoldOblique.ttf'],
            ['freefont', 'FreeMonoBold.ttf'],
            ['freefont', 'FreeMonoOblique.ttf'],
            ['freefont', 'FreeMono.ttf'],
            ['freefont', 'FreeSansBoldOblique.ttf'],
            ['freefont', 'FreeSansBold.ttf'],
            ['freefont', 'FreeSansOblique.ttf'],
            ['freefont', 'FreeSans.ttf'],
            ['freefont', 'FreeSerifBoldItalic.ttf'],
            ['freefont', 'FreeSerifBold.ttf'],
            ['freefont', 'FreeSerifItalic.ttf'],
            ['freefont', 'FreeSerif.ttf'],
            ['unifont', 'unifont.ttf'],
            ['dejavu/ttf', 'DejaVuSans.ttf'],
            ['dejavu/ttf', 'DejaVuSans-BoldOblique.ttf'],
            ['dejavu/ttf', 'DejaVuSans-Bold.ttf'],
            ['dejavu/ttf', 'DejaVuSans-Oblique.ttf'],
            ['dejavu/ttf', 'DejaVuSansCondensed.ttf'],
            ['dejavu/ttf', 'DejaVuSansCondensed-BoldOblique.ttf'],
            ['dejavu/ttf', 'DejaVuSansCondensed-Bold.ttf'],
            ['dejavu/ttf', 'DejaVuSansCondensed-Oblique.ttf'],
            ['dejavu/ttf', 'DejaVuSansMono.ttf'],
            ['dejavu/ttf', 'DejaVuSansMono-BoldOblique.ttf'],
            ['dejavu/ttf', 'DejaVuSansMono-Bold.ttf'],
            ['dejavu/ttf', 'DejaVuSansMono-Oblique.ttf'],
            ['dejavu/ttf', 'DejaVuSans-ExtraLight.ttf'],
            ['dejavu/ttf', 'DejaVuSerif.ttf'],
            ['dejavu/ttf', 'DejaVuSerif-BoldItalic.ttf'],
            ['dejavu/ttf', 'DejaVuSerif-Bold.ttf'],
            ['dejavu/ttf', 'DejaVuSerif-Italic.ttf'],
            ['dejavu/ttf', 'DejaVuSerifCondensed.ttf'],
            ['dejavu/ttf', 'DejaVuSerifCondensed-BoldItalic.ttf'],
            ['dejavu/ttf', 'DejaVuSerifCondensed-Bold.ttf'],
            ['dejavu/ttf', 'DejaVuSerifCondensed-Italic.ttf'],
        ];
    }

    #[Test]
    #[DataProvider('subsetDataProvider')]
    public function testSubset(string $fontdir, string $font): void
    {
        // Import the test font
        $srcFile = "$fontdir/$font";
        [$strFont, $fdt] = $this->importTestFont($srcFile);

        $subsetCharacters = \array_flip([
            // 0: Missing Glyph (.notdef) -- By definition, this must be the first glyph
            0,
            // 121: LATIN SMALL LETTER Y (Y)
            \ord('y'),
            // 32: SPACE
            \ord(' '),
            // 391: LATIN CAPITAL LETTER C WITH HOOK (Ƈ)
            \mb_ord('Ƈ', 'UTF-8'),
            // 404: LATIN CAPITAL LETTER GAMMA (Ɣ)
            \mb_ord('Ɣ', 'UTF-8'),
            // 77: LATIN CAPITAL LETTER M (M)
            \ord('M'),
        ]);
        //var_dump($subsetCharacters);
        //exit();

        //Subset::enableDebug(true);
        //Subset::$saveSubsetFile = true;
        $fdt['subset'] = true;
        $subset = new Subset($strFont, $fdt, $subsetCharacters);

        $subsetFdt = $subset->getFontData();
        $subsetStrFont = $subset->getSubsetFont();
        $subsetFbyte = new Byte($subsetStrFont);

        // Use the TrueType class to get information about the base font.
        //$trueType = new TrueType($strFont, $subsetFdt, $fbyte, $subsetCharacters);
        //$subsetFdt = $trueType->getFontMetrics();

        // Calculate each table's checksum from the sa
        foreach ($subsetFdt['table'] as $table => $vals) {
            $data = \substr($subsetStrFont, $vals['offset'], $vals['length']);
            if ($table === 'head') {
                $data = substr_replace($data, "\0\0\0\0", 8, 4);
            }
            $checksum = Subset::getTableChecksum($data);

            $this->assertSame(
                $vals['checkSum'],
                $checksum,
                "Checksum for table $table does not match value stored in subsetted file",
            );
        }

        // Verify the TTF 'head' table checksumAdjustment field
        $adjustFieldFromFile = $subsetFbyte->getULong($subsetFdt['table']['head']['offset'] + 8);
        $checksumFontFile = Subset::getTableChecksum(
            \substr_replace($subsetStrFont, "\0\0\0\0", $subsetFdt['table']['head']['offset'] + 8, 4),
        );
        // Bitwise AND to use only the rightmost 32 bits in comparison
        $checksumAdjustment = (0xb1b0afba - $checksumFontFile) & 0xffffffff;

        // Verify the checksum adjustment from the source file binary matches the one calculated
        $this->assertSame(
            $adjustFieldFromFile,
            $checksumAdjustment,
            'Checksum adjustment does not match value in TTF file',
        );
    }
}

<?php

declare(strict_types=1);

/**
 * SubsetTest.php
 *
 * @since     2026-05-01
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
 * Subset Test
 *
 * @since     2026-05-01
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontData from \Com\Tecnick\Pdf\Font\Load
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

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setValue($obj, $value);
    }

    /** @return array<array-key, mixed> */
    private function getDefaultFdt(): array
    {
        $ref = new \ReflectionClass(\Com\Tecnick\Pdf\Font\Subset::class);
        $prop = $ref->getProperty('fdt');
        $instance = $ref->newInstanceWithoutConstructor();

        return (static fn(mixed $value): array => \is_array($value) ? $value : [])($prop->getValue($instance));
    }

    /**
     * @param array<array-key, mixed> $table
     *
     * @return array<array-key, mixed>
     */
    private function getTableRecord(array $table, string $tag): array
    {
        if (!isset($table[$tag]) || !\is_array($table[$tag])) {
            $this->fail('Missing or invalid table record: ' . $tag);
        }

        return $table[$tag];
    }

    /** @param array<array-key, mixed> $table */
    private function getTableRecordString(array $table, string $tag, string $field): string
    {
        $record = $this->getTableRecord($table, $tag);
        if (!isset($record[$field]) || !\is_string($record[$field])) {
            $this->fail('Missing or invalid table string field: ' . $tag . '.' . $field);
        }

        return $record[$field];
    }

    /** @param array<array-key, mixed> $table */
    private function getTableRecordInt(array $table, string $tag, string $field): int
    {
        $record = $this->getTableRecord($table, $tag);
        if (!isset($record[$field]) || !\is_int($record[$field])) {
            $this->fail('Missing or invalid table int field: ' . $tag . '.' . $field);
        }

        return $record[$field];
    }

    // Verifies getTableChecksum() zero-pads a length-3 table to a 4-byte word before summing, so partial trailing words checksum correctly.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    #[Test]
    public function testTableChecksumPadsTrailingBytes(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            /** @throws \Com\Tecnick\Pdf\Font\Exception */
            public function checksum(string $table, int $length): int
            {
                return $this->getTableChecksum($table, $length);
            }
        };

        // 3-byte table must be zero-padded to a 4-byte word before summing.
        $this->assertSame(0x01020300, $subset->checksum("\x01\x02\x03", 3));
    }

    // Verifies getTableChecksum() sums a full 32-bit word plus a zero-padded partial word and wraps modulo 2^32.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    #[Test]
    public function testTableChecksumHandlesMixedFullAndPartialWords(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            /** @throws \Com\Tecnick\Pdf\Font\Exception */
            public function checksum(string $table, int $length): int
            {
                return $this->getTableChecksum($table, $length);
            }
        };

        $table = "\x11\x22\x33\x44\x55\x66\x77";
        // 0x11223344 + 0x55667700, modulo 2^32
        $this->assertSame(0x6688AA44, $subset->checksum($table, 7));
    }

    // -------------------------------------------------------------------------
    // removeUnusedTables
    // -------------------------------------------------------------------------

    // Verifies removeUnusedTables() drops tags absent from the keep-list ('xxxx') while retaining required tables ('head').
    #[Test]
    public function testRemoveUnusedTablesDropsUnknownTableTags(): void
    {
        // Build an anonymous subclass that exposes removeUnusedTables and lets us
        // inspect the resulting fdt without running the full constructor chain.
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            public function run(): void
            {
                $this->removeUnusedTables();
            }

            /** @return array<string, mixed> */
            public function getTable(): array
            {
                return $this->fdt['table'];
            }
        };

        // A font binary with 8 bytes of head data (for substr to not be empty)
        $font = str_repeat("\x00", 64);
        $this->setProp($subset, 'font', $font);

        // Provide two tables: 'head' (known → kept) and 'xxxx' (unknown → removed)
        $this->setProp($subset, 'fdt', array_merge($this->getDefaultFdt(), [
            'table' => [
                'head' => ['offset' => 0, 'length' => 8, 'checkSum' => 0, 'data' => ''],
                'xxxx' => ['offset' => 0, 'length' => 8, 'checkSum' => 0, 'data' => ''],
            ],
        ]));

        $subset->run();

        $table = $subset->getTable();
        $this->assertArrayHasKey('head', $table);
        $this->assertArrayNotHasKey('xxxx', $table);
    }

    // -------------------------------------------------------------------------
    // addProcessedTables
    // -------------------------------------------------------------------------

    // Verifies addProcessedTables() rebuilds the loca and glyf tables (with non-zero checksums) by extracting the subset glyph's bytes.
    #[Test]
    public function testAddProcessedTablesBuildsLocaAndGlyfFromSubsetGlyphs(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            public function run(): void
            {
                $this->addProcessedTables();
                // Table length and checksums are calculated after unused tables are dropped
                $this->removeUnusedTables();
            }

            /** @return array<string, mixed> */
            public function getTable(): array
            {
                return $this->fdt['table'];
            }
        };

        // Font data: 12 bytes (glyf table at offset 0, 12 bytes of raw glyph data)
        $font = str_repeat("\xAB", 12);
        $this->setProp($subset, 'font', $font);

        // subglyphs: only glyph 0 is in the subset
        $this->setProp($subset, 'subglyphs', [0 => 0]);

        // Inject the fdt state the method needs
        $this->setProp($subset, 'fdt', array_replace_recursive($this->getDefaultFdt(), [
            'tot_num_glyphs' => 2,
            'short_offset' => false, // long (Offset32) loca entries
            'indexToLoc' => [0 => 0, 1 => 8], // glyph 0 is 8 bytes
            'numHMetrics' => 1,
            'tableSubset' => ['hmtx' => ['hMetrics' => [[100, 0]]]],
            'table' => [
                'glyf' => ['offset' => 0, 'length' => 12, 'checkSum' => 0, 'data' => ''],
                'hmtx' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'loca' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'head' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'hhea' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'maxp' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
            ],
        ]));

        $subset->run();

        $table = $subset->getTable();

        // loca must have been rebuilt
        $this->assertNotEmpty($this->getTableRecordString($table, 'loca', 'data'));
        // glyf must contain the extracted glyph bytes (8 bytes for glyph 0, padded to multiple of 4)
        $this->assertNotEmpty($this->getTableRecordString($table, 'glyf', 'data'));
        // The checksum must have been computed (non-zero for non-empty data)
        $this->assertNotSame(
            0,
            $this->getTableRecordInt($table, 'loca', 'checkSum') + $this->getTableRecordInt($table, 'glyf', 'checkSum'),
        );
    }

    // Verifies buildSubsetFont() writes a correct table-record offset (28 = 12-byte sfnt header + one 16-byte record) for a single-table font.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    #[Test]
    public function testBuildSubsetFontKeepsTableDirectoryOffsetsIntact(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            /** @throws \Com\Tecnick\Pdf\Font\Exception */
            public function run(): void
            {
                // removeUnusedTables calculates offset, length, and checksums all tables
                $this->removeUnusedTables();
                $this->buildSubsetFont();
            }

            public function getSubFont(): string
            {
                return $this->subfont;
            }
        };

        $this->setProp($subset, 'fdt', array_merge($this->getDefaultFdt(), [
            'table' => [
                // Offsets in fdt are relative to the 12-byte sfnt header.
                'head' => [
                    'checkSum' => 0,
                    'data' => str_repeat("\x00", 12),
                    'length' => 12,
                    'offset' => 12,
                ],
            ],
        ]));

        $subset->run();
        $subfont = $subset->getSubFont();

        // With a single table, the table directory offset must point to byte 28
        // (12-byte sfnt header + 16-byte table record).
        $offset = \unpack('N', \substr($subfont, 20, 4));
        $this->assertIsArray($offset);
        $this->assertSame(28, $offset[1]);
    }

    // Verifies addProcessedTables() falls back to the next available loca index for a glyph's
    // end boundary when the immediate next index is missing, so glyph 0 is not dropped.
    #[Test]
    public function testAddProcessedTablesUsesNextAvailableLocaIndexWhenImmediateIsMissing(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            public function run(): void
            {
                $this->addProcessedTables();
            }

            /** @return array<string, mixed> */
            public function getTable(): array
            {
                return $this->fdt['table'];
            }
        };

        // 8 bytes for glyph 0 followed by 8 bytes for glyph 1.
        $font = str_repeat("\xAB", 16);
        $this->setProp($subset, 'font', $font);
        $this->setProp($subset, 'subglyphs', [0 => 0]);

        // Simulate parser output where index 1 was removed as duplicate-empty marker.
        // Glyph 0 must still use index 2 as the closing boundary.
        $this->setProp($subset, 'fdt', array_merge($this->getDefaultFdt(), [
            'tot_num_glyphs' => 3,
            'short_offset' => false,
            'indexToLoc' => [0 => 0, 2 => 8, 3 => 16],
            'table' => [
                'glyf' => ['offset' => 0, 'length' => 16, 'checkSum' => 0, 'data' => ''],
                'loca' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'head' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'hhea' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'hmtx' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'maxp' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
            ],
            'numHMetrics' => 5,
            'tableSubset' => ['hmtx' => ['hMetrics' => [[100, 0], [222, 0], [111, 0]]]],
        ]));

        $subset->run();
        $table = $subset->getTable();

        // Glyph 0 must not be dropped just because index 1 is missing.
        $this->assertNotEmpty($this->getTableRecordString($table, 'glyf', 'data'));
    }

    // Verifies addCompositeGlyphs() merges newly discovered composite-child gids into subglyphs under their original numeric keys (here 3283 alongside the seed 853).
    #[Test]
    public function testAddCompositeGlyphsPreservesNumericGlyphIndexes(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            private bool $added = false;

            public function __construct() {}

            public function run(): void
            {
                $this->addCompositeGlyphs();
            }

            /** @return array<int, bool> */
            public function getSubglyphs(): array
            {
                return $this->subglyphs;
            }

            /**
             * @param array<int, bool> $new_sga
             * @param int              $key
             *
             * @return array<int, bool>
             */
            protected function findCompositeGlyphs(array $new_sga, int $key): array
            {
                if (!$this->added) {
                    $this->added = true;
                    $new_sga[3283] = true;
                }

                return $new_sga;
            }
        };

        $this->setProp($subset, 'subglyphs', [853 => 111]);

        $subset->run();
        $subglyphs = $subset->getSubglyphs();

        $this->assertArrayHasKey(853, $subglyphs);
        $this->assertArrayHasKey(3283, $subglyphs);
    }

    // Removed upstream test: testAddCompositeGlyphsSkipsDisabledDerivedGlyphs.
    // It fabricated a $new_sga value of false (400 => false) to assert that "disabled" derived glyphs are
    // skipped. That case cannot occur in this fork: findCompositeGlyphs() only ever stores an int char code
    // or GlyphType::CompositeChildWithoutCharacterCode (never a bool), and addCompositeGlyphs() merges
    // discovered children by array union with no enabled/disabled filtering — so a false value, and the skip
    // path it would exercise, are unreachable. Real behavior is covered by
    // testAddCompositeGlyphsPreservesNumericGlyphIndexes.

    // Verifies findCompositeGlyphs() walks a composite glyph's components, correctly skipping the variable-size X&Y-scale and 2x2-transform fields, to discover child gids 5 and 6.
    #[Test]
    public function testFindCompositeGlyphsParsesScaleAndTwoByTwoComponents(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            /**
             * @param array<int, bool> $newSga
             *
             * @return array<int, bool>
             */
            public function runFindCompositeGlyphs(array $newSga, int $key): array
            {
                return $this->findCompositeGlyphs($newSga, $key);
            }
        };

        // Glyph header: numberOfContours = -1 (composite), 8 bytes bbox.
        // Component 1: MORE_COMPONENTS + WE_HAVE_AN_X_AND_Y_SCALE, glyph 5.
        // Component 2: WE_HAVE_A_TWO_BY_TWO, glyph 6.
        $font =
            "\xFF\xFF\x00\x00\x00\x00\x00\x00\x00\x00"
            . "\x00\x60\x00\x05\x00\x00\x00\x00\x00\x00"
            . "\x00\x80\x00\x06\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

        $this->setProp($subset, 'font', $font);
        $this->setProp($subset, 'fbyte', new \Com\Tecnick\File\Byte($font));
        $this->setProp($subset, 'subglyphs', []);
        $this->setProp($subset, 'fdt', \array_replace_recursive($this->getDefaultFdt(), [
            'indexToLoc' => [0 => 0],
            'table' => [
                'glyf' => ['offset' => 0, 'length' => \strlen($font), 'checkSum' => 0, 'data' => ''],
            ],
        ]));

        $newSga = $subset->runFindCompositeGlyphs([], 0);
        $this->assertArrayHasKey(5, $newSga);
        $this->assertArrayHasKey(6, $newSga);
    }

    // Verifies short-format (Offset16) loca is emitted and table DATA is padded to a 4-byte boundary, while each
    // table-record length stays the ACTUAL unpadded data length (glyf: 3 data bytes -> length 3, data padded to 4) per the OpenType spec.
    #[Test]
    public function testAddProcessedTablesCreatesShortLocaAndPadsTables(): void
    {
        $subset = new class() extends \Com\Tecnick\Pdf\Font\Subset {
            public function __construct() {}

            public function run(): void
            {
                $this->addProcessedTables();
                // table length and checksum are calculated after dropping unwanted tables
                $this->removeUnusedTables();
            }

            /** @return array<string, mixed> */
            public function getTable(): array
            {
                return $this->fdt['table'];
            }
        };

        // One glyph with 3 bytes to force padding in both loca and glyf tables.
        $font = "\xAA\xBB\xCC";
        $this->setProp($subset, 'font', $font);
        $this->setProp($subset, 'subglyphs', [0 => 0]);
        $this->setProp($subset, 'fdt', \array_replace_recursive($this->getDefaultFdt(), [
            'tot_num_glyphs' => 1,
            'short_offset' => true,
            'indexToLoc' => [0 => 0, 1 => 3],
            'numHMetrics' => 1,
            'tableSubset' => ['hmtx' => ['hMetrics' => [[100, 0]]]],
            'table' => [
                'glyf' => ['offset' => 0, 'length' => 3, 'checkSum' => 0, 'data' => ''],
                'hmtx' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'head' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'hhea' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
                'maxp' => ['offset' => 0, 'length' => 0, 'checkSum' => 0, 'data' => ''],
            ],
        ]));

        $subset->run();
        $table = $subset->getTable();

        $this->assertArrayHasKey('loca', $table);
        $this->assertSame(4, $this->getTableRecordInt($table, 'loca', 'length'));
        // Per the OpenType spec the record length is the actual data length (3), not the 4-byte-padded length.
        $this->assertSame(3, $this->getTableRecordInt($table, 'glyf', 'length'));
        $this->assertSame(0, \strlen($this->getTableRecordString($table, 'loca', 'data')) % 4);
        $this->assertSame(0, \strlen($this->getTableRecordString($table, 'glyf', 'data')) % 4);
    }


    // Verifies getTableChecksum() reproduces each per-table checkSum and the head checksumAdjustment stored in the original TTF files, validating the checksum algorithm against real fonts.
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

    // End-to-end: subsets each real font to a fixed character set and verifies every table checkSum and the head checksumAdjustment in the generated subset are internally consistent.
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

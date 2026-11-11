<?php

/**
 * SubsetCompactTest.php
 *
 * @since     2026-09-29
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 */

namespace Test;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\FontPaths;
use Com\Tecnick\Pdf\Font\Import;
use Com\Tecnick\Pdf\Font\Output;
use Com\Tecnick\Pdf\Font\Stack;
use Com\Tecnick\Pdf\Font\Subset;

/**
 * Subset with renumbered glyphs.
 *
 * A GID encoded font names its glyphs by their original index, so the subset can keep the
 * used glyphs only, renumbered from 1, while a CIDToGIDMap resolves the original indices of
 * the content streams to the new ones.
 *
 * @since     2026-09-29
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontData from \Com\Tecnick\Pdf\Font\Load
 */
class SubsetCompactTest extends TestUtil
{
    private const MIRROR = 'util/vendor/tecnickcom/tc-font-mirror/freefont/';

    /**
     * 'A', 'B', 'À' and 'Ä'. In FreeSans 'À' is a composite of 'A' and of a grave accent
     * that no codepoint maps to, so the text uses a component directly and a component
     * that only a composite glyph refers to.
     */
    private const TEXT = [0x41, 0x42, 0xC0, 0xC4];

    /**
     * Font with variation sequences: its glyphs are .notdef (0), space (1), A (2), A.vs1 (3),
     * A.vs2 (4), B (5), uni908A (6) and uni908A.vs1 (7).
     */
    private const SEQUENCES = '/fixtures/ivs/ivstest.ttf';

    private function setProp(object $obj, string $name, mixed $value): void
    {
        $prop = new \ReflectionProperty($obj, $name);
        $prop->setValue($obj, $value);
    }

    /**
     * @return TFontData
     */
    private function getDefaultFdt(): array
    {
        $ref = new \ReflectionClass(Subset::class);
        /** @var TFontData $fdt */
        $fdt = $ref->getProperty('fdt')->getValue($ref->newInstanceWithoutConstructor());

        return $fdt;
    }

    private function getProgram(): string
    {
        $program = \file_get_contents(\dirname(__DIR__) . '/' . self::MIRROR . 'FreeSans.ttf');
        $this->assertIsString($program);

        return $program;
    }

    /**
     * Import FreeSans and return a stack holding it as the current font.
     *
     * @throws \Throwable
     */
    private function getStack(bool $subset = true): Stack
    {
        $this->setupTest();
        new Import(\dirname(__DIR__) . '/' . self::MIRROR . 'FreeSans.ttf', $this->getFontPath());

        $objnum = 1;
        $stack = new Stack(1, $subset);
        $stack->insert($objnum, 'freesans');

        return $stack;
    }

    /**
     * Import the font with variation sequences and return a stack holding it as the current font.
     *
     * @throws \Throwable
     */
    private function getSequenceStack(): Stack
    {
        $this->setupTest();
        new Import(__DIR__ . self::SEQUENCES, $this->getFontPath());

        $objnum = 1;
        $stack = new Stack(1, true);
        $stack->insert($objnum, 'ivstest');

        return $stack;
    }

    /**
     * Returns the glyph indices the current font maps the codepoints to.
     *
     * @param array<int, int> $ords Codepoints.
     *
     * @return array<int, bool> Glyph index => enabled.
     *
     * @throws \Throwable
     */
    private function getUsedGids(Stack $stack, array $ords): array
    {
        $gids = [];
        foreach ($ords as $ord) {
            $gids[$stack->getGidForOrd($ord)] = true;
        }

        return $gids;
    }

    /**
     * Build a compacted subset of the imported FreeSans.
     *
     * @param array<int, bool> $subgids Glyph index => enabled.
     *
     * @throws \Throwable
     */
    private function getCompactProgram(array $subgids, ?string $program = null): string
    {
        $definition = \file_get_contents($this->getFontPath() . 'freesans.json');
        $this->assertIsString($definition);
        /** @var array<array-key, mixed> $decoded */
        $decoded = \json_decode($definition, true);
        /** @var TFontData $fdt */
        $fdt = \array_replace_recursive($this->getDefaultFdt(), $decoded);
        $fileHelper = new ObjFile(allowedPaths: FontPaths::buildAllowedPaths());

        return (new Subset($program ?? $this->getProgram(), $fdt, $fileHelper, [], $subgids, true))->getSubsetFont();
    }

    /**
     * Returns the original glyphs and every component they are built from.
     *
     * @param array<int, int> $gids Original glyph indices.
     *
     * @return array<int, bool>
     */
    private function getClosure(SfntReader $orig, array $gids): array
    {
        $closure = [];
        $queue = [0, ...$gids];
        while ($queue !== []) {
            $gid = (int) \array_pop($queue);
            if (isset($closure[$gid])) {
                continue;
            }

            $closure[$gid] = true;
            foreach ($orig->getComponents($orig->getGlyph($gid)) as $component) {
                $queue[] = $component;
            }
        }

        return $closure;
    }

    private function getIndexToLocFormat(SfntReader $reader): int
    {
        $format = \unpack('n', \substr($reader->getTable('head'), 50, 2));
        $this->assertIsArray($format);

        return (int) $format[1];
    }

    /**
     * Assert that a glyph of the compacted program is the original glyph: same program,
     * with every component resolving to the original component, and same metrics.
     */
    private function assertSameGlyph(SfntReader $orig, int $origGid, SfntReader $compact, int $newGid): void
    {
        $want = $orig->getGlyph($origGid);
        $got = $compact->getGlyph($newGid);
        $wantComponents = $orig->getComponents($want);
        $gotComponents = $compact->getComponents($got);
        $this->assertSame(\array_keys($wantComponents), \array_keys($gotComponents), 'components of glyph ' . $origGid);
        foreach ($gotComponents as $pos => $component) {
            $wantComponent = $wantComponents[$pos] ?? $this->fail('component layout of glyph ' . $origGid);
            $this->assertSameGlyph($orig, $wantComponent, $compact, $component);
            // the rest of the program is compared with the original component index in place
            $got = \substr_replace($got, \pack('n', $wantComponent), $pos, 2);
        }

        // every glyph of the compacted program is padded to a four byte boundary
        $this->assertSame($want, \substr($got, 0, \strlen($want)), 'program of glyph ' . $origGid);
        $this->assertSame(\str_repeat("\x00", \max(0, \strlen($got) - \strlen($want))), \substr($got, \strlen($want)));
        $this->assertSame(
            $orig->getHorMetric($origGid),
            $compact->getHorMetric($newGid),
            'metrics of glyph ' . $origGid,
        );
    }

    /**
     * The sfnt checksum of a run of bytes: the sum of its 32-bit words, the trailing
     * partial one zero padded, truncated to 32 bits.
     */
    private function checksum(string $data): int
    {
        $data = \str_pad($data, (int) ((\strlen($data) + 3) / 4) * 4, "\x00");
        $sum = 0;
        foreach (\str_split($data, 4) as $word) {
            $value = \unpack('N', $word);
            $this->assertIsArray($value);
            $sum = ($sum + (int) $value[1]) & 0xFFFF_FFFF;
        }

        return $sum;
    }

    /**
     * @throws \Throwable
     */
    private function getFontsBlock(
        Stack $stack,
        bool $compact = true,
        ?SpyFontSubsetCache $cache = null,
        bool $cidset = false,
    ): string {
        $output = new Output($stack->getFonts(), 100, new Encrypt(false), null, $cache, $cidset, $compact);

        return $output->getFontsBlock();
    }

    /**
     * Returns the stream of an object of the fonts block, inflated when it is compressed.
     */
    private function getObjectStream(string $block, int $objnum): string
    {
        $block = "\n" . $block;
        $objpos = \strpos($block, "\n" . $objnum . " 0 obj\n");
        $this->assertNotFalse($objpos, 'object ' . $objnum);
        $start = \strpos($block, " stream\n", $objpos);
        $this->assertNotFalse($start);
        $end = \strpos($block, "\nendstream", $start);
        $this->assertNotFalse($end);
        $stream = \substr($block, $start + 8, $end - $start - 8);
        if (!\str_contains(\substr($block, $objpos, $start - $objpos), '/FlateDecode')) {
            return $stream;
        }

        $inflated = \gzuncompress($stream);
        $this->assertIsString($inflated);

        return $inflated;
    }

    /**
     * Returns the object number the first dictionary entry of the given name refers to.
     */
    private function getReference(string $block, string $name): int
    {
        $matches = [];
        $this->assertSame(1, \preg_match('#/' . $name . ' (\d+) 0 R#', $block, $matches), $name);

        return (int) ($matches[1] ?? 0);
    }

    private function getReferencedStream(string $block, string $name): string
    {
        return $this->getObjectStream($block, $this->getReference($block, $name));
    }

    /**
     * Returns the subset index a glyph takes in the map.
     *
     * @param array<int, int> $map Original glyph index => subset glyph index.
     */
    private function getMapped(array $map, int $gid): int
    {
        return $map[$gid] ?? $this->fail('the glyph ' . $gid . ' is not in the map');
    }

    /**
     * Returns the CIDToGIDMap stream of the fonts block, decoded (CID => GID).
     *
     * @return array<int, int>
     */
    private function getCidToGidMap(string $block): array
    {
        $codes = \unpack('n*', $this->getReferencedStream($block, 'CIDToGIDMap'));
        $this->assertIsArray($codes);

        return \array_map(static fn(mixed $code): int => (int) $code, \array_values($codes));
    }

    private function getCidFontDict(string $block): string
    {
        $matches = [];
        $this->assertSame(1, \preg_match(
            '#\d+ 0 obj\n<< /Type /Font /Subtype /CIDFontType2.*?endobj#s',
            $block,
            $matches,
        ));

        // the CIDToGIDMap entry is the only one the renumbering changes
        return \preg_replace('#\n/CIDToGIDMap (/Identity|\d+ 0 R)#', '', $matches[0] ?? '') ?? '';
    }

    // -------------------------------------------------------------------------
    // glyph index map
    // -------------------------------------------------------------------------

    public function testTheMapNumbersTheUsedGlyphsFromOneInAscendingOrder(): void
    {
        $this->assertSame(
            [0 => 0, 7 => 1, 42 => 2, 900 => 3],
            Subset::getCompactGidMap([900 => true, 7 => true, 42 => true]),
        );
    }

    public function testTheMapSkipsNotdefAndValuesOutsideTheGlyphIndexRange(): void
    {
        $this->assertSame(
            [0 => 0, 5 => 1],
            Subset::getCompactGidMap([0 => true, 5 => true, 0x1_0000 => true, -3 => true]),
        );
        $this->assertSame([0 => 0], Subset::getCompactGidMap([]));
    }

    public function testTheMapSkipsTheDisabledGlyphs(): void
    {
        $this->assertSame([0 => 0, 1 => 1, 3 => 2], Subset::getCompactGidMap([1 => true, 2 => false, 3 => true]));
    }

    public function testTheMapDependsOnTheSetOfGlyphsOnly(): void
    {
        $this->assertSame(
            Subset::getCompactGidMap([3 => true, 1 => true]),
            Subset::getCompactGidMap([1 => true, 3 => true]),
        );
    }

    // -------------------------------------------------------------------------
    // compacted program
    // -------------------------------------------------------------------------

    /**
     * @throws \Throwable
     */
    public function testTheProgramKeepsTheUsedGlyphsAndTheirComponentsOnly(): void
    {
        $subgids = $this->getUsedGids($this->getStack(), self::TEXT);
        $orig = new SfntReader($this->getProgram());
        $compact = new SfntReader($this->getCompactProgram($subgids));

        $closure = $this->getClosure($orig, \array_keys($subgids));
        $this->assertGreaterThan(1 + \count($subgids), \count($closure), 'the text must use a composite glyph');
        $this->assertSame(\count($closure), $compact->getNumGlyphs());
        // the tables of a program subset for a CIDFontType2, the cmap and post tables excluded
        $expected = \array_values(\array_intersect([
            'cvt ',
            'fpgm',
            'glyf',
            'head',
            'hhea',
            'hmtx',
            'loca',
            'maxp',
            'prep',
        ], $orig->getTags()));
        $this->assertSame($expected, $compact->getTags());
    }

    /**
     * @throws \Throwable
     */
    public function testEveryUsedGlyphKeepsItsProgramAndMetricsUnderItsNewIndex(): void
    {
        $subgids = $this->getUsedGids($this->getStack(), self::TEXT);
        $orig = new SfntReader($this->getProgram());
        $compact = new SfntReader($this->getCompactProgram($subgids));

        $map = Subset::getCompactGidMap($subgids);
        foreach ($map as $gid => $newgid) {
            $this->assertSameGlyph($orig, $gid, $compact, $newgid);
        }
    }

    /**
     * A component that the text also uses directly is renumbered inside the composite glyph,
     * not only a component that the composite glyph brought into the subset.
     *
     * @throws \Throwable
     */
    public function testAComponentUsedDirectlyIsRenumberedInsideTheCompositeGlyph(): void
    {
        $stack = $this->getStack();
        $subgids = $this->getUsedGids($stack, self::TEXT);
        $gidA = $stack->getGidForOrd(0x41);
        $gidAgrave = $stack->getGidForOrd(0xC0);
        $orig = new SfntReader($this->getProgram());
        $this->assertContains($gidA, $orig->getComponents($orig->getGlyph($gidAgrave)), "'À' must be built on 'A'");

        $compact = new SfntReader($this->getCompactProgram($subgids));
        $map = Subset::getCompactGidMap($subgids);
        $components = $compact->getComponents($compact->getGlyph($this->getMapped($map, $gidAgrave)));
        $this->assertContains($this->getMapped($map, $gidA), $components);
        $this->assertNotContains($gidA, $components);
    }

    /**
     * FreeSans stores long glyph offsets, while the glyphs of a small subset fit the short
     * format.
     *
     * @throws \Throwable
     */
    public function testTheShortLocaFormatIsSelectedWhenTheGlyphsFit(): void
    {
        $subgids = $this->getUsedGids($this->getStack(), self::TEXT);
        $this->assertSame(1, $this->getIndexToLocFormat(new SfntReader($this->getProgram())));

        $compact = new SfntReader($this->getCompactProgram($subgids));
        $this->assertSame(0, $this->getIndexToLocFormat($compact));
        $this->assertSame(2 * ($compact->getNumGlyphs() + 1), \strlen($compact->getTable('loca')));
    }

    /**
     * @throws \Throwable
     */
    public function testTheLongLocaFormatIsSelectedWhenTheGlyphsExceedTheShortRange(): void
    {
        $this->getStack();
        $subgids = \array_fill_keys(\range(1, 3000), true);
        $orig = new SfntReader($this->getProgram());
        $compact = new SfntReader($this->getCompactProgram($subgids));

        $this->assertGreaterThan(131_070, \strlen($compact->getTable('glyf')));
        $this->assertSame(1, $this->getIndexToLocFormat($compact));
        $this->assertSame(4 * ($compact->getNumGlyphs() + 1), \strlen($compact->getTable('loca')));
        $map = Subset::getCompactGidMap($subgids);
        foreach ([1, 1500, 3000] as $gid) {
            $this->assertSameGlyph($orig, $gid, $compact, $this->getMapped($map, $gid));
        }
    }

    /**
     * The digits share one advance width, so the trailing run they form in the subset
     * stores only its left side bearings after the first of them.
     *
     * @throws \Throwable
     */
    public function testATrailingRunOfEqualAdvanceWidthsKeepsOnlyItsLeftSideBearings(): void
    {
        $subgids = $this->getUsedGids($this->getStack(), \range(0x30, 0x39));
        $orig = new SfntReader($this->getProgram());
        $compact = new SfntReader($this->getCompactProgram($subgids));

        $map = Subset::getCompactGidMap($subgids);
        $advances = [];
        foreach ($map as $gid => $newgid) {
            $advances[$newgid] = $orig->getHorMetric($gid)[0];
        }

        $numHMetrics = \count($advances);
        while ($numHMetrics > 1 && ($advances[$numHMetrics - 1] ?? -1) === ($advances[$numHMetrics - 2] ?? -2)) {
            --$numHMetrics;
        }

        $this->assertLessThan(\count($advances), $numHMetrics, 'the digits must share one advance width');
        $this->assertSame($numHMetrics, $compact->getNumberOfHMetrics());
        $this->assertSame(
            (4 * $numHMetrics) + (2 * (\count($advances) - $numHMetrics)),
            \strlen($compact->getTable('hmtx')),
        );
        foreach ($map as $gid => $newgid) {
            $this->assertSame($orig->getHorMetric($gid), $compact->getHorMetric($newgid));
        }
    }

    /**
     * @throws \Throwable
     */
    public function testTheTableChecksumsAndTheHeadAdjustmentAreComputedFromTheEmittedBytes(): void
    {
        $program = $this->getCompactProgram($this->getUsedGids($this->getStack(), self::TEXT));
        $compact = new SfntReader($program);

        $headOffset = $compact->getRecords()['head']['offset'] ?? $this->fail('the program must carry a head table');
        foreach ($compact->getTags() as $index => $tag) {
            $data = $compact->getTable($tag);
            if ($tag === 'head') {
                // the head checksum is the one of its form with a zero checkSumAdjustment
                $data = \substr_replace($data, "\x00\x00\x00\x00", 8, 4);
            }

            // the checksum is the second field of the 16-byte directory record
            $declared = \unpack('N', \substr($program, 12 + (16 * $index) + 4, 4));
            $this->assertIsArray($declared);
            $this->assertSame($this->checksum($data), (int) $declared[1], 'checksum of the ' . $tag . ' table');
        }

        $adjustment = \unpack('N', \substr($program, $headOffset + 8, 4));
        $this->assertIsArray($adjustment);
        $zeroed = \substr_replace($program, "\x00\x00\x00\x00", $headOffset + 8, 4);
        $this->assertSame((0xB1B0_AFBA - $this->checksum($zeroed)) & 0xFFFF_FFFF, (int) $adjustment[1]);
    }

    /**
     * A glyph index the font does not define keeps its place in the map, as an empty glyph.
     *
     * @throws \Throwable
     */
    public function testAGlyphIndexTheFontDoesNotDefineBecomesAnEmptyGlyph(): void
    {
        $gidA = $this->getStack()->getGidForOrd(0x41);
        $orig = new SfntReader($this->getProgram());
        $undefined = $orig->getNumGlyphs() + 5;
        $subgids = [$gidA => true, $undefined => true];

        $compact = new SfntReader($this->getCompactProgram($subgids));
        $map = Subset::getCompactGidMap($subgids);
        $this->assertSame(3, $compact->getNumGlyphs());
        $this->assertSame(0, $compact->getGlyphLength($this->getMapped($map, $undefined)));
        $this->assertSame([0, 0], $compact->getHorMetric($this->getMapped($map, $undefined)));
        $this->assertSameGlyph($orig, $gidA, $compact, $this->getMapped($map, $gidA));
    }

    /**
     * @throws \Throwable
     */
    public function testTheComponentsOfEveryRecordLayoutAreRenumbered(): void
    {
        $subset = new class() extends Subset {
            public function __construct() {}

            /**
             * @param array<int, int> $gidmap
             *
             * @throws FontException
             */
            public function renumber(array $gidmap, string $glyph): string
            {
                $this->gidmap = $gidmap;

                return $this->renumberComponents($glyph);
            }
        };

        // a composite header, a component with 16-bit arguments that is followed by another
        // one (flags 0x0021) on glyph 5, then a component with a scale (flags 0x0008) on glyph 6
        $glyph =
            "\xFF\xFF\x00\x00\x00\x00\x00\x00\x00\x00"
            . "\x00\x21\x00\x05\x00\x01\x00\x02"
            . "\x00\x08\x00\x06\x01\x02\x40\x00";
        $renumbered = $subset->renumber([0 => 0, 5 => 1, 6 => 2], $glyph);

        $this->assertSame("\x00\x01", \substr($renumbered, 12, 2));
        $this->assertSame("\x00\x02", \substr($renumbered, 20, 2));
        $this->assertSame(\substr_replace(\substr_replace($glyph, "\x00\x01", 12, 2), "\x00\x02", 20, 2), $renumbered);
        // a simple glyph is returned unchanged
        $this->assertSame("\x00\x01\x00\x00", $subset->renumber([0 => 0], "\x00\x01\x00\x00"));
    }

    /**
     * @throws \Throwable
     */
    public function testAComponentOutsideTheSubsetIsRejected(): void
    {
        $subset = new class() extends Subset {
            public function __construct() {}

            /** @throws FontException */
            public function renumber(string $glyph): string
            {
                $this->gidmap = [0 => 0];

                return $this->renumberComponents($glyph);
            }
        };

        $this->assertThrowsMessage(
            FontException::class,
            'the component glyph 5 is not part of the subset',
            /** @throws \Throwable */
            static function () use ($subset): void {
                $subset->renumber("\xFF\xFF\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x05\x00\x00");
            },
        );
    }

    /**
     * @throws \Throwable
     */
    public function testMissingHorizontalMetricsAreRejected(): void
    {
        $subset = new class() extends Subset {
            public function __construct() {}

            /**
             * @return array{int, int}
             *
             * @throws FontException
             */
            public function metric(int $gid): array
            {
                return $this->getHorMetric($gid);
            }
        };

        // two glyphs, one longHorMetric (advance 500, lsb -10) and no left side bearing for
        // the second glyph
        $font = "\x01\xF4\xFF\xF6";
        $this->setProp($subset, 'fbyte', new Byte($font));
        $this->setProp($subset, 'fdt', \array_replace($this->getDefaultFdt(), [
            'numGlyphs' => 2,
            'numHMetrics' => 1,
            'table' => ['hmtx' => ['offset' => 0, 'length' => 4, 'checkSum' => 0, 'data' => '']],
        ]));

        $this->assertSame([500, -10], $subset->metric(0));
        $this->assertSame([0, 0], $subset->metric(2));
        $this->assertThrowsMessage(
            FontException::class,
            'the hmtx table has no metrics for the glyph 1',
            /** @throws \Throwable */
            static function () use ($subset): void {
                $subset->metric(1);
            },
        );
    }

    /**
     * @throws \Throwable
     */
    public function testAFieldPastTheEndOfACopiedTableIsRejected(): void
    {
        $subset = new class() extends Subset {
            public function __construct() {}

            /** @throws FontException */
            public function patch(string $tag): void
            {
                $this->patchTable($tag);
            }
        };

        $this->setProp($subset, 'patches', ['maxp' => [4 => "\x00\x03"]]);
        $this->setProp($subset, 'fdt', \array_replace($this->getDefaultFdt(), [
            'table' => ['maxp' => ['offset' => 0, 'length' => 4, 'checkSum' => 0, 'data' => "\x00\x01\x00\x00"]],
        ]));

        $this->assertThrowsMessage(
            FontException::class,
            'truncated font table: maxp',
            /** @throws \Throwable */
            static function () use ($subset): void {
                $subset->patch('maxp');
            },
        );
    }

    // -------------------------------------------------------------------------
    // PDF font objects
    // -------------------------------------------------------------------------

    /**
     * Each character code of the text resolves, through the CIDToGIDMap, to the original
     * glyph. A character the font has no glyph for is encoded as .notdef and does not shift
     * the glyphs that follow it.
     *
     * @throws \Throwable
     */
    public function testEveryEncodedCharacterResolvesToItsOriginalGlyph(): void
    {
        $stack = $this->getStack();
        $codes = $stack->ordArrToGidStr([0x41, 0xE000, 0xC0, 0x42, 0xC4, 0x41]);
        $block = $this->getFontsBlock($stack);

        $map = $this->getCidToGidMap($block);
        $orig = new SfntReader($this->getProgram());
        $compact = new SfntReader($this->getReferencedStream($block, 'FontFile2'));
        $this->assertSame("\x00\x00", \substr($codes, 2, 2), 'a character without glyph is .notdef');
        foreach (\str_split($codes, 2) as $code) {
            $cid = (\ord($code[0]) << 8) | \ord($code[1]);
            $this->assertSameGlyph($orig, $cid, $compact, $map[$cid] ?? 0);
        }
    }

    /**
     * An alias code shows the glyph of another code, so it resolves to the index that glyph
     * takes in the subset.
     *
     * @throws \Throwable
     */
    public function testAnAliasCodeResolvesToTheRenumberedGlyphItShows(): void
    {
        $stack = $this->getSequenceStack();
        // A, A+FE01, B+FE01 (the glyph of A+FE01), A+FE00 (the glyph of A) and U+908A+E0101
        $codes = $stack->ordArrToGidStr([0x41, 0x41, 0xFE01, 0x42, 0xFE01, 0x41, 0xFE00, 0x908A, 0xE_0101]);
        $this->assertSame('00020003000800090007', \bin2hex($codes));
        $font = $stack->getFont('ivstest');
        $this->assertSame([8 => 3, 9 => 2], $font['usedalias']);

        $block = $this->getFontsBlock($stack);
        $kept = $this->getFontsBlock($stack, false);

        // the glyphs 2, 3 and 7 are renumbered 1, 2 and 3, and the alias codes 8 and 9 show
        // the glyphs 3 and 2
        $map = $this->getCidToGidMap($block);
        $this->assertSame([0, 0, 1, 2, 0, 0, 0, 3, 2, 1], $map);
        $this->assertStringNotContainsString('/Predictor', $block);

        $program = \file_get_contents(__DIR__ . self::SEQUENCES);
        $this->assertIsString($program);
        $orig = new SfntReader($program);
        $compact = new SfntReader($this->getReferencedStream($block, 'FontFile2'));
        $this->assertSame(4, $compact->getNumGlyphs());
        foreach (\str_split($codes, 2) as $code) {
            $cid = (\ord($code[0]) << 8) | \ord($code[1]);
            $this->assertSameGlyph($orig, $font['usedalias'][$cid] ?? $cid, $compact, $map[$cid] ?? 0);
        }

        // the widths and the text of the codes are those of the subset keeping the glyph indices
        $this->assertSame($this->getCidFontDict($kept), $this->getCidFontDict($block));
        $this->assertSame(
            $this->getReferencedStream($kept, 'ToUnicode'),
            $this->getReferencedStream($block, 'ToUnicode'),
        );
    }

    /**
     * The CIDSet stream lists the character codes, which the renumbering leaves unchanged,
     * and follows the CIDToGIDMap stream.
     *
     * @throws \Throwable
     */
    public function testTheCidSetOfARenumberedSubsetListsTheCharacterCodes(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $compact = $this->getFontsBlock($stack, true, null, true);
        $kept = $this->getFontsBlock($stack, false, null, true);

        $this->assertSame($this->getReference($compact, 'CIDToGIDMap') + 1, $this->getReference($compact, 'CIDSet'));
        $this->assertSame($this->getReferencedStream($kept, 'CIDSet'), $this->getReferencedStream($compact, 'CIDSet'));
    }

    /**
     * A font that shows no glyph of a renumbered program maps the .notdef code only.
     *
     * @throws \Throwable
     */
    public function testAFontShowingNoGlyphOfARenumberedProgramMapsNotdefOnly(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $fonts = $stack->getFonts();
        $font = $fonts[(string) \array_key_first($fonts)] ?? $this->fail('the stack must hold the font');
        // the same program, used by a font that shows no text
        $unused = $font;
        $unused['usedgid'] = [];
        $unused['i'] = $font['i'] + 1;
        $unused['n'] = 900;
        $fonts['unused'] = $unused;

        $block = (new Output($fonts, 100, new Encrypt(false), null))->getFontsBlock();

        $matches = [];
        $this->assertSame(2, \preg_match_all('#/CIDToGIDMap (\d+) 0 R#', $block, $matches));
        $this->assertSame("\x00\x00", $this->getObjectStream($block, (int) ($matches[1][1] ?? 0)));
    }

    /**
     * The character codes stay the original glyph indices, so only the CIDToGIDMap entry of
     * the CIDFont dictionary changes.
     *
     * @throws \Throwable
     */
    public function testTheWidthsAndTheToUnicodeMapAreTheSameAsWithoutRenumbering(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $compact = $this->getFontsBlock($stack);
        $kept = $this->getFontsBlock($stack, false);

        $this->assertMatchesRegularExpression('#/CIDToGIDMap \d+ 0 R#', $compact);
        $this->assertStringContainsString('/CIDToGIDMap /Identity', $kept);
        $this->assertSame($this->getCidFontDict($kept), $this->getCidFontDict($compact));
        $this->assertSame(
            $this->getReferencedStream($kept, 'ToUnicode'),
            $this->getReferencedStream($compact, 'ToUnicode'),
        );
    }

    /**
     * @throws \Throwable
     */
    public function testWithoutRenumberingTheSubsetKeepsTheOriginalGlyphIndices(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $fonts = $stack->getFonts();
        $compact = new Output($fonts, 100, new Encrypt(false), null);
        $kept = new Output($fonts, 100, new Encrypt(false), compactSubset: false);

        // the renumbered subset adds the CIDToGIDMap stream object
        $this->assertSame($kept->getObjectNumber() + 1, $compact->getObjectNumber());
        $keptProgram = $this->getReferencedStream($kept->getFontsBlock(), 'FontFile2');
        $compactProgram = $this->getReferencedStream($compact->getFontsBlock(), 'FontFile2');
        $this->assertSame(
            (new SfntReader($this->getProgram()))->getNumGlyphs(),
            (new SfntReader($keptProgram))->getNumGlyphs(),
        );
        $this->assertLessThan(\strlen($keptProgram), \strlen($compactProgram));
    }

    /**
     * @throws \Throwable
     */
    public function testRenumberedAndKeptSubsetsAreCachedUnderDifferentKeys(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $cache = new SpyFontSubsetCache();
        $this->getFontsBlock($stack, true, $cache);
        $this->getFontsBlock($stack, false, $cache);

        $this->assertCount(2, $cache->setCalls);
        $compactKey = $cache->setCalls[0] ?? $this->fail('the renumbered subset must be stored');
        $keptKey = $cache->setCalls[1] ?? $this->fail('the kept subset must be stored');
        $this->assertIsString($compactKey);
        $this->assertIsString($keptKey);
        $this->assertSame($keptKey . ':compact', $compactKey);
    }

    /**
     * A program read back from the cache gets the same CIDToGIDMap, as the map is derived
     * from the used glyphs alone.
     *
     * @throws \Throwable
     */
    public function testACachedSubsetProducesTheSameFontObjects(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $cache = new SpyFontSubsetCache();
        $first = $this->getFontsBlock($stack, true, $cache);
        $second = $this->getFontsBlock($stack, true, $cache);

        $this->assertCount(1, $cache->setCalls, 'the second pass must be served by the cache');
        $this->assertSame($first, $second);
    }

    /**
     * A font file also used by a font whose character codes are codepoints keeps the original
     * glyph indices, as that font resolves them through its own CIDToGIDMap.
     *
     * @throws \Throwable
     */
    public function testAFontFileSharedWithACodepointEncodedFontIsNotRenumbered(): void
    {
        $stack = $this->getStack();
        $stack->ordArrToGidStr(self::TEXT);
        $fonts = $stack->getFonts();
        $font = $fonts[(string) \array_key_first($fonts)] ?? $this->fail('the stack must hold the font');
        // the same program, used by a font whose character codes are codepoints
        $legacy = $font;
        $legacy['gidenc'] = false;
        $legacy['i'] = $font['i'] + 1;
        $legacy['n'] = 900;
        $fonts['legacy'] = $legacy;

        $block = (new Output($fonts, 100, new Encrypt(false), null))->getFontsBlock();

        $this->assertStringContainsString('/CIDToGIDMap /Identity', $block);
        $this->assertSame(
            (new SfntReader($this->getProgram()))->getNumGlyphs(),
            (new SfntReader($this->getReferencedStream($block, 'FontFile2')))->getNumGlyphs(),
        );
    }

    /**
     * A program whose license forbids subsetting is embedded whole, with its glyph indices.
     *
     * @throws \Throwable
     */
    public function testAProgramWhoseLicenseForbidsSubsettingKeepsTheIdentityMap(): void
    {
        $this->setupTest();
        $outdir = $this->getFontPath();
        $program = $this->getProgram();
        // fsType is the fifth uint16 of the OS/2 table, and bit 8 forbids subsetting
        $os2 = (new SfntReader($program))->getRecords()['OS/2'] ?? $this->fail('FreeSans must carry an OS/2 table');
        $fsTypeAt = $os2['offset'] + 8;
        $source = $outdir . 'nosubsetsans.ttf';
        \file_put_contents($source, \substr_replace($program, "\x01\x00", $fsTypeAt, 2));
        new Import($source, $outdir);

        $objnum = 1;
        $stack = new Stack(1, true);
        $stack->insert($objnum, 'nosubsetsans');
        $stack->ordArrToGidStr(self::TEXT);
        $block = (new Output($stack->getFonts(), 100, new Encrypt(false), null))->getFontsBlock();

        $this->assertStringContainsString('/CIDToGIDMap /Identity', $block);
        $this->assertDoesNotMatchRegularExpression('#/CIDToGIDMap \d+ 0 R#', $block);
        $this->assertStringContainsString('/Length1 ' . \strlen($program), $block);
    }
}

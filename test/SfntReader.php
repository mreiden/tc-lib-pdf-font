<?php

/**
 * SfntReader.php
 *
 * @since     2026-08-14
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

/**
 * Minimal reader of the sfnt structures a test needs to inspect an emitted font program.
 *
 * The table directory, the loca table and the glyph offsets are read here independently of
 * the library under test.
 *
 * @since     2026-08-14
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 */
final class SfntReader
{
    /**
     * Records of the table directory, keyed by tag, in the order they appear.
     *
     * @var array<string, array{'offset': int, 'length': int}>
     */
    private array $records = [];

    public function __construct(
        private readonly string $program,
    ) {
        $count = $this->readUShort($this->program, 4);
        for ($idx = 0; $idx < $count; ++$idx) {
            $record = 12 + ($idx * 16);
            $this->records[\substr($this->program, $record, 4)] = [
                'offset' => $this->readULong($this->program, $record + 8),
                'length' => $this->readULong($this->program, $record + 12),
            ];
        }
    }

    /**
     * Returns the tags of the table directory, in the order they appear in it.
     *
     * @return array<int, string>
     */
    public function getTags(): array
    {
        return \array_keys($this->records);
    }

    /**
     * Returns the records of the table directory, keyed by tag.
     *
     * @return array<string, array{'offset': int, 'length': int}>
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Returns the position of the first byte that follows the table directory.
     */
    public function getDirectoryEnd(): int
    {
        return 12 + (\count($this->records) * 16);
    }

    /**
     * Returns the bytes of a table.
     *
     * @throws \LogicException if the program does not carry the table.
     */
    public function getTable(string $tag): string
    {
        $record = $this->records[$tag] ?? null;
        if ($record === null) {
            throw new \LogicException('the font program has no ' . $tag . ' table');
        }

        return \substr($this->program, $record['offset'], $record['length']);
    }

    /**
     * Returns the number of bytes the glyf table stores for a glyph index.
     *
     * @throws \LogicException if the program carries no loca or head table.
     */
    public function getGlyphLength(int $gid): int
    {
        return $this->getLocaEntry($gid + 1) - $this->getLocaEntry($gid);
    }

    /**
     * Returns the bytes the glyf table stores for a glyph index.
     *
     * @throws \LogicException if the program carries no loca, head or glyf table.
     */
    public function getGlyph(int $gid): string
    {
        $start = $this->getLocaEntry($gid);

        return \substr($this->getTable('glyf'), $start, $this->getLocaEntry($gid + 1) - $start);
    }

    /**
     * Returns the number of glyphs the maxp table declares.
     *
     * @throws \LogicException if the program carries no maxp table.
     */
    public function getNumGlyphs(): int
    {
        return $this->readUShort($this->getTable('maxp'), 4);
    }

    /**
     * Returns the numberOfHMetrics field of the hhea table.
     *
     * @throws \LogicException if the program carries no hhea table.
     */
    public function getNumberOfHMetrics(): int
    {
        return $this->readUShort($this->getTable('hhea'), 34);
    }

    /**
     * Returns the advance width and the left side bearing the hmtx table stores for a glyph.
     *
     * A glyph past the last longHorMetric record takes the advance width of that record.
     *
     * @return array{0: int, 1: int}
     *
     * @throws \LogicException if the program carries no hhea or hmtx table.
     */
    public function getHorMetric(int $gid): array
    {
        $hmtx = $this->getTable('hmtx');
        $numHMetrics = $this->getNumberOfHMetrics();
        $advance = $this->readUShort($hmtx, 4 * \min($gid, $numHMetrics - 1));
        $lsb = $this->readUShort(
            $hmtx,
            $gid < $numHMetrics ? (4 * $gid) + 2 : (4 * $numHMetrics) + (2 * ($gid - $numHMetrics)),
        );

        return [$advance, $lsb >= 0x8000 ? $lsb - 0x1_0000 : $lsb];
    }

    /**
     * Returns the component glyph indices of a composite glyph, keyed by the position of
     * each index in the glyph bytes, or an empty array for a simple glyph.
     *
     * @return array<int, int>
     */
    public function getComponents(string $glyph): array
    {
        // a composite glyph has a negative numberOfContours
        if (\strlen($glyph) < 10 || (\ord($glyph[0]) & 0x80) === 0) {
            return [];
        }

        $components = [];
        $pos = 10;
        do {
            $flags = $this->readUShort($glyph, $pos);
            $components[$pos + 2] = $this->readUShort($glyph, $pos + 2);
            // the arguments are 16-bit when ARG_1_AND_2_ARE_WORDS (bit 0) is set, then the
            // transform holds one, two or four F2DOT14 values (bits 3, 6 and 7)
            $pos += ($flags & 1) !== 0 ? 8 : 6;
            $pos += match (true) {
                ($flags & 8) !== 0 => 2,
                ($flags & 64) !== 0 => 4,
                ($flags & 128) !== 0 => 8,
                default => 0,
            };
        } while (($flags & 32) !== 0 && ($pos + 4) <= \strlen($glyph));

        return $components;
    }

    /**
     * Returns the glyf offset the loca table declares for a glyph index.
     *
     * @throws \LogicException if the program carries no loca or head table.
     */
    private function getLocaEntry(int $index): int
    {
        $loca = $this->getTable('loca');
        // head.indexToLocFormat, at offset 50: zero selects the short (halved) offsets
        if ($this->readUShort($this->getTable('head'), 50) === 0) {
            return $this->readUShort($loca, $index * 2) * 2;
        }

        return $this->readULong($loca, $index * 4);
    }

    /**
     * Returns the big-endian 16-bit value stored at the given position.
     */
    private function readUShort(string $data, int $offset): int
    {
        return (\ord($data[$offset] ?? "\x00") << 8) | \ord($data[$offset + 1] ?? "\x00");
    }

    /**
     * Returns the big-endian 32-bit value stored at the given position.
     */
    private function readULong(string $data, int $offset): int
    {
        return ($this->readUShort($data, $offset) << 16) | $this->readUShort($data, $offset + 2);
    }
}

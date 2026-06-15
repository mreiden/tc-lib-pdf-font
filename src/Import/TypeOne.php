<?php

declare(strict_types=1);

/**
 * TypeOne.php
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

namespace Com\Tecnick\Pdf\Font\Import;

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\Compression;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Unicode\Data\Encoding;

/**
 * Com\Tecnick\Pdf\Font\Import\TypeOne
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class TypeOne extends Core
{
    /**
     * Helper function to validate and return the size of a Type1 font segment.
     *
     * @param int $offset
     * @param int $segmentNumber
     *
     * @return int
     *
     * @throws FileException
     * @throws FontException
     */
    private function storeFontDataUnpack(int $offset, int $segmentNumber): int
    {
        $dat = Byte::unpackOrThrow('Cmarker/Ctype/Vsize', \substr($this->font, $offset, 6));
        if ([$dat['marker'], $dat['type']] !== [128, $segmentNumber]) {
            throw new FontException("Font file is not a valid binary Type1 - Invalid segment #$$segmentNumber");
        }
        if (!\is_int($dat['size'])) {
            throw new FontException("Font file is not a valid binary Type1 - Invalid segment #$$segmentNumber size");
        }

        return $dat['size'];
    }

    /**
     * Store font data
     *
     * Type 1 PFB Binary Layout:
     *  - Segment 1 (ASCII):  Contains PostScript font information (e.g., FontName, FamilyName).
     *  - Segment 2 (Binary): Contains the encrypted charstring data (the glyph shapes).
     *  - Segment 3 (ASCII):  Contains encrypted charstrings or additional postscript code.
     *  - EOF Marker:         A final 2-byte sequence (0x80 followed by 0x03) signals the end of the file.
     *
     * Segment Header: Each segment is preceded by a 6-byte header:
     *  0 - uint8             Start marker (0x80 or 128 in decimal).
     *  1 - uint8             Segment type (1 for ASCII, 2 for Binary).
     *  2 - uint32-le         Length of the segment in little-endian format (4 bytes).
     *
     *  @throws FileException
     *  @throws FontException
     */
    protected function storeFontData(): void
    {
        // Parse size of segments 1 and 2.
        $this->fdt['size1'] = $this->storeFontDataUnpack(0, 1);
        $this->fdt['size2'] = $this->storeFontDataUnpack(6 + $this->fdt['size1'], 2);
        $this->fdt['encrypted'] = \substr($this->font, 12 + $this->fdt['size1'], $this->fdt['size2']);

        // File name where the compressed font data will be stored
        $this->fdt['file'] = $this->fdt['file_name'] . '.z';
        // Store the compressed font data
        $fpt = $this->fileHelper->fopenLocal($this->fdt['dir'] . $this->fdt['file'], 'wb');
        \fwrite($fpt, Compression::compress(\substr($this->font, 6, $this->fdt['size1']) . $this->fdt['encrypted']));
        \fclose($fpt);
    }

    /**
     * Extract Font information
     *
     * @throws FontException
     */
    protected function extractFontInfo(): void
    {
        if (
            !\preg_match('#/FontName\s*+/(\S*+)#', $this->font, $matches) ||
            !\preg_match('#/FullName\s*+\(([^)]*+)#', $this->font, $matches)
        ) {
            throw new FontException('Unable to extract font name');
        }
        $name = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $matches[1]);
        if ($name === null) {
            throw new FontException('Unable to extract font name');
        }
        $this->fdt['name'] = $name;

        if (!\preg_match('#/FontBBox\s*+{([^}]*+)#', $this->font, $matches)) {
            throw new FontException('Unable to extract FontBBox');
        }
        $rawbvl = \explode(' ', \trim($matches[1]));
        $bvl = [(int) $rawbvl[0], (int) $rawbvl[1], (int) $rawbvl[2], (int) $rawbvl[3]];
        $this->fdt['bbox'] = \implode(' ', $bvl);
        $this->fdt['Ascent'] = $bvl[3];
        $this->fdt['Descent'] = $bvl[1];

        \preg_match('#/ItalicAngle\s*+([0-9+\-]*+)#', $this->font, $matches);
        $this->fdt['italicAngle'] = (int) ($matches[1] ?? 0);

        if ($this->fdt['italicAngle'] !== 0) {
            $this->fdt['Flags'] |= 64;
        }

        \preg_match('#/UnderlinePosition\s*+([0-9+\-]*+)#', $this->font, $matches);
        $this->fdt['underlinePosition'] = (int) ($matches[1] ?? 0);
        \preg_match('#/UnderlineThickness\s*+([0-9+\-]*+)#', $this->font, $matches);
        $this->fdt['underlineThickness'] = (int) ($matches[1] ?? 0);
        \preg_match('#/isFixedPitch\s*+(\S*+)#', $this->font, $matches);
        if (($matches[1] ?? false) == 'true') {
            $this->fdt['Flags'] |= 1;
        }

        //\preg_match("#/Weight\s*+\(([^)]*+)#", $this->font, $matches);
        //if (!empty($matches[1])) {
        //    $this->fdt["weight"] = \strtolower($matches[1]);
        //}
        $this->fdt['weight'] = 'Book';

        $this->fdt['Leading'] = 0;
    }

    /**
     * Extract Font information
     *
     * @return array<string, int>
     */
    protected function getInternalMap(): array
    {
        $imap = [];
        if (\preg_match_all('#dup\s([0-9]+)\s*+/(\S*+)\sput#U', $this->font, $fmap, PREG_SET_ORDER) > 0) {
            foreach ($fmap as $val) {
                $imap[$val[2]] = (int) $val[1];
            }
        }

        return $imap;
    }

    /**
     * Decrypt eexec encrypted part
     */
    protected function getEplain(): string
    {
        $csr = 55_665; // eexec encryption constant
        $cc1 = 52_845;
        $cc2 = 22_719;
        $elen = \strlen($this->fdt['encrypted']);
        $eplain = '';
        for ($idx = 0; $idx < $elen; $idx++) {
            $chr = \ord($this->fdt['encrypted'][$idx]);
            $eplain .= \chr(($chr ^ ($csr >> 8)) & 0xff);
            $csr = (($chr + $csr) * $cc1 + $cc2) % 65_536;
        }

        return $eplain;
    }

    /**
     * Extract eexec info
     *
     * @return array<int, array<int, string>>
     */
    protected function extractEplainInfo(): array
    {
        $eplain = $this->getEplain();
        if (\preg_match('#/ForceBold\s*+(\S*+)#', $eplain, $matches) > 0 && $matches[1] == 'true') {
            $this->fdt['Flags'] |= 0x4_0000;
        }

        $this->extractStem($eplain);
        if (\preg_match('#/BlueValues\s*+\[([^]]*+)#', $eplain, $matches) === 1) {
            $bvl = \explode(' ', $matches[1]);
            if (\count($bvl) >= 6) {
                $vl1 = (int) $bvl[2];
                $vl2 = (int) $bvl[4];
                $this->fdt['XHeight'] = \min($vl1, $vl2);
                $this->fdt['CapHeight'] = \max($vl1, $vl2);
            }
        }

        $this->getRandomBytes($eplain);
        return $this->getCharstringData($eplain);
    }

    /**
     * Extract eexec info
     *
     * @param string $eplain Decoded eexec encrypted part
     */
    protected function extractStem(string $eplain): void
    {
        if (\preg_match('#/StdVW\s*+\[([^]]*+)#', $eplain, $matches) > 0) {
            $this->fdt['StemV'] = (int) $matches[1];
        } elseif (\in_array($this->fdt['weight'], ['bold', 'black'])) {
            $this->fdt['StemV'] = 123;
        } else {
            $this->fdt['StemV'] = 70;
        }

        $this->fdt['StemH'] = \preg_match('#/StdHW\s*+\[([^]]*+)#', $eplain, $matches) === 1 ? (int) $matches[1] : 30;

        if (\preg_match('#/CapX?Height\s*+\[([^]]*+)#', $eplain, $matches) === 1) {
            $this->fdt['CapHeight'] = (int) $matches[1];
        } else {
            $this->fdt['CapHeight'] = (int) $this->fdt['Ascent'];
        }

        $this->fdt['XHeight'] = (int) $this->fdt['Ascent'] + (int) $this->fdt['Descent'];
    }

    /**
     * Get the number of random bytes at the beginning of charstrings
     */
    protected function getRandomBytes(string $eplain): void
    {
        $this->fdt['lenIV'] = 4;
        if (\preg_match('#/lenIV\s*+(\d*+)#', $eplain, $matches) === 1) {
            $this->fdt['lenIV'] = (int) $matches[1];
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    protected function getCharstringData(string $eplain): array
    {
        $this->fdt['enc_map'] = [];
        $charstringsPos = \strpos($eplain, '/CharStrings');
        if ($charstringsPos === false) {
            return [];
        }

        $eplain = \substr($eplain, $charstringsPos + 1);
        $matches = [];
        \preg_match_all('#/([A-Za-z0-9\.]*+)[\s][0-9]+[\s]RD[\s](.*)[\s]ND#sU', $eplain, $matches, PREG_SET_ORDER);
        /** @var array<int, array<int, string>> $matches */
        if ($this->fdt['enc'] === '') {
            return $matches;
        }

        if (!isset(Encoding::MAP[$this->fdt['enc']])) {
            return $matches;
        }

        $this->fdt['enc_map'] = Encoding::MAP[$this->fdt['enc']];
        return $matches;
    }

    /**
     * get CID
     *
     * @param array<string, int> $imap
     * @param array<int, string> $val
     */
    protected function getCid(array $imap, array $val): int
    {
        if (isset($imap[$val[1]])) {
            return $imap[$val[1]];
        }

        if ($this->fdt['enc_map'] === false) {
            return 0;
        }

        $cid = \array_search($val[1], $this->fdt['enc_map'], true);

        if ($cid === false) {
            return 0;
        }

        if ($cid > 1000) {
            return 1000;
        }

        return (int) $cid;
    }

    /**
     * Helper function to decode number when $ccom[$idx] = 255
     *
     * @param int $idx
     * @param int $cck
     * @param array<int, int> $ccom
     * @param array<int, int> $cdec
     *
     * @return int
     *
     * @throws FileException
     * @throws FontException
     */
    private function decodeNumberHelper255(int $idx, int $cck, array $ccom, array &$cdec): int
    {
        $sval = \array_reduce(
            \array_slice($ccom, $idx + 1, 4),
            function (string|false $combined, int $item): string|false {
                return $combined === false || $item < 0 || $item > 255 ? false : $combined . \chr($item);
            },
            '',
        );
        if (!$sval) {
            throw new FontException('Argument to chr must be between 0 <= number <= 255');
        }

        $vsval = Byte::unpackOrThrow('li', $sval);
        if (!\is_numeric($vsval['i'])) {
            throw new FontException('Unable to unpack number');
        }

        $cdec[$cck] = (int) $vsval['i'];
        return $idx + 5;
    }

    /**
     * Decode number
     *
     * @param array<int, int> $ccom
     * @param array<int, int> $cdec
     * @param array<int, int> $cwidths
     *
     * @throws FileException
     * @throws FontException
     */
    protected function decodeNumber(int $idx, int $cck, int $cid, array $ccom, array &$cdec, array &$cwidths): int
    {
        if ($ccom[$idx] == 255) {
            return $this->decodeNumberHelper255($idx, $cck, $ccom, $cdec);
        }

        if ($ccom[$idx] >= 251) {
            $cdec[$cck] = -(($ccom[$idx] - 251) * 256) - $ccom[$idx + 1] - 108;
            return $idx + 2;
        }

        if ($ccom[$idx] >= 247) {
            $cdec[$cck] = ($ccom[$idx] - 247) * 256 + $ccom[$idx + 1] + 108;
            return $idx + 2;
        }

        if ($ccom[$idx] >= 32) {
            $cdec[$cck] = $ccom[$idx] - 139;
            return $idx + 1;
        }

        $cdec[$cck] = $ccom[$idx];
        if ($cck <= 0) {
            return $idx + 1;
        }

        if ($cdec[$cck] != 13) {
            return $idx + 1;
        }

        // hsbw command: update width
        $cwidths[$cid] = $cdec[$cck - 1];
        return $idx + 1;
    }

    /**
     * Process Type1 font
     *
     * @throws FileException
     * @throws FontException
     */
    protected function process(): void
    {
        $this->storeFontData();
        $this->extractFontInfo();
        $imap = $this->getInternalMap();
        $matches = $this->extractEplainInfo();
        $cwidths = [];
        $cc1 = 52_845;
        $cc2 = 22_719;
        foreach ($matches as $match) {
            $cid = $this->getCid($imap, $match);
            // decrypt charstring encrypted part
            $csr = 4330; // charstring encryption constant
            $ccd = $match[2];
            $clen = \strlen($ccd);
            $ccom = [];
            for ($idx = 0; $idx < $clen; $idx++) {
                $chr = \ord($ccd[$idx]);
                $ccom[] = $chr ^ ($csr >> 8);
                $csr = (($chr + $csr) * $cc1 + $cc2) % 65_536;
            }

            // decode numbers
            $cdec = [];
            $cck = 0;
            $idx = $this->fdt['lenIV'];
            while ($idx < $clen) {
                $idx = $this->decodeNumber($idx, $cck, $cid, $ccom, $cdec, $cwidths);
                $cck++;
            }
        }

        $this->setCharWidths($cwidths);
    }
}

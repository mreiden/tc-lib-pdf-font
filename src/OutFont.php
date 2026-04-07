<?php

declare(strict_types=1);

/**
 * OutFont.php
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

namespace Com\Tecnick\Pdf\Font;

use Com\Tecnick\File\Compression;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Encrypt\Exception as EncException;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\PdfObjects\OutFontObjs;
use Com\Tecnick\Unicode\Data\Identity;

/**
 * Com\Tecnick\Pdf\Font\OutFont
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * @phpstan-import-type TFontDataCidInfo from \Com\Tecnick\Pdf\Font\Load
 * @phpstan-import-type TFontDataDesc from \Com\Tecnick\Pdf\Font\Load
 */
abstract class OutFont extends OutUtil
{
    private const COMPRESS_FILTER = ' /Filter/FlateDecode';

    /**
     * Current PDF object number
     */
    protected int $pon;

    /**
     * Encrypt object
     */
    protected Encrypt $enc;

    /**
     * Array mapping PDF object numbers to PDF object strings
     *
     * @var array<int, string>
     */
    protected array $pdfObjects = [];

    /**
     * Get the PDF output string for a CID-0 font.
     * A Type 0 CIDFont contains glyph descriptions based on the Adobe Type 1 font format
     *
     * @param array{
     *        'cidinfo': TFontDataCidInfo,
     *        'cw':  array<int, int>,
     *        'desc': TFontDataDesc,
     *        'dw': int,
     *        'enc': string,
     *        'i': int,
     *        'n': int,
     *        'name': string,
     *        'subset': bool,
     *        'subsetchars': array<int, int>,
     *    } $font Font to process
     *
     * @return string
     *
     * @throws EncException
     */
    protected function getCid0(array $font): string
    {
        $cidoffset = 0;
        if (!isset($font['cw'][1])) {
            $cidoffset = 31;
        }

        $this->uniToCid($font, $cidoffset);
        $name = $font['name'];
        $longname = $name;
        if (!empty($font['enc'])) {
            $longname .= '-' . $font['enc'];
        }

        \assert($font['n'] === $this->pon);
        $pdfRefType0 = $font['n'];
        $pdfRefCIDFontType0 = ++$this->pon;
        $pdfRefFontDescriptor = ++$this->pon;

        $objSprintf = <<<'OUT'
        %1$d 0 obj
        <</Type/Font /Subtype/Type0 /BaseFont/%2$s /Name/F%3$d %4$s /DescendantFonts [%5$d 0 R] >>
        endobj
        %5$d 0 obj
        <</Type/Font /Subtype/CIDFontType0 /BaseFont/%6$s /CIDSystemInfo %7$s /FontDescriptor %8$d
        /DW %9$s %10$s >>
        endobj
        %8$d O obj
        <</Type/FontDescriptor /FontName/%6$s %11$s >>
        endobj

        OUT;
        return \vsprintf($objSprintf, [
            $pdfRefType0,
            $longname,
            $font['i'],
            empty($font['enc']) ? '' : "/Encoding/{$font['enc']}",
            $pdfRefCIDFontType0,
            $name,
            \vsprintf('<< /Registry %s /Ordering %s /Supplement %d >>', [
                $this->enc->escapeDataString($font['cidinfo']['Registry'], $pdfRefCIDFontType0),
                $this->enc->escapeDataString($font['cidinfo']['Ordering'], $pdfRefCIDFontType0),
                $font['cidinfo']['Supplement'],
            ]),
            $pdfRefFontDescriptor,
            $font['dw'],
            $this->getCharWidths($font, $cidoffset),
            \array_reduce(
                \array_keys($font['desc']),
                fn(string $str, $key) => $str . $this->getKeyValOut($key, $font['desc'][$key]),
                '',
            ),
        ]);
    }

    /**
     * Convert Unicode to CID
     *
     * @param array{
     *        'cidinfo': TFontDataCidInfo,
     *        'cw':  array<int, int>,
     *        'desc': TFontDataDesc,
     *        'dw': int,
     *        'enc': string,
     *        'i': int,
     *        'n': int,
     *        'name': string,
     *        'subset': bool,
     *        'subsetchars': array<int, int>,
     *    } $font Font to process
     * @param int $cidoffset Offset for CID values
     */
    protected function uniToCid(array &$font, int $cidoffset): void
    {
        // convert unicode to cid.
        $uni2cid = $font['cidinfo']['uni2cid'];
        $chw = [];
        foreach ($font['cw'] as $uni => $width) {
            if (isset($uni2cid[$uni])) {
                $chw[$uni2cid[$uni] + $cidoffset] = $width;
            } elseif ($uni < 256) {
                $chw[$uni] = $width;
            } // else unknown character
        }

        $font['cw'] = \array_merge($font['cw'], $chw);
    }

    private function pdfNameOrId(string|int $nameOrId, bool $isObjInsteadOfRef = false): string
    {
        return !\is_int($nameOrId)
            ? '/' . $this->enc->encodeNameObject($nameOrId)
            : ($isObjInsteadOfRef
                ? "$nameOrId 0 obj"
                : " $nameOrId 0 R");
    }

    private function compressFilter(string &$data, bool $condition = true): string
    {
        if (!$condition) {
            return '';
        }

        $data = Compression::compress($data);

        return self::COMPRESS_FILTER;
    }

    /**
     * Get the PDF output string for a TrueTypeUnicode font.
     * Based on PDF Reference 1.3 (section 5)
     *
     * @param array{
     *        'cidinfo': TFontDataCidInfo,
     *        'compress': bool,
     *        'ctg': string,
     *        'cw':  array<int, int>,
     *        'desc': TFontDataDesc,
     *        'dir': string,
     *        'dw': int,
     *        'enc': string,
     *        'file_n': int,
     *        'i': int,
     *        'n': int,
     *        'name': string,
     *        'subset': bool,
     *        'subsetchars': array<int, int>,
     *    } $font Font to process
     *
     * @return string
     *
     * @throws EncException
     * @throws FontException
     */
    protected function getTrueTypeUnicode(array $font): string
    {
        $pdfRefDescendantFont = ++$this->pon;
        $pdfRefCIDFontType2 = $pdfRefDescendantFont;
        $pdfRefFontDescriptor = ++$this->pon;

        $CIDtoGIDMap = '';
        $fontname = $font['name'];
        if ($font['subset']) {
            $pdfRefFontEncoding = 'Identity-H';
            $pdfRefCIDToGIDMap = 'Identity';
            $CIDtoGIDMap = "/CIDToGIDMap/$pdfRefCIDToGIDMap";

            // ToUnicode PDF CMap: Custom object for subset
            $pdfRefToUnicode = ++$this->pon;
            $cidhmap = $font['toUnicodeCMap'] ?? Identity::CIDHMAP;
            $filterCompress = $this->compressFilter($cidhmap, $font['compress']);
            $stream = $this->enc->encryptString($cidhmap, $pdfRefToUnicode);
            $this->pdfObjects[$pdfRefToUnicode] = \vsprintf(OutFontObjs::TO_UNICODE, [
                $this->pdfNameOrId($pdfRefToUnicode, true),
                \strlen($stream),
                $filterCompress,
                $stream,
            ]);

            // change name for font subsetting
            $subtag = \sprintf('%06u', $font['i']);
            $subtag = \strtr($subtag, '0123456789', 'ABCDEFGHIJ');
            $fontname = $subtag . '+' . $fontname;
        } else {
            $pdfRefFontEncoding = ++$this->pon;
            // ToUnicode map: Identity-H for non-subset
            $pdfRefToUnicode = 'Identity-H';

            if (!empty($font['ctg'])) {
                // Embed CIDToGIDMap: A specification of the mapping from CIDs to glyph indices
                $pdfRefCIDToGIDMap = ++$this->pon;
                $CIDtoGIDMap = "/CIDToGIDMap $pdfRefCIDToGIDMap 0 R";
                // search and get CTG font file to embed
                $fontfile = $this->getFontFullPath($font['dir'], \strtolower($font['ctg']));
                $content = \file_get_contents($fontfile);
                if ($content === false) {
                    throw new FontException('Unable to read font file: ' . $fontfile);
                }
                $stream = $this->enc->encryptString($content, $this->pon);
                $this->pdfObjects[$pdfRefCIDToGIDMap] = \vsprintf(OutFontObjs::CID_TO_GID_MAP, [
                    $pdfRefCIDToGIDMap,
                    \strlen($stream),
                    // stream is compressed if $fontfile ends with '.z'
                    \str_ends_with($fontfile, '.z') ? self::COMPRESS_FILTER : '',
                    $stream,
                ]);
            }
        }

        // Type0 Font: A composite font composed of other fonts, organized hierarchically
        $this->pdfObjects[$font['n']] = \vsprintf(OutFontObjs::TYPE0, [
            $this->pdfNameOrId($font['n'], true),
            $fontname,
            'F' . $font['i'],
            $this->pdfNameOrId($pdfRefFontEncoding),
            '/ToUnicode' . $this->pdfNameOrId($pdfRefToUnicode),
            $this->pdfNameOrId($pdfRefDescendantFont),
        ]);

        // CIDFontType2: A CIDFont whose glyph descriptions are based on TrueType font technology
        $this->pdfObjects[$pdfRefCIDFontType2] = \vsprintf(OutFontObjs::CID_FONT_TYPE2, [
            $this->pdfNameOrId($pdfRefCIDFontType2, true),
            $fontname,
            // CIDSystemInfo
            \vsprintf('<< /Registry %s /Ordering %s /Supplement %d >>', [
                $this->enc->escapeDataString($font['cidinfo']['Registry'], $pdfRefCIDFontType2),
                $this->enc->escapeDataString($font['cidinfo']['Ordering'], $pdfRefCIDFontType2),
                $font['cidinfo']['Supplement'],
            ]),
            $CIDtoGIDMap,
            $this->pdfNameOrId($pdfRefFontDescriptor),
            $font['dw'],
            $this->getCharWidths($font, 0),
        ]);

        // Font descriptor: A font descriptor describing the CIDFont default metrics other than its glyph widths
        $this->pdfObjects[$pdfRefFontDescriptor] = \vsprintf(OutFontObjs::FONT_DESCRIPTOR, [
            $pdfRefFontDescriptor,
            $fontname,
            \array_reduce(
                \array_keys($font['desc']),
                fn(string $str, $key) => $str . $this->getKeyValOut($key, $font['desc'][$key]),
                '',
            ),
            $this->pdfNameOrId($font['file_n']),
        ]);

        \ksort($this->pdfObjects);
        return implode("\n", $this->pdfObjects) . "\n";
    }

    /**
     * Get the PDF output string for a Core font.
     *
     * @param array{
     *        'family': string,
     *        'i': int,
     *        'n': int,
     *        'name': string,
     *    } $font Font to process
     *
     * @return string
     */
    protected function getCore(array $font): string
    {
        $encoding = \in_array($font['family'], ['symbol', 'zapfdingbats']) ? '' : '/Encoding /WinAnsiEncoding';
        return <<<OUT
        {$font['n']} 0 obj
        << /Type/Font /Subtype/Type1 /BaseFont/{$font['name']} /Name/F{$font['i']} $encoding >>
        endobj

        OUT;
    }

    /**
     * Get the PDF output string for a Core font.
     *
     * @param array{
     *        'cw':  array<int, int>,
     *        'desc': TFontDataDesc,
     *        'diff_n': int,
     *        'dw': int,
     *        'enc': string,
     *        'file': string,
     *        'file_n': int,
     *        'i': int,
     *        'n': int,
     *        'name': string,
     *        'type': string,
     *    } $font Font to process
     *
     * @return string
     */
    protected function getTrueType(array $font): string
    {
        $pdfRefFontDescriptor = ++$this->pon;

        $pdfObjects = [];
        $pdfObjects[$font['n']] = \vsprintf(OutFontObjs::TRUE_TYPE, [
            $font['n'],
            $font['type'],
            $font['name'],
            $font['i'],
            \array_reduce(
                \range(32, 255),
                fn(string $combined, int $idx): string => "$combined " . ($font['cw'][$idx] ?? $font['dw']),
                '',
            ),
            empty($font['enc'])
                ? ''
                : (empty($font['diff_n'])
                    ? '/Encoding/WinAnsiEncoding'
                    : "/Encoding {$font['diff_n']} O R"),
        ]);

        // Font descriptor: A font descriptor describing the default metrics other than its glyph widths
        $pdfObjects[$pdfRefFontDescriptor] = \vsprintf(OutFontObjs::TRUE_TYPE_FONT_DESCRIPTOR, [
            $pdfRefFontDescriptor,
            $font['name'],
            \array_reduce(
                \array_keys($font['desc']),
                fn(string $str, $key) => $str . $this->getKeyValOut($key, $font['desc'][$key]),
                '',
            ),
            empty($font['file'])
                ? ''
                : \sprintf(' /FontFile%s %d 0 R', $font['type'] == 'Type1' ? '' : '2', $font['file_n']),
        ]);

        return \implode("\n", $pdfObjects) . "\n";
    }

    /**
     * Returns the formatted key/value PDF string
     *
     * @param string           $key Key name
     * @param string|int|float $val Value
     *
     * @return string
     */
    protected function getKeyValOut(string $key, string|int|float $val): string
    {
        if (\is_float($val)) {
            $val = \sprintf('%F', $val);
        }

        return " /$key $val";
    }
}

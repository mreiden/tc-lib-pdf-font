<?php

declare(strict_types=1);

/**
 * Load.php
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

use Com\Tecnick\File\Dir;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Enum\FontTypes;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;

/**
 * Com\Tecnick\Pdf\Font\Load
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 *
 * @phpstan-type TFontDataCidInfoWithEncoding array{
 *     enc: string,
 *     Ordering: string,
 *     Registry: string,
 *     Supplement: int,
 *     uni2cid: array<int, int>,
 * }
 *
 * @phpstan-type TFontDataCidInfo array{
 *     Ordering: string,
 *     Registry: string,
 *     Supplement: int,
 *     uni2cid: array<int, int>,
 * }
 *
 * @phpstan-type TFontDataDesc array{
 *     Ascent: int,
 *     AvgWidth: int,
 *     CapHeight: int,
 *     Descent: int,
 *     Flags: int,
 *     FontBBox: string,
 *     ItalicAngle: int,
 *     Leading: int,
 *     MaxWidth: int,
 *     MissingWidth: int,
 *     StemH: int,
 *     StemV: int,
 *     XHeight: int,
 * }
 *
 * @phpstan-type TFontDataEncTable array{
 *     encodingId: int,
 *     offset: int,
 *     platformId: int,
 * }
 *
 * @phpstan-type TFontDataMode array{
 *     bold: bool,
 *     italic: bool,
 *     linethrough: bool,
 *     overline: bool,
 *     underline: bool,
 * }
 *
 * @phpstan-type TFontDataTableItem array{
 *     checkSum: int,
 *     data: string,
 *     length: int,
 *     offset: int,
 *     length_nul_padding: int,
 * }
 *
 * @phpstan-type TFontDataTableSubset array{
 *     hmtx: array{
 *         hMetrics: list<array<int,int>>,
 *         lsbOnly: list<int>,
 *     },
 *     name: array{
 *         nameIds: array<int, string>,
 *         platformId?: int,
 *         encodingId?: int,
 *         languageId?: int,
 *     },
 * }
 *
 * @phpstan-type TFontData array{
 *     Ascender: int,
 *     Ascent: int,
 *     AvgWidth: float,
 *     CapHeight: int,
 *     CharacterSet: string,
 *     Descender: int,
 *     Descent: int,
 *     EncodingScheme: string,
 *     FamilyName: string,
 *     Flags: int,
 *     FontBBox: array<int>,
 *     FontName: string,
 *     FullName: string,
 *     IsFixedPitch: bool,
 *     ItalicAngle: int,
 *     Leading: int,
 *     MaxWidth: int,
 *     MissingWidth: int|null,
 *     StdHW: int,
 *     StdVW: int,
 *     StemH: int,
 *     StemV: int,
 *     UnderlinePosition: int,
 *     UnderlineThickness: int,
 *     Version: string,
 *     Weight: string,
 *     XHeight: int,
 *     bbox: string,
 *     cbbox: array<int, array<int, int>>,
 *     cidinfo: TFontDataCidInfo,
 *     compress: bool,
 *     ctg: string,
 *     ctgdata: array<int, int>,
 *     cw:  array<int, int>,
 *     cwu:  array<int, int>,
 *     datafile: string,
 *     desc: TFontDataDesc,
 *     diff: string,
 *     diff_n: int,
 *     diffid?: int,
 *     dir: string,
 *     dw: int,
 *     enc: string,
 *     enc_map: array<int, string>,
 *     encodingTables: array<int, TFontDataEncTable>,
 *     encoding_id: int,
 *     encrypted: string,
 *     fakestyle: bool,
 *     family: string,
 *     file: string,
 *     file_n: int,
 *     file_name: string,
 *     i: int,
 *     ifile: string,
 *     indexToLoc: array<int, int>,
 *     input_file: string,
 *     isUnicode: bool,
 *     italicAngle: float,
 *     key: string,
 *     lenIV: int,
 *     length1: int,
 *     length2: int,
 *     linked: bool,
 *     mode: TFontDataMode,
 *     n: int,
 *     name: string,
 *     numGlyphs: int,
 *     numHMetrics: int,
 *     originalsize: int,
 *     pdfa: bool,
 *     platform_id: int,
 *     postscriptGlyphNames: list<int|string>,
 *     settype: string,
 *     short_offset: bool,
 *     size1: int,
 *     size2: int,
 *     style: string,
 *     subset: bool,
 *     subsetchars: array<int, int>,
 *     table: array<string, TFontDataTableItem>,
 *     tableSubset: TFontDataTableSubset,
 *     tot_num_glyphs: int,
 *     type: string,
 *     underlinePosition: int,
 *     underlineThickness: int,
 *     unicode: bool,
 *     unitsPerEm: int,
 *     up: int,
 *     urk: float,
 *     ut: int,
 *     weight: string,
 * }
 */
abstract class Load
{
    /**
     * File helper used to load font definition files.
     */
    protected ObjFile $fileHelper;

    /**
     * True when the file helper is created internally by this class.
     */
    protected bool $ownsFileHelper = false;

    /**
     * Font data
     *
     * Adds the 'protected TFontData $fdt' property shared with Subset
     */
    use FontDataTrait;

    /**
     * @param ObjFile|null $fileHelper Optional file helper for font loading.
     */
    public function __construct(?ObjFile $fileHelper = null)
    {
        $this->ownsFileHelper = $fileHelper === null;
        $this->fileHelper = $fileHelper ?? new ObjFile(allowedPaths: $this->buildAllowedPaths());
    }

    /**
     * Load the font data
     *
     * @throws FileException in case of error
     * @throws FontException in case of error
     */
    public function load(): void
    {
        $this->getFontInfo();
        $this->checkType();
        $this->setName();
        $this->setDefaultWidth();
        if ($this->fdt['fakestyle']) {
            $this->setArtificialStyles();
        }

        $this->setFileData();
    }

    /**
     * Load the font data
     *
     * @throws FileException in case of error
     * @throws FontException in case of error
     */
    protected function getFontInfo(): void
    {
        $this->findFontFile();

        if ($this->ownsFileHelper) {
            $this->fileHelper->setAllowedPaths($this->buildAllowedPaths());
        }

        // read the font definition file
        $fdt = $this->fileHelper->getFileData($this->fdt['ifile']);
        if ($fdt === false) {
            throw new FontException('unable to read file: ' . $this->fdt['ifile']);
        }

        /** @var array<string, mixed>|null $fdtdata */
        $fdtdata = \json_decode($fdt, true, 10, JSON_OBJECT_AS_ARRAY);
        if ($fdtdata === null) {
            throw new FontException('JSON decoding error [' . \json_last_error() . ']');
        }

        if (!\is_array($fdtdata) || !isset($fdtdata['type'])) {
            throw new FontException('fhe font definition file has a bad format: ' . $this->fdt['ifile']);
        }

        /** @var TFontData $fdtdata */
        $fdtdata = \array_replace_recursive($this->fdt, $fdtdata);

        $this->fdt = $fdtdata;
    }

    /**
     * Returns a list of font directories
     *
     * @return array<string> Font directories
     */
    protected function findFontDirectories(): array
    {
        $dir = new Dir();
        $dirs = [''];

        if (\defined('K_PATH_FONTS') && \is_string(\K_PATH_FONTS)) {
            $dirs[] = K_PATH_FONTS;
            $glb = \glob(K_PATH_FONTS . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($glb !== false) {
                $dirs = [...$dirs, ...$glb];
            }
        }

        // Include the library's bundled input fonts and the bulk-converted output fonts
        // (FontPaths::getOutputPath(), e.g. target/fonts) so they are discoverable without K_PATH_FONTS.
        foreach ([FontPaths::getInputPath(), FontPaths::getOutputPath()] as $base) {
            if (\is_dir($base)) {
                $dirs[] = $base;
                $glb = \glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
                if ($glb !== false) {
                    $dirs = [...$dirs, ...$glb];
                }
            }
        }

        $parent_font_dir = $dir->findParentDir('fonts', __DIR__);
        if ($parent_font_dir !== '' && $parent_font_dir !== '/') {
            $dirs[] = $parent_font_dir;
            $glb = \glob($parent_font_dir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
            if ($glb !== false) {
                $dirs = \array_merge($dirs, $glb);
            }
        }

        return \array_unique($dirs);
    }

    /**
     * Build trusted roots for local font definition loading.
     *
     * @return array<string>
     */
    protected function buildAllowedPaths(): array
    {
        return FontPaths::buildAllowedPaths();
    }

    /**
     * Load the font data
     */
    protected function findFontFile(): void
    {
        if ($this->fdt['ifile'] !== '') {
            $this->fdt['dir'] = \dirname($this->fdt['ifile']);
            return;
        }

        $this->fdt['ifile'] = \strtolower($this->fdt['key']) . '.json';

        // find font definition file names
        $files = \array_unique([\strtolower($this->fdt['key']) . '.json', \strtolower($this->fdt['family']) . '.json']);

        // directories where to search for the font definition file
        $dirs = $this->findFontDirectories();

        foreach ($files as $file) {
            foreach ($dirs as $dir) {
                if (\is_readable($dir . DIRECTORY_SEPARATOR . $file)) {
                    $this->fdt['ifile'] = $dir . DIRECTORY_SEPARATOR . $file;
                    $this->fdt['dir'] = $dir;
                    break 2;
                }
            }

            // we have not found the version with style variations
            $this->fdt['fakestyle'] = true;
        }
    }

    protected function setDefaultWidth(): void
    {
        if ($this->fdt['dw'] !== 0) {
            return;
        }

        $this->fdt['dw'] = match(true) {
            $this->fdt['desc']['MissingWidth'] > 0 => $this->fdt['desc']['MissingWidth'],
            !empty($this->fdt['cw'][32]) => $this->fdt['cw'][32],
            default => 600
        };
    }

    /**
     * Check Font Type
     *
     * @throws FontException on unknown font type
     */
    protected function checkType(): void
    {
        if (FontTypes::tryFrom($this->fdt['type']) === null) {
            throw new FontException('Unknown font type: ' . $this->fdt['type']);
        }
    }

    /**
     * @return void
     *
     * @throws FontException on using a CID0 font in a pdfa
     */
    protected function setName(): void
    {
        $fontType = FontTypes::tryFrom($this->fdt['type']);

        if ($fontType == FontTypes::Core) {
            $this->fdt['name'] = Core::FONT[$this->fdt['key']] ?? '';
            $this->fdt['subset'] = false;
        } elseif ($fontType == FontTypes::Type1 || $fontType == FontTypes::TrueType) {
            $this->fdt['subset'] = false;
        } elseif ($fontType == FontTypes::TrueTypeUnicode) {
            $this->fdt['enc'] = 'Identity-H';
        } elseif ($fontType == FontTypes::cidfont0 && $this->fdt['pdfa']) {
            throw new FontException('CID0 fonts are not supported, all fonts must be embedded in PDF/A mode!');
        }

        if ($this->fdt['name'] === '') {
            $this->fdt['name'] = $this->fdt['key'];
        }
    }

    /**
     * Set artificial styles if the font variation file is missing
     */
    protected function setArtificialStyles(): void
    {
        // artificial bold
        if ($this->fdt['mode']['bold']) {
            $this->fdt['name'] .= 'Bold';
            $this->fdt['desc']['StemV'] = $this->fdt['desc']['StemV'] === 0
                ? 123
                : (int) \round($this->fdt['desc']['StemV'] * 1.75);
        }

        // artificial italic
        if ($this->fdt['mode']['italic']) {
            $this->fdt['name'] .= 'Italic';
            if ($this->fdt['desc']['ItalicAngle'] !== 0) {
                $this->fdt['desc']['ItalicAngle'] -= 11;
            } else {
                $this->fdt['desc']['ItalicAngle'] = -11;
            }

            if ($this->fdt['desc']['Flags'] !== 0) {
                $this->fdt['desc']['Flags'] |= 64; //bit 7
            } else {
                $this->fdt['desc']['Flags'] = 64;
            }
        }
    }

    public function setFileData(): void
    {
        if ($this->fdt['file'] === '') {
            return;
        }

        $fontType = FontTypes::tryFrom($this->fdt['type']);
        if (\in_array($fontType, [FontTypes::TrueType, FontTypes::TrueTypeUnicode])) {
            $this->fdt['length1'] = $this->fdt['originalsize'];
            $this->fdt['length2'] = 0;
        } elseif ($fontType == FontTypes::Core) {
            $this->fdt['length1'] = $this->fdt['size1'];
            $this->fdt['length2'] = $this->fdt['size2'];
        }
    }
}

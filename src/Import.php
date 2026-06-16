<?php

declare(strict_types=1);

/**
 * Import.php
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

use Com\Tecnick\File\Byte;
use Com\Tecnick\File\Compression;
use Com\Tecnick\File\Dir;
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Font\Enum\FontTypes;
use Com\Tecnick\Pdf\Font\Exception as FontException;
use Com\Tecnick\Pdf\Font\Import\Core;
use Com\Tecnick\Pdf\Font\Import\TrueType;
use Com\Tecnick\Pdf\Font\Import\TypeOne;
use Com\Tecnick\Pdf\Font\Trait\FontDataTrait;
use Com\Tecnick\Unicode\Data\Encoding;
use RangeException;

/**
 * Com\Tecnick\Pdf\Font\Import
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
 * @phpstan-import-type TFontDataCidInfoWithEncoding from \Com\Tecnick\Pdf\Font\Load
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 */
class Import
{
    use FontDataTrait;

    /**
     * File helper used to load font definition files.
     */
    protected ObjFile $fileHelper;

    /**
     * True when the file helper is created internally by this class.
     */
    protected bool $ownsFileHelper = false;

    /**
     * Content (binary) of the input font file
     */
    protected string $font = '';

    /**
     * Object used to read font bytes
     */
    protected Byte $fbyte;

    /**
     * Import the specified font and create output files.
     *
     * @param string $file        Font file to process
     * @param string $output_path Output path for generated font files (must be writeable by the web server).
     *                            Leave null for default font folder.
     * @param string $type        Font type. Leave empty for autodetect mode. Valid values are:
     *                            Core (AFM - Adobe Font Metrics) TrueTypeUnicode TrueType
     *                            Type1 CID0JP (CID-0 Japanese) CID0KR (CID-0 Korean) CID0CS
     *                            (CID-0 Chinese Simplified) CID0CT (CID-0 Chinese Traditional)
     * @param string $encoding    Name of the encoding table to use. Leave empty for default mode.
     *                            Omit this parameter for TrueType Unicode and symbolic fonts like
     *                            Symbol or ZapfDingBats.
     * @param int    $flags       Unsigned 32-bit integer containing flags specifying various characteristics
     *                            of the font as described in "PDF32000:2008 - 9.8.2 Font Descriptor Flags":
     *                            +1 for fixed width font +4 for symbol or +32 for non-symbol +64 for italic
     *                            Note: Fixed and Italic mode are generally autodetected, so you have to set
     *                            it to 32 = non-symbolic font (default) or 4 = symbolic font.
     * @param int    $platform_id Platform ID for CMAP table to extract.
     *                            For a Unicode font for Windows this
     *                            value should be 3, for Macintosh
     *                            should be 1.
     * @param int    $encoding_id Encoding ID for CMAP table to extract.
     *                            For a Unicode font for Windows this
     *                            value should be 1, for Macintosh
     *                            should be 0. When Platform ID is 3,
     *                            legal values for Encoding ID are: 0 =
     *                            Symbol, 1 = Unicode, 2 = ShiftJIS, 3 =
     *                            PRC, 4 = Big5, 5 = Wansung, 6 = Johab,
     *                            7 = Reserved, 8 = Reserved, 9 =
     *                            Reserved, 10 = UCS-4.
     * @param bool   $linked      If true, links the font file to system font instead of copying the font data
     *                            (not transportable). Note: this option does not work with Type1 fonts.
     * @param ObjFile|null $fileHelper Optional file helper for font loading.
     *
     * @throws FileException in case of error
     * @throws FontException in case of error
     * @throws RangeException in case of byte-range errors
     */
    public function __construct(
        string $file,
        string $output_path = '',
        string $type = '',
        string $encoding = '',
        int $flags = 32,
        int $platform_id = 3,
        int $encoding_id = 1,
        bool $linked = false,
        ?ObjFile $fileHelper = null,
        bool $save = true,
    ) {
        $this->ownsFileHelper = $fileHelper === null;
        $this->fileHelper = $fileHelper ?? new ObjFile(allowedPaths: self::buildAllowedPaths($file));
        $validatedFile = $file;

        if (!$this->fileHelper->isValidFile($validatedFile)) {
            throw new FontException('Invalid font file name: ' . $file);
        }

        $this->fdt['input_file'] = $file;
        $this->fdt['file_name'] = $this->makeFontName($file);
        if ($this->fdt['file_name'] === '') {
            throw new FontException('the font name is empty');
        }

        $this->fdt['dir'] = $this->findOutputPath($output_path);
        if ($this->ownsFileHelper) {
            $this->fileHelper->setAllowedPaths(self::buildAllowedPaths($file, $this->fdt['dir']));
        }

        $this->fdt['datafile'] = $this->fdt['dir'] . $this->fdt['file_name'] . '.json';
        if (\file_exists($this->fdt['datafile'])) {
            throw new FontException('this font has been already imported: ' . $this->fdt['datafile']);
        }

        // get font data
        if (!\is_file($file)) {
            throw new FontException("invalid font file: $file");
        }

        if (($font = $this->fileHelper->getLocalFileData($file)) === false) {
            throw new FontException("unable to read the input font file: $file");
        }

        $this->font = $font;

        $this->fbyte = new Byte($this->font);

        $ftype = $this->getFontType($type);
        $this->fdt['settype'] = $type;
        $this->fdt['type'] = $ftype->name;
        $this->fdt['isUnicode'] = \in_array($ftype, [FontTypes::TrueTypeUnicode, FontTypes::cidfont0]);
        $this->fdt['Flags'] = $flags;
        $this->initFlags();
        $this->fdt['enc'] = $this->getEncodingTable($encoding);
        $this->fdt['diff'] = $this->getEncodingDiff();
        $this->fdt['originalsize'] = \strlen($this->font);
        $this->fdt['ctg'] = $this->fdt['file_name'] . '.ctg.z';
        $this->fdt['platform_id'] = $platform_id;
        $this->fdt['encoding_id'] = $encoding_id;
        $this->fdt['linked'] = $linked;

        $processor = match ($ftype) {
            FontTypes::Core => new Core(font: $this->font, fdt: $this->fdt, fileHelper: $this->fileHelper),
            FontTypes::Type1 => new TypeOne(font: $this->font, fdt: $this->fdt, fileHelper: $this->fileHelper),
            default => new TrueType(
                font: $this->font,
                fdt: $this->fdt,
                fileHelper: $this->fileHelper,
                fbyte: $this->fbyte,
            ),
        };

        $this->fdt = $processor->getFontMetrics();

        if ($save) {
            $this->saveFontData();
        }
    }

    /**
     * Get all the extracted font metrics
     *
     * @return TFontData
     */
    public function getFontMetrics(): array
    {
        return $this->fdt;
    }

    /**
     * Get the output font name
     */
    public function getFontName(): string
    {
        return $this->fdt['file_name'];
    }

    /**
     * Initialize font flags from font name
     */
    protected function initFlags(): void
    {
        $filename = \strtolower(\basename($this->fdt['input_file']));

        if (
            \str_contains($filename, 'mono')
            || \str_contains($filename, 'courier')
            || \str_contains($filename, 'fixed')
        ) {
            $this->fdt['Flags'] |= 1;
        }

        if (\str_contains($filename, 'symbol') || \str_contains($filename, 'zapfdingbats')) {
            $this->fdt['Flags'] |= 4;
        }

        if (\str_contains($filename, 'italic') || \str_contains($filename, 'oblique')) {
            $this->fdt['Flags'] |= 64;
        }
    }

    /**
     * Check for unsafe path components that were previously rejected by the
     * file helper's internal validation.
     */
    private static function hasUnsafePath(string $path): bool
    {
        return (
            $path !== ''
            && (
                \str_contains($path, '://')
                || \str_contains(\str_ireplace('%2E', '.', \html_entity_decode($path, ENT_QUOTES, 'UTF-8')), '..')
            )
        );
    }

    /**
     * Build trusted roots for local file validation.
     *
     * The minimum roots required by Import are:
     * - the input font directory (read access)
     * - the output directory (write access), when available
     *
     * For each root we include both the given path and, when resolvable,
     * its canonical realpath to support symlinked directories.
     *
     * @return array<string>
     */
    private static function buildAllowedPaths(string $fontFile, string $outputDir = ''): array
    {
        $roots = [];

        $fontDir = \dirname($fontFile);
        if ($fontDir !== '' && $fontDir !== '.') {
            $roots[] = $fontDir;
        }

        if ($outputDir !== '') {
            $roots[] = $outputDir;
        }

        $allowed = [];
        foreach ($roots as $root) {
            $normalized = \rtrim($root, '/\\');
            if ($normalized === '') {
                continue;
            }

            $allowed[] = $normalized;

            $resolved = \realpath($normalized);
            if ($resolved !== false) {
                $allowed[] = \rtrim($resolved, '/\\');
            }
        }

        return \array_values(\array_unique($allowed));
    }

    /**
     * Save the exported metadata font file
     */
    protected function saveFontData(): void
    {
        $ftype = FontTypes::from($this->fdt['type']);
        if (\in_array($ftype, [FontTypes::TrueType, FontTypes::TrueTypeUnicode])) {
            // Create the PDF CIDToGIDMap with length = (256 * 256 * 2) = 131072
            $cidtogidmap = \str_repeat("\0", 131072);
            foreach ($this->fdt['ctgdata'] as $cid => $gid) {
                $this->updateCIDtoGIDmap($cidtogidmap, (int) $cid, (int) $gid);
            }

            // store compressed CIDToGIDMap
            $fpt = $this->fileHelper->fopenLocal($this->fdt['dir'] . $this->fdt['ctg'], 'wb');
            \fwrite($fpt, Compression::compress($cidtogidmap));
            \fclose($fpt);
        }

        // Create JSON file data
        $pfile = $this->createFontJSON();
        // Save JSON file
        $fpt = $this->fileHelper->fopenLocal($this->fdt['datafile'], 'wb');
        \fwrite($fpt, $pfile);
        \fclose($fpt);
    }

    /**
     * Creates the JSON string to save as the font metrics config.
     *
     * @return string
     *
     * @throws \JsonException
     */
    public function createFontJSON(): string
    {
        // Base JSON common to all font types
        $json = [
            'type' => $this->fdt['type'],
            'name' => $this->fdt['name'],
            'up' => $this->fdt['underlinePosition'],
            'ut' => $this->fdt['underlineThickness'],
            'dw' => $this->fdt['MissingWidth'] > 0 ? $this->fdt['MissingWidth'] : $this->fdt['AvgWidth'],
            'diff' => $this->fdt['diff'],
            'platform_id' => $this->fdt['platform_id'],
            'encoding_id' => $this->fdt['encoding_id'],
        ];

        $ftype = FontTypes::from($this->fdt['type']);
        if ($ftype === FontTypes::Core) {
            $json['enc'] = '';
        } elseif ($ftype === FontTypes::Type1) {
            $json['enc'] = $this->fdt['enc'];
            $json['file'] = $this->fdt['file'];
            $json['size1'] = $this->fdt['size1'];
            $json['size2'] = $this->fdt['size2'];
        } else {
            $json['originalsize'] = $this->fdt['originalsize'];
            if ($ftype === FontTypes::cidfont0) {
                $cidPreset = \json_decode(
                    (string) \file_get_contents(
                        __DIR__ . '/../resources/UniToCid/' . \basename($this->fdt['settype']) . '.json',
                    ),
                    true,
                    flags: \JSON_THROW_ON_ERROR,
                );
                if ($cidPreset) {
                    /** @var TFontDataCidInfoWithEncoding $cidPreset */
                    $json = \array_merge($json, $cidPreset);
                }
            } else {
                // TrueType
                $json['enc'] = $this->fdt['enc'];
                $json['file'] = $this->fdt['file'];
                $json['ctg'] = $this->fdt['ctg'];
            }
        }

        $json['isUnicode'] = (bool) $this->fdt['isUnicode'];
        $json['desc'] = [
            'Flags' => $this->fdt['Flags'],
            'FontBBox' => '[' . $this->fdt['bbox'] . ']',
            'ItalicAngle' => $this->fdt['italicAngle'],
            'Ascent' => $this->fdt['Ascent'],
            'Descent' => $this->fdt['Descent'],
            'Leading' => $this->fdt['Leading'],
            'CapHeight' => $this->fdt['CapHeight'],
            'XHeight' => $this->fdt['XHeight'],
            'StemV' => $this->fdt['StemV'],
            'StemH' => $this->fdt['StemH'],
            'AvgWidth' => $this->fdt['AvgWidth'],
            'MaxWidth' => $this->fdt['MaxWidth'],
            'MissingWidth' => $this->fdt['MissingWidth'],
        ];

        $this->addIfNotEmpty($json, 'cbbox');
        $this->addIfNotEmpty($json, 'cw');
        $this->addIfNotEmpty($json, 'cwu');

        $this->addIfNotEmpty($json, 'table');
        $this->addIfNotEmpty($json, 'tableSubset');

        // Create JSON file data
        return \json_encode($json, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Adds a key/value to an array if the value is not empty.
     *
     * @param array<string, mixed>     $array
     * @param string                   $key
     *
     * @return void
     */
    private function addIfNotEmpty(array &$array, string $key): void
    {
        if (!empty($this->fdt[$key])) {
            $array[$key] = $this->fdt[$key];
        }
    }

    /**
     * Returns the normalized font name from a font file.
     *
     * @param string $font_file
     * @return string
     *
     * @throws FontException
     */
    public static function fontNameFromFile(string $font_file): string
    {
        $filename = \trim(\pathinfo($font_file, PATHINFO_FILENAME));
        if (empty($filename)) {
            throw new FontException("Invalid font file name: $font_file");
        }

        $filename = \preg_replace('/[^a-z0-9_]/', '', \strtolower($filename));
        if ($filename === null) {
            throw new FontException("Invalid font file name: $font_file");
        }

        return \str_replace(['bold', 'oblique', 'italic', 'regular'], ['b', 'i', 'i', ''], $filename);
    }

    /**
     * Make the output font name
     *
     * @param string $font_file Input font file
     *
     * @throws FontException
     */
    protected function makeFontName(string $font_file): string
    {
        return static::fontNameFromFile($font_file);
    }

    /**
     * Find the path where to store the processed font.
     *
     * @param string $output_path Output path for generated font files (must be writeable by the web server).
     *                            Leave null for default font folder (K_PATH_FONTS).
     */
    protected function findOutputPath(string $output_path = ''): string
    {
        if ($output_path !== '' && !self::hasUnsafePath($output_path) && \is_writable($output_path)) {
            return $output_path;
        }

        if (\defined('K_PATH_FONTS') && \is_string(K_PATH_FONTS) && K_PATH_FONTS !== '' && \is_writable(K_PATH_FONTS)) {
            return K_PATH_FONTS;
        }

        $dirobj = new Dir();
        $dir = $dirobj->findParentDir('fonts', __DIR__);
        if ($dir === '/') {
            $dir = \sys_get_temp_dir();
        }

        if (!\str_ends_with($dir, '/')) {
            $dir .= '/';
        }

        return $dir;
    }

    /**
     * Get the font type
     *
     * @param string $font_type Font type. Leave empty for autodetect mode.
     *
     * @throws FontException
     */
    protected function getFontType(string $font_type): FontTypes
    {
        // autodetect font type
        if ($font_type === '') {
            if (\str_starts_with($this->font, 'StartFontMetrics')) {
                // AFM type - we use this type only for the 14 Core fonts
                return FontTypes::Core;
            }

            if (\str_starts_with($this->font, 'OTTO')) {
                throw new FontException('Unsupported font format: OpenType with CFF data');
            }

            if ($this->fbyte->getULong(0) === 0x1_0000) {
                return FontTypes::TrueTypeUnicode;
            }

            return FontTypes::Type1;
        }

        if (\str_starts_with($font_type, 'CID0')) {
            return FontTypes::cidfont0;
        }

        $ftype = FontTypes::tryFrom($font_type);
        if ($ftype) {
            return $ftype;
        }

        throw new FontException('unknown or unsupported font type: ' . $font_type);
    }

    /**
     * Get the encoding table
     *
     * @param string $encoding Name of the encoding table to use. Leave empty for default mode.
     *                         Omit this parameter for TrueType Unicode and symbolic fonts like
     *                         Symbol or ZapfDingBats.
     *
     * @throws FontException
     */
    protected function getEncodingTable(string $encoding = ''): string
    {
        if ($encoding === '') {
            if ($this->fdt['type'] === FontTypes::Type1->name && ($this->fdt['Flags'] & 4) === 0) {
                return 'cp1252';
            }

            return '';
        }

        $enc = \preg_replace('/[^A-Za-z0-9_\-]/', '', $encoding);
        if ($enc === null) {
            throw new FontException('Invalid encoding name: ' . $encoding);
        }

        return $enc;
    }

    /**
     * Get differences between the reference encoding (cp1252) and the current encoding.
     */
    protected function getEncodingDiff(): string
    {
        $ftype = FontTypes::from($this->fdt['type']);
        if (
            !\in_array($ftype, [FontTypes::TrueType, FontTypes::Type1]) ||
            !\is_string($this->fdt['enc']) ||
            $this->fdt['enc'] == 'cp1252' ||
            !isset(Encoding::MAP[$this->fdt['enc']])
        ) {
            return '';
        }

        // Use cp1252 as the reference encoding.
        $enc_ref = Encoding::MAP['cp1252'];
        // The font specific encoding
        $enc_target = Encoding::MAP[$this->fdt['enc']];

        $last = 0;
        $diff = '';
        for ($idx = 32; $idx <= 255; $idx++) {
            $target = $enc_target[$idx] ?? '';
            $ref = $enc_ref[$idx] ?? '';
            if ($target === $ref) {
                continue;
            }

            if ($idx != $last + 1) {
                $diff .= $idx . ' ';
            }

            $last = $idx;
            $diff .= '/' . $target . ' ';
        }

        return $diff;
    }

    /**
     * Update the CIDToGIDMap string with a new value
     *
     * The CIDToGIDMap is made up of 16-bit values mapping a zero-based
     * Character Identifier index to its zero-based glyph id index.
     *
     * @param string $map CIDToGIDMap (binary).
     * @param int $cid CID value.
     * @param int $gid GID value.
     */
    protected function updateCIDtoGIDmap(string &$map, int $cid, int $gid): void
    {
        if ($cid >= 0 && $cid <= 0xffff && $gid >= 0) {
            if ($gid > 0xffff) {
                $gid -= 0x1_0000;
            }

            // First byte is the high byte of the glyph id.
            $map[$cid * 2] = \chr(($gid >> 8) & 0xff);
            // Second byte is the low byte of the glyph id.
            $map[$cid * 2 + 1] = \chr($gid & 0xff);
        }
    }
}

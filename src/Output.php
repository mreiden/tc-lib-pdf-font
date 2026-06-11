<?php

declare(strict_types=1);

/**
 * Output.php
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
use Com\Tecnick\File\Exception as FileException;
use Com\Tecnick\File\File as ObjFile;
use Com\Tecnick\Pdf\Encrypt\Encrypt;
use Com\Tecnick\Pdf\Encrypt\Exception as EncException;
use Com\Tecnick\Pdf\Font\Exception as FontException;

/**
 * Com\Tecnick\Pdf\Font\Output
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
class Output extends OutFont
{
    /**
     * Array of character subsets for each font file
     *
     * @var array<string, array<int, int>>
     */
    protected array $subchars = [];

    /**
     * PDF string block with the fonts definitions
     */
    protected string $out = '';

    /**
     * Initialize font data
     *
     * @param array<string, TFontData> $fonts      Array of imported fonts data
     * @param int                      $pon        Current PDF Object Number
     * @param Encrypt                  $encrypt    Encrypt object
     * @param ObjFile                  $fileHelper File helper for font loading.
     *
     * @throws EncException
     * @throws FileException
     * @throws FontException
     */
    public function __construct(
        protected array $fonts,
        int $pon,
        Encrypt $encrypt,
        ?ObjFile $fileHelper = null,
    ) {
        $this->fileHelper = $fileHelper ?? new ObjFile(allowedPaths: $this->buildAllowedPaths());

        $this->pon = $pon;
        $this->enc = $encrypt;

        $this->out = $this->getEncodingDiffs();
        $this->out .= $this->getFontFiles();
        $this->out .= $this->getFontDefinitions();
    }

    /**
     * Build trusted roots for local font file loading.
     *
     * @return array<string>
     */
    private function buildAllowedPaths(): array
    {
        return FontPaths::buildAllowedPaths();
    }

    /**
     * Returns current PDF object number
     */
    public function getObjectNumber(): int
    {
        return $this->pon;
    }

    /**
     * Returns the PDF fonts block
     */
    public function getFontsBlock(): string
    {
        return $this->out;
    }

    /**
     * Get the PDF output string for Font resources dictionary.
     *
     * @param array<string, TFontData|array{'i': int, 'n': int}> $data Font data.
     *
     * @return string
     */
    private function getOutFontResources(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $out = ' /Font << ';
        foreach ($data as $font) {
            $out .= ' /F' . $font['i'] . ' ' . $font['n'] . ' 0 R';
        }

        return $out . ' >>';
    }

    /**
     * Get the PDF output string for Font resources dictionary.
     *
     * @return string
     */
    public function getOutFontDict(): string
    {
        return $this->getOutFontResources($this->fonts);
    }

    /**
     * Get the PDF output string for XOBject Font resources dictionary.
     *
     * @param array<string> $keys Array of font keys.
     *
     * @return string
     */
    public function getOutFontDictByKeys(array $keys): string
    {
        if ($keys === []) {
            return '';
        }

        $data = [];
        foreach ($keys as $key) {
            $data[$key] = [
                'i' => $this->fonts[$key]['i'],
                'n' => $this->fonts[$key]['n'],
            ];
        }

        return $this->getOutFontResources($data);
    }

    /**
     * Get the PDF output string for font encoding diffs
     *
     * @return string
     */
    protected function getEncodingDiffs(): string
    {
        $out = '';
        $done = []; // store processed items to avoid duplication
        foreach ($this->fonts as $fkey => $font) {
            if ($font['diff'] !== '') {
                $dkey = \md5($font['diff']);
                if (!isset($done[$dkey])) {
                    $this->pon++;

                    $out .= <<<OUT
                    $this->pon 0 obj
                    << /Type/Encoding /BaseEncoding/WinAnsiEncoding /Differences [{$font['diff']}] >>
                    endobj

                    OUT;
                    $done[$dkey] = $this->pon;
                }

                $this->fonts[$fkey]['diff_n'] = $done[$dkey];
            }

            // extract the character subset
            if ($font['file'] !== '') {
                $file_key = \md5($font['file']);
                $this->subchars[$file_key] = empty($this->subchars[$file_key])
                    ? $font['subsetchars']
                    : \array_merge($this->subchars[$file_key], $font['subsetchars']);
            }
        }

        return $out;
    }

    /**
     * Get the PDF output string for font files
     *
     * @return string
     *
     * @throws EncException
     * @throws FileException
     * @throws FontException
     */
    protected function getFontFiles(): string
    {
        $out = '';
        $done = []; // store processed items to avoid duplication
        foreach ($this->fonts as $fkey => $font) {
            if ($font['file'] === '') {
                continue;
            }

            $dkey = \md5($font['file']);
            if (!isset($done[$dkey])) {
                $fontfile = $this->getFontFullPath($font['dir'], $font['file']);
                $font_data = $this->fileHelper->getLocalFileData($fontfile);
                if ($font_data === false) {
                        throw new FontException("Unable to read font file: $fontfile");
                }

                if ($font['subset']) {
                    $font_data = Compression::uncompress($font_data);

                    // Create the font subset binary
                    $sub = new Subset($font_data, $font, $this->fileHelper, $this->subchars[$dkey]);
                    $font_data = $sub->getSubsetFont();

                    // Update the font data
                    $this->fonts[$fkey] = $sub->getFontData();
                    $this->fonts[$fkey]['length1'] = \strlen($font_data);

                    // Compress the font
                    $font_data = Compression::compress($font_data);
                }

                    $stream = $this->enc->encryptString($font_data, ++$this->pon);
                    $objSprintf = <<<'OUT'
                    %1$d 0 obj
                    << /Filter/FlateDecode /Length %2$d /Length1 %3$d /Length2 %4$d /Length3 0 >> stream
                    %5$s
                    endstream
                    endobj

                    OUT;
                    $out .= vsprintf($objSprintf, [
                        $this->pon,
                        \strlen($stream),
                        $this->fonts[$fkey]['length1'],
                        $this->fonts[$fkey]['length2'],
                        $stream,
                    ]);
                $done[$dkey] = $this->pon;
            }

            $this->fonts[$fkey]['file_n'] = $done[$dkey];
        }

        return $out;
    }

    /**
     * Get the PDF output string for fonts
     *
     * @return string
     *
     * @throws EncException
     * @throws FontException
     */
    protected function getFontDefinitions(): string
    {
        $out = '';
        foreach ($this->fonts as $font) {
            $out .= match (\strtolower($font['type'])) {
                'core' => $this->getCore($font),
                'cidfont0' => $this->getCid0($font),
                'type1', 'truetype' => $this->getTrueType($font),
                'truetypeunicode' => $this->getTrueTypeUnicode($font),
                default => throw new FontException('Unsupported font type: ' . $font['type']),
            };
        }

        return $out;
    }
}

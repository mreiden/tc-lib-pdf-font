<?php

declare(strict_types=1);

/**
 * Core.php
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

use Com\Tecnick\Pdf\Font\Exception as FontException;

/**
 * Com\Tecnick\Pdf\Font\Import\Core
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
class Core
{
    /**
     * @param string         $font     Content of the input font file
     * @param TFontData      $fdt      Extracted font metrics
     *
     * @throws FontException
     */
    public function __construct(protected readonly string $font, protected array $fdt)
    {
        // Initialize fdt values
        $this->fdt['AvgWidth'] = 0.0;
        $this->fdt['Leading'] = 0;
        $this->fdt['MissingWidth'] = 600;
        $this->fdt['cbbox'] = [];
        $this->fdt['cw'] = [];

        $this->process();
    }

    /**
     * Process Core font
     *
     * @throws FontException
     */
    protected function process(): void
    {
        $this->extractMetrics();
        $this->setFlags();
        $this->setMissingValues();
        $this->remapValues();
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

    protected function setFlags(): void
    {
        $this->fdt['Flags'] |= match ($this->fdt['FontName']) {
            'Symbol', 'ZapfDingbats' => 4,
            default => 32,
        };

        if ($this->fdt['IsFixedPitch']) {
            $this->fdt['Flags'] |= 1;
        }

        if ((int) $this->fdt['ItalicAngle'] != 0) {
            $this->fdt['Flags'] |= 64;
        }
    }

    /**
     * Set Char widths
     *
     * @param array<int, int> $cwidths Extracted widths
     */
    protected function setCharWidths(array $cwidths): void
    {
        $this->fdt['MissingWidth'] = $cwidths[32] ?: 600;
        $this->fdt['MaxWidth'] = $this->fdt['MissingWidth'];

        for ($cid = 0; $cid <= 255; $cid++) {
            if (isset($cwidths[$cid])) {
                $chrWidth = $cwidths[$cid];
                $this->fdt['AvgWidth'] += $chrWidth;
            } else {
                $chrWidth = $this->fdt['MissingWidth'];
            }
            $this->fdt['cw'][$cid] = $chrWidth;
            $this->fdt['MaxWidth'] = \max($this->fdt['MaxWidth'], $chrWidth);
        }

        $this->fdt['AvgWidth'] = (int) \round($this->fdt['AvgWidth'] / \count($cwidths));
    }

    /**
     * Extract Metrics
     */
    protected function extractMetrics(): void
    {
        $cwd = [];
        $lines = \explode("\n", \str_replace("\r", '', $this->font));
        // process each row
        foreach ($lines as $line) {
            $col = \explode(' ', \rtrim($line));
            $this->processMetricRow($col, $cwd);
        }

        $this->setCharWidths($cwd);
    }

    /**
     * Process a row of the font metric
     *
     * @param array<int, string> $col Array containing row elements to process
     * @param array<int, int>    $cwd Array containing cid widths
     */
    protected function processMetricRow(array $col, array &$cwd): void
    {
        if (!isset($col[1])) {
            return;
        }

        $integerValueKeys = [
            ...['ItalicAngle', 'UnderlinePosition', 'UnderlineThickness', 'CapHeight'],
            ...['XHeight', 'Ascender', 'Descender', 'StdHW', 'StdVW'],
        ];
        $otherValueKeys = ['FontName', 'FullName', 'FamilyName', 'Weight', 'CharacterSet', 'Version', 'EncodingScheme'];

        if ($col[0] == 'IsFixedPitch') {
            $this->fdt['IsFixedPitch'] = $col[1] == 'true';
        } elseif ($col[0] == 'FontBBox') {
            $this->fdt['FontBBox'] = [(int) $col[1], (int) $col[2], (int) $col[3], (int) $col[4]];
        } elseif ($col[0] == 'C') {
            $cid = (int) $col[1];
            if ($cid >= 0) {
                $cwd[$cid] = (int) $col[4];
                if (!empty($col[14])) {
                    $this->fdt['cbbox'][$cid] = [(int) $col[10], (int) $col[11], (int) $col[12], (int) $col[13]];
                }
            }
        } elseif (\in_array($col[0], $integerValueKeys)) {
            $this->fdt[$col[0]] = (int) $col[1];
        } elseif (\in_array($col[0], $otherValueKeys)) {
            $this->fdt[$col[0]] = $col[1];
        }
    }

    /**
     * Map values to the correct key name
     *
     * @throws FontException
     */
    protected function remapValues(): void
    {
        // rename properties
        $this->fdt['name'] = $this->fdt['FullName'];
        $this->fdt['underlinePosition'] = $this->fdt['UnderlinePosition'];
        $this->fdt['underlineThickness'] = $this->fdt['UnderlineThickness'];
        $this->fdt['italicAngle'] = $this->fdt['ItalicAngle'];
        $this->fdt['Ascent'] = $this->fdt['Ascender'];
        $this->fdt['Descent'] = $this->fdt['Descender'];
        $this->fdt['StemV'] = $this->fdt['StdVW'];
        $this->fdt['StemH'] = $this->fdt['StdHW'];

        $name = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->fdt['name']);
        if ($name === null) {
            throw new FontException('Invalid font name');
        }

        $this->fdt['name'] = $name;
        $this->fdt['bbox'] = \implode(' ', $this->fdt['FontBBox']);

        $this->fdt['XHeight'] ??= 0;
    }

    protected function setMissingValues(): void
    {
        $this->fdt['Descent'] = $this->fdt['FontBBox'][1];
        $this->fdt['Ascent'] = $this->fdt['FontBBox'][3];

        $this->fdt['CapHeight'] ??= $this->fdt['Ascender'];
    }
}

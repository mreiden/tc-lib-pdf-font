<?php

/**
 * LoadTestHarness.php
 *
 * @since     2026-05-21
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

use Com\Tecnick\Pdf\Font\Load;

class LoadTestHarness extends Load
{
    public function __construct(string $key, string $name)
    {
        parent::__construct();
        $this->fdt['type'] = 'TrueType';
        $this->fdt['key'] = $key;
        $this->fdt['name'] = $name;
        $this->fdt['fakestyle'] = true;
        $this->fdt['cw'][32] = 500;
    }

    public function setModeAndMetrics(bool $bold, bool $italic, int $stemv, int $italicAngle, int $flags): void
    {
        $this->fdt['mode']['bold'] = $bold;
        $this->fdt['mode']['italic'] = $italic;
        $this->fdt['mode']['linethrough'] = false;
        $this->fdt['mode']['overline'] = false;
        $this->fdt['mode']['underline'] = false;
        $this->fdt['desc']['StemV'] = $stemv;
        $this->fdt['desc']['ItalicAngle'] = $italicAngle;
        $this->fdt['desc']['Flags'] = $flags;
        $this->fdt['desc']['MissingWidth'] = 0;
    }

    public function getNameValue(): string
    {
        return $this->fdt['name'];
    }

    public function getStemVValue(): int
    {
        return $this->fdt['desc']['StemV'];
    }

    public function getItalicAngleValue(): int
    {
        return $this->fdt['desc']['ItalicAngle'];
    }

    public function getFlagsValue(): int
    {
        return $this->fdt['desc']['Flags'];
    }

    protected function getFontInfo(): void
    {
        // Keep the preloaded test data and skip filesystem reads.
    }

    public function setFileData(): void
    {
        // Avoid dependency on actual font files for internal branch checks.
    }
}

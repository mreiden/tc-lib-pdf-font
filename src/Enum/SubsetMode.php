<?php

declare(strict_types=1);

/**
 * SubsetMode.php
 *
 * @since     2026-03-02
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2026-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 */

namespace Com\Tecnick\Pdf\Font\Enum;

/**
 * Com\Tecnick\Pdf\Font\Enum\SubsetMode
 *
 * @since     2026-03-02
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2026-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 */

/**
 * Subsetting Modes
 */
enum SubsetMode
{
    case OFF;
    case ON;
    case DEBUG;

    public function debug(): bool
    {
        return match ($this) {
            SubsetMode::DEBUG => true,
            default => false,
        };
    }
}

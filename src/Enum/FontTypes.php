<?php

declare(strict_types=1);

/**
 * FontTypes.php
 *
 * @since     2026-01-30
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
 * Com\Tecnick\Pdf\Font\Enum\FontTypes
 *
 * @since     2026-01-30
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2026-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 */

/**
 * Supported font types
 */
enum FontTypes: string
{
    case Core = 'Core';
    case TrueType = 'TrueType';
    case TrueTypeUnicode = 'TrueTypeUnicode';
    case Type1 = 'Type1';
    case cidfont0 = 'cidfont0';
}

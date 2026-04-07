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

namespace Com\Tecnick\Pdf\Font\PdfObjects;

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
class OutFontObjs
{
    public const TO_UNICODE = <<<'TO_UNICODE'
    %1$s
    << /Length %2$s %3$s >> stream
    %4$s
    endstream
    endobj
    TO_UNICODE;

    public const CID_TO_GID_MAP = <<<'CID_TO_GID_MAP'
    %d 0 obj
    << /Length %d %s >> stream
    %s
    endstream
    endobj
    CID_TO_GID_MAP;

    public const TYPE0 = <<<'TYPE0'
    %1$s
    << /Type/Font /Subtype/Type0 /BaseFont/%2$s /Name/%3$s /Encoding%4$s %5$s /DescendantFonts [%6$s ] >>
    endobj
    TYPE0;

    public const CID_FONT_TYPE2 = <<<'CID_FONT_TYPE2'
    %1$s
    << /Type/Font /Subtype/CIDFontType2 /BaseFont/%2$s /CIDSystemInfo%3$s %4$s /FontDescriptor%5$s
    /DW %6$s %7$s >>
    endobj
    CID_FONT_TYPE2;

    public const FONT_DESCRIPTOR = <<<'FONT_DESCRIPTOR'
    %1$d 0 obj
    << /Type/FontDescriptor /FontName/%2$d %3$s /FontFile2%4$s >>
    endobj
    FONT_DESCRIPTOR;

    public const TRUE_TYPE = <<<'TRUE_TYPE'
    %1$d 0 obj
    <</Type/Font /Subtype/%2$s /BaseFont/%3$s /Name/F%4$d /FirstChar 32 /LastChar 255 /Widths [ %5$s ] /FontDescriptor %6$d 0 R
    TRUE_TYPE;

    public const TRUE_TYPE_FONT_DESCRIPTOR = <<<'TRUE_TYPE_FONT_DESCRIPTOR'
    %d 0 obj
    << /Type/FontDescriptor /FontName/%s %3$s %4$s >>
    endobj
    TRUE_TYPE_FONT_DESCRIPTOR;
}

<?php

/**
 * FontTest.php
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

namespace Test;

/**
 * Font Test
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 */
class FontTest extends TestUtil
{
    // Verifies the constructor rejects a path-traversal ifile ('../font.json') via the file helper's
    // safety check, preventing font definitions from being loaded outside allowed paths.
    /** @throws \Com\Tecnick\Pdf\Font\Exception */
    public function testFontRejectsUnsafeInputDefinitionPath(): void
    {
        $this->bcExpectException(\Com\Tecnick\Pdf\Font\Exception::class);
        $this->setupTest();
        new \Com\Tecnick\Pdf\Font\Font(
            'helvetica',
            '',
            '../font.json',
            false,
            true,
            false,
            true,
            new \Com\Tecnick\File\File(),
        );
    }
}

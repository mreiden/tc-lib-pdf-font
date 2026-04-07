<?php

declare(strict_types=1);

/**
 * OutUtil.php
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
use Com\Tecnick\Pdf\Font\Exception as FontException;

/**
 * Com\Tecnick\Pdf\Font\OutUtil
 *
 * @since     2011-05-23
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 */
abstract class OutUtil
{
    /**
     * Return font full path
     *
     * @param string $fontdir Original font directory
     * @param string $file    Font file name.
     *
     * @return string Font full path or empty string
     *
     * @throws FontException
     */
    protected function getFontFullPath(string $fontdir, string $file): string
    {
        $dirobj = new Dir();
        // directories where to search for the font definition file
        $dirs = \array_unique([
            '',
            $fontdir,
            \defined('K_PATH_FONTS') && \is_string(K_PATH_FONTS) ? K_PATH_FONTS : '',
            $dirobj->findParentDir('fonts', __DIR__),
        ]);
        foreach ($dirs as $dir) {
            if (@\is_readable("$dir/$file")) {
                return "$dir/$file";
            }
        }

        throw new FontException('Unable to locate the file: ' . $file);
    }

    /**
     * get width ranges of characters
     *
     * @param array{
     *        'cw':  array<int, int>,
     *        'dw': int,
     *        'subset': bool,
     *        'subsetchars': array<int, int>,
     *    } $font      Font to process
     * @param int    $cidoffset Offset to translate Character Codes into Character IDs
     *
     * @return string
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     */
    /*
    protected function getCharWidths(array $font, int $cidoffset = 0): string
    {
        \ksort($font['cw']);

        $loop = $font['cw'];
        if ($font['subset']) {
            $loop = $font['subsetchars'];
            $cidoffset *= -1;
        }
        // Return early if there is nothing to do
        if ($loop == []) {
            return '';
        }

        $cidLast = \array_key_last($loop) - $cidoffset;
        $strWidths = '';
        $segment = [];
        $buffer = [];
        $cidPrevious = null;
        $widthPrevious = null;

        foreach (\array_keys($loop) as $cid) {
            $cid -= $cidoffset;
            // Flag used to finalize any queued characters
            $lastLoop = $cid === $cidLast;

            // Use default width if character width is not set
            $width = $font['cw'][$cid] ?? null;
            $missingWidth = $width === null;

            // Optimization: Do not include characters having the default width
            if ($width === $font['dw'] && (!$lastLoop || empty($segment))) {
                continue;
            }

            // Add character width to segment
            $prevOnNextLoop = $cidPrevious;
            if (!$missingWidth) {
                $segment[] = $width;
                $prevOnNextLoop = $cid;
            }

            // Initialize variables on the first loop iteration
            if ($cidPrevious === null) {
                // Initialize $buffer with the first $cid with a non-default width
                $buffer = [$cid, $cid];
                // Set $cidPrevious to force $cidGap=false
                $cidPrevious = $cid - 1;
                // Set $widthPrevious to force $sameWidth=true
                $widthPrevious = $width;
            }
            //if ($cidPrevious === null) {
            //    $buffer = [$cid, $cid]
            //    //if (!$missingWidth) {
            //    //    $segment[] = $width;
            //    //}
            //} else {
            $cidGap = $cid !== $cidPrevious + 1;
            $sameWidth = !$missingWidth && $width === $widthPrevious;

            if ($cidGap || !$sameWidth || $lastLoop) {
                if (!$sameWidth && $buffer[1] - $buffer[0] >= 2) {
                    $strWidths .= " $buffer[0] $buffer[1] $widthPrevious";
                } elseif (!empty($segment)) {
                    $strWidths .= " $buffer[0][" . \implode(' ', $segment) . ']';
                }
                $buffer = [$cid, $cid];
                $segment = $missingWidth ? [] : [$width];
            } else {
                $buffer[1]++;
            }
            //}

            $cidPrevious = $prevOnNextLoop;
            $widthPrevious = $width;
        }

        return $strWidths === '' ? '' : '/W [' . \trim($strWidths) . ']';
    }
    */

    protected function getCharWidths(array $font, int $cidoffset = 0): string
    {
        \ksort($font['cw']);

        if ($font['subset']) {
            $loop = $font['subsetchars'];
            $cidoffset *= -1;
        } else {
            $loop = $font['cw'];
        }

        // Return early if there is nothing to do
        if (empty($loop)) {
            return '';
        }

        $cidLast = \array_key_last($loop) - $cidoffset;
        $strWidths = '';
        $segment = [];
        foreach (\array_keys($loop) as $cid) {
            $cid -= $cidoffset;

            // Use default width if character width is not set
            $width = $font['cw'][$cid] ?? null;
            $missingWidth = $width === null;

            // Flag needed to finalize any queued characters
            $lastLoop = $cid === $cidLast;

            // Optimization: Do not include characters having the default width
            if ($width === $font['dw'] && (!$lastLoop || empty($segment))) {
                continue;
            }

            // Add width to different widths array
            if (!$missingWidth) {
                $segment[] = $width;
            }

            // Initialize these after finding the first cid to use
            $cidPrevious ??= $cid - 1;
            $widthPrevious ??= $width;
            $buffer ??= [$cid, $cid];

            $startNew = false;
            $cidGap = $cid !== $cidPrevious + 1;
            $sameWidth = !$missingWidth && $width === $widthPrevious;
            if ($cidGap || !$sameWidth || $lastLoop) {
                if (!$sameWidth && $buffer[1] - $buffer[0] >= 2) {
                    // Complete the segment as a "same-width" range since it is more compact for 3+ same-width consecutive cids.
                    $startNew = true;
                    $strWidths .= ' ' . $buffer[0] . ' ' . $buffer[1] . ' ' . $widthPrevious;
                } elseif (!empty($segment) && ($cidGap || $lastLoop || $missingWidth)) {
                    // Complete the segment using an array of individual widths for consecutive cids.
                    $startNew = true;
                    $strWidths .= ' ' . $buffer[0] . '[' . implode(' ', $segment) . ']';
                }
            }

            // Start a new segment when the previous segment is complete.
            if ($startNew) {
                $buffer = [$cid, $cid];
                $segment = [];
                if (!$missingWidth) {
                    $segment[] = $width;
                }
            }

            // Increase the ending cid if the width is the same.
            if ($sameWidth && $cid === $buffer[1] + 1) {
                $buffer[1]++;
            }

            // Update $cidPrevious if the current $cid has a width (and should thus be included in the widths array)
            if (!$missingWidth) {
                $cidPrevious = $cid;
            }

            // Update $widthPrevious to the width of the current $cid
            $widthPrevious = $width;
        }

        return $strWidths === '' ? '' : '/W [' . $strWidths . ']';
    }
}

<?php

declare(strict_types=1);

/**
 * TestUtil.php
 *
 * @since     2020-12-19
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-color software library.
 */

namespace Test;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Font class test
 *
 * @since     2020-12-19
 * @category  Library
 * @package   PdfFont
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2015-2026 Nicola Asuni - Tecnick.com LTD
 * @license   https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf-font
 *
 */

#[PreserveGlobalState(false)]
#[RunTestsInSeparateProcesses]
class TestUtil extends TestCase
{
    protected function setup(): void
    {
        if (!\defined('K_PATH_FONTS')) {
            \define('K_PATH_FONTS', \dirname(__DIR__) . '/target/tmptest/');
        }
        if (!\is_string(K_PATH_FONTS)) {
            throw new \Exception('K_PATH_FONTS must be a string');
        }

        //\system('rm -rf ' . K_PATH_FONTS . ' && mkdir -p ' . K_PATH_FONTS);
        $this->deleteDirectoryRecursively(K_PATH_FONTS);

        if (!\file_exists(K_PATH_FONTS)) {
            \mkdir(K_PATH_FONTS, recursive: true);
        }
    }

    protected function deleteDirectoryRecursively(string $dirPath, bool $createAfterDelete = false): void
    {
        if (!\file_exists($dirPath)) {
            // Target directory path does not exist
            if ($createAfterDelete) {
                // Create directory if specified
                \mkdir($dirPath, recursive: true);
            }
            // Nothing else to do when the target directory did not exist.
            return;
        }

        if (!\is_dir($dirPath)) {
            throw new \InvalidArgumentException("$dirPath must be a directory.");
        }
        $endChar = \substr($dirPath, -1);
        if ($endChar !== '/' && $endChar !== DIRECTORY_SEPARATOR) {
            $dirPath .= '/';
        }

        $directoryIterator = new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($directoryIterator, \RecursiveIteratorIterator::CHILD_FIRST);
        for ($files->rewind(); $files->valid(); $files->next()) {
            if ($files->isDir()) {
                // Delete directory
                //echo 'Delete directory: ' . $files->getPathName() . "\n";
                \rmdir($files->getPathName());
            } else {
                // Delete file
                //echo 'Delete file: ' . $files->getPathName() . "\n";
                \unlink($files->getPathName());
            }
        }

        // Finally, remove the top-level directory
        if (!$createAfterDelete) {
            \rmdir($dirPath);
        }
    }

    protected function getFontPath(): string
    {
        if (\defined('K_PATH_FONTS')) {
            if (!\is_string(K_PATH_FONTS)) {
                throw new \Exception('K_PATH_FONTS must be a string');
            }
            return K_PATH_FONTS;
        }

        return '';
    }
}

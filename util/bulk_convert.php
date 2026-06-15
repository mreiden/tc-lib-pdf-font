#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * bulk_convert.php
 *
 * @since       2015-11-30
 * @category    Library
 * @package     PdfFont
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2026 Nicola Asuni - Tecnick.com LTD
 * @license     https://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-font
 *
 * This file is part of tc-lib-pdf-font software library.
 *
 * Command-line tool to convert fonts data for the tc-lib-pdf-font library in bulk.
 */

use Com\Tecnick\Pdf\Font\Import;

if (\php_sapi_name() != 'cli') {
    \fwrite(STDERR, 'You need to run this command from console.' . "\n");
    exit(1);
}

/**
 * Display help guide for this command.
 */
function showHelp(): never
{
    $help = <<<'EOD'

    bulk_convert - Command-line tool to convert fonts data for the tc-lib-pdf-font library.

    Usage:
        bulk_convert.php [ options ]

    Options:

        -o, --outpath
            Output path for generated font files (must be writeable by the
            web server). Leave empty for default font folder [../target/fonts/].

        -t, --ttfpath
            Path to find source font files to convert.
            Leave empty for default font folder [./vendor/tecnickcom/tc-font-mirror/].

        -h, --help
            Display this help and exit.

    EOD;

    \fwrite(STDOUT, $help);
    exit(0);
}

// initialize the array of options
$options = [
    'outpath' => __DIR__ . '/../target/fonts/',
    'ttfpath' => __DIR__ . '/vendor/tecnickcom/tc-font-mirror/',
];

// short input options
$sopt = 'ho:t:';

// long input options
$lopt = ['help', 'outpath:', 'ttfpath:'];

// parse input options
$inopt = \getopt($sopt, $lopt);

// import options (with some sanitization)
foreach ($inopt as $opt => $val) {
    switch ($opt) {
        case 'o':
        case 'outpath':
            $options['outpath'] = $val;
            break;

        case 't':
        case 'ttfpath':
            $options['ttfpath'] = $val;
            break;

        default:
            showHelp();
    }
}

// Create output directory if it does not exist
if (!\is_dir($options['outpath']) && !\mkdir($options['outpath'], 0755, true)) {
    \fwrite(STDERR,"ERROR: The {$options['ttfpath']} directory could not be created.\n\n");
    exit(2);
}
// Check if output path is writable
if (!\is_writable($options['outpath'])) {
    \fwrite(STDERR, "ERROR: Can not write to {$options['outpath']}\n\n");
    exit(2);
}
// Add slash to real output path
$options['outpath'] = realpath($options['outpath']) . \DIRECTORY_SEPARATOR;

// Font Source Path
if (!$options['ttfpath'] || !\is_dir($options['ttfpath'])) {
    \fwrite(
        STDERR,
        "ERROR: The {$options['ttfpath']} directory is empty, please execute 'make build' before this command.\n\n",
    );
    exit(3);
}
$ttfpath = \realpath($options['ttfpath']);
if ($ttfpath === false) {
    \fwrite(
        STDERR,
        "ERROR: The {$options['ttfpath']} font source directory does not exist, please use the --ttfpath option.\n\n",
    );
    exit(3);
}
// Add slash to real ttf path
$options['ttfpath'] = $ttfpath . \DIRECTORY_SEPARATOR;


$summary = <<<SUMMARY

>>> Converting fonts:
***   Source fonts directory: '{$options['ttfpath']}'
***   Output files directory: '{$options['outpath']}'

SUMMARY;

\fwrite(STDOUT, $summary);

// count conversions
$convert_errors = 0;
$convert_success = 0;

require_once __DIR__ . '/../vendor/autoload.php';

$fontdir = \array_diff(\scandir($options['ttfpath']), ['.', '..', '.git', '.github', '.gitignore']);

// URL of websites containing the font sources
$font_url = [
    'cid0' => 'https://unifoundry.com/unifont/',
    'core' => 'https://partners.adobe.com/public/developer/en/pdf/Core14_AFMs.zip',
    'dejavu' => 'https://github.com/dejavu-fonts/dejavu-fonts/releases/download/version_2_37/dejavu-fonts-ttf-2.37.zip',
    'freefont' => 'https://ftp.gnu.org/gnu/freefont/freefont-ttf-20120503.zip',
    'pdfa' => 'https://github.com/tecnickcom/tc-font-pdfa',
    'unifont' => 'http://unifoundry.com/unifont.html',
];

foreach ($fontdir as $dir) {
    $indir = $options['ttfpath'] . $dir;

    if (!\is_dir($indir)) {
        continue;
    }

    // search font files in subdirectories
    $all_files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($indir));
    $fonts = \iterator_to_array(new \RegexIterator($all_files, '/\.ttf$/'));
    $fonts = \array_merge($fonts, \iterator_to_array(new \RegexIterator($all_files, '/\.pfb$/')));
    $fonts = \array_merge($fonts, \iterator_to_array(new \RegexIterator($all_files, '/\.otf$/')));
    if (empty($fonts)) {
        $fonts = \iterator_to_array(new \RegexIterator($all_files, '/\.afm$/'));
    }
    if (empty($fonts)) {
        continue;
    }

    // build output path directory
    $outdir = $options['outpath'] . $dir . '/';
    if (!\is_dir($outdir)) {
        \mkdir($outdir, 0755, true);
    }
    \copy($indir . '/LICENSE', $outdir . 'LICENSE');

    // generate a README file
    $readme = <<<README
    # $dir font files for tc-lib-pdf-font

    This folder contains font files and/or font data extracted from: {$font_url[$dir]}
    using the "bulk_convert.php" utility in https://github.com/tecnickcom/tc-font-pdf-font

    The original files (if present) have been renamed and compressed using the ZLIB data format (.z files).
    The font files are subject to the conditions stated in the LICENSE file.
    For further information please consult the original documentation at the link above.

    README;
    \file_put_contents($outdir . 'README', $readme);

    foreach ($fonts as $font) {
        // Convert SplFileInfo Object to a string
        $font = (string) $font;

        if (\str_ends_with($font, '.otf')) {
            // OTF fonts are not yet supported, but we can try to convert them to TTF using FontForge
            \system('fontforge -script otf2ttf.ff ' . \escapeshellcmd($font), $err);
            if ($err != 0) {
                \fwrite(STDERR, "\033[31m" . 'Unable to convert: ' . $font . "\033[m");
                continue;
            }
            $font = \substr($font, 0, -4) . '.ttf';
        }

        $type = '';
        $encoding = '';
        if ($dir == 'cid0') {
            $type = \strtoupper(\basename($font, '.ttf'));
        } elseif ($dir == 'core' || $dir == 'pdfa') {
            if (\str_contains($font, 'Symbol')) {
                $encoding = 'symbol';
            } elseif (!\str_contains($font, 'ZapfDingbats')) {
                $encoding = 'cp1252';
            }
        }
        try {
            if (!($realpath = \realpath($font))) {
                throw new \RuntimeException("Font file does not exist: $font");
            }
            $import = new Import($realpath, $outdir, $type, $encoding);
            $fontname = $import->getFontName();
            \fwrite(STDOUT, "\033[32m" . '+++ OK   : ' . $font . ' added as ' . $fontname . "\033[m\n");
            $convert_success++;
        } catch (\Exception $exc) {
            $convert_errors++;
            \fwrite(
                STDERR,
                "\033[31m" . '--- ERROR: can\'t add ' . $font . "\n           " . $exc->getMessage() . "\033[m\n",
            );
        }
    }
}

$endmsg =
    '>>> PROCESS COMPLETED: ' . $convert_success . ' CONVERTED FONT(S), ' . $convert_errors . ' ERROR(S)!' . "\n\n";

if ($convert_errors > 0) {
    \fwrite(STDERR, "\033[31m" . $endmsg . 'ERROR' . "\033[m");
    exit(4);
}

\fwrite(STDOUT, "\033[32m" . $endmsg . "\033[m");
exit();


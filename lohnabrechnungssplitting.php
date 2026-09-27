#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Process encrypted payroll PDF files.
 *
 * Usage:
 *   php lohnabrechnungen.php <input.pdf>
 *
 * Requirements:
 *   - qpdf
 *   - pdftk
 *   - config.php in the same directory as this script
 */

// ------------------------------------------------------------
// Helper functions
// ------------------------------------------------------------

/**
 * Print an error message and terminate the script.
 */
function errorExit(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, "Fehler: {$message}\n");
    exit($exitCode);
}

/**
 * Ask the user for input.
 *
 * Empty input is not accepted and causes the question
 * to be repeated.
 */
function ask(string $question): string
{
    while (true) {
        echo $question;

        $input = fgets(STDIN);

        if ($input === false) {
            echo "Ungültige Eingabe, bitte versuche es erneut\n";
            continue;
        }

        $input = trim($input);

        if ($input === '') {
            echo "Ungültige Eingabe, bitte versuche es erneut\n";
            continue;
        }

        return $input;
    }
}

/**
 * Execute a shell command and terminate the script on failure.
 */
function executeCommand(string $command): void
{
    echo "  > {$command}\n";

    $output = [];
    $returnCode = 0;

    exec($command . ' 2>&1', $output, $returnCode);

    if ($returnCode !== 0) {
        echo implode(PHP_EOL, $output) . PHP_EOL;

        errorExit(
            "Der Befehl ist mit dem Exit-Code {$returnCode} fehlgeschlagen."
        );
    }
}

// ------------------------------------------------------------
// Validate command line arguments
// ------------------------------------------------------------

if ($argc !== 2) {
    fwrite(
        STDERR,
        "Verwendung: {$argv[0]} <verschlüsselte-pdf-datei>\n"
    );
    exit(1);
}

$inputFilename = $argv[1];

if (!is_file($inputFilename)) {
    errorExit("Die Eingabedatei existiert nicht: {$inputFilename}");
}

if (!is_readable($inputFilename)) {
    errorExit("Die Eingabedatei ist nicht lesbar: {$inputFilename}");
}

// ------------------------------------------------------------
// Load config.php
// ------------------------------------------------------------

$configFile = __DIR__ . '/config.php';

if (!is_file($configFile)) {
    errorExit("config.php wurde nicht gefunden: {$configFile}");
}

$config = require $configFile;

if (!is_array($config)) {
    errorExit("config.php muss ein Array zurückgeben.");
}

if (!isset($config['employees']) || !is_array($config['employees'])) {
    errorExit(
        "In config.php muss ein Array 'employees' vorhanden sein."
    );
}

if (
    !isset($config['outputDir']) ||
    !is_string($config['outputDir']) ||
    $config['outputDir'] === ''
) {
    errorExit(
        "In config.php muss 'outputDir' als Ausgabeverzeichnis definiert sein."
    );
}

$outputDir = rtrim($config['outputDir'], DIRECTORY_SEPARATOR);

// ------------------------------------------------------------
// Ask for password and month
// ------------------------------------------------------------

$password = ask("Passwort zur Entschlüsselung: ");

do {
    $monthInput = ask("Monat der Lohnabrechnungen (1-12): ");

    if (
        filter_var($monthInput, FILTER_VALIDATE_INT) === false ||
        (int)$monthInput < 1 ||
        (int)$monthInput > 12
    ) {
        echo "Ungültige Eingabe, bitte versuche es erneut\n";
        $monthInput = '';
    }
} while ($monthInput === '');

$month = (int)$monthInput;
$monthFormatted = sprintf('%02d', $month);

$currentYear = date('Y');

// ------------------------------------------------------------
// Create output directory if necessary
// ------------------------------------------------------------

if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0775, true)) {
        errorExit(
            "Das Ausgabeverzeichnis konnte nicht erstellt werden: {$outputDir}"
        );
    }
}

if (!is_writable($outputDir)) {
    errorExit(
        "Das Ausgabeverzeichnis ist nicht beschreibbar: {$outputDir}"
    );
}

// ------------------------------------------------------------
// Build decrypted PDF filename
// ------------------------------------------------------------

$decryptedFilename =
    $outputDir
    . DIRECTORY_SEPARATOR
    . $currentYear
    . '-'
    . $monthFormatted
    . '_Lohnabrechnungen.pdf';

// ------------------------------------------------------------
// Decrypt the PDF using qpdf
// ------------------------------------------------------------

echo PHP_EOL;
echo "Entschlüssele PDF ...\n";

$qpdfCommand =
    '/usr/bin/qpdf'
    . ' --password=' . escapeshellarg($password)
    . ' --decrypt'
    . ' ' . escapeshellarg($inputFilename)
    . ' ' . escapeshellarg($decryptedFilename);

executeCommand($qpdfCommand);

echo "Entschlüsselte Datei: {$decryptedFilename}\n";

// ------------------------------------------------------------
// Process each employee
// ------------------------------------------------------------

echo PHP_EOL;
echo "Nun werden die Seiten für die einzelnen Mitarbeiter abgefragt.\n";
echo PHP_EOL;

foreach ($config['employees'] as $employee) {

    if (!is_array($employee)) {
        errorExit("Ungültiger Mitarbeitereintrag in config.php.");
    }

    if (
        !isset($employee['firstname']) ||
        !isset($employee['lastname'])
    ) {
        errorExit(
            "Jeder Mitarbeiter benötigt 'firstname' und 'lastname'."
        );
    }

    $firstname = (string)$employee['firstname'];
    $lastname = (string)$employee['lastname'];

    // Replace all special characters with hyphens.
    $safeFirstname = preg_replace(
        '/[^\p{L}\p{N}]+/u',
        '-',
        $firstname
    );

    $safeLastname = preg_replace(
        '/[^\p{L}\p{N}]+/u',
        '-',
        $lastname
    );

    if ($safeFirstname === null || $safeLastname === null) {
        errorExit(
            "Der Name von {$firstname} {$lastname} konnte nicht verarbeitet werden."
        );
    }

    // Remove leading and trailing hyphens.
    $safeFirstname = trim($safeFirstname, '-');
    $safeLastname = trim($safeLastname, '-');

    $employeeDisplayName = "{$firstname} {$lastname}";

    // Ask for and validate the page number.
    do {
        $pageInput = ask(
            "Seite für {$employeeDisplayName}: "
        );

        if (
            filter_var($pageInput, FILTER_VALIDATE_INT) === false ||
            (int)$pageInput < 1
        ) {
            echo "Ungültige Eingabe, bitte versuche es erneut\n";
            $pageInput = '';
        }
    } while ($pageInput === '');

    $page = (int)$pageInput;

    // Build the output filename for this employee.
    $employeeOutputFilename =
        $outputDir
        . DIRECTORY_SEPARATOR
        . $currentYear
        . '-'
        . $monthFormatted
        . '_Lohnabrechnung_'
        . $safeFirstname
        . '-'
        . $safeLastname
        . '.pdf';

    // Extract the employee's page using pdftk.
    $pdftkCommand =
        '/usr/bin/pdftk'
        . ' ' . escapeshellarg($decryptedFilename)
        . ' cat '
        . $page
        . ' output '
        . escapeshellarg($employeeOutputFilename);

    echo PHP_EOL;
    echo "Verarbeite {$employeeDisplayName}, Seite {$page} ...\n";

    executeCommand($pdftkCommand);

    echo "  Erstellt: {$employeeOutputFilename}\n";
}

// ------------------------------------------------------------
// Finish
// ------------------------------------------------------------

echo PHP_EOL;
echo "Alle Mitarbeiter wurden verarbeitet.\n";
echo "Ausgabeverzeichnis: {$outputDir}\n";

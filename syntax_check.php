<?php
$dir = __DIR__;
$ite = new RecursiveDirectoryIterator($dir);
$foreach = new RecursiveIteratorIterator($ite);
$errors = [];
$checked = 0;

foreach ($foreach as $file) {
    if ($file->getExtension() === 'php' && strpos($file->getPathname(), 'vendor') === false) {
        $path = $file->getPathname();
        $output = [];
        $return_var = 0;
        exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $return_var);
        $checked++;
        if ($return_var !== 0) {
            $errors[] = "File: " . $path . "\nError: " . implode("\n", $output);
        }
    }
}

if (empty($errors)) {
    echo "SUCCESS: Checked $checked PHP files across the entire application. Zero syntax errors found.";
} else {
    echo "SYNTAX ERRORS FOUND in " . count($errors) . " files:\n\n" . implode("\n\n", $errors);
}

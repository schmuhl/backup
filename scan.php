<?php

function findMissingSequentialFiles($directory) {
    if (!is_dir($directory)) {
        echo "Error: Directory '$directory' does not exist.\n";
        return;
    }

    $files = [];
    $filesInDir = scandir($directory);

    foreach ($filesInDir as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

        if (is_file($fullPath)) {
            $files[] = $file;
        }
    }

    if (empty($files)) {
        echo "No files found in '$directory'.\n";
        return;
    }

    $fileData = [];
    $prefix = null;
    $extension = null;

    foreach ($files as $file) {
        if (preg_match('/^(.+?)(\d+)\.(\w+)$/', $file, $matches)) {
            if ($prefix === null) {
                $prefix = $matches[1];
                $extension = "." . $matches[3];
            }
            if ($matches[1] !== $prefix || "." . $matches[3] !== $extension) {
                echo "Error: Files do not have consistent prefixes or extensions.\n";
                return;
            }
            $number = (int) $matches[2];
            $fileData[] = [
                'filename' => $file,
                'number' => $number,
            ];
        }
    }

    if (empty($fileData)) {
        echo "No files found matching the naming pattern.\n";
        return;
    }

    usort($fileData, function ($a, $b) {
        return $a['number'] - $b['number'];
    });

    $missingFiles = [];
    $expectedNumber = 1;

    foreach ($fileData as $data) {
        if ($data['number'] !== $expectedNumber) {
            $originalNumberLength = strlen((string)$data['number']-1);
            for ($i = $expectedNumber; $i < $data['number']; $i++) {
                $missingFiles[] = $prefix . sprintf("%0" . $originalNumberLength . "d", $i) . $extension;
            }
            $expectedNumber = $data['number'] + 1;
        } else {
            $expectedNumber++;
        }
    }

    if (!empty($missingFiles)) {
        echo "Missing files:\n";
        foreach ($missingFiles as $missingFile) {
            echo "$missingFile\n";
        }
    } else {
        echo "No missing files found.\n";
    }
}

// Command-line arguments
if ($argc !== 2) {
    echo "Usage: php find_missing.php <directory>\n";
    exit(1);
}

$directory = $argv[1];

findMissingSequentialFiles($directory);
?>

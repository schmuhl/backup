<?php



// Command-line arguments
if ($argc < 3 || $argc > 4) {
    echo "Usage: php delete_files.php <directory> <ends_with> [--delete]\n";
    exit(1);
}

$directory = $argv[1];
$endsWith = $argv[2];
$delete = false;

if ($argc === 4 && $argv[3] === '--delete') {
    $delete = true;
}

findAndProcessFiles($directory, $endsWith, $delete);





function findAndProcessFiles($directory, $endsWith, $delete = false) {
    if (!is_dir($directory)) {
        echo "Error: Directory '$directory' does not exist.\n";
        return;
    }

    $filesToDelete = [];
    $filesInDir = scandir($directory);

    foreach ($filesInDir as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

        if (is_file($fullPath) && substr($file, -strlen($endsWith)) === $endsWith) {
            $filesToDelete[] = $fullPath;
        }
    }

    if (empty($filesToDelete)) {
        echo "No files found ending with '$endsWith' in '$directory'.\n";
        return;
    }

    if ($delete) {
        foreach ($filesToDelete as $fileToDelete) {
          if (unlink($fileToDelete)) {
              echo "Deleted: $fileToDelete\n";
          } else {
              echo "Failed to delete: $fileToDelete\n";
          }
        }
    } else {
        echo "Files that would be deleted (use --delete to actually delete):\n";
        foreach ($filesToDelete as $fileToDelete) {
            echo "$fileToDelete\n";
        }
    }
}

<?php

// Example usage:
if ($argc < 5 || $argc > 6) {
    echo "Usage: php renumber.php <directory> <start_number> <end_number> <adjustment> [--rename]\n";
    exit(1);
}

$directory = $argv[1];
$startNumber = (int) $argv[2];
$endNumber = (int) $argv[3];
$adjustment = (int) $argv[4];
$doRename = false;

if ($argc === 6 && $argv[5] === '--rename') {
    $doRename = true;
}

renumberFiles2($directory, $startNumber, $endNumber, $adjustment, $doRename);

function renumberFiles2 ( $directory, $startNumber, $endNumber, $adjustment, $doRename ) {
  if (!is_dir($directory)) {
      echo "Error: Directory '$directory' does not exist.\n";
      return;
  }

  $files = [];
  $filesToRename = [];
  $filesInDir = scandir($directory);

  foreach ($filesInDir as $filename) {
    if ($filename === '.' || $filename === '..') continue;
    $fullPath = $directory . DIRECTORY_SEPARATOR . $filename;

    // look at each file
    if (preg_match('/^([A-Za-z0-9]*[_]*)(0*)(\d+)([^.]*)(\.\w+)$/', $filename, $matches)) {
      $prefix = $matches[1];
      $numberDigits = strlen($matches[2].$matches[3]);
      $number = $matches[3];
      $newNumber = $number+$adjustment;
      while ( strlen($newNumber) < $numberDigits ) $newNumber = '0'.$newNumber;
      $suffix = $matches[4];
      $extension = $matches[5];

      // only in the range we're talking about
      if ( $number < $startNumber || $number > $endNumber ) continue;

      $filesToRename[$filename] = [
          'filename' => $directory . DIRECTORY_SEPARATOR . $filename,
          'prefix' => $prefix,
          'numberDigits' => $numberDigits,
          'number' => $number,
          'suffix' => $suffix,
          'extension' => $extension,
          'newFilename' => $directory . DIRECTORY_SEPARATOR . $prefix.$newNumber.$suffix.$extension
      ];

      //print_r($filesToRename);die();
    }
  }

  // what order basedd on adjustment
  if ( $adjustment > 0 ) arsort($filesToRename);
  else asort($filesToRename);
  //print_r($filesToRename); exit();

  // loop through the changes
  foreach ( $filesToRename as $file ) {
    echo "Rename ".$file['filename']." to ".$file['newFilename']." ... ";

    if ($doRename) {
        if (file_exists($file['newFilename'])) {
            echo "File already exists!\n";
            continue;
        }
        if (rename($file['filename'], $file['newFilename'])) {
            echo "Renamed.\n";
        } else {
            echo "Failed to rename!\n";
        }
    } else {
        echo "Skipped.\n";
    }
  }
}
?>

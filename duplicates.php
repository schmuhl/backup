<?php

// Check if the path parameter is provided
if (!isset($argv[1])) {
    echo "Usage: php ".basename(__FILE__)." <path_to_search> [--verbose] [--remove]\n";
    exit(1);
}

// Get the required search path
$directory = $argv[1];

// Initialize flags for optional parameters
$verbose = false;
$removeDuplicates = false;
$removeEmptyDirectories = false;

// Process optional parameters
for ($i = 2; $i < count($argv); $i++) {
    $param = $argv[$i];

    switch ($param) {
        case '--verbose':
            $verbose = true;
            break;
        case '--remove':
            $removeDuplicates = true;
            $removeEmptyDirectories = true;
            break;
        default:
            echo "Error: Unknown parameter '{$param}'.\n";
            echo "Usage: php ".basename(__FILE__)." <path_to_search> [--verbose] [--remove]\n";
            exit(1);
    }
}

$output_json_file = "file_report.json";

// Check if the provided directory exists
if (!is_dir($directory)) {
    echo "Error: Directory '" . $directory . "' not found.\n";
    exit(1);
}

echo "\nOrganizing $directory ...\n";
$start_time = microtime(true);

// Start the scan (this will also write to the JSON file)
if (file_exists($output_json_file)) {
    unlink($output_json_file); // Remove previous report if it exists
}
$handle = fopen($output_json_file, 'w'); // Use 'w' to overwrite, 'a' to append
if ($handle) {
  fwrite($handle,"[\n");
  fclose($handle);
}
list($total_files, $total_folders, $emptyDirectories, $directoriesRemoved) = scanDirectory($directory, $output_json_file,$verbose,$removeEmptyDirectories);
$handle = fopen($output_json_file, 'a'); // Use 'w' to overwrite, 'a' to append
if ($handle) {
  ftruncate($handle,filesize($output_json_file)-2);  // remove the last comma after the last file object
  fwrite($handle,"\n]");
  fclose($handle);
}

echo "\nFound " . number_format($total_files) . " files in " . number_format($total_folders). " folders (" . get_human_seconds((microtime(true) - $start_time)).").\n";
echo "\nFound $emptyDirectories empty directories";
if ( ($emptyDirectories+$directoriesRemoved) > 0 && $removeEmptyDirectories ) echo " and sucessfully removed $directoriesRemoved";
echo ".\n";

analyzeFiles($output_json_file,$verbose,$removeDuplicates);

echo "\n";




function analyzeFiles($output_json_file, $verbose=false, $removeDuplicates=false) {
  $removedFiles = 0;
  echo "\nAnalyzing... ";
  $start_time = microtime(true);

  // Read the entire JSON file into a string
  $jsonString = file_get_contents($output_json_file);

  // Check if there was an error reading the file
  if ($jsonString === false) {
      die("Error reading JSON file: " . $output_json_file . "\n");
  }

  // Decode the JSON string into a PHP object
  $files = json_decode($jsonString);

  // Check if there was an error decoding the JSON
  if ($files === null && json_last_error() !== JSON_ERROR_NONE) {
      die("Error decoding JSON: " . json_last_error_msg() . "\n");
  }

  $duplicate_names = array();
  $duplicate_sizes = array();
  foreach ( $files as $file ) {
    if ( !isset($duplicate_names[$file->name]) ) $duplicate_names[$file->name] = array();
    $duplicate_names[$file->name] []= $file->path;


    if ( !isset($duplicate_sizes[$file->size]) ) { // add the first one
      $duplicate_sizes[$file->size] = array();
      $duplicate_sizes[$file->size] []= $file;
    } else { // add another one
      $duplicate_sizes[$file->size] []= $file;
      for ( $i=0; $i<count($duplicate_sizes[$file->size]); $i++) {  // loop through and ensure all must have a checksum
        if ( !isset($duplicate_sizes[$file->size][$i]->checksum) || empty($duplicate_sizes[$file->size][$i]->checksum) ) {
          $duplicate_sizes[$file->size][$i]->checksum = calculateChecksum($file->path);
        }
        if ( !isset($duplicate_sizes[$file->size][$i]->type) || empty($duplicate_sizes[$file->size][$i]->type) ) {
          $duplicate_sizes[$file->size][$i]->type = @mime_content_type($file->path);
        }
        if ( !isset($duplicate_sizes[$file->size][$i]->time) || empty($duplicate_sizes[$file->size][$i]->time) ) {
          $duplicate_sizes[$file->size][$i]->time = date("Y-m-d H:i:s",@filectime($file->path));
        }
      }
    }

  }

  /*
  foreach ( $duplicate_names as $dup ) {
    if ( count($dup) > 1 ) print_r($dup);
  }*/
  unset($duplicate_names);

  $duplicates = array();
  foreach ( $duplicate_sizes as $dup ) {
    if ( count($dup) > 1 ) {  // we have a duplicate
      if ( in_array(substr($dup[0]->type,0,5),array('image','audio','video') ) ) {  // ignore anything besides images and audio
        //print_r($dup);
        $duplicates []= $dup;
      }
    }
  }
  unset($duplicate_sizes);
  echo " found ".number_format(count($duplicates))." duplicate sets of images, photos and/or videos (" . get_human_seconds((microtime(true) - $start_time)).").\n";


  foreach ( $duplicates as $dup ) {
    if ( $verbose ) echo "Duplicate: ";
    $i = 0;
    foreach ( $dup as $d ) {
      if ( $verbose ) echo "\n\t(".$d->time.', '.get_human_file_size($d->size).') ';
      if ($i==0) {
        if ( $verbose ) echo "ORIGINAL  ";
      } else {
        if (!$removeDuplicates) {
          if ( $verbose ) echo "DUPLICATE ";
        } else {
          if ( unlink($d->path) ) {
            if ( $verbose ) echo "✓ REMOVED ";
            $removedFiles++;
          } else {
            if ( $verbose ) echo "! FAILURE ";
          }
        }
      }

      if ( $verbose ) echo $d->path;
      $i++;
    }
    if ( $verbose ) echo "\n\n";
  }

  if ( $removeDuplicates ) echo "Removed ".number_format($removedFiles)." duplicate files.\n";
  return $removedFiles;
}



/**
 * Recursively scans a directory, collects file information, and writes it to a JSON file.
 */
function scanDirectory($directory, $output_json_file, $verbose=false, $removeEmptyDirectories=false) {

    $file_count = 0;
    $folder_count = 0;
    $all_file_data = array(); // Collect all file data here
    $ignore = array ('.','..','.DS_Store','._.DS_Store','._Thumbs.db');
    $emptyDirectories = 0;
    $directoriesRemoved = 0;

    //echo "Scanning directory: " . $directory . " ... ";
    $start_time = microtime(true);

    $items = scandir($directory);
    if ($items) {
      $directoryIsEmpty = true;
      foreach ($items as $item) {
          if ( in_array($item,$ignore) ) continue;
          $directoryIsEmpty = false;
          $item_path = $directory . DIRECTORY_SEPARATOR . $item;

          if (is_file($item_path)) {
              $file_count++;
              try {
                  $file_info = [
                      "path" => $item_path,
                      "name" => $item,
                      "time" => null,
                      "type" => null, //mime_content_type($item_path), // save time by only running this when needed
                      "size" => filesize($item_path),
                      "checksum" => null //calculateChecksum($item_path) // save time by not running this unless you need to
                  ];
                  $all_file_data[] = $file_info; // Add to the main array
              } catch (Exception $e) {
                  echo "Error processing file " . $item_path . ": " . $e->getMessage() . "\n";
              }
          } elseif (is_dir($item_path)) {
              $folder_count++;
              list($sub_file_count, $sub_folder_count,$sub_emptyDirectories,$sub_directoriesRemoved) = scanDirectory($item_path, $output_json_file,$verbose,$removeEmptyDirectories); // Recursive call to get sub-data
              $file_count += $sub_file_count;
              $folder_count += $sub_folder_count;
              $emptyDirectories += $sub_emptyDirectories;
              $directoriesRemoved += $sub_directoriesRemoved;
          }
      }

      if ($directoryIsEmpty) {
        $emptyDirectories ++;
        if ( $verbose ) echo "Empty Directory: ".$directory;
        if ( $removeEmptyDirectories ) {
          if ( @rmdir($directory) ) {
            if ( $verbose ) echo "✓ REMOVED ";
            $directoriesRemoved++;
          } else {
            if ( $verbose ) echo "! FAILURE ";
          }
        }
        if ( $verbose ) echo "\n";
      }

        // write to the file
        try {
            $handle = fopen($output_json_file, 'a'); // Use 'w' to overwrite, 'a' to append
            if ($handle) {
                foreach ( $all_file_data as $data ) {
                  fwrite($handle, json_encode($data, JSON_PRETTY_PRINT)); // Write the entire array
                  fwrite($handle,",\n");
                }
                fclose($handle);
            } else {
                echo "Error opening JSON file for writing: " . $output_json_file . "\n";
            }
        } catch (Exception $e) {
            echo "Error writing to JSON file: " . $e->getMessage() . "\n";
        }

    } else {
        if ($verbose) echo "Error reading directory: " . $directory . "\n";
    }

    if ($verbose) echo "Scanned $directory (" . get_human_seconds((microtime(true) - $start_time)).") and found ".number_format($file_count)." files and ".number_format($folder_count)." folders.\n";

    return [$file_count, $folder_count, $emptyDirectories, $directoriesRemoved]; // Return the data as well
}


function calculateChecksum($filepath) {
    /**
     * Calculates the SHA-256 checksum of a file.
     */
    try {
        $handle = fopen($filepath, 'rb');
        if ($handle) {
            $ctx = hash_init('sha256');
            while (!feof($handle)) {
                $chunk = fread($handle, 4096); // Read in chunks
                hash_update($ctx, $chunk);
            }
            fclose($handle);
            return hash_final($ctx);
        } else {
            echo "Error opening file: " . $filepath . "\n";
            return null;
        }
    } catch (Exception $e) {
        echo "Error calculating checksum for " . $filepath . ": " . $e->getMessage() . "\n";
        return null;
    }
}

function get_human_file_size ( $size ) {
  if ( $size < 1024 ) return $size.'B';
  if ( $size < 1024**2 ) return round($size/1024,1).'KB';
  if ( $size < 1024**3 ) return round($size/1024**2,1).'MB';
  return round($size/1024**3,1).'GB';
}

function get_human_seconds ( $seconds ) {
  if ( $seconds <= 90 ) return round($seconds,2).' seconds';
  return floor($seconds/60).' minutes and '.round($seconds-(floor($seconds/60)*60)).' seconds';
}

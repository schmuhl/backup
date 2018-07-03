<?php
date_default_timezone_set('America/Denver');



// parse the command line parameters
$path = '/Volumes/SCHMACKUP/Memories/'; //"Sort These".DIRECTORY_SEPARATOR;
$organizedPath = '/Volumes/SCHMACKUP/Memories/'; //".".DIRECTORY_SEPARATOR;
$really = true; // mode 1/true means that we'll actually make changes





echo "Organizing the media files in '$path' ...\n";
if ( !$really ) echo "Only running in analysis mode.\n";



$files = array();

// get all of the files under that path
if ( !file_exists($path) ) die("Could not find path '$path'\n");
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
foreach ($rii as $file) {
  if ( $file->isDir() ) continue;
  $files[] = $file->getPathname();
}
//print_r($files);die();



echo "Looking at ".count($files)." files...\n";



// initialize counters
$validFiles = 0;
$moves = 0;
$failedMoves = 0;
$newDirectories = 0;
$duplicates = 0;
$notDuplicates = 0;


// loop through the results
foreach ( $files as $file ) {

  // skip the directories
  if ( is_dir($file) ) {
    echo "Looking at directory: '$file'...\n";
    continue;
  }


  // get info about this file
  $result = getFileData($file);
  //print_r($result); die();


  if ( $result ) {
      echo "Looking at '$file'...";


      // files we know we can Skip
      if ( in_array($result['FileName'], array('.DS_Store', 'Desktop.ini')) || preg_match("`^\._`",$result['FileName']) ) {
        echo " ignorable file. Skip.\n";
        continue;
      }


      // validate--need a valid date
      if ( !isset($result['FileDateTime']) || $result['FileDateTime'] == 0 ) {
        echo " no valid file date. Skip.\n";
        continue; // need a valid date
      }

      echo " taken on ".date("Y-m-d",$result['FileDateTime'])." ...";

      $validFiles++;

      // Create the new path
      $newPath = $organizedPath.date("Y",$result['FileDateTime']).DIRECTORY_SEPARATOR.date("Y-m",$result['FileDateTime']).DIRECTORY_SEPARATOR;

      if ( $newPath.$result['FileName'] == $file ) {
        echo " Already good.\n";
        continue;
      }


      // Create the newPath directory, if needed
      if ( !file_exists($newPath) ) {
          echo "\n\tCREATING DIRECTORY '$newPath' ...";
          if ( $really && mkdir($newPath,0777,true) ) {
            echo " DONE.\n";
            $newDirectories++;
          } else echo " FAILED.\n";
      }


      echo "\n\tMOVE TO '$newPath' ...";
      // How could it already be there?
      if ( file_exists($newPath.$result['FileName']) ) {
          if (
            filesize($newPath.$result['FileName']) == filesize($file) // Same filesize
            //&& md5($newPath.$result['FileName']) == md5($file) // same content @todo md5(file_get_contents($file))
          ) {
              echo " DUPLICATE\n";
              // delete one?
              die("\n\n'Should delete one of these files?\n$file'\n'$newPath".$result['FileName']."'\n\n");
              $duplicates++;
              continue;
          } else {
              echo " DIFFERENT and ";
              $notDuplicates++;
              $new = substr($result['FileName'],0,strrpos($result['FileName'],'.')).' 2'.substr($result['FileName'],strrpos($result['FileName'],'.'));
              $result['FileName'] = $new;
              //continue;
          }
      }


      // Attempt to move the file
      if ( $really && rename($file,$newPath.$result['FileName']) ) {
          echo " MOVED!!!\n";
          $moves++;
      } else {
          echo "Could not move from '$file' to '$newPath".$result['FileName']."'\n";
          $failedMoves++;
      }


      //print_r($result);
      //die();
  }
}


// Summary details.
echo "Done. \n\tConsidered ".count($files)." files\n\tLooked at $validFiles valid files\n\tMoved $moves ($failedMoves failed)\n\tCreated $newDirectories new directories";
echo "\n\tIdentified $duplicates duplicate files\n\tFound $notDuplicates possible duplicates or filename conflicts.\n\n";





function getFileData ( $path ) {
  $info = array();

  // default values, can be overwritten by exif
  $info['FileDateTime'] = filectime($path);
  $info['FileName'] = explode(DIRECTORY_SEPARATOR,$path);
  $info['FileName'] = $info['FileName'][count($info['FileName'])-1];
  $info['FileSize'] = filesize($path);
  // file type: audio, video, image, etc.

  $exif = @exif_read_data($path);
  if ( $exif ) {
    $info = $exif;
  }

  return $info;
}

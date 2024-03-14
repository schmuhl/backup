<?php
date_default_timezone_set('America/Chicago');

// Error handling
if ( !isset($argv[1]) || empty(trim($argv[1])) ) {
  echo "Please specify a folder to organize. Usage: folder [-real] [-duplicates]\n";
  exit(1);
}

if ( !file_exists($argv[1]) ) {
  echo "Sorry, I could not find the '".$argv[1]."' folder to organize.\n";
  exit(1);
}


// set the parameters
File::$home = $argv[1];
if ( in_array('-real',$argv) ) File::$forReal = true; // are we allowed to make changes?
else File::$forReal = false;
if ( in_array('-duplicates',$argv) ) File::$resolveDuplicates = true; // are we allowed
else File::$resolveDuplicates = false;

echo "Scanning '".File::$home."' ...\n";
$file = new File ( File::$home );
//print_r($file); die();

echo "---------- Photo Folder Summary:\n";
$stats = $file->getStats(false); //die();
echo "\n\n";

$file->pruneEmptyDirectories();
$num = $file->organize();
echo "\n---------------\n".intval($num)." files moved.\n\n";


// summary
echo "---------- Action Summary\n";
echo File::$resolvedDuplicates." duplicate files were resolved.\n";
echo File::$deletedFiles." unnecessary files were removed.\n";
echo File::$deletedDirectories." empty folders were removed.\n";
echo "There were    ".$stats['files'].' files in '.$stats['directories']." directories.\n";
if ( File::$forReal ) { // only meaningful if changes were made
  $file2 = new File ( File::$home );
  $stats2 = $file2->getStats();
  echo "There are now ".$stats2['files'].' files in '.$stats2['directories']." directories.\n\n";
}



class File {

  public static $home;
  public static $filesToIgnore = array('.','..','organizePhotos.php','.git');
  public static $filesToDelete = array('desktop.ini','.DS_Store');
  public static $forReal = false;
  public static $resolveDuplicates = false;
  public static $resolvedDuplicates = 0;
  public static $deletedFiles = 0;
  public static $deletedDirectories = 0;

  public $fileName;
  public $data;
  public $type;

  public $directoryContents;
  public $taken;  // photo taken date


   public function __construct ( $path ) {
      //echo "Looking at file $path ...\n";
      if ( !file_exists($path) ) return;
      $this->taken = null;
      $this->fileName = $path;
      $this->data = @exif_read_data($path);
      if ( is_array($this->data) ) {
         //print_r($this->data); die();
         if ( isset($this->data['DateTimeOriginal']) && !empty($this->data['DateTimeOriginal']) )
            $this->taken = strtotime($this->data['DateTimeOriginal']);
         else if ( isset($this->data['FileDateTime']) && !empty($this->data['FileDateTime']) )
            $this->taken = $this->data['FileDateTime'];
      }

      if ( !is_numeric($this->taken) || $this->taken < 1 || !isset($this->taken) )
         $this->taken = filectime($path); //null;

      $this->type = filetype($path);
      if ( $this->type == 'dir' ) $this->directoryContents = $this->getDirectoryContents();
   }


   public function getStats ( $quiet=true ) {
     $stats = array();
     $stats['files'] = 0;
     $stats['directories'] = 0;
     if ( is_array($this->directoryContents) ) {
       foreach ( $this->directoryContents as $file ) {  // loop through the directory
         if ( $file->type == 'file' ) $stats['files']++;
         if ( $file->type == 'dir' ) {
           $stats['directories']++;
           $stats2 = $file->getStats($quiet);
           $stats['files'] += $stats2['files'];
           $stats['directories'] += $stats2['directories'];
         }
       }
     }
     if ( $quiet==false ) echo "$this->fileName has ".$stats['files']." files and ".$stats['directories']." directories.\n";
     return $stats;
   }


   public function getDirectoryContents () {
      $return = array();
      $files = scandir($this->fileName);
      //echo "Getting directory contents of $this->fileName...\n";
      foreach ( $files as $file ) {
         if ( in_array($file,File::$filesToIgnore) ) continue;
         $return []= new File ($this->fileName.DIRECTORY_SEPARATOR.$file);
      }
      return $return;
   }


  public function pruneEmptyDirectories () {
    // attempt to remove unwanted files
    if ( $this->type != 'dir' ) {
      // is this a file we don't want?
      $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
      $file = $file[count($file)-1];
      //die("File is $file");
      if ( in_array($file,File::$filesToDelete) ) {
        if ( File::$forReal ) {
          echo "\tRemoving file $file ...";
          if ( unlink($this->fileName) ) {
            echo " done.\n";
            File::$deletedFiles++;
            return 0;
          }
          else echo " ERROR!\n";
        } else {
          echo "\tNOT removing file $this->fileName\n";
          return 1;
        }
      }

      //echo "\t$this->fileName file found!\n";
      return 1;
    }

    // recurse
    $hasFiles = 0;
    foreach ( $this->directoryContents as $file ) {
      $hasFiles += $file->pruneEmptyDirectories();
      //echo "\t$file->fileName\n";
    }

    // Attempt to prune this direcotry
    //if ( $hasFiles > 0 ) echo "$this->fileName has $hasFiles files and cannot be pruned.\n";
    if ( $hasFiles <= 0 ) {
      if ( is_array($this->directoryContents) && count($this->directoryContents) > 0 ) return $hasFiles;  // can't delete a directory with stuff in it!
      echo "Pruning $this->fileName ...";
      if ( File::$forReal ) {
        if ( is_dir($this->fileName) && $this->fileName != './' ) {
          if ( !rmdir($this->fileName) ) echo " FAILED!\n";
          else {  // successfully removed empty directory
            File::$deletedDirectories++;
            echo " done.\n";
          }
        } else echo " this is not a directory.\n";
      } else echo " NOT pruned.\n";
      return $hasFiles;
    }
  }


   public function organize () {
      $num = 0;
      if ( $this->type == 'dir' ) { // directory? then recurse!
         foreach ( $this->directoryContents as $file ) {
            $num += $file->organize();
         }
         return $num;
      } else {  // leaf/edge, handle the move, if possible

        // check if this is a file that should have been deleted or ignored
        $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
        $file = $file[count($file)-1];
        if ( in_array($file,File::$filesToDelete) || in_array($file,File::$filesToIgnore) ) {
          echo "Organizing $this->fileName and skipping because it should be deleted or ignored.\n";
          return false;
        }

         //echo "Organizing $this->fileName";
         if ( !isset($this->taken) ) {
            echo "Organizing $this->fileName and skipping because of unknown date!\n";
            return false;
         }
         //echo " (taken ".date("Y-m-d",$this->taken).") ...";

         // Create the new path
         $path = File::$home.'/';
         $newPath = $path.date("Y",$this->taken).DIRECTORY_SEPARATOR.date("Y-m",$this->taken).DIRECTORY_SEPARATOR;
         $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
         $file = $file[sizeof($file)-1];

         // already where it needs to be?
         if ( $this->fileName == $newPath.$file ) {
            //echo " already OK\n";
            return false;
         }

         echo "Organizing $this->fileName ..."; // (taken ".date("Y-m-d",$this->taken).") ...";
         echo " MOVE TO $newPath ...";

         // Create the new directory, if needed
         if ( !file_exists($newPath) ) {
             echo "\n\tCREATING DIRECTORY $newPath ...";
             if ( File::$forReal ) {
               if ( mkdir($newPath,0777,true) ) {
                 echo " done.\n";
               } else echo " FAILED!\n";
             } else {
               echo " not created.\n";
             }
         }

         // How could it already be there?
         if ( $this->fileName == $newPath.$file ) {
           echo " already where it needs to be.\n";
         } else if ( file_exists($newPath.$file) ) {
           //echo " FAILED: already a file there with the same name!\n";
             //echo "\n\tInvestigating duplicate file...";
             // the file names and dates are the same - we know that
             if (filesize($newPath . $file) == filesize($this->fileName)) {
                if ( md5(file_get_contents($newPath.$file)) == md5(file_get_contents($this->fileName) ) ) {
                   echo " duplicate file";
                   if ( File::$forReal && File::$resolveDuplicates ) {
                     if ( unlink($this->fileName) ) {
                       echo " resolved.\n";
                       File::$resolvedDuplicates++;
                       return 1;
                     } else {
                       echo " could not be resolved.\n";
                     }
                   } else {
                      echo " still exists!\n";
                      return false;
                   }
                }
            }

            // the files are named the same, so rename and then copy over
            for ( $n=2; $n<10; $n++ ) {
               $extension = explode('.',$file);
               $extension = $extension[count($extension)-1];
               $try = str_replace(".$extension"," $n.$extension",$file);
               if ( !file_exists($newPath.$try) ) {
                  $file = $try;
                  break;
               }
            }
            //echo("\nOK the old name is $this->fileName and the new name is ".$newPath.$file."\n");
            if ( file_exists($newPath.$file) ) return false;
         }


         // Attempt to move the file
         if ( File::$forReal ) {
           if ( rename($this->fileName,$newPath.$file) ) {
             echo " done.\n";
             return 1;
           } else {
             echo " FAILED.\n";
             return false;
           }
         } else {
           echo " not moved.\n";
         }

         return false;
      }
   }

}

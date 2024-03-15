<?php
date_default_timezone_set('America/Chicago');

// Error handling
if ( !isset($argv[1]) || empty(trim($argv[1])) ) {
  echo "Please specify a folder to organize. Usage: folder [-move] [-prune] [-duplicates]\n";
  exit(1);
}

if ( !file_exists($argv[1]) ) {
  echo "Sorry, I could not find the '".$argv[1]."' folder to organize.\n";
  exit(1);
}


// set the parameters
File::$home = $argv[1];
if ( in_array('-move',$argv) ) File::$move = true; // are we allowed to make changes?
else File::$move = false;
if ( in_array('-prune',$argv) ) File::$prune = true; // are we allowed to remove useless?
else File::$prune = false;
if ( in_array('-duplicates',$argv) ) File::$resolveDuplicates = true; // are we allowed to de-dup?
else File::$resolveDuplicates = false;


// Execute!
echo "Scanning '".File::$home."' ...\n";
$file = new File ( File::$home );
if ( File::$prune ) $pruned = $file->prune(); else $pruned = 0;
if ( File::$move ) $organized = $file->organize(); else $organized = 0;
if ( File::$resolveDuplicates ) $duplicates = $file->handleDuplicates(); else $duplicates = 0;


// summary
echo "---------- Action Summary\n";
echo "$organized files were organized.\n";
echo "$duplicates duplicate files were resolved.\n";
echo File::$deletedFiles." unnecessary files were pruned.\n";
echo "$pruned empty folders were removed.\n";
$stats = $file->getStats(true);
echo "There were    ".$stats['files'].' files in '.$stats['directories']." directories.\n";
if ( File::$move ) { // only meaningful if changes were made
  $file2 = new File ( File::$home );
  $stats2 = $file2->getStats();
  echo "There are now ".$stats2['files'].' files in '.$stats2['directories']." directories.\n\n";
}



class File {

  public static $home;
  public static $filesToIgnore = array('.','..','organizePhotos.php','.git');
  public static $filesToPrune = array('desktop.ini','.DS_Store','.picasa.ini','.picasa 2.ini','Picasa.ini','Picasa 2.ini');
  public static $move = false;
  public static $prune = false;
  public static $resolveDuplicates = false;

  public static $movedFiles = 0;
  public static $deletedFiles = 0;
  public static $deletedDirectories = 0;

  public $fileName;
  public $data;
  public $type;
  public $size;
  public $md5;

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
      foreach ( $files as $file ) {
         if ( in_array($file,File::$filesToIgnore) ) continue;
         $return []= new File ($this->fileName.DIRECTORY_SEPARATOR.$file);
      }
      echo "Found ".count($return)." items found in $this->fileName \n";
      $this->directoryContents = $return;
      return $return;
   }


  public function prune () {
    $pruned = 0;

    // prune recursively first
    if ( $this->type == 'dir' ) {

      foreach ( $this->directoryContents as $f ) {
        $pruned += $f->prune();
      }

      // Attempt to prune this directory
      $this->getDirectoryContents();

      if ( count($this->directoryContents) <= 0 ) {
        echo "Pruning directory $this->fileName ...";
        if ( File::$prune ) {
          if ( is_dir($this->fileName) && $this->fileName != './' && $this->fileName != File::$home ) {
            if ( !rmdir($this->fileName) ) echo " FAILED!\n";
            else {  // successfully removed empty directory
              echo " done.\n";
              return 1;
            }
          } else echo " this is not a valid directory.\n";
        } else echo " NOT pruned.\n";
      }

      return $pruned;
    }


    // attempt to remove unwanted files
    if ( $this->type != 'dir' ) {
      // is this a file we don't want?
      $f = explode(DIRECTORY_SEPARATOR,$this->fileName);
      $f = $f[count($f)-1];
      //die("File is $f");
      if ( in_array($f,File::$filesToPrune) ) {
        if ( File::$prune ) {
          echo "\tRemoving file $f ...";
          if ( $this->delete(false) ) {
            File::$deletedFiles++;
            echo " done.\n";
            return 1;
          }
          else echo " ERROR!\n";
        } else {
          echo "\tNOT removing file $this->fileName\n";
          return 0;
        }
      }

      //echo "\t$this->fileName file found!\n";
      return 0;
    }

    return 0;
  }


   public function organize () {
      $num = 0;
      if ( $this->type == 'dir' ) { // directory? then recurse!
        if ( $this->fileName == FILE::$home.DIRECTORY_SEPARATOR.'Trash' ) return 0; // don't process the trash

         foreach ( $this->directoryContents as $file ) {
            $num += $file->organize();
         }
         return $num;
      } else {  // leaf/edge, handle the move, if possible

        // check if this is a file that should have been deleted or ignored
        $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
        $file = $file[count($file)-1];
        if ( in_array($file,File::$filesToPrune) || in_array($file,File::$filesToIgnore) ) {
          echo "Organizing $this->fileName and skipping because it should be deleted or ignored.\n";
          return 0;
        }

         //echo "Organizing $this->fileName";
         if ( !isset($this->taken) ) {
            echo "Organizing $this->fileName and skipping because of unknown date!\n";
            return 0;
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
            return 0;
         }

         echo "Organizing $this->fileName ..."; // (taken ".date("Y-m-d",$this->taken).") ...";
         echo " MOVE TO $newPath ...";

         // Create the new directory, if needed
         if ( !file_exists($newPath) ) {
             echo "\n\tCREATING DIRECTORY $newPath ...";
             if ( File::$move ) {
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
            if ( file_exists($newPath.$file) ) {
              echo " file already exists there.\n";
              return 0;
            }
         }


         // Attempt to move the file
         if ( File::$move ) {
           if ( rename($this->fileName,$newPath.$file) ) {
             echo " done.\n";
             return 1;
           } else {
             echo " FAILED.\n";
             return 0;
           }
         } else {
           echo " not moved.\n";
         }

         return 0;
      }
   }


  public function handleDuplicates ( ) {
    if ( $this->type != 'dir' ) return 0;
    if ( $this->fileName == FILE::$home.DIRECTORY_SEPARATOR.'Trash' ) return 0; // don't process the trash
    //echo "Looking at folder $this->fileName ...\n";

    $duplicates = 0;
    $this->getDirectoryContents(); // grab a fresh copy

    // get details on all the files in this directory
    $files = array();
    foreach ( $this->directoryContents as $child ) {
      // recurse down into child directories first
      if ( $child->type == 'dir' ) {
        $duplicates += $child->handleDuplicates();
      } else {  // get more file info to determine duplicates
        $child->md5 = md5(file_get_contents($child->fileName));
        $child->size = filesize($child->fileName);
        $files []= $child;
      }
    }

    // compare all of the files to each other to find duplicates
    for ( $i=0; $i<count($files); $i++ ) {
      if ( $files[$i] == null || ! $files[$i] instanceof File || !file_exists($files[$i]->fileName) ) continue; // file was deleted
      for ( $j=0; $j<count($files); $j++ ) {
        if ( $files[$j] == null || $files[$i] == null || !file_exists($files[$j]->fileName) ) continue; // file was deleted
        // compare files
        if ( $i != $j && $files[$i]->md5 == $files[$j]->md5 && $files[$i]->size == $files[$j]->size ) {
          echo "DUPLICATE FILES: ".$files[$i]->fileName." and ".$files[$j]->fileName." ...";
          if ( FILE::$resolveDuplicates ) {

            // which one has a shorter name?
            if ( strlen($files[$i]->fileName) < strlen($files[$j]->fileName) ) {
              $d = $j;
            } else if ( strlen($files[$i]->fileName) == strlen($files[$j]->fileName) ) {
              if ( $files[$i]->fileName < $files[$j]->fileName ) $d = $j;
              else $d = $i;
            } else $d = $i;

            // delete
            if ( $files[$d]->delete() ) {
              $files[$d] = null;
              echo " Resolved.\n";
              $duplicates++;
            } else echo " FAILED.\n";
          } else {
            echo " Ignored.\n";
          }
        }
      }
    }

    return $duplicates;
  }


  public function delete ( $trash = true ) {
    // here's where I'd like to remove the file from the global $file but I can't figure out how without a map or something
    if ( !file_exists($this->fileName) ) return true;  // already gone?

    //echo "\nDeleting $this->fileName.\n"; return true;

    // move it to the trash
    if ( $trash == true ) {
      // make sure the trash directory exists
      $newPath = File::$home.DIRECTORY_SEPARATOR.'Trash'.DIRECTORY_SEPARATOR;
      if ( !file_exists($newPath) ) {
        if ( !mkdir($newPath,0777,true) ) return false;
      }

      // Move the file to the trash
      $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
      $file = microtime(true).' -'.$file[sizeof($file)-1];
      return rename($this->fileName,$newPath.$file);
    } else {
      return unlink($this->fileName);  // naw, just delete it
    }
  }



}

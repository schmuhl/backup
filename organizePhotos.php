<?php
date_default_timezone_set('America/Chicago');
$path = '.';
File::$forReal = true;

$file = new File ( $path );
//print_r($file); die();
$file->pruneEmptyDirectories();
$num = $file->organize();
echo "\n---------------\n".intval($num)." files moved.\n\n";
die();





class File {

   public $fileName;
   public $data;
   public $type;
   public $directoryContents;
   public static $filesToIgnore = array('.','..','organizePhotos.php');
   public static $filesToDelete = array('desktop.ini','_DS_Store');
   public static $forReal = false;


   public function __construct ( $path ) {
      //echo "Looking at file $path ...\n";
      if ( !file_exists($path) ) return;
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

      $hasFiles = 0;
      foreach ( $this->directoryContents as $file ) {
         $hasFiles += $file->pruneEmptyDirectories();
         //echo "\t$file->fileName\n";
      }

      //if ( $hasFiles > 0 ) echo "$this->fileName has $hasFiles files and cannot be pruned.\n";
      if ( $hasFiles <= 0 ) {
         echo "Pruning $this->fileName ...";
         if ( is_dir($this->fileName) && $this->fileName != './' ) {
            if ( !rmdir($this->fileName) ) echo " FAILED!\n";
            else echo " done.\n";
         }
      }
      return $hasFiles;
   }


   public function organize () {
      $num = 0;
      if ( $this->type == 'dir' ) { // directory? then recurse!
         foreach ( $this->directoryContents as $file ) {
            $num += $file->organize();
         }
         return $num;
      } else {  // leaf/edge, handle the move, if possible
         echo "Organizing $this->fileName";
         if ( !isset($this->taken) ) {
            echo " skipping because of unknown date!\n";
            return false;
         }
         echo " (taken ".date("Y-m-d",$this->taken).") ...";

         // Create the new path
         $path = '../';
         $newPath = $path.date("Y",$this->taken).DIRECTORY_SEPARATOR.date("Y-m",$this->taken).DIRECTORY_SEPARATOR;
         $file = explode(DIRECTORY_SEPARATOR,$this->fileName);
         $file = $file[sizeof($file)-1];

         // already where it needs to be?
         if ( $this->fileName == $newPath.$file ) {
            echo " OK\n";
            return false;
         }

         echo " MOVE TO $newPath ...";

         // Create the new directory, if needed
         if ( !file_exists($newPath) ) {
             echo "\n\tCREATING DIRECTORY $newPath ...";
             if ( File::$forReal && mkdir($newPath,0777,true) )
               echo " done.\n";
             else echo " FAILED!\n";
         }

         // How could it already be there?
         if ( $this->fileName != $newPath.$file && file_exists($newPath.$file) ) {
             //echo "\n\tInvestigating duplicate file...";
             // the file names and dates are the same - we know that
             if (filesize($newPath . $file) == filesize($this->fileName)) {
                if ( md5(file_get_contents($newPath.$file)) == md5(file_get_contents($this->fileName) ) ) {
                   echo " duplicate file resolved...";
                   if ( unlink($this->fileName) ) {
                      echo " done.\n";
                      return 1;
                   } else {
                      echo " FAILED!\n";
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
         if ( File::$forReal && rename($this->fileName,$newPath.$file) ) {
             echo " done.\n";
             return 1;
         } else if ( File::$forReal ) {
             die("Could not move from $path to $newPath\n");
         } else {
            echo "     test, no move.\n";
            return false;
         }

         return false;
      }
   }

}









die();
$forReal = false;
$files = scandir($path);
//print_r($result); die();

if ( !is_array($files) || count($files) == 0 ) die("There are no files in $path\n");

foreach ( $files as $file ) {
    if ( is_dir($path.$file) ) continue;
    $result = @exif_read_data($path.$file);
    if ( $result ) {
        // validate




        //print_r($result);
        //die();
    }
}

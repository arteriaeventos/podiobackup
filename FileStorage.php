<?php

require_once 'IStorage.php';

/**
 * Description of FileStorage
 *
 * @author SCHRED
 */
class FileStorage implements IStorage {

    private $filesFolder;
    private $filenameFilestore;

    /**
     * 
     * @param type $filesFolder files are downloaded in this folder. All links are relative to this folder.
     */
    function __construct($filesFolder) {
        $this->filesFolder = $filesFolder;
        $this->filenameFilestore = $this->filesFolder . '/filestore.php';
    }

    public function storePodioFile(PodioFile $file) {
        global $config;

        $link = $file->link;
        if ($file->hosted_by == "podio") {
            //$filestore: Stores fileid->path_to_file_relative to backupTo-folder.
            //this is loaded on every call to assure files from interrupted runs are preserved.
            $filestore = array();

            if (file_exists($this->filenameFilestore)) {
                $filestore = unserialize(file_get_contents($this->filenameFilestore));
            }
            $filename = fixDirName($file->name);
            while (file_exists($this->filesFolder . '/' . $filename))
                $filename = 'Z' . $filename;
            if (array_key_exists($file->file_id, $filestore)) {
                echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
                return $filestore[$file->file_id];
            } else {

                try {
                    $filepath = $this->filesFolder . '/' . $filename;
                    file_put_contents($filepath, $file->get_raw());
                    RateLimitChecker::preventTimeOut();
                    $link = $filename;

                    $filestore[$file->file_id] = $filename;
                    file_put_contents($this->filenameFilestore, serialize($filestore));
                } catch (PodioBadRequestError $e) {
                    echo $e->body;   # Parsed JSON response from the API
                    echo $e->status; # Status code of the response
                    echo $e->url;    # URI of the API request
                    // You normally want this one, a human readable error description
                    echo $e->body['error_description'];
                }
            }
        } else {
            #echo "Warning: Not downloading file hosted by ".$file->hosted_by."\n";
        }
        unset($filestore);
        return $link;
    }

    public function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL) {
        echo "item storage not implemented yet.\n";
        return -1;
    }

    function storeFile($bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL) {
        echo "filestorage not implemented yet.\n";
        return -1;
    }

}

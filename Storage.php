<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once 'podio-php/PodioAPI.php';
require_once 'IStorage.php';

/**
 * Description of Storage
 *
 * @author SCHRED
 */
class Storage implements IStorage {

    private $db;
    private $collectionname = 'mytestcollection';
    private $collection;
    private $fs;
    private $backupId;
    private $filestoreId;

    /**
     * Stores fileid->mongo_id to backupTo-folder.
     * Currently this is loaded in the beginning and saved in the destructor. 
     * If performance is not an issue one could implement: load on every call to assure files from interrupted runs are preserved.
     * @var array 
     */
    private $filestore;

    function __construct($dbname, $collection, $backupId) {
        $this->db = Storage::getMongo()->selectDB($dbname);
        if (isset($collection)) {
            $this->collectionname = $collection;
        }
        $this->fs = $this->db->getGridFS();
        $this->collection = $this->db->selectCollection($this->collectionname);
        $this->backupId = $backupId;

        $filestoreDoc = $this->collection->findOne(array("description" => "filestore"));
        if (is_null($filestoreDoc)) {
            $newFileStore = array();
            $this->store($newFileStore, 'filestore');
            echo "created filestore\n";
            $filestoreDoc = $this->collection->findOne(array("description" => "filestore"));
        }
        $this->filestoreId = $filestoreDoc['_id'];
        $this->filestore = unserialize($filestoreDoc['value']);
    }

    public function __destruct() {
        $this->collection->save(array(
            '_id' => $this->filestoreId,
            'value' => serialize($this->filestore),
            'description' => 'filestore'));
        echo "saved filestore to db. (id: $this->filestoreId)\n";
    }

    /**
     * 
     * @global type $mongo
     * @return \MongoClient
     */
    public static function getMongo() {
        global $mongo;
        if (!isset($mongo) || is_null($mongo)) {
            $dbhost = getenv('OPENSHIFT_MONGODB_DB_HOST');
            if ($dbhost != false) {
                $dbport = getenv('OPENSHIFT_MONGODB_DB_PORT');
                $user = "admin";
                $password = "IZ7ZCYaV8KrM";

                $mongo = new MongoClient("mongodb://$user:$password@$dbhost:$dbport/");
            } else {
                $mongo = new MongoClient();
            }
        }
        return $mongo;
    }

    /**
     * 
     * @return MongoDB
     */
    public static function getMongoDb() {
        $dbname = 'php';
        return getMongo()->selectDB($dbname);
    }

    function storeFile($bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL) {
        $metadata = array(
            'filename' => $filename,
            'backupcollection' => $this->collectionname,
            'backupId' => $this->backupId,
            'mimeType' => $mimeType);

        if (!is_null($originalUrl))
            $metadata['originalUrl'] = $originalUrl;
        if (!is_null($podioFileId))
            $metadata['podioFileId'] = $podioFileId;
        if (!is_null($orgName))
            $metadata['organization'] = $orgName;
        if (!is_null($spaceName))
            $metadata['space'] = $spaceName;
        if (!is_null($appName))
            $metadata['app'] = $appName;
        if (!is_null($podioItemId))
            $metadata['podioItemId'] = $podioItemId;

        /* type MongoId */
        $result = $this->fs->storeBytes($bytes, $metadata);

        return $result->id;
    }

    function storePodioFile(PodioFile $file) {
        echo "storing file $file->name\n";
        #var_dump($file);
        $link = $file->link;
        if ($file->hosted_by == "podio") {
            echo "file hosted by podio\n";
            $filename = fixDirName($file->name);
            if (array_key_exists($file->file_id, $this->filestore)) {
                echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
                return $this->filestore[$file->file_id];
            } else {
                try {
                    $fileId = $this->storeFile(
                            $file->get_raw(), $filename, $file->mimetype, $file->link, $file->file_id);
                    RateLimitChecker::preventTimeOut();
                    $this->filestore[$file->file_id] = $fileId;
                    return $fileId;
                } catch (PodioBadRequestError $e) {
                    echo $e->body;   # Parsed JSON response from the API
                    echo $e->status; # Status code of the response
                    echo $e->url;    # URI of the API request
                    // You normally want this one, a human readable error description
                    echo $e->body['error_description'];
                }
            }
        } else {
            echo "Not downloading file hosted by " . $file->hosted_by . "\n";
        }
        return $link;
    }

    function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL) {

        $item = array('backupId' => $this->backupId, 'value' => ((!is_string($value) && is_object($value)) ? serialize($value) : $value));

        if (!is_null($description))
            $item['description'] = $description;
        if (!is_null($orgName))
            $item['organization'] = $orgName;
        if (!is_null($spaceName))
            $item['space'] = $spaceName;
        if (!is_null($appName))
            $item['app'] = $appName;
        if (!is_null($podioItemId))
            $item['podioItemId'] = $podioItemId;

        $this->collection->insert($item);
    }

}

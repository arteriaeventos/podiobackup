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
class Storage implements IStorage
{

    private $db;
    private $collectionname = 'mytestcollection';
    private $collection;
    private $fs;
    private $backupId;

    function __construct($dbname, $collection, $backupId)
    {
        $this->db = Storage::getMongo()->selectDB($dbname);
        if (isset($collection)) {
            $this->collectionname = $collection;
        }
        $this->fs = $this->db->getGridFS();
        $this->collection = $this->db->selectCollection($this->collectionname);
        $this->backupId = $backupId;
    }

    function start()
    {
        $this->store(array(
            'time_start' => new DateTime(),
            'status' => 'running'
        ), 'backup iteration metadata');
    }

    function pause_start()
    {
        $this->collection->update(
            array(
                'description' => 'backup iteration metadata',
                'backupId' => $this->backupId),
            array(
                '$set' => array(
                    'value.status' => 'paused',
                    'value.time_pause' => new DateTime()
                )
            ));
    }

    function pause_end()
    {
        $this->collection->update(
            array(
                'description' => 'backup iteration metadata',
                'backupId' => $this->backupId),
            array(
                '$set' => array('value.status' => 'running')
            ));
    }

    function finished()
    {
        $this->collection->update(
            array(
                'description' => 'backup iteration metadata',
                'backupId' => $this->backupId),
            array(
                '$set' => array(
                    'value.status' => 'finished',
                    'value.time_end' => new DateTime()
                )
            ));
    }

    /**
     *
     * @global MongoClient $mongo
     * @return \MongoClient
     */
    public static function getMongo()
    {
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
    public static function getMongoDb()
    {
        $dbname = 'php';
        return getMongo()->selectDB($dbname);
    }

    function storeFile($bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL)
    {
        $metadata = array(
            'filename' => $filename,
            'backupcollection' => $this->collectionname,
            'backupId' => array($this->backupId),
            'mimeType' => $mimeType);

        if (!is_null($originalUrl))
            $metadata['originalUrl'] = $originalUrl;
        if (!is_null($podioFileId))
            $metadata['podioFileId'] = $podioFileId;

        $metadata['organization'] = array(is_null($orgName) ? 'NULL' : $orgName);
        $metadata['space'] = array(is_null($spaceName) ? 'NULL' : $spaceName);
        $metadata['app'] = array(is_null($appName) ? 'NULL' : $appName);
        $metadata['podioItemId'] = array(is_null($podioItemId) ? 'NULL' : $podioItemId);

        if (is_null($bytes)) {
            $metadata['external'] = true;
            $result = $this->fs->storeBytes('external file', $metadata);
        } else {
            /* type MongoId */
            $result = $this->fs->storeBytes($bytes, $metadata);
        }

        return $result->{'$id'};
    }

    function storePodioFile(PodioFile $file, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL)
    {
        echo "storing file $file->name\n";
        #var_dump($file);
        $link = $file->link;
        $dbfile = $this->fs->findOne(array('podioFileId' => $file->file_id));


        if (!is_null($dbfile)) {
            echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
            $changed = false;
            $attributes = array(
                'backupId' => $this->backupId,
                'organization' => $orgName,
                'space' => $spaceName,
                'app' => $appName,
                'podioItemId' => $podioItemId
            );
            foreach ($attributes as $key => $value) {
                $realValue = is_null($value) ? 'NULL' : $value;
                if (isset($dbfile->file[$key])) {
                    if (!in_array($realValue, $dbfile->file[$key])) {
                        array_push($dbfile->file[$key], $realValue);
                        $changed = true;
                    }
                } else {
                    $dbfile->file[$key] = array($realValue);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->fs->save($dbfile->file);
            }
            return $dbfile->file['_id']->{'$id'};
        } else {
            if ($file->hosted_by == "podio") {
                echo "file hosted by podio\n";
                try {
                    $fileId = $this->storeFile(
                        $file->get_raw(), $file->name, $file->mimetype, $file->link, $file->file_id, $orgName, $spaceName, $appName, $podioItemId);
                    RateLimitChecker::preventTimeOut();
                    return $fileId;
                } catch (PodioBadRequestError $e) {
                    echo $e->body; # Parsed JSON response from the API
                    echo $e->status; # Status code of the response
                    echo $e->url; # URI of the API request
                    // You normally want this one, a human readable error description
                    echo $e->body['error_description'];
                }
            } else {
                echo "Not downloading file hosted by " . $file->hosted_by . "\n";
                $fileId = $this->storeFile(
                    NULL, $file->name, $file->mimetype, $file->link, $file->file_id, $orgName, $spaceName, $appName, $podioItemId);
            }
        }

        return $link;
    }

    function store($value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL)
    {

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

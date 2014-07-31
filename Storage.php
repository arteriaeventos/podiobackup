<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

require_once 'podio-php/PodioAPI.php';
require_once 'IStorage.php';

/* storage element attributes: */
define('PODIO_ID', 'podioItemId');
define('APP', 'app');
define('SPACE', 'space');
define('ORG', 'organization');
define('DESCRIPTION', 'description');
define('ITERATION', 'backupId');
define('VALUE', 'value');

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
    private $account;
    private $doCheckDbSize = true;

    function __construct($dbname, $collection, $backupId)
    {
        $this->db = Storage::getMongo()->selectDB($dbname);
        if (isset($collection)) {
            $this->collectionname = $collection;
        }
        $this->fs = $this->db->getGridFS();
        $this->collection = $this->db->selectCollection($this->collectionname);
        $this->backupId = $backupId;
        $this->account = self::getMongo()->selectDB('application')->selectCollection('accounts')->findOne(array('account' => $dbname));
    }

    function start()
    {

        $this->doCheckDbSize = false;
        $this->store(array(
            'time_start' => new DateTime(),
            'status' => 'running'
        ), 'backup iteration metadata');
        $this->doCheckDbSize = true;
    }

    function pause_start()
    {
        $this->collection->update(
            array(
                DESCRIPTION => 'backup iteration metadata',
                ITERATION => $this->backupId),
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
                DESCRIPTION => 'backup iteration metadata',
                ITERATION => $this->backupId),
            array(
                '$set' => array('value.status' => 'running')
            ));
    }

    function finished()
    {
        $this->collection->update(
            array(
                DESCRIPTION => 'backup iteration metadata',
                ITERATION => $this->backupId),
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
            $dburl = getenv('OPENSHIFT_MONGODB_DB_URL');
            if ($dburl != false) {
                $mongo = new MongoClient($dburl);
            } else {
                $mongo = new MongoClient();
            }
        }
        return $mongo;
    }

    function checkDbSize()
    {
        echo "-->checkDbSize\n";
        if ($this->doCheckDbSize && !is_null($this->account)) {
            $maxStorage = $this->account['maxStorage'];
            $dbStats = $this->db->command(array(
                'dbStats' => 1,
                'scale' => 1
            ));
            if ($dbStats['dataSize'] > $maxStorage) {
                $this->collection->update(
                    array(
                        DESCRIPTION => 'backup iteration metadata',
                        ITERATION => $this->backupId),
                    array(
                        '$set' => array(
                            'value.status' => 'cancelled: no space left.',
                            'value.time_end' => new DateTime()
                        )
                    ));
                $this->doCheckDbSize = false;
                $this->storeFile("backup cancelled, as no more space is left. please upgrade account.\n", 'error.txt', 'text/text');
                $this->doCheckDbSize = true;
                exit("no space left! maxStorage=$maxStorage, dbStats['dataSize']=" . $dbStats['dataSize']);
            }
        }
    }

    function storePodioContact(PodioContact $contact, $raw_response, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL)
    {
        $this->checkDbSize();
        $query = array(ITERATION => $this->backupId, DESCRIPTION => 'original contact', PODIO_ID => $contact->profile_id);
        $existing_contact = $this->collection->findOne($query);
        if (is_null($existing_contact)) {
            $new_item = array(
                ITERATION => $this->backupId,
                DESCRIPTION => 'original contact',
                PODIO_ID => $contact->profile_id,
                VALUE => $raw_response,
                ORG => array(is_null($orgName) ? 'NULL' : $orgName),
                SPACE => array(is_null($spaceName) ? 'NULL' : $spaceName),
                APP => array(is_null($appName) ? 'NULL' : $appName)
            );
            $this->collection->insert($new_item);
        } else {
            $changed = false;
            $attributes = array(
                ORG => array(is_null($orgName) ? 'NULL' : $orgName),
                SPACE => array(is_null($spaceName) ? 'NULL' : $spaceName),
                APP => array(is_null($appName) ? 'NULL' : $appName)
            );
            foreach ($attributes as $key => $value) {
                if (isset($existing_contact[$key])) {
                    if (!in_array($value, $existing_contact[$key])) {
                        array_push($existing_contact[$key], $value);
                        $changed = true;
                    }
                } else {
                    $existing_contact[$key] = array($value);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->collection->save($existing_contact);
            }
        }

    }


    function storeFile($bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL)
    {
        $this->checkDbSize();
        $metadata = array(
            'filename' => $filename,
            'backupcollection' => array($this->collectionname),
            ITERATION => array($this->backupId),
            'mimeType' => $mimeType);

        if (!is_null($originalUrl))
            $metadata['originalUrl'] = $originalUrl;
        if (!is_null($podioFileId))
            $metadata['podioFileId'] = $podioFileId;

        $metadata[ORG] = array(is_null($orgName) ? 'NULL' : $orgName);
        $metadata[SPACE] = array(is_null($spaceName) ? 'NULL' : $spaceName);
        $metadata[APP] = array(is_null($appName) ? 'NULL' : $appName);
        $metadata[PODIO_ID] = array(is_null($podioItemId) ? 'NULL' : $podioItemId);

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
        $this->checkDbSize();
        echo "storing file $file->name\n";
        #var_dump($file);
        $link = $file->link;
        $dbfile = $this->fs->findOne(array('podioFileId' => $file->file_id));


        if (!is_null($dbfile)) {
            echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
            $changed = false;
            $attributes = array(
                'backupcollection' => $this->collectionname,
                ITERATION => $this->backupId,
                ORG => $orgName,
                SPACE => $spaceName,
                APP => $appName,
                PODIO_ID => $podioItemId
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
        $this->checkDbSize();

        $item = array(ITERATION => $this->backupId, VALUE => ((!is_string($value) && is_object($value)) ? serialize($value) : $value));

        if (!is_null($description))
            $item[DESCRIPTION] = $description;
        if (!is_null($orgName))
            $item[ORG] = $orgName;
        if (!is_null($spaceName))
            $item[SPACE] = $spaceName;
        if (!is_null($appName))
            $item[APP] = $appName;
        if (!is_null($podioItemId))
            $item[PODIO_ID] = $podioItemId;

        $this->collection->insert($item);
    }

}

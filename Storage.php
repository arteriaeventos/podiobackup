<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Storage
 *
 * @author SCHRED
 */
class Storage {

    private $dbhost;
    private $dbport;
    private $user = "admin";
    private $password = "IZ7ZCYaV8KrM";
    private $dbname = 'php';
    private $db;
    private $mongo;
    private $collectionname = 'mytestcollection';
    private $collection;
    private $backupId;

    function __construct(string $collection, string $backupId) {
        $this->dbhost = getenv('OPENSHIFT_MONGODB_DB_HOST');
        $this->dbport = getenv('OPENSHIFT_MONGODB_DB_PORT');
        $this->mongo = new MongoClient("mongodb://$this->user:$this->password@$this->dbhost:$this->dbport/");
        $this->db = $this->mongo->selectDB($this->dbname);
        if (isset($collection)) {
            $this->collectionname = $collection;
        }
        $this->collection = $this->db->selectCollection($this->collectionname);
        $this->backupId = $backupId;
    }

    function store(&$value, string $description, string $orgName = NULL, sting $spaceName = NULL, string $appName = NULL, $podioItemId = NULL) {
        $this->collection->insert(array('value'=>$value, 'description'=>$description, 'organization'=>$orgName, 'space'=>$spaceName, 'app'=>$appName, 'podioItemId'=>$podioItemId));
    }

}

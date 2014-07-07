<?php

interface IStorage {

    /**
     * Backups file if it is hosted by Podio
     * @param type $file
     * @return type id/url
     */
    function storeFile($file);
    
    function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);
}

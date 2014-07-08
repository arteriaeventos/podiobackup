<?php

interface IStorage {

    /**
     * Backups file if it is hosted by Podio. Assures file is not downloaded twice.
     * @param PodioFile $file
     * @return type id/url
     */
    function storePodioFile(PodioFile $file);

    function storeFile(
    $bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);

    function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);
}

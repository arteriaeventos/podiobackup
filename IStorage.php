<?php

interface IStorage {

    /**
     * Backups file if it is hosted by Podio. Assures file is not downloaded twice.
     * @param PodioFile $file
     * @return type id/url
     */
    function storePodioFile(PodioFile $file, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);

    /**
     * @param $bytes can be NULL for external files that should not be stored but only referenced.
     * @param $filename
     * @param $mimeType
     * @param null $originalUrl
     * @param null $podioFileId
     * @param null $orgName
     * @param null $spaceName
     * @param null $appName
     * @param null $podioItemId
     * @return mixed
     */
    function storeFile(
    $bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);

    function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);
}

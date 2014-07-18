<?php

interface IStorage {

    /**
     * Backups file if it is hosted by Podio. Assures file is not downloaded twice.
     * @param PodioFile $file
     * @return type id/url
     */
    function storePodioFile(PodioFile $file, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);

    /**
     * @param string $bytes can be NULL for external files that should not be stored but only referenced.
     * @param string $filename
     * @param string $mimeType
     * @param string $originalUrl
     * @param null $podioFileId
     * @param string $orgName
     * @param string $spaceName
     * @param string $appName
     * @param null $podioItemId
     * @return mixed
     */
    function storeFile(
    $bytes, $filename, $mimeType, $originalUrl = NULL, $podioFileId = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);

    function store(&$value, $description = NULL, $orgName = NULL, $spaceName = NULL, $appName = NULL, $podioItemId = NULL);
}

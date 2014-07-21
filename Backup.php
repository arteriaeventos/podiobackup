<?php

require_once 'Storage.php';

global $verbose;

/**
 * Performes a backup. Contains logic to iterate over all items and fieles.
 *
 * @author SCHRED
 */
class Backup
{

    /**
     *
     * @var IStorage
     */
    private $storage;

    /**
     *
     * @var boolean
     */
    private $downloadFiles;

    public function __construct(IStorage $storage, $downloadFiles)
    {
        $this->storage = $storage;
        $this->downloadFiles = $downloadFiles;
    }

    function backup_org(PodioOrganization $org)
    {
        global $verbose;

        if ($verbose)
            echo "Org: " . $org->name . "\n";

        $contactsFile = '';
        try {
            $contacts = PodioFetchAll::iterateApiCall('PodioContact::get_for_org', $org->org_id);
            $contactsFile .= contacts2text($contacts);
        } catch (PodioError $e) {
            show_error($e);
            $contactsFile .= "\n\nPodio Error:\n" . $e;
        }
        $this->storage->storeFile($contactsFile, 'podio_organization_contacts.txt', 'text/plain', NULL, NULL, $org->name);

        foreach ($org->spaces as $space) { // space_id
            $this->backup_space($space, $org);
        }
    }

    function backup_space(PodioSpace $space, PodioOrganization $org)
    {
        global $verbose;
        if ($verbose)
            echo "Space: " . $space->name . "\n";

        $contactsFile = '';
        try {
            if ($space->name == "Employee Network")
                $filter = array('contact_type' => 'user');
            else
                $filter = array('contact_type' => 'space');
            $contacts = PodioFetchAll::iterateApiCall('PodioContact::get_for_space', $space->space_id, $filter);
            $contactsFile .= contacts2text($contacts);
        } catch (PodioError $e) {
            show_error($e);
            $contactsFile .= "\n\nPodio Error:\n" . $e;
        }
        $this->storage->store($contactsFile, 'podio_space_contacts.txt', $org->name, $space->name);

        try {
            $spaceFiles = PodioFetchAll::iterateApiCall('PodioFile::get_for_space', $space->space_id, array(), FILE_GET_FOR_APP_LIMIT);
            echo "space files: " . sizeof($spaceFiles) . "\n";
            #var_dump($appFiles);
            PodioFetchAll::flattenObjectsArray($spaceFiles, PodioFetchAll::podioElements(
                array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
                    'context' => array('id' => NULL, 'type' => null, 'title' => null))));
            if ($verbose)
                echo "fetched information for " . sizeof($spaceFiles) . " files in space.\n";
            foreach ($spaceFiles as $file) {
                $this->storage->storePodioFile($file, $org->name, $space->name);
            }
        } catch (PodioError $e) {
            show_error($e);
        }

        $spaceApps = array();
        try {
            $spaceApps = PodioApp::get_for_space($space->space_id);
            RateLimitChecker::preventTimeOut();
        } catch (PodioError $e) {
            show_error($e);
        }

        foreach ($spaceApps as $app) {
            $this->backup_app($app, $org->name, $space->name, $this->downloadFiles, $this->storage);
        }
    }

    function backup_all()
    {
        $podioOrgs = PodioOrganization::get_all();

        foreach ($podioOrgs as $org) { //org_id
            $this->backup_org($org);
        }
    }

    /**
     * Backups $app to a subfolder in $path
     *
     * @param PodioApp $app app to backup
     * @param string $orgName
     * @param string $spaceName
     */
    function backup_app(PodioApp $app, $orgName, $spaceName)
    {
        $appName = $app->config['name'];

        global $verbose;

        if ($verbose) {
            echo "App: " . $app->config['name'] . "\n";
            echo "debug: MEMORY: " . memory_get_usage(true) . " | " . memory_get_usage(false) . "\n";
        }

        $appFile = "";

        $appFiles = array();

        try {
            #$appFiles = PodioFile::get_for_app($app->app_id, array('attached_to' => 'item'));
            $appFiles = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', $app->app_id, array(), FILE_GET_FOR_APP_LIMIT);
            echo "app files: " . sizeof($appFiles) . "\n";
            #var_dump($appFiles);
            PodioFetchAll::flattenObjectsArray($appFiles, PodioFetchAll::podioElements(
                array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
                    'context' => array('id' => NULL, 'type' => null, 'title' => null))));
            if ($verbose)
                echo "fetched information for " . sizeof($appFiles) . " files in app.\n";
        } catch (PodioError $e) {
            show_error($e);
            //dummy
        }


        try {
            echo "fetching items for app $app->app_id\n";
            $items_as_array = array();
            $allitems = PodioFetchAll::iterateApiCall('PodioItem::filter', $app->app_id, array(), ITEM_FILTER_LIMIT, 'items', $items_as_array);

            echo "DEBUG allitems:\n";
            var_dump($allitems);
            echo "DEBUG allitems as array:\n";
            var_dump($items_as_array);

            echo "app contains " . sizeof($allitems) . " items.\n";

            for ($i = 0; $i < sizeof($allitems); $i += ITEM_XLSX_LIMIT) {
                $itemFile = PodioItem::xlsx($app->app_id, array("limit" => ITEM_XLSX_LIMIT, "offset" => $i));
                RateLimitChecker::preventTimeOut();
                $this->storage->storeFile(
                    $itemFile, $appName . '_' . $i . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', NULL, NULL, $orgName, $spaceName, $appName);
                unset($itemFile);
            }

            $before = time();
            gc_collect_cycles();
            echo "gc took : " . (time() - $before) . " seconds.\n";

            for ($i = 0; $i < sizeof($allitems); $i++) {
                $this->backup_item($allitems[$i], $appFiles, $orgName, $spaceName, $appName, $items_as_array[$i]);
            }
            //store non item/comment files:
            if ($verbose)
                echo "storing non item/comment files..\n";
            $app_files_folder = 'other_files';

            foreach ($appFiles as $file) {
                if ($file->context['type'] != 'item' && $file->context['type'] != 'comment') {
                    echo "debug: downloading non item/comment file: $file->name\n";
                    if ($this->downloadFiles) {
                        $link = $this->storage->storePodioFile($file, $orgName, $spaceName, $appName);
                    } else {
                        $link = $file->link;
                    }
                }
            }
        } catch (PodioError $e) {
            show_error($e);
            $appFile .= "\n\nPodio Error:\n" . $e;
        }
        $this->storage->storeFile($appFile, 'all_items_summary.txt', 'text/plain', NULL, NULL, $orgName, $spaceName, $appName);
        unset($appFile);
        unset($files_in_app_html);
    }

    /**
     * @param $item
     * @param $appFiles
     * @param $orgName
     * @param $spaceName
     * @param $appName
     * @param $item_as_array should reflect the original/raw API response
     */
    function backup_item(PodioItem $item, $appFiles, $orgName, $spaceName, $appName, $item_as_array)
    {
        global $verbose;
        if ($verbose)
            echo " - " . $item->title . "\n";

        if ($this->downloadFiles) {
            foreach ($appFiles as $file) {

                if ($file->context['type'] == 'item' && $file->context['id'] == $item->item_id) {
                    $link = $this->storage->storePodioFile($file, $orgName, $spaceName, $appName, $item->item_id);
                }
            }
        }

        //store images:
        foreach ($item->fields as $field) {
            if ($field->type == 'image') {
                foreach ($field->values as $value) {
                    $this->storage->storePodioFile($value, $orgName, $spaceName, $appName, $item->item_id);
                }
            }
        }

        //TODO refactor to use less api calls: (not possible??!)
        if ($item->comment_count > 0) {
            #echo "comments.. (".$item->comment_count.")\n";
            $comments = PodioComment::get_for('item', $item->item_id);
            $raw_commtents = PodioFetchAll::getRawResponse();
            RateLimitChecker::preventTimeOut();

            for ($i = 0; $i < sizeof($comments); $i++) {
                $comment = $comments[$i];
                if ($this->downloadFiles && isset($comment->files) && sizeof($comment->files) > 0) {
                    foreach ($comment->files as $file) {
                        $link = $this->storage->storePodioFile($file);
                    }
                }
                $this->storage->store($raw_commtents[$i], 'original comment', $orgName, $spaceName, $appName, $item->item_id);
            }
        } else {
            #echo "no comments.. (".$item->comment_count.")\n";
        }
        $this->storage->store($item_as_array, 'original item', $orgName, $spaceName, $appName, $item->item_id);
    }

}

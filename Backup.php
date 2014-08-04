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

        try {
            PodioFetchAll::foreachItem('PodioContact::get_for_org', $org->org_id, array(), 100, null,
                function ($contact, $raw) use ($org) {
                    //TODO add view for org contacts
                    $this->storage->storePodioContact($contact, $raw, $org->name);
                });
        } catch (PodioError $e) {
            show_error($e);
        }

        foreach ($org->spaces as $space) { // space_id
            $this->backup_space($space, $org);
        }
    }

    function backup_space(PodioSpace $space, PodioOrganization $org)
    {
        global $verbose;
        if ($verbose)
            echo "Space: " . $space->name . "\n";
        $raw_result = array();
        try {
            $params = array(
                'type' => 'full',
                'contact_type' => 'space,user',
                'exclude_self' => 'false'
            );
            $contacts = PodioFetchAll::foreachItem('PodioContact::get_for_space', $space->space_id, $params, 100, null,
                function ($contact, $raw) use ($org, $space) {
                    $this->storage->storePodioContact($contact, $raw, $org->name, $space->name);
                });
        } catch (PodioError $e) {
            show_error($e);
        }

        try {
            $spaceFiles = PodioFetchAll::foreachItem('PodioFile::get_for_space', $space->space_id, array(), FILE_GET_FOR_APP_LIMIT, null,
                function ($file, $raw) use ($org, $space) {
                    $this->storage->storePodioFile($file, $org->name, $space->name);
                });
            echo "space files: " . $spaceFiles . "\n";
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

        try {
            if ($this->downloadFiles) {
                PodioFetchAll::foreachItem('PodioFile::get_for_app', $app->app_id, array(), FILE_GET_FOR_APP_LIMIT, NULL,
                    function ($podioFile, $raw_response) use ($orgName, $spaceName, $appName) {
                        //TODO handle comment files with correct context!?
                        //TODO save podio file metadata
                        if ($podioFile->context['type'] == 'item') {
                            $link = $this->storage->storePodioFile($podioFile, $orgName, $spaceName, $appName, $podioFile->context['id']);
                        } else {
                            $this->storage->storePodioFile($podioFile, $orgName, $spaceName, $appName);
                        }
                    });
            }
        } catch (PodioError $e) {
            show_error($e);
        }


        try {
            echo "fetching items for app $app->app_id\n";
            $totalItems = PodioFetchAll::foreachItem('PodioItem::filter', $app->app_id, array(), ITEM_FILTER_LIMIT, 'items',
                function ($item, $raw) use ($orgName, $spaceName, $appName) {
                    $this->backup_item($item, $orgName, $spaceName, $appName, $raw);
                });

            echo "app contains " . $totalItems . " items.\n";

            for ($i = 0; $i < $totalItems; $i += ITEM_XLSX_LIMIT) {
                $itemFile = PodioItem::xlsx($app->app_id, array("limit" => ITEM_XLSX_LIMIT, "offset" => $i));
                RateLimitChecker::preventTimeOut();
                $this->storage->storeFile(
                    $itemFile, $appName . '_' . $i . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', NULL, NULL, $orgName, $spaceName, $appName);
                unset($itemFile);
            }

            $before = time();
            gc_collect_cycles();
            echo "gc took : " . (time() - $before) . " seconds.\n";
        } catch (PodioError $e) {
            show_error($e);
        }
    }

    /**
     * @param $item
     * @param $orgName
     * @param $spaceName
     * @param $appName
     * @param $item_as_array should reflect the original/raw API response
     */
    function backup_item(PodioItem $item, $orgName, $spaceName, $appName, $item_as_array)
    {
        global $verbose;
        if ($verbose)
            echo " - " . $item->title . "\n";

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

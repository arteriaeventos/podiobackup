<?php

require_once 'Storage.php';

global $verbose;

/**
 * Performes a backup. Contains logic to iterate over all items and fieles.
 *
 * @author SCHRED
 */
class Backup {

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

    public function __construct(IStorage $storage, $downloadFiles) {
        $this->storage = $storage;
        $this->downloadFiles = $downloadFiles;
    }

    function backup_org($org) {
        echo "TODO: type parameter to: " + get_class($org); //TODO
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
        $this->storage->store($contactsFile, 'podio_organization_contacts.txt', $org->name);

        foreach ($org->spaces as $space) { // space_id
            $this->backup_space($space);
        }
    }

    function backup_space($space) {
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

    function backup_all() {
        $podioOrgs = PodioOrganization::get_all();

        foreach ($podioOrgs as $org) { //org_id
            $this->backup_org($org);
        }
    }

    /**
     * Backups $app to a subfolder in $path
     * 
     * @param type $app app to backup
     * @param type $path in this folder a subfolder for the app will be created
     */
    function backup_app($app, $orgName, $spaceName) {
        $appName = $app->config['name'];

        global $verbose;

        if ($verbose) {
            echo "App: " . $app->config['name'] . "\n";
            echo "debug: MEMORY: " . memory_get_usage(true) . " | " . memory_get_usage(false) . "\n";
        }

        $appFile = "";

        $appFiles = array();

        $files_in_app_html = "<html><head><title>Files in app: " . $app->config['name'] . "</title></head><body>" .
                "<table border=1><tr><th>name</th><th>link</th><th>context</th></tr>";
        try {
            #$appFiles = PodioFile::get_for_app($app->app_id, array('attached_to' => 'item'));
            $appFiles = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', $app->app_id, array(), FILE_GET_FOR_APP_LIMIT);
            #var_dump($appFiles);
            PodioFetchAll::flattenObjectsArray($appFiles, PodioFetchAll::podioElements(
                            array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
                                'context' => array('id' => NULL, 'type' => null, 'title' => null))));
            if ($verbose)
                echo "fetched information for " . sizeof($appFiles) . " files in app.\n";
        } catch (PodioError $e) {
            show_error($e);
        }


        try {
            echo "fetching items for app $app->app_id\n";
            $allitems = PodioFetchAll::iterateApiCall('PodioItem::filter', $app->app_id, array(), ITEM_FILTER_LIMIT, 'items');

            echo "app contains " . sizeof($allitems) . " items.\n";

            for ($i = 0; $i < sizeof($allitems); $i+=ITEM_XLSX_LIMIT) {
                $itemFile = PodioItem::xlsx($app->app_id, array("limit" => ITEM_XLSX_LIMIT, "offset" => $i));
                RateLimitChecker::preventTimeOut();
                $this->storage->store($itemFile, $appName . '_' . $i . '.xlsx', $orgName, $spaceName, $appName);
                unset($itemFile);
            }

            $before = time();
            gc_collect_cycles();
            echo "gc took : " . (time() - $before) . " seconds.\n";

            foreach ($allitems as $item) {
                $this->backup_item($item, $appFiles, $path_item, $path_app, $appFile, $orgName, $spaceName, $appName);
            }

            //store non item/comment files:
            if ($verbose)
                echo "storing non item/comment files..\n";
            $app_files_folder = 'other_files';
            $path_app_files = $path_app . '/' . $app_files_folder;
            mkdir($path_app_files);
            $files_in_app_html .= "<tr><td><b>App Files</b></td><td><a href=$app_files_folder>" . $app_files_folder . "</a></td><td></td></tr>";
            foreach ($appFiles as $file) {
                if ($file->context['type'] != 'item' && $file->context['type'] != 'comment') {
                    echo "debug: downloading non item/comment file: $file->name\n";
                    if ($this->downloadFiles) {
                        $link = $this->storage->storeFile($file);
                    } else {
                        $link = $file->link;
                    }
                    $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                }
            }
        } catch (PodioError $e) {
            show_error($e);
            $appFile .= "\n\nPodio Error:\n" . $e;
        }
        $this->storage->store($appFile, '/all_items_summary.txt', $orgName, $spaceName, $appName);
        $files_in_app_html .= "</table></body></html>";
        $this->storage->store($files_in_app_html, "/files_in_app.html", $orgName, $spaceName, $appName);
        unset($appFile);
        unset($files_in_app_html);
    }

    function backup_item($item, $appFiles, $path_item, $path_app, &$appFile, $orgName, $spaceName, $appName) {
        global $verbose;
        if ($verbose)
            echo " - " . $item->title . "\n";

        $itemFile = HumanFormat::toHumanReadableString($item);

        if ($this->downloadFiles) {
            foreach ($appFiles as $file) {

                if ($file->context['type'] == 'item' && $file->context['id'] == $item->item_id) {
                    $link = $this->storage->storeFile($file);
                    $itemFile .= "File: $link\n";
                    $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                }
            }
        }

        //TODO refactor to use less api calls: (not possible??!)
        if ($item->comment_count > 0) {
            #echo "comments.. (".$item->comment_count.")\n";
            $comments = PodioComment::get_for('item', $item->item_id);
            RateLimitChecker::preventTimeOut();

            $commentsFile = "\n\nComments\n--------\n\n";
            foreach ($comments as $comment) {
                $commentsFile .= 'by ' . $comment->created_by->name . ' on ' . $comment->created_on->format('Y-m-d at H:i:s') . "\n----------------------------------------\n" . $comment->value . "\n\n\n";
                if ($this->downloadFiles && isset($comment->files) && sizeof($comment->files) > 0) {
                    foreach ($comment->files as $file) {

                        $link = $this->storage->storeFile($file);
                        $commentsFile .= "File: $link\n";
                        $files_in_app_html .= "<tr><td>" . $file->name . "</td><td><a href=\"" . $link . "\">" . $link . "</a></td><td>" . $file->context['title'] . "</td></tr>";
                    }
                }
            }
        } else {
            $commentsFile = "\n\n[no comments]\n";
            #echo "no comments.. (".$item->comment_count.")\n";
        }
        $this->storage->store($itemFile . $commentsFile, fixDirName($item->item_id . '-' . $item->title) . '.txt', $orgName, $spaceName, $appName, $item->item_id);

        $this->storage->store($item, 'original item', $orgName, $spaceName, $appName, $item->item_id);

        $appFile .= $itemFile . "\n\n";
    }

}

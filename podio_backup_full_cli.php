<?php

/* =====================================================================
 * podio_backup.php
 * This script backs up your entire Podio account.
 * [(c) 2013 Globi Web Solutions]
 * v1.3 2013-10-03 - Andreas Huttenrauch
 * v1.4 2013-10-18 - Daniel Schreiber
 * v2.0 2013-10-31 - Daniel Schreiber
 * 
 *
 *  https://github.com/daniel-sc/podio-backup 
 * 
 *  Please post something nice on your website or blog, and link back to www.podiomail.com if you find this script useful.
 * ===================================================================== */

require_once 'podio-php/PodioAPI.php'; // include the php Podio Master Class

require_once 'RelativePaths.php';
require_once 'RateLimitChecker.php';
require_once 'HumanFormat.php';
require_once 'PodioFetchAll.php';
require_once 'Storage.php';
require_once './IStorage.php';
require_once 'Backup.php';

define('FILE_GET_FOR_APP_LIMIT', 100);
define('ITEM_FILTER_LIMIT', 500);
define('ITEM_XLSX_LIMIT', 500);

Podio::$debug = true;

gc_enable();
ini_set("memory_limit", "-1"); //200M fails on openshift..

global $start;
$start = time();

global $config;
$config_command_line = getopt("fvs:l:", array("backupTo:", "podioClientId:", "podioClientSecret:", "podioUser:", "podioPassword:", "help"));

$usage = "\nUsage:\n\n" .
        "php podio_backup_full_cli [-f] [-v] [-s PARAMETER_FILE] --backupTo BACKUP_FOLDER" .
        " --podioClientId PODIO_CLIENT_ID --podioClientSecret PODIO_CLIENT_SECRET " .
        "--podioUser PODIO_USERNAME --podioPassword PODIO_PASSWORD  [--podioSpace PODIO_SPACE_ID]\n\n" .
        "php podio_backup_full_cli [-f] [-v] -l PARAMETER_FILE [--backupTo BACKUP_FOLDER]" .
        " [--podioClientId PODIO_CLIENT_ID] [--podioClientSecret PODIO_CLIENT_SECRET] " .
        "[--podioUser PODIO_USERNAME] [--podioPassword PODIO_PASSWORD]\n\n" .
        "php podio_backup_full_cli --help" .
        "\n\nArguments:\n" .
        "   -f\tdownload files from podio (rate limit of 250/h applies!)\n" .
        "   -v\tverbose\n" .
        "   -s\tstore parameters in PARAMETER_FILE\n" .
        "   -l\tload parameters from PARAMETER_FILE (parameters can be overwritten by command line parameters)\n" .
        " \n" .
        "BACKUP_FOLDER represents a (incremental) backup storage. " .
        "I.e. consecutive backups only downloads new files.\n";

if (array_key_exists("help", $config_command_line)) {
    echo $usage;
    return;
}

if (array_key_exists("l", $config_command_line)) {
    read_config($config_command_line['l']);
    $config = array_merge($config, $config_command_line);
} else {
    $config = $config_command_line;
}


if (array_key_exists("s", $config_command_line)) {
    write_config($config['s']);
}

$downloadFiles = array_key_exists("f", $config);

global $verbose;
$verbose = array_key_exists("v", $config);

check_backup_folder();

if (check_config()) {
    do_backup($downloadFiles);
} else {
    echo $usage;

    return -1;
}
$total_time = (time() - $start) / 60;
echo "Duration: $total_time minutes.\n";

function check_backup_folder() {
    return;
}

// END check_backup_folder

function check_config() {
    global $config;
    Podio::$debug = true;
    try {
        Podio::setup($config['podioClientId'], $config['podioClientSecret']);
    } catch (PodioError $e) {
        show_error("Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    try {
        Podio::authenticate('password', array('username' => $config['podioUser'], 'password' => $config['podioPassword']));
    } catch (PodioError $e) {
        show_error("Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    if (!Podio::is_authenticated()) {
        show_error("Podio Authentication Failed. Please check the API key and user details.");
        return false;
    }
    return true;
}

// END check_config

function read_config($filename) {
    global $config;
    $config['podioClientId'] = '';
    $config['podioClientSecret'] = '';
    $config['podioUser'] = '';
    $config['podioPassword'] = '';
    if (!file_exists($filename)) {
        write_config($filename);
    }
    #echo "filename: $filename\n";
    $data = file_get_contents($filename);
    #$config = unserialize($data);
    $config = array_merge($config, unserialize($data));
}

function write_config($filename) {
    global $config;
    $data = serialize($config);
    file_put_contents($filename, $data);
}

function show_error($error) {
    echo "ERROR: " . $error . "\n";
}

function show_success($message) {
    echo "Message: " . $message . "\n";
}

function do_backup($downloadFiles) {
    global $config, $verbose;
    if ($verbose)
        echo "Warning: This script may run for a LONG time\n";

    $timeStamp = date('Y-m-d_H-i');
    $backupTo = $config['backupTo'];

    $storage = new Storage($backupTo, $timeStamp);

    $backup = new Backup($storage, $downloadFiles);

    if (array_key_exists('podioSpace', $config)) {
        $space = PodioSpace::get($config['podioSpace']);
        echo "backup space: $space->name\n";
        $backup->backup_space($space);
    } else {
        $backup->backup_all();
    }

    if ($verbose)
        show_success("Backup Completed successfully to " . $backupTo . "/" . $timeStamp);
}

function contacts2text($contacts) {
    $contactsFile = "";
    foreach ($contacts as $contact) {
        $contactsFile .= '--- ' . $contact->name . ' ---' . "\n";
        if (isset($contact->profile_id))
            $contactsFile .= 'Profile ID: ' . $contact->profile_id . "\n";
        if (isset($contact->user_id))
            $contactsFile .= 'User ID: ' . $contact->user_id . "\n";
        if (isset($contact->name))
            $contactsFile .= 'Name: ' . $contact->name . "\n";
        if (isset($contact->location) && is_array($contact->location))
            $contactsFile .= 'Location: ' . implode(', ', $contact->location) . "\n";
        //if (isset($contact->about)) $contactsFile .= 'About: '.$contact->about."\n";
        if (isset($contact->mail) && is_array($contact->mail))
            $contactsFile .= 'Email Address: ' . implode(', ', $contact->mail) . "\n";
        if (isset($contact->phone) && is_array($contact->phone))
            $contactsFile .= 'Phone Number: ' . implode(', ', $contact->phone) . "\n";
        if (isset($contact->url) && is_array($contact->url))
            $contactsFile .= 'Website: ' . implode(', ', $contact->url) . "\n";
        if (isset($contact->title) && is_array($contact->title))
            $contactsFile .= 'Title: ' . implode(', ', $contact->title) . "\n";
        if (isset($contact->organization))
            $contactsFile .= 'Organization: ' . $contact->organization . "\n";
        if (isset($contact->address) && is_array($contact->address))
            $contactsFile .= 'Address: ' . implode(', ', $contact->address) . "\n";
        if (isset($contact->city))
            $contactsFile .= 'City: ' . $contact->city . "\n";
        if (isset($contact->state))
            $contactsFile .= 'State: ' . $contact->state . "\n";
        if (isset($contact->zip))
            $contactsFile .= 'Zip: ' . $contact->zip . "\n";
        if (isset($contact->country))
            $contactsFile .= 'Country: ' . $contact->country . "\n";
        if (isset($contact->birthdate))
            $contactsFile .= 'Birth Date: ' . $contact->birthdate->format('Y-m-d') . "\n";
        if (isset($contact->twitter))
            $contactsFile .= 'Twitter: ' . $contact->twitter . "\n";
        if (isset($contact->linkedin))
            $contactsFile .= 'LinkedIn: ' . $contact->linkedin . "\n";
        $contactsFile .= "\n\n";
    }
    return $contactsFile;
}

/**
 * TODO the current approach is just fast forward - could be made more sophisticated - e.g. \F6->oe..
 *
 * @param String $name
 * @return String valid dir/file name
 */
function fixDirName($name) {
    $name = preg_replace("/[^.a-zA-Z0-9_-]/", '', $name);

    $name = substr($name, 0, 25);
    return $name;
}

function br2nl($string) {
    $s2 = preg_replace('/\<br(\s*)?\/?\>/i', "\n", $string);
    $s3 = preg_replace('/<p>/i', "\n", $s2);
    return $s3;
}

/**
 * If $file represents a file hosted by podio, it is downloaded to $folder.
 * In any case a link to the file is returned (relative to $folder).
 * $folder is assumed to be without trailing '/'.
 * (The problem with files not hosted by podio is that mostly you need a login,
 * i.e. you wont get the file but an html login page.)
 *
 * Uses the file $config['backupTo'].'/filestore.php' to assure no file is downloaded twice.
 * (over incremental backups). Creates a (sym)link to the original file in case.
 *
 * @param type $folder
 * @param type $file
 * @return String link relative to $folder or weblink
 */
function downloadFileIfHostedAtPodio($folder, $file) {
    global $config;

    $link = $file->link;
    if ($file->hosted_by == "podio") {
        //$filestore: Stores fileid->path_to_file_relative to backupTo-folder.
        //this is loaded on every call to assure files from interrupted runs are preserved.
        $filestore = array();
        $filenameFilestore = $config['backupTo'] . '/filestore.php';

        if (file_exists($filenameFilestore)) {
            $filestore = unserialize(file_get_contents($filenameFilestore));
        }
        $filename = fixDirName($file->name);
        while (file_exists($folder . '/' . $filename))
            $filename = 'Z' . $filename;
        if (array_key_exists($file->file_id, $filestore)) {

            echo "DEBUG: Detected duplicate download for file: $file->file_id\n";
            $existing_file = realpath($config['backupTo'] . '/' . $filestore[$file->file_id]);
            $link = RelativePaths::getRelativePath($folder, $existing_file);
            link($existing_file, $folder . '/' . $filename);
            #symlink($existing_file, $folder.'/'.$filename);
        } else {

            try {
                file_put_contents($folder . '/' . $filename, $file->get_raw());
                RateLimitChecker::preventTimeOut();
                $link = $filename;

                $filestore[$file->file_id] = RelativePaths::getRelativePath($config['backupTo'], $folder . '/' . $filename);
                file_put_contents($filenameFilestore, serialize($filestore));
            } catch (PodioBadRequestError $e) {
                echo $e->body;   # Parsed JSON response from the API
                echo $e->status; # Status code of the response
                echo $e->url;    # URI of the API request
                // You normally want this one, a human readable error description
                echo $e->body['error_description'];
            }
        }
    } else {
        #echo "Warning: Not downloading file hosted by ".$file->hosted_by."\n";
    }
    unset($filestore);
    return $link;
}

?>
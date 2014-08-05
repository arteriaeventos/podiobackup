<?php

require 'flight/flight/Flight.php';
require_once 'Storage.php';
require_once 'PodioFetchAll.php';

/**
 *
 * @global MongoClient $mongo
 * @return \MongoClient
 */
function getMongo()
{
    global $mongo;
    if (!isset($mongo) || is_null($mongo)) {
        $mongo = Storage::getMongo();
    }
    return $mongo;
}

/**
 *
 * @return MongoDB DB containing all backups for the current user
 * @param $useCookie
 */
function getDbForUser($useCookie = false)
{
    $curr_user = getUser($useCookie);
    $db = $curr_user['db'];
    error_log("selecting db: $db\n", 3, 'myphperror.log');
    return getMongo()->selectDB($db);
}

/**
 * Collection containing all registered users
 * @return MongoCollection
 */
function getUserCollection()
{
    return getMongo()->selectDB('application')->selectCollection('users');
}


/**
 *  returns user or sends http error if user is not found/authorized
 * @global type $user
 * @param boolean $useCookie use cookie auth (less secure - cross site scripting!)
 * @return array
 */
function getUser($useCookie = false)
{
    global $user;

    if (!isset($user)) {
        $headers = getallheaders();
        $basic_auth_header = 'Authorization';
        $session_header = 'PhpBackupLoginSession';
        $cookie_name = 'podio_backup_login_session';
        if ($useCookie && isset(Flight::request()->cookies[$cookie_name]) && !is_null(Flight::request()->cookies[$cookie_name])) {
            $cookie_content = Flight::request()->cookies[$cookie_name];
            $user = getUserCollection()->findOne(array('loginsession' => $cookie_content));
        } elseif (array_key_exists($basic_auth_header, $headers) && isset($headers[$basic_auth_header]) && !is_null($headers[$basic_auth_header])) {
            $user_password_base64 = str_replace('Basic ', '', $headers[$basic_auth_header]);
            $user_password = base64_decode($user_password_base64);

            $username = strtok($user_password, ':');
            $password = str_replace($username . ":", '', $user_password);

            error_log("looking up user: $username/$password\n", 3, 'myphperror.log');

            $user = getUserCollection()->findOne(array('user' => $username, 'password' => $password));
        } elseif (array_key_exists($session_header, $headers) && isset($headers[$session_header]) && !is_null($headers[$session_header])) {
            $session_header_content = $headers[$session_header];
            $user = getUserCollection()->findOne(array('loginsession' => $session_header_content));
        } else {
            Flight::halt(401, 'no credentials provided.');
        }
        if (is_null($user)) {
            Flight::halt(401, 'user/session not found.');
        }
    }
    return $user;
}

/**
 * Checks if user is authorized (http basic auth or session header)
 */
function checkLogin()
{
    getUser();
}

function createUserDbName($user)
{
    //TODO assure uniqueness!
    return str_replace(array('@', '.'), '-', $user);
}

function isBackupRunning($backupcollection)
{
    /* TODO account for other OS */
    $search = "--backupTo $backupcollection";
    $result = array();
    exec('ps auxwww', $result);
    foreach ($result as $line) {
        if (strstr($line, $search)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array $array
 * @return array flat unique array
 */
function flatten(array $array)
{
    $return = array();
    array_walk_recursive($array, function ($a) use (&$return) {
        array_push($return, $a);
    });
    return array_unique($return);
}

function filterNULL(array $array)
{
    return array_filter($array, function ($elem) {
        return 'NULL' != $elem;
    });
}

Flight::set('flight.log_errors', true);

Flight::route('/login', function () {
    $curr_user = getUser();
    if (!isset($curr_user['loginsession'])) {
        $curr_user['loginsession'] = md5($curr_user['user'] . time());
        getUserCollection()->save($curr_user);
    }
    Flight::json(array('loginsession' => $curr_user['loginsession']));
});

Flight::route('GET /backupcollection/@backupcollection/backupiteration/@backupiteration', function ($backupcollection, $backupiteration) {
    $collection = getDbForUser()->selectCollection($backupcollection);
    $metadata = $collection->findOne(array(
        'description' => 'backup iteration metadata',
        'backupId' => $backupiteration
    ));
    if (!is_null($metadata) && $metadata['value']['status'] == 'running' && !isBackupRunning($backupcollection)) {
        $metadata['value']['status'] = 'aborted';
        $collection->save($metadata);
    }
    Flight::json(array(
        'status' => is_null($metadata) ? 'undefined' : $metadata['value']['status']
    ));
});

Flight::route('/gui/tree(/backupcollection/@backupcollection(/backupiteration/@backupiteration(/org/@org(/space/@space(/app/@app)))))', function ($backupcollection, $backupiteration, $org, $space, $app) {

    error_log("gui/tree: " . var_export(Flight::request()->url, true) . "\n", 3, 'myphperror.log');

    $result = array();

    if (is_null($backupcollection)) {
        $allCollections = getDbForUser()->getCollectionNames();
        $visibleCollections = array_filter($allCollections, function ($var) {
            return $var != 'system.indexes' && $var != 'fs.files' && $var != 'fs.chunks';
        });
        foreach ($visibleCollections as $collection) {
            array_push($result, array(
                'text' => $collection,
                'children' => sizeof(getDbForUser()->selectCollection($collection)->distinct('backupId')) > 0
            ));
        }

    } else if (is_null($backupiteration)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $backups = flatten($collection->distinct('backupId'));
        $backups = filterNULL(array_merge($backups, flatten(getDbForUser()->getGridFS()->distinct('backupId'))));

        foreach ($backups as $backup) {
            array_push($result, array(
                'text' => $backup,
                'children' => sizeof($collection->distinct('organization', array('backupId' => $backup))) > 0
            ));
        }

    } else if (is_null($org)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $orgs = filterNULL(flatten($collection->distinct('organization', array('backupId' => $backupiteration))));
        foreach ($orgs as $org) {
            array_push($result, array(
                'text' => $org,
                'children' => sizeof($collection->distinct('space', array('backupId' => $backupiteration, 'organization' => $org))) > 0
            ));
        }

    } else if (is_null($space)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $spaces = filterNULL(flatten($collection->distinct('space', array('backupId' => $backupiteration, 'organization' => $org))));
        foreach ($spaces as $space) {
            array_push($result, array(
                'text' => $space,
                'children' => sizeof($collection->distinct('app', array('backupId' => $backupiteration, 'organization' => $org, 'space' => $space))) > 0
            ));
        }

    } else if (is_null($app)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $apps = filterNULL(flatten($collection->distinct('app', array('backupId' => $backupiteration, 'organization' => $org, 'space' => $space))));
        foreach ($apps as $app) {
            array_push($result, array(
                'text' => $app,
                'children' => false
            ));
        }
    } else {
        Flight::halt(404, 'cannot parse url: ' . Flight::request()->url);
    }

    Flight::json($result);
});

Flight::route('/logout', function () {
    $curr_user = getUser();
    unset($curr_user['loginsession']);
    getUserCollection()->save($curr_user);
    Flight::halt(204);
});

Flight::route('GET /file(/.*)', function () {
    $mongofileid = Flight::request()->query['mongofileid'];
    $podiofileid = Flight::request()->query['podiofileid'];
    $file = null;
    if (isset($mongofileid) && !is_null($mongofileid)) {
        $file = getDbForUser(true)->getGridFS()->findOne(array('_id' => new MongoId($mongofileid)));
    } else if (isset($podiofileid) && !is_null($podiofileid)) {
        $file = getDbForUser(true)->getGridFS()->findOne(array('podioFileId' => intval($podiofileid)));
    }

    if (is_null($file)) {
        Flight::halt(404, "File with id $mongofileid not found.");
        return;
    }

    Flight::response()->header('Content-type', $file->file['mimeType']);
    Flight::response()->header('Content-Disposition', $file->file['filename']);

    $bytes = $file->getBytes();
    error_log("file download - bytes: " . strlen($bytes) . "\n", 3, 'myphperror.log');
    echo $bytes;
    /* IMPORTANT: do not flush() as this prevents custom headers to be sent! */

    Flight::stop();
});

Flight::route('GET /account', function () {
    $dbStats = getDbForUser()->command(array(
        'dbStats' => 1,
        'scale' => 1
    ));
    $account = getMongo()->selectDB('application')->selectCollection('accounts')->findOne(array('account' => getUser()['db']));
    Podio::setup('podio-backup14', 'lL7Rj2tOT1u59IqojbVN2lVl0sWIjmpwLQoBGbpflw5fnasmKgusrFwr82HX5USq');
    Podio::authenticate('password', array('username' => $account['podioUser'], 'password' => $account['podioPassword']));
    $orgs = PodioOrganization::get_all();
    $orgs_raw = PodioFetchAll::getRawResponse();
    Flight::json(array(
        'used_storage' => $dbStats['dataSize'],
        'max_storage' => is_null($account) ? null : $account['maxStorage'],
        'other_data' => $dbStats,
        'available_orgs' => $orgs_raw
    ));
});

Flight::route('POST /register', function () {
    /* maybe use PUT + POST? */
    $user = base64_decode(Flight::request()->data['user']);
    $password = base64_decode(Flight::request()->data['password']);
    $podioUser = base64_decode(Flight::request()->data['podioUser']);
    $podioPassword = base64_decode(Flight::request()->data['podioPassword']);

    error_log("\nregistering user: $user with password: $password\n", 3, 'myphperror.log');

    if (is_null(getUserCollection()->findOne(array('user' => $user)))) {
        $userDbName = createUserDbName($user);
        getUserCollection()->save(array(
            'user' => $user,
            'password' => $password,
            'podioUser' => $podioUser,
            'podioPassword' => $podioPassword,
            'balance' => 0,
            'db' => $userDbName
        ));
        $maxStorageBytes = 10 * 1024 * 1024; //100MB
        getMongo()->selectDB('application')->selectCollection('accounts')->save(array(
            DESCRIPTION => 'account',
            'podioUser' => $podioUser,
            'podioPassword' => $podioPassword,
            'balance' => 0,
            'maxStorage' => $maxStorageBytes,
            'maxStorage_humanized' => '100MB',
            'account' => $userDbName
        ));
    } else {
        Flight::halt(409, 'user name exists.');
        return;
    }
});


Flight::route('/backupcollection/count', function () {
    Flight::json(sizeof(getDbForUser()->getCollectionNames()));
});

Flight::route('/backupcollection/@backupcollection/backupiteration/count', function ($backupcollection) {
    $collection = getDbForUser()->selectCollection($backupcollection);
    Flight::json(sizeof($collection->distinct('backupId')));
});

Flight::route('POST /backupcollection/@backupcollection/create', function ($backupcollection) {
    if (in_array($backupcollection, getDbForUser()->getCollectionNames())) {
        Flight::halt(409, "backup with given name exists ($backupcollection).");
    } else {
        $collection = getDbForUser()->createCollection($backupcollection);
        $requestData = Flight::request()->data->getData();
        $spaceId = $requestData['spaceId'];
        $spaceName = $requestData['spaceName'];
        $orgId = $requestData['orgId'];
        $orgName = $requestData['orgName'];
        $backupMetadata = array(
            'createdOn' => new MongoDate(),
            'description' => 'backup metadata'
        );
        if (isset($spaceId) && $spaceId != null) {
            $backupMetadata['spaceId'] = $spaceId;
            $backupMetadata['spaceName'] = $spaceName;
        }
        if (isset($orgId) && $orgId != null) {
            $backupMetadata['orgId'] = $orgId;
            $backupMetadata['orgName'] = $orgName;
        }
        $collection->save($backupMetadata);
    }
});

Flight::route('DELETE /backupcollection/@backupcollection', function ($backupcollection) {
    if (!in_array($backupcollection, getDbForUser()->getCollectionNames())) {
        Flight::halt(404, "backup with given name not found ($backupcollection).");
    } else {
        $response = getDbForUser()->selectCollection($backupcollection)->drop();
        Flight::json($response);
    }
});

Flight::route('GET /backupcollection/@backupcollection', function ($backupcollection) {
    if (!in_array($backupcollection, getDbForUser()->getCollectionNames())) {
        Flight::halt(404, "backup with given name not found ($backupcollection).");
    } else {
        $collection = getDbForUser()->selectCollection($backupcollection);

        $backupMetadata = $collection->findOne(array('description' => 'backup metadata'));

        $backupMetadata['backupRunning'] = isBackupRunning($backupcollection);

        //TODO include backup size

        Flight::json($backupMetadata);
    }
});


Flight::route('POST /backupcollection/@backupcollection', function ($backupcollection) {
    if (!in_array($backupcollection, getDbForUser()->getCollectionNames())) {
        Flight::halt(404, "backup with given name not found ($backupcollection).");
    } else {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $request = Flight::request()->data;
        error_log("POST backupcollection - request: " . var_export($request, true), 3, 'myphperror.log');
        if ($request['action'] == 'doBackup') {
            if (isBackupRunning($backupcollection)) {
                Flight::halt(412, 'a backup process for is already running.');
                return;
            }
            $backupMetadata = $collection->findOne(array('description' => 'backup metadata'));
            $user = getUser();
            $command = "php podio_backup_full_cli.php"
                . " -f"
                . " -v"
                . " --db " . $user['db']
                . " --backupTo $backupcollection"
                . " --podioClientId podio-backup14"
                . " --podioClientSecret lL7Rj2tOT1u59IqojbVN2lVl0sWIjmpwLQoBGbpflw5fnasmKgusrFwr82HX5USq"
                . " --podioUser " . $user['podioUser']
                . " --podioPassword " . $user['podioPassword']
                . (isset($backupMetadata['spaceId']) ? " --podioSpace " . $backupMetadata['spaceId'] : "")
                . " > $backupcollection.log"
                . " &";
            error_log("executing command: $command", 3, 'myphperror.log');
            $result = exec($command);
            Flight::halt(202, "job id: $result");
        } elseif ($request['action'] == 'modifyRecurrence') {
            //TODO
        }
    }
});

Flight::route('/backupcollection/@backupcollection/backupiteration/@backupiteration/org/@org/space/@space/contacts', function ($backupcollection, $backupiteration, $org, $space) {
    $query = array(
        DESCRIPTION => 'original contact',
        ITERATION => $backupiteration,
        ORG => $org,
        SPACE => $space,
    );

    $collection = getDbForUser()->selectCollection($backupcollection);
    $contacts = $collection->find($query);

    error_log("query: " . var_export($query, true) . "\n", 3, 'myphperror.log');

    // $comments->sort(array('value.created_on' => 1));

    $result = array_map(function ($element) {
        return $element[VALUE];
    }, iterator_to_array($contacts, false));

    Flight::json($result);
});

Flight::route('/backupcollection/@backupcollection/backupiteration/@backupiteration/org/@org/space/@space/app/@app/item/count', function ($backupcollection, $backupiteration, $org, $space, $app) {
    $query = array(
        'description' => 'original item',
        'backupId' => $backupiteration,
        'organization' => $org,
        'space' => $space,
        'app' => $app
    );

    $collection = getDbForUser()->selectCollection($backupcollection);
    $items = $collection->find($query);
    Flight::json(sizeof($items));
});

Flight::route('/backupcollection/@backupcollection/backupiteration/@backupiteration/org/@org/space/@space/app/@app/item/@item/comments', function ($backupcollection, $backupiteration, $org, $space, $app, $item) {
    $query = array(
        'description' => 'original comment',
        'backupId' => $backupiteration,
        'organization' => $org,
        'space' => $space,
        'app' => $app,
        'podioItemId' => intval($item)
    );

    $collection = getDbForUser()->selectCollection($backupcollection);
    $comments = $collection->find($query);

    error_log("query: " . var_export($query, true) . "\n", 3, 'myphperror.log');

    $comments->sort(array('value.created_on' => 1));

    $result = array_map(function ($element) {
        return $element['value'];
    }, iterator_to_array($comments, false));

    Flight::json($result);
});

/* files */
Flight::route('GET /backupcollection/@backupcollection(/backupiteration/@backupiteration(/org/@org(/space/@space(/app/@app(/item/@item)))))/files', function ($backupcollection, $backupiteration, $org, $space, $app, $item) {
    error_log("query: " . var_export(Flight::request()->query, true), 3, 'myphperror.log');

    $all = strcasecmp('true', Flight::request()->query['all']) == 0;
    error_log("all: $all", 3, 'myphperror.log');
    $query_params = array(
        'backupcollection' => $backupcollection,
        'backupId' => $backupiteration,
        'organization' => $org,
        'space' => $space,
        'app' => $app,
        'podioItemId' => is_null($item) ? null : intval($item)
    );
    error_log("query_params: " . var_export($query_params, true) . "\n", 3, 'myphperror.log');

    $query = array();
    foreach ($query_params as $key => $value) {
        if (is_null($value)) {
            if (!$all) {
                $query[$key] = 'NULL';
            }
        } else {
            $query[$key] = $value;
        }
    }

    error_log("query: " . var_export($query, true) . "\n", 3, 'myphperror.log');

    $files = getDbForUser()->getGridFS()->find($query);
    $result = array();

    foreach ($files as $file) {
        error_log("file: " . var_export($file->file, true) . "\n", 3, 'myphperror.log');
        if (isset($file->file['external'])) {
            array_push($result, array(
                'filename' => $file->file['filename'],
                'id' => $file->file['_id']->{'$id'},
                'url' => $file->file['originalUrl']
            ));
        } else {
            array_push($result, array(
                'filename' => $file->file['filename'],
                'id' => $file->file['_id']->{'$id'}
            ));
        }
    }

    error_log("files: " . var_export($files, true) . "\n", 3, 'myphperror.log');
    error_log("result: " . var_export($result, true) . "\n", 3, 'myphperror.log');

    Flight::json($result);
});

Flight::route('/backupcollection/@backupcollection/backupiteration/@backupiteration/org/@org/space/@space/app/@app/item(/@item)', function ($backupcollection, $backupiteration, $org, $space, $app, $item) {

    if (is_null($item)) {
        $query = array(
            'description' => 'original item',
            'backupId' => $backupiteration,
            'organization' => $org,
            'space' => $space,
            'app' => $app
        );

        $collection = getDbForUser()->selectCollection($backupcollection);
        $items = $collection->find($query);

        $items->sort(array('_id' => 1)); //here we have an index for sure..
        $count = Flight::request()->query['count'];
        if (isset($count) && $count != null) {
            $items->limit($count);
        }
        $start = Flight::request()->query['start'];
        if (isset($start) && $start != null) {
            $items->skip($start);
        }

        error_log("query: " . var_export($query, true) . "\n", 3, 'myphperror.log');
        error_log("fetching $count items. start=$start\n", 3, 'myphperror.log');
        error_log("query result: " . var_export($items, true) . "\n", 3, 'myphperror.log');
        $result = array();

        foreach ($items as $item) {
            array_push($result, $item['value']);
        }
        error_log("fetched " . sizeof($result) . " items.\n", 3, 'myphperror.log');
        error_log("result: " . var_export($result, true) . "\n", 3, 'myphperror.log');
        Flight::json($result);
    } else {
        $query = array(
            'description' => 'original item',
            'backupId' => $backupiteration,
            'organization' => $org,
            'space' => $space,
            'app' => $app,
            'podioItemId' => intval($item)
        );
        $theItem = getDbForUser()->selectCollection($backupcollection)->findOne($query);
        if (is_null($theItem)) {
            Flight::halt('404', 'Item with id=' . $item . ' not found.');
        } else {
            Flight::json($theItem['value']);
        }
    }
});

Flight::start();
?>
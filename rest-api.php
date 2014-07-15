<?php

require 'flight/flight/Flight.php';

/**
 * 
 * @global MongoClient $mongo
 * @return \MongoClient
 */
function getMongo() {
    global $mongo;
    if (!isset($mongo) || is_null($mongo)) {
        $dbhost = getenv('OPENSHIFT_MONGODB_DB_HOST');
        if ($dbhost != false) {
            $dbport = getenv('OPENSHIFT_MONGODB_DB_PORT');
            $user = "admin";
            $password = "IZ7ZCYaV8KrM";

            $mongo = new MongoClient("mongodb://$user:$password@$dbhost:$dbport/");
        } else {
            $mongo = new MongoClient();
        }
    }
    return $mongo;
}

/**
 * 
 * @return MongoDB DB containing all backups for the current user
 */
function getDbForUser() {
    $curr_user = getUser();
    return getMongo()->selectDB($curr_user['db']);
}

/**
 * Collection containing all registered users
 * @return MongoCollection
 */
function getUserCollection() {
    return getMongo()->selectDB('application')->selectCollection('users');
}

/**
 * returns user or sends http error if user is not found/authorized
 * @global type $user
 * @return array
 */
function getUser() {
    global $user;

    if (!isset($user)) {
        $headers = getallheaders();
        error_log("\nheaders: " . var_export($headers) . "\n", 3, 'myphperror.log');
        $basic_auth_header = 'Authorization';
        $session_header = 'PhpBackupLoginSession';
        if (array_key_exists($basic_auth_header, $headers) && isset($headers[$basic_auth_header]) && !is_null($headers[$basic_auth_header])) {
            $user_password_base64 = str_replace('Basic ', '', $headers[$basic_auth_header]);
            $user_password = base64_decode($user_password_base64);

            $username = strtok($user_password, ':');
            $password = str_replace($username . ":", '', $user_password);

            error_log("looking up user: $username/$password\n", 3, 'myphperror.log');

            $user = getUserCollection()->findOne(array('user' => $username, 'password' => $password));
            if (is_null($user)) {
                Flight::halt(401, 'user/password not found.');
            }
        } elseif (array_key_exists($session_header, $headers) && isset($headers[$session_header]) && !is_null($headers[$session_header])) {
            $session_header_content = $headers[$session_header];
            $user = getUserCollection()->findOne(array('loginsession' => $session_header_content));
            if (is_null($user)) {
                Flight::halt(401, 'session not found.');
            }
        } else {
            Flight::halt(401, 'no credentials provided.');
        }
    }
    return $user;
}

/**
 * Checks if user is authorized (http basic auth or session header)
 */
function checkLogin() {
    getUser();
}

function createUserDbName($user) {
    //TODO assure uniqueness!
    return str_replace(array('@', '.'), '-', $user);
}

Flight::set('flight.log_errors', true);

Flight::route('/login', function() {
    $curr_user = getUser();
    if (!isset($curr_user['loginsession'])) {
        $curr_user['loginsession'] = md5($curr_user['user'] . time());
        getUserCollection()->save($curr_user);
    }
    Flight::json(array('loginsession' => $curr_user['loginsession']));
});

Flight::route('/logout', function() {
    $curr_user = getUser();
    unset($curr_user['loginsession']);
    getUserCollection()->save($curr_user);
    Flight::halt(204);
});

Flight::route('POST /register', function() {
    /* maybe use PUT + POST? */
    $user = base64_decode(Flight::request()->data['user']);
    $password = base64_decode(Flight::request()->data['password']);

    error_log("\nregistering user: $user with password: $password\n", 3, 'myphperror.log');

    if (is_null(getUserCollection()->findOne(array('user' => $user)))) {
        getUserCollection()->save(array(
            'user' => $user,
            'password' => $password,
            'balance' => 0,
            'db' => createUserDbName($user)
        ));
    } else {
        Flight::halt(409, 'user name exists.');
        return;
    }
});


Flight::route('/backupcollection/count', function() {
    Flight::json(sizeof(getDbForUser()->getCollectionNames()));
});

Flight::route('/backupcollection/@backupcollection/backupiteration/count', function($backupcollection) {
    $collection = getDbForUser()->selectCollection($backupcollection);
    Flight::json(sizeof($collection->distinct('backupId')));
});

Flight::route('/backupcollection/@backupcollection/backupiteration/@backupiteration/org/@org/space/@space/app/@app/item/count', function($backupcollection, $backupiteration, $org, $space, $app) {
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

/* browsing */
Flight::route('/backupcollection(/@backupcollection/backupiteration(/@backupiteration/org(/@org/space(/@space/app(/@app/item(/@item))))))', function($backupcollection, $backupiteration, $org, $space, $app, $item) {

    checkLogin();

    if (is_null($backupcollection)) {
        $allCollections = getDbForUser()->getCollectionNames();
        Flight::json(array_filter($allCollections, function ($var) {
                    return $var != 'system.indexes';
                }));
    } else if (is_null($backupiteration)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $backups = $collection->distinct('backupId');
        Flight::json($backups);
    } else if (is_null($org)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $orgs = $collection->distinct('organization', array('backupId' => $backupiteration));
        Flight::json($orgs);
    } else if (is_null($space)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $spaces = $collection->distinct('space', array('backupId' => $backupiteration, 'organization' => $org));
        Flight::json($spaces);
    } else if (is_null($app)) {
        $collection = getDbForUser()->selectCollection($backupcollection);
        $apps = $collection->distinct('app', array('backupId' => $backupiteration, 'organization' => $org, 'space' => $space));
        Flight::json($apps);
    } else if (is_null($item)) {
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

        error_log("fetching $count items. start=$start\n", 3, 'myphperror.log');

        $result = array();

        foreach ($items as $item) {
            $podioItem = unserialize($item['value']);
            array_push($result, $podioItem->as_json());
        }
        Flight::json($result);
    }
});


Flight::start();
?>

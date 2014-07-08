<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>Show App</title>
    </head>
    <body>
        <?php
        require_once '../Storage.php';

        $collectionname = $_GET['collection'];
        $backupId = $_GET['backup'];
        $org = $_GET['org'];
        $space = $_GET['space'];
        $app = $_GET['app'];

        echo "<h1>App $app in space $space in organization $org in backup $backupId in collection $collectionname</h1>\n";
        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);
        ?>

        <h2>Apps</h2>
        TODO (incl. paging)
        <?php
        /*$apps = $collection->distinct('app', array('backupId' => $backupId, 'organization' => $org, 'space'=>$space, 'app'=>$app));
        if ($apps) {
            foreach ($apps as $app) {
                echo "<a href='ShowApp.php?collection=$collectionname&backup=$backupId&org=$org&space=$space&app?$app'>$app</a><br>\n";
            }
        } else {
            echo "<i>no app found in space.</i>\n";
        }*/
        ?>

        <h2>Files</h2>
        TODO
    </body>
</html>

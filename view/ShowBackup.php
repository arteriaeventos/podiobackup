<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>Show Backup</title>
    </head>
    <body>
        <?php
        require_once '../Storage.php';

        $collectionname = $_GET['collection'];
        $backupId = $_GET['backup'];

        echo "<h1>Backup $backupId in collection $collectionname</h1>\n";
        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);
        ?>

        <h2>Organizations</h2>
        <?php
        $orgs = $collection->distinct('organization', array('backupId' => $backupId));
        if ($orgs) {
            foreach ($orgs as $org) {
                echo "<a href='ShowOrg.php?collection=$collectionname&backup=$backup&org=$org'>$org</a><br>\n";
            }
        } else {
            echo "<i>no organization found in backup.</i>\n";
        }
        ?>

        <h2>Files</h2>
        TODO
    </body>
</html>

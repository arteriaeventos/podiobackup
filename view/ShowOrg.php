<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>Show Organization</title>
    </head>
    <body>
        <?php
        require_once '../Storage.php';

        $collectionname = $_GET['collection'];
        $backupId = $_GET['backup'];
        $org = $_GET['org'];

        echo "<h1>Organization $org in backup $backupId in collection $collectionname</h1>\n";
        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);
        ?>

        <h2>Spaces</h2>
        <?php
        $spaces = $collection->distinct('space', array('backupId' => $backupId, 'organization' => $org));
        if ($spaces) {
            foreach ($spaces as $space) {
                echo "<a href='ShowSpace.php?collection=$collectionname&backup=$backup&org=$org&space=$space'>$space</a><br>\n";
            }
        } else {
            echo "<i>no space found in organization.</i>\n";
        }
        ?>

        <h2>Files</h2>
        TODO
    </body>
</html>

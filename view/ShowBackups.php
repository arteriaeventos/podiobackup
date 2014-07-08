<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>All backups in a collection</title>
    </head>
    <body>
        <?php
        require_once '../Storage.php';

        $collectionname = $_GET['collection'];

        echo "<h1>Backups in collection $collectionname</h1>\n";
        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);
        $backups = $collection->distinct('backupId');
        if ($backups) {
            foreach ($backups as $backup) {
                echo "<a href='ShowBackup.php?collection=$collectionname&backup=$backup'>$backup</a><br>\n";
            }
        } else {
            echo "<i>no backups found.</i>\n";
        }
        ?>
    </body>
</html>

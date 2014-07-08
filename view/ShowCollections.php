<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>All backup collections</title>
    </head>
    <body>
        <h1>All backup collections</h1>
        <?php
        require_once '../Storage.php';
        $db = Storage::getMongoDb();

        foreach ($db->getCollectionNames(false) as $collectionname) {
            echo "<a href='ShowBackups.php?collection=$collectionname'>$collectionname</a><br>\n";
        }
        ?>
    </body>
</html>

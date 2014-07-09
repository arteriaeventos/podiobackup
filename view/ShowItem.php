<!DOCTYPE html>
<!--
To change this license header, choose License Headers in Project Properties.
To change this template file, choose Tools | Templates
and open the template in the editor.
-->
<html>
    <head>
        <meta charset="UTF-8">
        <title>Show Item</title>
    </head>
    <body>
        <?php
        require_once '../Storage.php';
        require_once '../podio-php/PodioAPI.php';
        require_once '../HumanFormat.php';

        $collectionname = $_GET['collection'];
        $backupId = $_GET['backup'];
        $org = $_GET['org'];
        $space = $_GET['space'];
        $app = $_GET['app'];
        $podioItemId = intval($_GET['podioItemId']);

        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);

        $query = array(
            'description' => 'original item',
            'backupId' => $backupId,
            'organization' => $org,
            'space' => $space,
            'app' => $app,
            'podioItemId' => $podioItemId);

        $item = $collection->findOne($query);
        $podioItem = unserialize($item['value']);

        echo "<h1>Item $podioItem->title</h1>\n";
        echo "<h2>in app $app in space $space in organization $org in backup $backupId in collection $collectionname</h2>\n";

        $itmeAsText = HumanFormat::toHumanReadableString($podioItem);

        echo "<pre>$itemAsText</pre>\n";
        ?>


    </body>
</html>

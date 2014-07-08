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

        $start = $_GET['start'];
        if (!isset($start) || is_null($start) || $start < 0)
            $start = 0;
        $count = $_GET['count'];
        if (!isset($count) || is_null($count))
            $count = 50;

        echo "<h1>App $app in space $space in organization $org in backup $backupId in collection $collectionname</h1>\n";
        $db = Storage::getMongoDb();
        $collection = $db->selectCollection($collectionname);
        ?>

        <h2>Items</h2>
        <?php
        $query = array(
            'description' => 'original item',
            'backupId' => $backupId,
            'organization' => $org,
            'space' => $space,
            'app' => $app);
        
        $items = $collection->find($query);
        echo "query: ";
        var_dump($query);
        echo "\n<br>count=$count skip=$start\n";
        
        $items->sort(array('_id' => 1)); //here we have an index for sure..
        $items->limit($count);
        $items->skip($start);

        foreach ($items as $item) {
            $podioItem = unserialize($item['value']);
            $podioItemId = $item['podioItemId'];
            echo "<a href='ShowItem.php?"
            . "collection=$collectionname"
            . "&backup=$backupId"
            . "&org=$org"
            . "&space=$space"
            . "&app=$app"
            . "&podioItemId=$podioItemId'>"
            . "$podioItem->name"
            . "</a><br>\n";
        }

        echo "<br><a href='ShowApp.php?"
        . "collection=$collectionname"
        . "&backup=$backupId"
        . "&org=$org"
        . "&space=$space"
        . "&app=$app&start=" . ($start + $count) . "'>forward</a>\n";

        if ($count > 0) {
            echo "<br><a href='ShowApp.php?"
            . "collection=$collectionname"
            . "&backup=$backupId"
            . "&org=$org"
            . "&space=$space"
            . "&app=$app&start=" . ($start - $count) . "'>backward</a>\n";
        }
        ?>


        <h2>Files</h2>
        TODO
    </body>
</html>

<?php

require_once 'RateLimitChecker.php';

/**
 * Description of PodioFetchAll
 *
 * @author SCHRED
 */
class PodioFetchAll
{
    /**
     * The json result of the last request is element wise added to $store.
     * Important: this must be called immediately after the API call and <i>before</i> RateLimitChecker::preventTimeOut()
     * @param array $store
     * @param string $resulttype if not NULL $store[$resulttype] is used
     */
    public static function storeRawResponse(&$store, $resulttype = null)
    {
        $raw_response = Podio::$last_response->json_body();
        //echo "DEBUG raw response:\n";
        //var_dump($raw_response);
        if (!is_null($resulttype)) {
            $response = $raw_response[$resulttype];
        } else {
            $response = $raw_response;
        }
        foreach ($response as $response_item) {
            array_push($store, $response_item);
        }
    }

    public static function getRawResponse($resulttype = null)
    {
        $result = array();
        self::storeRawResponse($result, $resulttype);
        return $result;
    }

    /**
     * Wrapper to fetch all elements besides some from podio imposed maxresult.
     *
     * Examples:
     *
     * $result = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', "YOUR_APP_ID", array('attached_to' => 'item'), null, function($item, $raw){
     *      //do something with it
     * });
     * $result = PodioFetchAll::iterateApiCall('PodioItem::filter', "YOUR_APP_ID", array(), "items", function($item, $raw){
     *      //do something with it
     * });
     *
     * @param string $function e.g. 'PodioFile::get_for_app'
     * @param type $id first parameter of function
     * @param array $params
     * @param int $limit no of elements fetched on each call
     * @param string $resulttype if set, the result of the call is suspected to be in $result[$resulttype]
     * @param callable $apply first parameter: the podio item, second parameter: the raw result
     * @return int number of items interated over
     */
    public static function foreachItem($function, $id, $params = array(), $limit = 100, $resulttype = null, $apply)
    {
        $completed = false;
        $iteration = 0;
        $total = -1;
        while (!$completed) {
            #$tmp_result = $function($id, array_merge($params, array("limit" => $limit, 'offset' => $limit * $iteration)));
            $tmp_result = call_user_func($function, $id, array_merge($params, array('limit' => $limit, 'offset' => $limit * $iteration)));
            $raw_responses = array();
            self::storeRawResponse($raw_responses, $resulttype);
            RateLimitChecker::preventTimeOut();
            echo "done iteration $iteration\n"; #(result: " . var_dump($tmp_result) . ")\n";

            $iteration++;
            $item_responses = null;

            if ($tmp_result instanceof PodioCollection) {
                $item_responses = $tmp_result->_get_items();
                if ($tmp_result instanceof PodioItemCollection) {
                    echo "filtered: $tmp_result->filtered, total: $tmp_result->total (limit: $limit)\n";
                    if (sizeof($tmp_result->_get_items()) < $limit) {
                        $completed = true;
                        $total = $limit * ($iteration - 1) + sizeof($tmp_result->_get_items());
                    }
                } else {
                    echo "WARNING unexpected collection: " . get_class($tmp_result);
                }
            } else if ((!is_null($resulttype)) && isset($resulttype)) {
                if (is_array($tmp_result) && isset($tmp_result[$resulttype]) && is_array($tmp_result[$resulttype])) {
                    $item_responses = $tmp_result[$resulttype];
                    if (sizeof($tmp_result[$resulttype]) < $limit) {
                        $completed = true;
                        $total = $limit * ($iteration - 1) + sizeof($tmp_result[$resulttype]);
                    }
                } else {
                    $completed = true;
                    $total = $limit * ($iteration - 1);
                }
            } else {
                if (is_array($tmp_result)) {
                    $item_responses = $tmp_result;
                    if (sizeof($tmp_result) < $limit) {
                        $completed = true;
                        $total = $limit * ($iteration - 1) + sizeof($tmp_result);
                    }
                } else {
                    $completed = true;
                    $total = $limit * ($iteration - 1);
                }
            }
            unset($tmp_result);

            for ($i = 0; $i < sizeof($item_responses); $i++) {
                $apply($item_responses[$i], $raw_responses[$i]);
            }
        }
        return $total;
    }

    /**
     * @deprecated (leads to high memory usage!) use #foreachItem<br>
     *
     * Wrapper to fetch all elements besides some from podio imposed maxresult.
     *
     * Examples:
     *
     * $result = PodioFetchAll::iterateApiCall('PodioFile::get_for_app', "YOUR_APP_ID", array('attached_to' => 'item'));
     * $result = PodioFetchAll::iterateApiCall('PodioItem::filter', "YOUR_APP_ID", array(), "items");
     *
     * @param string $function e.g. 'PodioFile::get_for_app'
     * @param type $id first parameter of function
     * @param array $params
     * @param int $limit no of elements fetched on each call
     * @param string $resulttype if set, the result of the call is suspected to be in $result[$resulttype]
     * @param array $items_as_array if given, the raw responses are added element wise to this array
     * @return array array of all fetched elements
     */
    public static function iterateApiCall($function, $id, $params = array(), $limit = 100, $resulttype = null, &$items_as_array = null)
    {
        $completed = false;
        $iteration = 0;
        $result = array();
        while (!$completed) {
            #$tmp_result = $function($id, array_merge($params, array("limit" => $limit, 'offset' => $limit * $iteration)));
            $tmp_result = call_user_func($function, $id, array_merge($params, array('limit' => $limit, 'offset' => $limit * $iteration)));
            if (!is_null($items_as_array))
                self::storeRawResponse($items_as_array, $resulttype);
            RateLimitChecker::preventTimeOut();
            echo "done iteration $iteration\n"; #(result: " . var_dump($tmp_result) . ")\n";

            $iteration++;

            if ($tmp_result instanceof PodioCollection) {
                $result = array_merge($result, $tmp_result->_get_items());
                if ($tmp_result instanceof PodioItemCollection) {
                    echo "filtered: $tmp_result->filtered, total: $tmp_result->total (limit: $limit)\n";
                    if (sizeof($tmp_result->_get_items()) < $limit) {
                        $completed = true;
                    }
                } else {
                    echo "WARNING unexpected collection: " . get_class($tmp_result);
                }
            } else if ((!is_null($resulttype)) && isset($resulttype)) {
                if (is_array($tmp_result) && isset($tmp_result[$resulttype]) && is_array($tmp_result[$resulttype])) {
                    $result = array_merge($result, $tmp_result[$resulttype]);
                    if (sizeof($tmp_result[$resulttype]) < $limit) {
                        $completed = true;
                    }
                } else {
                    $completed = true;
                }
            } else {
                if (is_array($tmp_result)) {
                    $result = array_merge($result, $tmp_result);
                    if (sizeof($tmp_result) < $limit) {
                        $completed = true;
                    }
                } else {
                    $completed = true;
                }
            }
            unset($tmp_result);
        }
        return $result;
    }

    public static function podioElements(array $elements)
    {
        return array('__attributes' => $elements, '__properties' => $elements);
    }

    /**
     * Removes all elements/properties of $object, that are not defined in $elements.
     * This can be used to save memory when handling large lists of items.
     *
     * Be aware that podio objects make use of the __get(..) and __set(..) funktions,
     * hence a direct removal of the attributes is not possible - on might use PodioFetchAll::podioElemnts(..)
     *
     * Example usage:
     *
     * PodioFetchAll::flattenObjectsArray($appFiles, array('__attributes' =>
     *       array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *           'context' => array('id' => NULL, 'type' => null, 'title' => null)),
     *       '__properties' => array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *           'context' => NULL)));
     *
     * analogous:
     *
     * PodioFetchAll::flattenObjectsArray($appFiles,
     *           PodioFetchAll::podioElemnts(array('file_id' => null, 'name' => null, 'link' => null, 'hosted_by' => null,
     *                       'context' => array('id' => NULL, 'type' => null, 'title' => null)));
     *
     *
     * @param type $object can be class or array
     * @param array $elements
     */
    public static function flattenObject(&$object, array $elements)
    {
        //unset all propterties/elements of $object:
        if (is_array($object)) {
            foreach (array_keys($object) as $key) {

                if (array_key_exists($key, $elements)) {
                    #echo "found array-key: $key -> $elements[$key]\n";
                    if (is_array($elements[$key])) {
                        #echo "flattening key. for elements $elements[$key]\n";
                        self::flattenObject($object[$key], $elements[$key]);
                    } #else: do nothing
                } else {
                    #echo "unsetting $key\n";
                    unset($object[$key]);
                }
            }
        } else {
            foreach (array_keys(get_object_vars($object)) as $key) {
                if (array_key_exists($key, $elements)) {
                    #echo "found object-key: $key -> $elements[$key]\n";
                    #var_dump($elements[$key]);
                    if (is_array($elements[$key])) {
                        #echo "flattening key. for elements $elements[$key]\n";
                        self::flattenObject($object->$key, $elements[$key]);
                    } #else: do nothing
                } else {
                    #echo "unsetting $key\n";
                    unset($object->$key);
                }
            }
        }
    }

    /**
     * @see PodioFetchAll::flattenObject(..)
     * @param array $objects
     * @param array $elements
     * @return array
     */
    public static function flattenObjectsArray(array &$objects, array $elements)
    {
        $start = time();
        foreach ($objects as $object) {
            self::flattenObject($object, $elements);
        }
        echo "flattening took " . (time() - $start) . " seconds.\n";
    }

}

#!/usr/bin/env php
<?php
require_once '../vendor/autoload.php';

use Easy\BoxApi\EasyBoxApi;

try {
    $test = new EasyBoxApi("config.json");
    $folderID = $test->getFolderByName("bin");
    $test->uploadFile($folderID, 'xxx.zip');
} catch (Exception $e) {
    print($e);
}
?>
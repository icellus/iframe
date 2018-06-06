<?php


define("FRAMEWORK_START", microtime(true));


require dirname(__DIR__) . '/vendor/autoload.php';

$response = require_once __DIR__ . '/../core/bootstrap.php';


return $response;

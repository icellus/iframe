<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helloWorld = new \App\HelloWorld();
$helloWorld->announce();
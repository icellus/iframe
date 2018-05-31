<?php
//declare(strict_types=1);

define("FRAMEWORK",microtime(true));



use DI\ContainerBuilder;
use App\HelloWorld;
use function DI\create;
// use App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$home = new \App\HomeController();
$home->index();

// $containerBuilder = new containerBuilder();
// $containerBuilder->useAutowiring(false);
// $containerBuilder->useAnnotations(false);
// $containerBuilder->addDefinitions([
// 	// HelloWorld::class => create(HelloWorld::class)
// 	HomeController::class => create(HomeController::class)
// ]);

// $container = $containerBuilder->build();
// // $helloWorld = $container->get(HelloWorld::class);
// // $helloWorld->announce();
// // 
// $home = $container->get(HomeController::class);
// $home->index();

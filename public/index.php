<?php
//declare(strict_types=1);

define("FRAMEWORK", microtime(true));

use App\HelloWorld;
use DI\ContainerBuilder;
use FastRoute\RouteCollector;
use Middlewares\FastRoute;
use Middlewares\RequestHandler;
use Relay\Relay;
use Zend\Diactoros\Response;
use Zend\Diactoros\Response\SapiEmitter;
use Zend\Diactoros\ServerRequestFactory;
use function DI\create;
use function DI\get;
use function FastRoute\simpleDispatcher;

// use App;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$containerBuilder = new containerBuilder();
$containerBuilder->useAutowiring(false);
$containerBuilder->useAnnotations(false);

$containerBuilder->addDefinitions([
	HelloWorld::class => create(HelloWorld::class)->constructor(get('Foo'),get('Response')),
	'Foo'             => 'bar',
	'Response'        => function() {
		return new Response();
	},
]);

$container = $containerBuilder->build();
$routes    = simpleDispatcher(function(RouteCollector $r) {
	$r->get('/hello', HelloWorld::class);
});

$middlewareQueue   = [];
$middlewareQueue[] = new FastRoute($routes);
$middlewareQueue[] = new RequestHandler($container);

$requestHandler = new Relay($middlewareQueue);
//$requestHandler->handle(ServerRequestFactory::fromGlobals());
$response = $requestHandler->handle(ServerRequestFactory::fromGlobals());
//
$emitter = new SapiEmitter();
return $emitter->emit($response);





// $helloWorld = $container->get(HelloWorld::class);
// $helloWorld->announce();
//
// $home = $container->get(HomeController::class);
// $home->index();

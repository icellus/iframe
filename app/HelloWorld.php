<?php
declare(strict_types=1);

namespace App;


use Psr\Http\Message\ResponseInterface;

class HelloWorld
{
	private $foo;

	private $response;

	public function __construct (string $foo, ResponseInterface $response) {
		$this->foo = $foo;
		$this->response = $response;
	}

	function __invoke(): ResponseInterface
	{
		$response = $this->response->withHeader('Content-Type','text/html');
		$response->getBody()->write("<html><head></head><body>hello,{$this->foo} world!</body></html>");

		return $response;
	}

}
<?php
namespace App;

use Test\test;

/**
 * index
 */
class HomeController
{
	
	function index()
	{


		$test = new test();
		dump($test);

		echo '123';
	}
}
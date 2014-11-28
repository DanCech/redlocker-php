<?php

require_once dirname(__DIR__) .'/vendor/autoload.php';

use RedLocker\LockManager;

function debug($msg)
{
	echo LockManager::get_microtime() .' '. $msg ."\n";
}

$locker = new LockManager(array(
	array('localhost','4545'),
	array('localhost','4546'),
	array('localhost','4547'),
	array('localhost','4548'),
	array('localhost','4549'),
	array('localhost','4550'),
));

// $locker->debug = True;

while (true) {
	debug('Locking');
	$lock = $locker->lock('test',10*1000,10*60*1000);
	
	if (!$lock) {
		debug('Failed');
		continue;
	}
	
	debug('Got Lock '. json_encode($lock));
	
	$sleep = mt_rand(1,5);
	
	debug('Sleeping for '. $sleep .' seconds');
	
	sleep($sleep);
	
	debug('Unlocking');
	
	$locker->unlock($lock);
	
	debug('Unlocked');
}

// end of script

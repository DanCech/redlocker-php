<?php

namespace RedLocker;

class Lock
{
	protected $manager;
	
	public $resource;
	public $token;
	public $n = 0;
	public $expiry;
	
	public function __construct(LockManager $manager, $resource)
	{
		$this->manager  = $manager;
		$this->resource = $resource;
		$this->token    = mt_rand();
	}
	
	public function release()
	{
		return $this->manager->unlock($this);
	}
}

// end of script

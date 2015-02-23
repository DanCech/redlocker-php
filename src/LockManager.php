<?php

namespace RedLocker;

class LockManager
{
	/**
	 * Reply size from locker server in bytes.
	 */
	const REPLY_SIZE = 6;
	
	/**
	 * Action constant to lock.
	 */
	const ACTION_LOCK = 1;
	
	/**
	 * Action constant to unlock.
	 */
	const ACTION_UNLOCK = 0;
	
	/**
	 * Debug flag
	 */
	public $debug = false;
	
	
	protected $clockDriftFactor = 0.01;
	
	protected $quorum;
	
	protected $servers = array();
	
	protected $sockets = array();
	
	
	public function __construct(array $servers)
	{
		if (!function_exists('socket_create')) {
			throw new \Exception('Socket extension is required');
		}
		
		$this->servers = $servers;
		
		$this->quorum  = min(count($servers), (count($servers) / 2 + 1));
	}
	
	public function lock($resource, $lock_wait, $ttl)
	{
		$this->initSockets();
		
		// make sure we have enough sockets to get a quorum
		if (count($this->sockets) < $this->quorum) {
			throw new \Exception(sprintf('%d servers available is not enough to form a quorum',count($this->sockets)));
		}
		
		$lock = new Lock($this,$resource);
		
		$startTime = self::get_microtime();
		
		$length = strlen($lock->resource);
		$buffer = pack('CVVVC', $length, $lock->token, $lock_wait, $ttl, self::ACTION_LOCK) . $lock->resource;
		
		# Add 2 milliseconds to the drift to account for Locker expires
		# precision, which is 1 millisecond, plus 1 millisecond min drift
		# for small TTLs.
		$drift = ($ttl * $this->clockDriftFactor) + 2;
		
		$to_read = $this->sockets;
		$to_write = $this->sockets;
		
		do {
			// create a copy, so $to_read and $to_write don't get modified by socket_select()
			$read = $to_read;
			$write = $to_write;
			$except = NULL;
			
			// see if there are any sockets we can read from or write to
			if (socket_select($read, $write, $except, 0) === false) {
				$code = @socket_last_error();
				throw new \Exception('Error selecting from sockets: '. $code .' '. socket_strerror($code));
			}
			
			// if we can't read or write, sleep for 10 milliseconds and try again
			if (!$read && !$write) {
				usleep(10);
				continue;
			}
			
			// send lock requests
			foreach ($write as $i => $socket) {
				if (@socket_write($socket, $buffer) === false) {
					$code = @socket_last_error($socket);
					
					@socket_close($socket);
					unset($this->sockets[$i]);
					unset($to_write[$i]);
					
					$this->debug('! error writing to '. $this->servers[$i][0] .':'. $this->servers[$i][1] .': ' . $code .' '. socket_strerror($code));
					continue;
				}
				
				unset($to_write[$i]);
				$this->debug('> sent lock request to '. $this->servers[$i][0] .':'. $this->servers[$i][1]);
			}
			
			if (count($this->sockets) < $this->quorum) {
				throw new \Exception(sprintf('%d servers available is not enough to form a quorum',count($this->sockets)));
			}
			
			// read lock results
			foreach ($read as $i => $socket) {
				$data = @socket_read($socket, self::REPLY_SIZE, PHP_BINARY_READ);
				if ($data === false) {
					$code = @socket_last_error($socket);
					
					@socket_close($socket);
					unset($this->sockets[$i]);
					unset($to_read[$i]);
					
					$this->debug('! error reading from '. $this->servers[$i][0] .':'. $this->servers[$i][1] .': ' . $code .' '. socket_strerror($code));
					continue;
				}
				
				if ($data === '') {
					@socket_close($socket);
					unset($this->sockets[$i]);
					unset($to_read[$i]);
					
					$this->debug('! no data from '. $this->servers[$i][0] .':'. $this->servers[$i][1]);
					continue;
				}
				
				$reply = unpack('Vsequence/Caction/Cresult', $data);
				
				$this->debug('< reply from '. $this->servers[$i][0] .':'. $this->servers[$i][1] .' '. $reply['sequence'] .' '. $reply['action'] .' '. $reply['result']);
				
				if ($reply['sequence'] != $lock->token) {
					// $this->reset();
					// throw new \Exception('Requested lock with sequence ' . $lock->token .', received reply with ' . $reply['sequence']);
					continue;
				}
				
				if ($reply['action'] != self::ACTION_LOCK) {
					// $this->reset();
					// throw new \Exception('Requested action = ' . self::ACTION_LOCK . '), received action = ' . $reply['action']);
					continue;
				}
				
				if (!$reply['result']) {
					// couldn't get lock
					continue;
				}
				
				unset($to_read[$i]);
				$this->debug('+ locked '. $this->servers[$i][0] .':'. $this->servers[$i][1]);
				
				$lock->n++;
			}
			
			if (count($this->sockets) < $this->quorum) {
				throw new \Exception(sprintf('%d servers available is not enough to form a quorum',count($this->sockets)));
			}
			
			$expiryTime = $startTime + $ttl - $drift;
			
			// if we know we have a quorum, return lock
			if ($lock->n >= $this->quorum && $expiryTime > self::get_microtime()) {
				$lock->expiry = $expiryTime;
				return $lock;
			}
			
			// if we don't have any sockets left to work with, break
			if (!$to_write && !$to_read) {
				break;
			}
		} while ((self::get_microtime() - $startTime) < $lock_wait);
		
		// couldn't acquire quorum of locks before the lock_wait timeout
		
		// release any locks we managed to get
		$this->unlock($lock);
		
		return false;
	}
	
	public function unlock(Lock $lock)
	{
		$this->initSockets();
		
		if (count($this->sockets) < $this->quorum) {
			throw new \Exception(sprintf('%d servers available is not enough to form a quorum',count($this->sockets)));
		}
		
		$length = strlen($lock->resource);
		$buffer = pack('CVVVC', $length, $lock->token, 0, 0, self::ACTION_UNLOCK) . $lock->resource;
		
		// initialize socket arrays for socket_select()
		$read = NULL;
		$write = $this->sockets;
		$except = NULL;
		
		// see if there are any sockets we can write to
		if (socket_select($read, $write, $except, 0) === false) {
			$code = socket_last_error();
			throw new \Exception('Error selecting from sockets: '. $code .' '. socket_strerror($code));
		}
		
		// send unlock requests
		foreach ($write as $i => $socket) {
			if (@socket_write($socket, $buffer) === false) {
				$code = @socket_last_error($socket);
				
				socket_close($socket);
				unset($this->sockets[$i]);
				
				$this->debug('! error writing to '. $this->servers[$i][0] .':'. $this->servers[$i][1] .': ' . $code .' '. socket_strerror($code));
				continue;
			}
			
			$this->debug('> sent unlock request to '. $this->servers[$i][0] .':'. $this->servers[$i][1]);
		}
		
		if (count($this->sockets) < $this->quorum) {
			throw new \Exception(sprintf('%d servers available is not enough to form a quorum',count($this->sockets)));
		}
		
		return true;
	}
	
	public function initSockets()
	{
		// establish connections
		foreach ($this->servers as $i => $server) {
			// already connected
			if (!isset($this->sockets[$i])) {
				// initialize socket
				$result = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				if (!$result) {
					continue;
				}
				$this->sockets[$i] = $result;
				
				socket_set_nonblock($this->sockets[$i]);
			}
			
			// Try to connect.  Don't bother checking result.  It's always
			// false due to socket_set_nonblock(), above.
			$this->debug('> connecting to '. $server[0] .':'. $server[1]);
			@socket_connect($this->sockets[$i],$server[0],$server[1]);
		}
		
		return true;
	}
	
	public static function get_microtime()
	{
		list($usec, $sec) = explode(' ',microtime());
		return ($sec . substr($usec,2,3));
	}
	
	protected function debug($msg)
	{
		if ($this->debug) {
			echo self::get_microtime() .' '. $msg ."\n";
		}
	}
}

// end of script

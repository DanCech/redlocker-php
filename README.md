redlocker-php
=============

Redlock style Locker distributed locks in PHP

Based on [Redlock-rb](https://github.com/antirez/redlock-rb), [redlock-php](https://github.com/ronnylt/redlock-php) and [php-locker](https://github.com/bobrik/php-locker).

This library implements the distributed lock manager algorithm [described in this blog post](http://antirez.com/news/77), using the node.js [locker](https://github.com/bobrik/locker) server.

## Features of locker:

* Lock timeouts with millisecond precision:
    * Timeout to wait for getting lock.
    * Timeout to keep lock before releae.
* No polling: one request to acquire, one request to release.
* Auto-releasing locks on disconnect.
* Pure node.js. Just awesome.

## Features of redlocker-php:

* Uses non-blocking socket io to obtain distributed locks in parallel
  * Can obtain distributed lock in ~2x the round-trip latency of the (N/2+1)th fastest server

## Example usage:

```php
use RedLocker\LockManager;

$servers = array(
  array('localhost','4545'),
  array('localhost','4546'),
  array('localhost','4547'),
);

$manager = new LockManager($servers);

$lockOne = $manager->lock('example', 200, 10000);
if (!$lockOne) {
  echo 'Couldn\'t acquire lock!'."\n";
} else {
  echo 'Got lock, working...'."\n";
  // do stuff
  sleep(5);
  $lockOne->release();
  echo 'Done'."\n";
}
```

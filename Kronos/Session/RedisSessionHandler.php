<?php

/*
 * This file is part of the SncRedisBundle package.
 *
 * (c) Henrik Westphal <henrik.westphal@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Kronos\Session;

use Redis;

/**
 * Redis based session storage with session locking support.
 *
 * @author Justin Rainbow <justin.rainbow@gmail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Henrik Westphal <henrik.westphal@gmail.com>
 * @author Maurits van der Schee <maurits@vdschee.nl>
 * @author Pierre Boudelle <pierre.boudelle@gmail.com>
 */
class RedisSessionHandler implements \SessionHandlerInterface
{
    /**
     * @var Redis
     */
    protected $redis;

    /**
     * @var int
     */
    protected int $ttl;

    /**
     * @var string
     */
    protected string $prefix;

    /**
     * @var int Default PHP max execution time in seconds
     */
    const DEFAULT_MAX_EXECUTION_TIME = 30;

    /**
     * @var bool Indicates an sessions should be locked
     */
    protected bool $locking;

    /**
     * @var bool Indicates an active session lock
     */
    protected bool $locked;

    /**
     * @var string Session lock key
     */
    private string $lockKey;

    /**
     * @var string Session lock token
     */
    private string $token;

    /**
     * @var int Microseconds to wait between acquire lock tries
     */
    private int $spinLockWait;

    /**
     * @var int Maximum amount of seconds to wait for the lock
     */
    private int $lockMaxWait;

    /**
     * Redis session storage constructor.
     *
     * @param Redis $redis Redis database connection
     * @param array $options Session options
     * @param string $prefix Prefix to use when writing session data
     */
    public function __construct(
        Redis $redis,
        array $options = array(),
        string $prefix = 'session',
        bool $locking = true,
        int $spinLockWait = 150000
    ) {
        $this->redis = $redis;
        $this->ttl = isset($options['gc_maxlifetime']) ? (int)$options['gc_maxlifetime'] : 0;
        if (isset($options['cookie_lifetime']) && $options['cookie_lifetime'] > $this->ttl) {
            $this->ttl = (int)$options['cookie_lifetime'];
        }
        $this->prefix = $prefix;
        $this->locking = $locking;
        $this->locked = false;
        $this->lockKey = "";
        $this->spinLockWait = $spinLockWait;
        $this->lockMaxWait = ini_get('max_execution_time')
            ? (int)ini_get('max_execution_time')
            : self::DEFAULT_MAX_EXECUTION_TIME;
    }

    /**
     * {@inheritdoc}
     */
    public function open($path, $name): bool
    {
        return true;
    }

    /**
     * Lock the session data.
     */
    protected function lockSession($sessionId): bool
    {
        $attempts = (1000000 / $this->spinLockWait) * $this->lockMaxWait;
        $this->token = uniqid();
        $this->lockKey = $sessionId . '.lock';
        for ($i = 0; $i < $attempts; ++$i) {
            // We try to acquire the lock
            $success = $this->redis->set(
                $this->getRedisKey($this->lockKey),
                $this->token,
                array('NX', 'PX' => $this->lockMaxWait * 1000 + 1)
            );

            if ($success) {
                $this->locked = true;
                return true;
            }

            /** @psalm-suppress ArgumentTypeCoercion */
            usleep($this->spinLockWait);
        }

        return false;
    }

    /**
     * Unlock the session data.
     */
    private function unlockSession()
    {
        // If we have the right token, then delete the lock
        $script = <<<LUA
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;

        $this->redis->eval($script, array($this->getRedisKey($this->lockKey), $this->token), 1);
        $this->locked = false;
        $this->token = "";
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        if ($this->locking) {
            if ($this->locked) {
                $this->unlockSession();
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($id)
    {
        if ($this->locking) {
            if (!$this->locked) {
                if (!$this->lockSession($id)) {
                    return false;
                }
            }
        }

        $result = $this->redis->get($this->getRedisKey($id));
        if (isset($result)) {
            return is_array($result) && !empty($result) ? array_values($result)[0] : $result;
        } else {
            return '';
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($id, $data): bool
    {
        if (0 < $this->ttl) {
            $this->redis->setex($this->getRedisKey($id), $this->ttl, $data);
        } else {
            $this->redis->set($this->getRedisKey($id), $data);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($id): bool
    {
        $this->redis->del($this->getRedisKey($id));
        $this->close();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($max_lifetime)
    {
        return 1;
    }

    /**
     * Change the default TTL.
     */
    public function setTtl(int $ttl)
    {
        $this->ttl = $ttl;
    }

    /**
     * Prepends the given key with a user-defined prefix (if any).
     */
    protected function getRedisKey(string $key): string
    {
        if (empty($this->prefix)) {
            return $key;
        }

        return $this->prefix . $key;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        $this->close();
    }
}

<?php
namespace app\common\service\TelnetClient;

use app\common\service\TelnetClient\Common\Strings;

/**
 * Telnet 连接类
 * 基于 https://github.com/phpseclib/phpseclib/blob/master/phpseclib/Net/SSH2.php 进行的修改
 *
 * @author  jshensh <admin@imjs.work>
 */

class TelnetClient
{
    /**
     * The Socket Object
     *
     * @var object
     * @access private
     */

    private $fsock;

    /**
     * Hostname
     *
     * @var string
     * @access private
     */

    private $host;

    /**
     * Port Number
     *
     * @var int
     * @access private
     */

    private $port;

    /**
     * Timeout
     *
     * @access private
     */

    protected $timeout;

    /**
     * Current Timeout
     *
     * @access private
     */

    protected $curTimeout;

    /**
     * Did read() timeout or return normally?
     *
     * @var bool
     * @access private
     */

    private $is_timeout = false;

    /**
     * Time of first network activity
     *
     * @var int
     * @access private
     */

    private $last_packet;

    /**
     * Interactive Buffer
     *
     * @var array
     * @access private
     */

    private $interactiveBuffer = '';

    /**
     * Default Constructor.
     *
     * $host can either be a string, representing the host, or a stream resource.
     *
     * @param mixed $host
     * @param int $port
     * @param int $timeout
     *
     * @return SSH2|void
     *
     * @access public
     */

    public function __construct($host, $port = 23, $timeout = 10)
    {
        if (is_resource($host)) {
            $this->fsock = $host;
            return;
        }

        if (is_string($host)) {
            $this->host = $host;
            $this->port = $port;
            $this->timeout = $timeout;
        }

        $this->connect();
    }

    /**
     * Set Timeout
     *
     * $ssh->exec('ping 127.0.0.1'); on a Linux host will never return and will run indefinitely.  setTimeout() makes it so it'll timeout.
     * Setting $timeout to false or 0 will mean there is no timeout.
     *
     * @param mixed $timeout
     *
     * @access public
     */

    public function setTimeout($timeout)
    {
        $this->timeout = $this->curTimeout = $timeout;
    }

    /**
     * Is timeout?
     *
     * Did exec() or read() return because they timed out or because they encountered the end?
     *
     * @access public
     */

    public function isTimeout()
    {
        return $this->is_timeout;
    }

    /**
     * Connect to a Telnet server
     *
     * @return bool
     *
     * @access private
     */

    private function connect()
    {
        $this->curTimeout = $this->timeout;

        $this->last_packet = microtime(true);

        if (!is_resource($this->fsock)) {
            $start = microtime(true);
            // with stream_select a timeout of 0 means that no timeout takes place;
            // with fsockopen a timeout of 0 means that you instantly timeout
            // to resolve this incompatibility a timeout of 100,000 will be used for fsockopen if timeout is 0
            $this->fsock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->curTimeout == 0 ? 100000 : $this->curTimeout);
            if (!$this->fsock) {
                $host = $this->host . ':' . $this->port;
                throw new \Exception(rtrim("Cannot connect to $host. Error $errno. $errstr"));
            }
            $elapsed = microtime(true) - $start;

            if ($this->curTimeout) {
                $this->curTimeout-= $elapsed;
                if ($this->curTimeout < 0) {
                    $this->is_timeout = true;
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Disconnect
     *
     * @access public
     */

    public function disconnect()
    {
        return @fclose($this->fsock);
    }

    /**
     * Inputs a command into an interactive shell.
     *
     * @param string $cmd
     *
     * @return bool
     *
     * @access public
     */

    public function write($cmd)
    {
        return @fputs($this->fsock, $cmd);
    }

    /**
     * Returns the output of an interactive shell
     *
     * Returns when there's a match for $expect, which can take the form of a string literal or,
     * if $mode == self::READ_REGEX, a regular expression.
     *
     * @param string $expect
     *
     * @return string|bool|null
     *
     * @access public
     */

    public function read($expect = '')
    {
        $this->curTimeout = $this->timeout;
        $this->is_timeout = false;

        $match = $expect;
        while (true) {
            // if ($mode == self::READ_REGEX) {
            //     preg_match($expect, substr($this->interactiveBuffer, -1024), $matches);
            //     $match = isset($matches[0]) ? $matches[0] : '';
            // }
            $pos = strlen($match) ? strpos($this->interactiveBuffer, $match) : false;
            if ($pos !== false) {
                return Strings::shift($this->interactiveBuffer, $pos + strlen($match));
            }
            $response = $this->get_channel_packet();
            if (is_bool($response)) {
                // $this->in_request_pty_exec = false;
                return $response ? Strings::shift($this->interactiveBuffer, strlen($this->interactiveBuffer)) : false;
            }

            $this->interactiveBuffer.= $response;
        }
    }

    /**
     * Gets channel data
     *
     * Returns the data as a string if it's available and false if not.
     *
     * @return mixed
     *
     * @access private
     */

    protected function get_channel_packet()
    {
        while (true) {
            $read = [$this->fsock];
            $write = $except = null;

            // \Illuminate\Support\Facades\Log::debug($this->curTimeout);
            if (!$this->curTimeout) {
                stream_select($read, $write, $except, null);
            } else {
                if ($this->curTimeout < 0) {
                    $this->is_timeout = true;
                    return true;
                }

                $read = [$this->fsock];
                $write = $except = null;

                $start = microtime(true);
                $sec = floor($this->curTimeout);
                $usec = 1000000 * ($this->curTimeout - $sec);
                if (!stream_select($read, $write, $except, $sec, $usec)) {
                    $this->is_timeout = true;
                    // if ($client_channel == self::CHANNEL_EXEC && !$this->request_pty) {
                    //     $this->close_channel($client_channel);
                    // }
                    return true;
                }
                $elapsed = microtime(true) - $start;
                $this->curTimeout-= $elapsed;
            }

            $response = stream_get_contents($this->fsock, 1);
            if ($response === false) {
                throw new \Exception('Connection closed by server');
            }
            return $response;
        }
        return true;
    }
}
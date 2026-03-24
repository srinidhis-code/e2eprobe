<?php
// library for various networking functions using php

// Wake on LAN
function nwakeonlan($addr, $mac, $port = '7') { // port 7 seems to be default
    flush();
    $addr_byte = explode(':', $mac);
    $hw_addr = '';
    for ($a = 0; $a < 6; $a++) {
        $hw_addr .= chr(hexdec($addr_byte[$a]));
    }

    $msg = chr(255) . chr(255) . chr(255) . chr(255) . chr(255) . chr(255);
    for ($a = 1; $a <= 16; $a++) {
        $msg .= $hw_addr;
    }

    // send it to the broadcast address using UDP
    $s = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($s == false) {
        return false;
    } else {
        $opt_ret = @socket_set_option($s, 1, 6, true);
        if ($opt_ret < 0) {
            return false;
        }

        if (socket_sendto($s, $msg, strlen($msg), 0, $addr, $port)) {
            socket_close($s);
            return true;
        } else {
            echo "Magic packet failed!";
            return false;
        }
    }
}

class NTCPClient {
    public $id;
    public $sock;
    public $lastime;
    public $inbuffer = '';
    public $outbuffer = '';
    public $buffersize;

    public function __construct() {}

    public function init($rip = null, $rport = null, $id = null, $sock = null, $buffersize = 2048) {
        $this->id = $id ? $id : uniqid();

        if ($sock) {
            $this->sock = $sock;
        } else {
            $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)
                or die("ERROR (NTCPClient): could not create a new socket.\n");

            @socket_set_nonblock($sock);
            $status = false;
            $limit = 5;

            while ($limit--) {
                $status = @socket_connect($sock, $rip, $rport);
                if ($status || socket_last_error() == SOCKET_EINPROGRESS) {
                    break;
                }
                usleep(10000);
            }

            if (!$status && socket_last_error() != SOCKET_EINPROGRESS) {
                die("ERROR (NTCPServer): could not connect to the new socket.\n");
            }

            $this->sock = $sock;
        }

        $this->lastime = tsystem();
        $this->buffersize = $buffersize;
    }

    public function recv() {
        $buffer = '';
        $status = @socket_recv($this->sock, $buffer, $this->buffersize, 0);
        if ($status <= 0) {
            return null;
        }

        $this->inbuffer .= substr($buffer, 0, $status);
        return $this->parse();
    }

    public function parse() {
        $B =& $this->inbuffer;

        // Try FFFFF protocol first
        if (strpos($B, 'FFFFF') === 0) {
            $count = '';
            for ($pos = 5; $pos < 25 && ($pos + 5 < strlen($B)); $pos++) {
                if (substr($B, $pos, 5) == 'FFFFF') {
                    $count = substr($B, 5, $pos - 5);
                    break;
                }
            }

            if (strlen($count) && strlen($B) >= 5 * 2 + strlen($count) + (int)$count) {
                $h = json2h(substr($B, 5 * 2 + strlen($count), (int)$count), true, null, true);
                if ($h) {
                    $B = substr($B, 5 * 2 + strlen($count) + (int)$count);
                    return $h;
                }
            }
        }

        // Fallback: raw key=value comma-separated format
        // Example: tag=test,run=1,method=pathchirp,psize=1200,...
        if (strlen($B) > 0 && strpos($B, 'method=') !== false) {
            $parts = explode(',', trim($B));
            $h = array();

            foreach ($parts as $p) {
                $kv = explode('=', trim($p), 2);
                if (count($kv) == 2) {
                    $h[trim($kv[0])] = trim($kv[1]);
                }
            }

            if (isset($h['method'])) {
                $B = '';
                return $h;
            }
        }

        return null;
    }

    // will send bz64json(msg)
    public function send($h = null, $persist = false) {
        $B =& $this->outbuffer;

        if ($h !== null && is_string($h)) {
            $h = tth($h);
        }

        if ($h !== null) {
            $B = h2json($h, true, null, null, true);
            $B = 'FFFFF' . strlen($B) . 'FFFFF' . $B;
        }

        $len = strlen($B);
        if ($len <= 0) {
            return 0;
        }

        $status = @socket_write($this->sock, $B, $len > $this->buffersize ? $this->buffersize : $len);
        if ($status === false || $status <= 0) {
            return $status;
        }

        $B = substr($B, $status);

        if ($B && $persist) {
            return $this->send(null, true);
        }

        return $status;
    }

    public function isempty() {
        return $this->outbuffer ? false : true;
    }

    public function close() {
        @socket_close($this->sock);
    }
}

class NTCPServer {
    public $port;
    public $sock;
    public $socks = array();
    public $clients = array();
    public $buffersize = 2048;
    public $nonblock = true;
    public $usleep = 10;
    public $timeout;
    public $clientclass;

    public function __construct() {}

    public function start($port, $nonblock = false, $usleep = 0, $timeout = 300, $clientclass = 'NTCPClient') {
        $this->port = $port;
        $this->nonblock = $nonblock;
        $this->clientclass = $clientclass;
        $this->usleep = $usleep;
        $this->timeout = $timeout;

        $this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)
            or die("ERROR (NTCPServer): failed to creater new socket.\n");
        echo "Socket created\n";

        socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1)
            or die("ERROR (NTCPServer): socket_setopt() filed!\n");

        if ($nonblock) {
            socket_set_nonblock($this->sock);
        }

        $status = false;
        $limit = 5;
        while ($limit--) {
            $status = @socket_bind($this->sock, '0.0.0.0', $port);
            if ($status) {
                break;
            }
            usleep(10000);
        }

        if (!$status) {
            die("ERROR (NTCPServer): cound not bind the socket.\n");
        }
        echo "Socket bind success (0.0.0.0:$port)\n";

        socket_listen($this->sock, 20)
            or die("ERROR (NTCPServer): could not start listening to the socket.\n");
        echo "Listening...\n";

        $this->socks = array($this->sock);

        while (1) {
            if ($this->timetoquit()) {
                break;
            }

            foreach ($this->socks as $sock) {
                if ($sock == $this->sock) {
                    // main socket, check for new connections
                    $newsock = @socket_accept($sock);
                    if ($newsock) {
                        echo "CLIENT CONNECTED\n";

                        if ($this->nonblock) {
                            @socket_set_nonblock($newsock);
                        }

                        lpush($this->socks, $newsock);

                        $client = new $this->clientclass();
                        $client->init(null, null, uniqid(), $newsock, $this->buffersize);

                        lpush($this->clients, $client);
                        $this->newclient($client);
                    }
                } else {
                    // existing socket
                    $client = null;
                    foreach ($this->clients as $client2) {
                        if ($client2->sock == $sock) {
                            $client = $client2;
                            break;
                        }
                    }

                    if (!$client) {
                        continue;
                    }

                    if (tsystem() - $client->lastime > $this->timeout) {
                        $this->clientout($client);
                        @socket_close($client->sock);
                        $this->removeclient($client);
                        continue;
                    }

                    $this->eachloop($client);

                    if (strlen($client->outbuffer)) {
                        if ($client->send()) {
                            $client->lastime = tsystem();
                        }
                    }

                    $h = $client->recv();
                    if ($h) {
                        $this->receive($h, $client);
                        $client->lastime = tsystem();
                    }
                }
            }

            if ($this->usleep) {
                usleep($this->usleep);
            }
        }

        socket_close($this->sock);
    }

    public function clientout($client) {
        $L = array();
        $L2 = array($this->sock);

        foreach ($this->clients as $client2) {
            if ($client2->sock != $client->sock) {
                lpush($L, $client2);
                lpush($L2, $client2->sock);
            }
        }

        $this->clients = $L;
        $this->socks = $L2;
    }

    // interface, should extend some of the functions, some may be left alone
    public function timetoquit() { return false; }
    public function newclient($client) {}
    public function removeclient($client) {}
    public function eachloop($client) {}
    public function send($h, $client) { $client->send($h); }
    public function receive($h, $client) {}
}

?>
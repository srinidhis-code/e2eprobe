<?php
set_time_limit(0);
ob_implicit_flush(1);

// Load ajaxkit
for ($prefix = is_dir('ajaxkit') ? 'ajaxkit/' : '';
     !is_dir($prefix) && count(explode('/', $prefix)) < 4;
     $prefix .= '../');

if (!is_file($prefix . "env.php")) $prefix = '/web/ajaxkit/';
if (!is_file($prefix . "env.php"))
    die("\nERROR! Cannot find env.php in [$prefix]\n\n");

foreach (array('functions', 'env') as $k)
    require_once($prefix . "$k.php");

clinit();

clhelp("PURPOSE: TCP receiver for e2eprobe");
clhelp("NOTE: uses port+1 for UDP probing");

htg(clget('port,wdir'));

$port2 = $port + 1;

echo "\n\n";

$lastime = tsystem();
$setup = null;

class MyServer extends NTCPServer {

    public function eachloop($client) {
        global $lastime, $setup, $wdir;

        if (tsystem() - $lastime < 1) return;
        $lastime = tsystem();

        if (!$setup) return;
        extract($setup);

        // wait if probe still running
        if (procpid('probe.udp')) return;

        // no file → throughput = 0
        if (!is_file("$wdir/temp.hcsv")) {
            $setup = null;

            echo "SENDING FINAL RESPONSE (no data)\n";
            $client->send(array(
                'msg' => 'ok',
                'status' => 'done',
                'thru' => 0
            ));
            return;
        }

        // read probe output
        $h = $setup;
        $h['probe'] = array();

        $lines = file("$wdir/temp.hcsv");
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;

            $h2 = tth($line);
            if (!isset($h2['pos'])) continue;

            extract($h2);
            $h['probe'][$pos] = $pspace;
        }

        ksort($h['probe']);
        $h['probe'] = hv($h['probe']);

        // write result to bz64jsonl
        $out = foutopen("$wdir/$tag.bz64jsonl", 'a');
        foutwrite($out, $h);
        foutclose($out);
        echo "WROTE RESULT TO $wdir/$tag.bz64jsonl\n";

        // compute throughput
        $vs = $h['probe'];
        $valid = array();

        foreach ($vs as $v) {
            if ($v != -1) lpush($valid, $v);
        }

        $thru = 0;
        if (count($valid) && msum($valid) > 0) {
            $thru = round(
                0.001 * (($psize * 8 * count($valid)) /
                (0.000001 * msum($valid)))
            );
        }

        echo "SENDING FINAL RESPONSE (JSON)\n";

        // ✅ FIX: send structured JSON
        $client->send(array(
            'msg' => 'ok',
            'status' => 'done',
            'thru' => $thru
        ));

        echo "FINAL RESPONSE SENT\n";

        $setup = null;
        `rm -Rf $wdir/temp.hcsv`;
    }

    public function receive($h, $client) {
        global $port2, $wdir, $setup, $lastime;

        echo "CONTROL MESSAGE RECEIVED\n";
        var_dump($h);

        $params = array();

        if (is_array($h)) {
            $params = $h;
        } else {
            $parts = explode(',', trim($h));
            foreach ($parts as $p) {
                $kv = explode('=', trim($p), 2);
                if (count($kv) == 2)
                    $params[trim($kv[0])] = trim($kv[1]);
            }
        }

        $method = isset($params['method']) ? $params['method'] : null;

        // restart — die so rx.run.php restarts with clean state
        if (isset($params['action']) && $params['action'] == 'restart') {
            echo "RESTART RECEIVED — exiting\n";
            die("\n");
        }

        $setup = $params;
        extract($params);

        // kill previous probe
        if (procpid('probe.udp')) {
            prockill(procpid('probe.udp'));
        }

        // PATHCHIRP
        if ($method === 'pathchirp') {

            $psize = isset($params['psize']) ? $params['psize'] : 1200;
            $probesize = isset($params['probesize']) ? $params['probesize'] : 60;

            $cmd = "$wdir/probe.udp.pathchirp.rx $port2 $wdir/temp.hcsv $psize $probesize";

            echo "PATHCHIRP CMD: $cmd\n";

            `rm -Rf $wdir/temp.hcsv`;
            exec($cmd . ' > /dev/null 2>&1 &');

            $lastime = tsystem();

            $client->send(array('status' => 'ok'));
            echo "ACK SENT\n";
            fflush(STDOUT);

            return;
        }

        // IGI / PROPOSED
        if ($method === 'igi' || $method === 'proposed') {

            $psize = isset($params['psize']) ? $params['psize'] : 1200;
            $probesize = isset($params['probesize']) ? $params['probesize'] : 60;

            $cmd = "$wdir/probe.udp.$method.rx $port2 $wdir/temp.hcsv $psize $probesize";

            echo "CMD: $cmd\n";

            `rm -Rf $wdir/temp.hcsv`;
            exec($cmd . ' > /dev/null 2>&1 &');

            $lastime = tsystem();

            $client->send(array('status' => 'ok'));
            echo "ACK SENT\n";
            fflush(STDOUT);

            return;
        }

        // fallback
        $client->send(array('status' => 'ok'));
        echo "FALLBACK ACK SENT\n";
        fflush(STDOUT);
    }
}

$S = new MyServer();
$S->start($port, true, 10000, 30);
?>
<?php
/** Local fake Google/WordPress contract tests. No real network or WordPress writes. */
namespace RankMath\Google {
    class Api {
        public string $token = 'fixture-not-a-real-token';
        private bool $ok = true;
        public static function get(): self { return $GLOBALS['fixture_api']; }
        public function is_success(): bool { return $this->ok; }
        public function http_get($url): array {
            $GLOBALS['gets']++;
            if (str_ends_with($url, '/dataStreams/456')) {
                return ['webStreamData' => ['measurementId' => $GLOBALS['scenario'] === 'wrong_stream' ? 'G-WRONG123' : 'G-TEST123']];
            }
            if ($GLOBALS['scenario'] === 'empty_resource') { return []; }
            if ($GLOBALS['patched'] && $GLOBALS['scenario'] === 'unknown') { $this->ok = false; return []; }
            $settings = $GLOBALS['settings'];
            if ($GLOBALS['scenario'] === 'late_lock') { $GLOBALS['lock'] = true; }
            return $settings;
        }
    }
}
namespace {
    if (($argv[1] ?? '') === '--fixture') {
        define('WP_CLI', true);
        $scenario = $argv[2];
        $kind = $argv[3];
        $lock = $scenario === 'lock'; $patched = false; $patches = 0; $gets = 0;
        $fixture_api = new \RankMath\Google\Api();
        $settings = ['name' => 'properties/123/' . ($kind === 'retention' ? 'dataRetentionSettings' : 'dataStreams/456/enhancedMeasurementSettings')];
        if ($kind === 'retention') {
            $settings += ['eventDataRetention' => $scenario === 'already' ? 'FOURTEEN_MONTHS' : 'TWO_MONTHS'];
            if ($scenario !== 'already') { $settings['resetUserDataOnNewActivity'] = true; }
        } elseif ($scenario !== 'already') {
            foreach (['pageChangesEnabled','siteSearchEnabled','formInteractionsEnabled','outboundClicksEnabled','fileDownloadsEnabled','videoEngagementEnabled','scrollsEnabled'] as $f) { $settings[$f] = true; }
        }
        function get_option($name, $default = false) {
            return $name === 'rank_math_google_analytic_options' ? ['property_id' => '123', 'view_id' => '456'] : $GLOBALS['lock'];
        }
        function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
        function wp_remote_request($url, $args) {
            $GLOBALS['patches']++; $GLOBALS['patched'] = true;
            if ($args['method'] !== 'PATCH' || $args['timeout'] !== 20 || !str_contains($url, 'updateMask=')) { throw new \RuntimeException('Invalid PATCH contract'); }
            if ($GLOBALS['scenario'] === 'denied') { return ['code' => 403, 'body' => '{"error":{"code":403}}']; }
            $GLOBALS['settings'] = ['name' => $GLOBALS['settings']['name']] + json_decode($args['body'], true);
            // Google protobuf JSON omits default false, which must still verify correctly.
            $GLOBALS['settings'] = array_filter($GLOBALS['settings'], fn($v) => $v !== false);
            return $GLOBALS['scenario'] === 'timeout' ? new \stdClass() : ['code' => 200, 'body' => '{}'];
        }
        function is_wp_error($r) { return $r instanceof \stdClass; }
        function wp_remote_retrieve_response_code($r) { return $r['code']; }
        function wp_remote_retrieve_body($r) { return $r['body']; }
        register_shutdown_function(function () {
            echo "\nFIXTURE " . json_encode(['patches' => $GLOBALS['patches'], 'gets' => $GLOBALS['gets']]) . "\n";
        });
        putenv('LCA_EXPECTED_MEASUREMENT_ID=G-TEST123');
        putenv('LCA_CONFIG_KIND=' . $kind);
        putenv('LCA_CONFIG_APPLY=' . ($scenario === 'preview' ? '0' : '1'));
        require dirname(__DIR__) . '/configure-analytics-google.php';
        exit;
    }
    $cases = [
        ['preview','retention','preview_only',0,0], ['already','retention','already_configured',0,0],
        ['already','enhanced','already_configured',0,0], ['apply','retention','verified',1,0],
        ['apply','enhanced','verified',1,0], ['timeout','enhanced','verified',1,0],
        ['denied','retention','not_applied',1,2], ['unknown','retention','outcome_unknown',1,2],
        ['lock','retention','blocked_by_ecosystem_operation',0,3],
        ['late_lock','enhanced','blocked_by_ecosystem_operation',0,3],
        ['wrong_stream','retention',null,0,255], ['empty_resource','enhanced',null,0,255],
    ];
    $failed = 0;
    foreach ($cases as [$scenario,$kind,$status,$patches,$exit]) {
        $proc = proc_open([PHP_BINARY,__FILE__,'--fixture',$scenario,$kind], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $actual_exit = proc_close($proc);
        $parts = explode("\nFIXTURE ", $out); $result = json_decode(trim($parts[0]), true);
        $stats = json_decode(trim($parts[1] ?? ''), true);
        $ok = $actual_exit === $exit && ($stats['patches'] ?? -1) === $patches && ($result['status'] ?? null) === $status;
        $ok = $ok && !str_contains($out, 'fixture-not-a-real-token');
        if ($status === 'verified') { $ok = $ok && ($stats['gets'] ?? 0) === 3; }
        if (isset($result['snapshot_path'])) {
            $path = $result['snapshot_path'];
            $backup = json_decode(file_get_contents($path), true);
            $ok = $ok && (fileperms($path) & 0777) === 0600 && !str_contains(json_encode($backup), 'token');
            // Remove only the exact fixture-created snapshot; never glob server snapshots.
            unlink($path);
        }
        echo ($ok ? 'PASS ' : 'FAIL ') . "$scenario/$kind\n";
        if (!$ok) { $failed++; echo "exit=$actual_exit status=" . ($result['status'] ?? 'none') . " patches=" . ($stats['patches'] ?? -1) . "\n"; }
    }
    echo count($cases) . " cases, $failed failures\n";
    exit($failed ? 1 : 0);
}

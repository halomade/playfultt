<?php
/**
 * RedTrack postback proxy (original-style) with dual-event logic and low-value marker
 *
 * Rules (case-sensitive types on output):
 * 1) If payout < THRESHOLD: send TWO events: LowValue (with payout + sub11=low_value) + ALSO send InitiateCheckout with payout=0.
 * 2) Else if (type/et)=Purchase: send Purchase (with payout) + ALSO send Lead with payout=0 + ALSO send InitiateCheckout with payout=0. (MODIFIED)
 * 3) Else if (type/et)=Lead: send Lead (with payout) + ALSO send Purchase with payout=0 + ALSO send InitiateCheckout with payout=0. (MODIFIED)
 * 4) Else (any other type with payout >= THRESHOLD): pass through as-is (one event).
 * 5) If type/et is missing: default to Lead.
 *
 * INPUT (GET/POST accepted; first match wins):
 * - clickid | cid | s2
 * - sum | payout
 * - type | et | s3
 * - sub12 | s1   (passthrough for reporting)
 *
 * OUTPUT (RedTrack):
 * - clickid, sum, type (Lead|Purchase|Other), sub12 (optional), sub11=low_value (optional for low-value)
 */

//////////////////////////// CONFIG ////////////////////////////
$REDTRACK_POSTBACK_BASE   = "https://xkcfj.ttrk.io/postback";
$VALUE_THRESHOLD          = 1;        // low-value threshold
$TIMEOUT_SECONDS          = 6;           // HTTP timeout

// Logging
$LOG_ENABLED              = true;
$LOG_DIR                  = __DIR__ . '/logs';
$LOG_FILE                 = $LOG_DIR . '/redtrack-postback-proxy.log';

// Masking for log safety (GDPR-ish)
$MASK_CLICKID             = true;        // true => mask clickid in logs
$CLICKID_MASK_LEFT        = 3;           // keep first n chars
$CLICKID_MASK_RIGHT       = 2;           // keep last n chars
$CLICKID_MASK_CHAR        = '*';

// Low-value marker (put on sub11)
$LOW_VALUE_MARK_SUBKEY    = 'sub11';
$LOW_VALUE_MARK_SUBVALUE  = 'low_value';

///////////////////////// UTIL FUNCTIONS ///////////////////////
function ensure_log_dir($dir) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

function log_line($file, $line) {
    global $LOG_ENABLED, $LOG_DIR;
    if (!$LOG_ENABLED) return;
    ensure_log_dir($LOG_DIR);
    @file_put_contents($file, '['.date('Y-m-d H:i:s').'] '.$line."\n", FILE_APPEND);
}

function mask_clickid_for_log($clickid) {
    global $MASK_CLICKID, $CLICKID_MASK_LEFT, $CLICKID_MASK_RIGHT, $CLICKID_MASK_CHAR;
    if (!$MASK_CLICKID || !$clickid) return $clickid;
    $len = strlen($clickid);
    if ($len <= ($CLICKID_MASK_LEFT + $CLICKID_MASK_RIGHT)) return str_repeat($CLICKID_MASK_CHAR, $len);
    $left  = substr($clickid, 0, $CLICKID_MASK_LEFT);
    $right = substr($clickid, -$CLICKID_MASK_RIGHT);
    $mid   = str_repeat($CLICKID_MASK_CHAR, $len - $CLICKID_MASK_LEFT - $CLICKID_MASK_RIGHT);
    return $left.$mid.$right;
}

function get_param($keys, $default = null) {
    foreach ((array)$keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '')  return $_GET[$k];
        if (isset($_POST[$k]) && $_POST[$k] !== '') return $_POST[$k];
    }
    return $default;
}

function is_numeric_like($v) {
    if (is_int($v) || is_float($v)) return true;
    if (is_string($v)) return is_numeric(str_replace(',', '.', $v));
    return false;
}

function safe_float($v, $def = 0.0) {
    if (!is_numeric_like($v)) return (float)$def;
    return (float)str_replace(',', '.', (string)$v);
}

function http_get_verbose($url, $timeout) {
    $out = ['ok'=>false, 'code'=>0, 'body'=>null, 'err'=>null, 'url'=>$url];

    if (function_exists('curl_init')) {
        $ch = curl_init();
        @curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'RT-Proxy-OG/1.0',
        ]);
        $body = @curl_exec($ch);
        if ($body === false) {
            $out['err']  = @curl_error($ch);
            $out['code'] = @curl_errno($ch);
        } else {
            $out['body'] = $body;
            $http_code   = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out['code'] = $http_code;
            $out['ok']   = ($http_code >= 200 && $http_code < 400);
        }
        @curl_close($ch);
        return $out;
    }

    $ctx = @stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => $timeout,
            'header'  => "Connection: close\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $out['body'] = ($body !== false) ? $body : null;
    $out['ok']   = ($body !== false);
    return $out;
}

function build_rt_url($base, array $params) {
    return $base . '?' . http_build_query($params);
}

///////////////////////// INPUT PARSING /////////////////////////
// clickid aliases
$clickid_raw = get_param(['clickid', 'cid', 's2'], null);
$clickid     = $clickid_raw ? (string)$clickid_raw : null;

// payout aliases
$payout      = safe_float(get_param(['sum', 'payout'], 0.0), 0.0);

// event type aliases (support input as type/et/s3)
$etype_raw   = get_param(['type', 'et', 's3'], null);

// passthrough subs
$sub12       = get_param(['sub12', 's1'], null);

// validation
// --- MODIFICATION HERE: Changed $payout <= 0 to $payout < 0 ---
if (!$clickid || $payout < 0) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Invalid or missing data.";
    log_line($LOG_FILE, "DROP invalid data: clickid=".mask_clickid_for_log($clickid)." payout={$payout}");
    exit;
}
// -------------------------------------------------------------

// normalize type for RedTrack (Lead/Purchase/Ucfirst)
$etype_norm = 'Lead';
if ($etype_raw !== null && $etype_raw !== '') {
    $low = strtolower(trim((string)$etype_raw));
    if ($low === 'lead')        $etype_norm = 'Lead';
    elseif ($low === 'purchase')$etype_norm = 'Purchase';
    else                        $etype_norm = ucfirst($low); // e.g., "Signup"
}

//////////////////////////// DECISION ///////////////////////////
$events = []; // each: ['clickid'=>..., 'sum'=>..., 'type'=>..., 'sub12'=>..., 'sub11'=>...]

if ($payout < $VALUE_THRESHOLD) {
    // Regula 1: low value -> TWO events: LowValue (with marker) + InitiateCheckout (payout=0)
    
    // Event 1: The original low-value conversion with the marker
    $e1 = [
        'clickid' => $clickid,
        'sum'     => $payout,
        'type'    => 'LowValue', // Type for the conversion value
    ];
    if ($sub12 !== null && $sub12 !== '') $e1['sub12'] = $sub12;
    // Add the low-value marker to the first event
    if ($LOW_VALUE_MARK_SUBKEY && $LOW_VALUE_MARK_SUBVALUE) {
        $e1[$LOW_VALUE_MARK_SUBKEY] = $LOW_VALUE_MARK_SUBVALUE;
    }
    $events[] = $e1; // Add the low-value event

    // Event 2: The zero-value secondary event (InitiateCheckout)
    $e2 = [
        'clickid' => $clickid,
        'sum'     => 0.00, // Zero payout
        'type'    => 'InitiateCheckout', 
    ];
    if ($sub12 !== null && $sub12 !== '') $e2['sub12'] = $sub12;
    $events[] = $e2; // Add the InitiateCheckout event

} else {
    // payout >= threshold
    if ($etype_norm === 'Purchase') {
        // Regula 2 - MODIFIED to fire three events
        $e1 = ['clickid'=>$clickid, 'sum'=>$payout, 'type'=>'Purchase'];          // 1. Original event with payout
        $e2 = ['clickid'=>$clickid, 'sum'=>0,       'type'=>'Lead'];              // 2. Secondary zero-payout event
        $e3 = ['clickid'=>$clickid, 'sum'=>0,       'type'=>'InitiateCheckout'];  // 3. Tertiary zero-payout event
        
        // Apply sub12 to all three events
        if ($sub12 !== null && $sub12 !== '') { 
            $e1['sub12']=$sub12; 
            $e2['sub12']=$sub12;
            $e3['sub12']=$sub12;
        }
        $events[] = $e1; $events[] = $e2; $events[] = $e3; // Add all three events

    } elseif ($etype_norm === 'Lead') {
        // Regula 3 - MODIFIED to fire three events
        $e1 = ['clickid'=>$clickid, 'sum'=>$payout, 'type'=>'Lead'];          // 1. Original event with payout
        $e2 = ['clickid'=>$clickid, 'sum'=>0,       'type'=>'Purchase'];      // 2. Secondary zero-payout event
        $e3 = ['clickid'=>$clickid, 'sum'=>0,       'type'=>'InitiateCheckout'];// 3. Tertiary zero-payout event
        
        // Apply sub12 to all three events
        if ($sub12 !== null && $sub12 !== '') { 
            $e1['sub12']=$sub12; 
            $e2['sub12']=$sub12;
            $e3['sub12']=$sub12;
        }
        $events[] = $e1; $events[] = $e2; $events[] = $e3; // Add all three events

    } else {
        // Regula 4
        $e = ['clickid'=>$clickid, 'sum'=>$payout, 'type'=>$etype_norm];
        if ($sub12 !== null && $sub12 !== '') $e['sub12'] = $sub12;
        $events[] = $e;
    }
}

///////////////////////// DISPATCH & LOG /////////////////////////
header('Content-Type: text/plain; charset=utf-8');

$total = count($events);
$idx   = 0;
foreach ($events as $ev) {
    $idx++;
    $url = build_rt_url($REDTRACK_POSTBACK_BASE, $ev);
    $res = http_get_verbose($url, $TIMEOUT_SECONDS);

    $safe_clickid = mask_clickid_for_log($clickid);
    $line = sprintf(
        "Fired[%d/%d]: clickid=%s type=%s sum=%s sub12=%s%s | URL=%s | code=%s ok=%s body=%s",
        $idx, $total,
        $safe_clickid,
        $ev['type'] ?? 'n/a',
        (string)($ev['sum'] ?? 'n/a'),
        $ev['sub12'] ?? '-',
        isset($ev[$LOW_VALUE_MARK_SUBKEY]) ? " {$LOW_VALUE_MARK_SUBKEY}=".$ev[$LOW_VALUE_MARK_SUBKEY] : '',
        $url,
        $res['code'] ?? 'n/a',
        $res['ok'] ? '1' : '0',
        substr((string)($res['body'] ?? ''), 0, 280)
    );

    log_line($LOG_FILE, $line);
    echo $line . "\n";
}

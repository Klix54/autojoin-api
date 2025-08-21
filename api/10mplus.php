<?php
header('Content-Type: application/json');

// UTC to match Discord timestamps
date_default_timezone_set('UTC');

// --- Config ---
$discordtoken = getenv('DISCORD_TOKEN') ?: '';
if (empty($discordtoken)) {
    echo json_encode(['error' => true, 'httpCode' => 500, 'message' => 'DISCORD_TOKEN env var not configured']);
    exit;
}

// If this is a BOT token, prefix with "Bot "
$authHeaderToken = (stripos($discordtoken, 'Bot ') === 0) ? $discordtoken : $discordtoken;
$authHeader = 'Authorization: ' . $authHeaderToken;

$channel = [
    'url'  => 'https://discord.com/api/v9/channels/1401775181025775738/messages?limit=1',
    'type' => 'basic',
];

// --- Parse query params ---
$morethan = isset($_GET['morethan']) && trim($_GET['morethan']) !== '' ? trim($_GET['morethan']) : null;
$threshold = null;
if ($morethan !== null) {
    $norm = str_replace([',','m','M','k','K'], ['', '000000','000000','000','000'], $morethan);
    $threshold = floatval(preg_replace('/[^0-9.]/', '', $norm));
}
$whitelistedKeyword = isset($_GET['whitelisted']) && trim($_GET['whitelisted']) !== '' ? strtolower(trim($_GET['whitelisted'])) : null;

// --- Fetch + parse one channel ---
function processChannel($url, $headers, $type, $threshold = null, $whitelistedKeyword = null) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ["error" => "cURL error: $curlErr"];
    }
    if ($httpCode != 200) {
        return ["error" => "Failed to fetch messages, HTTP $httpCode"];
    }

    $messages = json_decode($response, true);
    if (!is_array($messages) || empty($messages)) {
        return ["error" => "No active brainrots (empty/invalid message response)."];
    }

    $message = $messages[0] ?? null;
    if (!is_array($message)) {
        return ["error" => "No active brainrots (no message object)."];
    }

    if (empty($message['timestamp'])) {
        return ["error" => "No active brainrots (missing timestamp)."];
    }

    $messageTime = strtotime($message['timestamp']);
    $currentTime = time();
    $timeDiff = $currentTime - $messageTime;

    // allow 0–5s to account for latency
    if ($timeDiff < 0 || $timeDiff > 3) {
        return ["error" => "No active brainrots (timestamp outside 0–3s window: $timeDiff s)."];
    }

    $embed  = $message['embeds'][0] ?? [];
    $fields = $embed['fields'] ?? [];
    if (empty($fields) || !is_array($fields)) {
        return ["error" => "No active brainrots (no embed fields)."];
    }

    $brainrotName = '';
    $moneyPerSec  = '';
    $jobId        = '';

    foreach ($fields as $field) {
        $fname = $field['name']  ?? '';
        $fval  = $field['value'] ?? '';
        if ($fname === '' || $fval === '') continue;

        if (stripos($fname, 'Name') !== false) {
            $brainrotName = trim($fval);
        } elseif (stripos($fname, 'Money per sec') !== false) {
            $moneyPerSec = trim(str_replace('**', '', $fval));
        } elseif (stripos($fname, 'Job ID') !== false) {
            $jobId = trim(str_replace('```', '', $fval));
            $jobId = trim(preg_replace('/^`+|`+$/', '', $jobId));
        }
    }

    if ($whitelistedKeyword !== null && stripos(strtolower($brainrotName), $whitelistedKeyword) === false) {
        return ["error" => "No active brainrots (brainrot_name '$brainrotName' doesn't match '$whitelistedKeyword')."];
    }

    if ($threshold !== null) {
        $moneyValue = floatval(preg_replace('/[^0-9.]/', '', str_replace(['$', 'M', 'K', ','], ['', '000000', '000', ''], $moneyPerSec)));
        if (!is_finite($moneyValue) || $moneyValue <= $threshold) {
            return ["error" => "No active brainrots (money_per_sec $moneyValue <= threshold $threshold)."];
        }
    }

    if (!$brainrotName || !$moneyPerSec || !$jobId) {
        return ["error" => "No active brainrots (missing brainrot_name, money_per_sec, or job_id)."];
    }

    return [
        "brainrot_name" => $brainrotName,
        "money_per_sec" => $moneyPerSec,
        "job_id"        => $jobId,
        "message_time"  => $messageTime
    ];
}

// Proper header format for cURL
$headers = [
    $authHeader,                    // e.g. "Authorization: Bot <token>" or raw token if you pass it that way
    'Accept: application/json',
    'Referer: https://discord.com/channels/@me/1401775061706346536',
];

// Run
$result = processChannel($channel['url'], $headers, $channel['type'], $threshold, $whitelistedKeyword);

if (isset($result['error'])) {
    echo json_encode(["error" => $result['error']]);
    exit;
}

echo json_encode([
    "brainrot_name" => $result['brainrot_name'],
    "money_per_sec" => $result['money_per_sec'],
    "job_id"        => $result['job_id']
]);
exit;

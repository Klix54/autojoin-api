<?php
header('Content-Type: application/json');

// Use UTC to compare against Discord timestamps
date_default_timezone_set('UTC');

// --- Config ---
$discordtoken = getenv('DISCORD_TOKEN') ?: '';
if (empty($discordtoken)) {
    echo json_encode(['error' => true, 'httpCode' => 500, 'message' => 'DISCORD_TOKEN env var not configured']);
    exit;
}

$channels = [
    'https://discord.com/api/v9/channels/1401775061706346536/messages?limit=1',
    'https://discord.com/api/v9/channels/1401775125765947442/messages?limit=1',
];

// --- Parse query params ---
$morethan = isset($_GET['morethan']) && trim($_GET['morethan']) !== '' ? trim($_GET['morethan']) : null;
$threshold = null;
if ($morethan !== null) {
    // normalize like "1.2M", "350k", "900,000" → plain number
    $norm = str_replace([',', 'm', 'M', 'k', 'K'], ['', '000000', '000000', '000', '000'], $morethan);
    $threshold = floatval(preg_replace('/[^0-9.]/', '', $norm));
}

$whitelistedKeyword = isset($_GET['whitelisted']) && trim($_GET['whitelisted']) !== '' ? strtolower(trim($_GET['whitelisted'])) : null;

// --- Helpers ---
function http_get_json($url, $authToken) {
    $ch = curl_init($url);

    // Proper header format: "Header: value"
    $headers = [
        'Authorization: ' . $authToken,   // NOTE: For bot tokens use "Bot <token>"
        'Accept: application/json',
    ];

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT        => 10,
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return [null, 0, 'cURL error: ' . $err];
    }
    if ($code >= 500) {
        return [null, $code, 'Discord server error: ' . $code];
    }
    if ($code == 429) {
        return [null, $code, 'Rate limited by Discord (HTTP 429).'];
    }
    if ($code != 200) {
        return [null, $code, 'HTTP error: ' . $code];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [null, $code, 'Invalid JSON from Discord'];
    }
    return [$json, $code, null];
}

function parse_message($message, $threshold, $whitelistedKeyword) {
    if (!isset($message['timestamp'])) {
        return ['error' => 'Missing timestamp'];
    }

    $messageTime = strtotime($message['timestamp']);
    $now = time();
    $diff = $now - $messageTime;

    // allow 0–5 seconds old to be safe (Discord latency + PHP execution time)
    if ($diff < 0 || $diff > 3) {
        return ['error' => "No active brainrots (timestamp outside 0–3s window: {$diff}s)."];
    }

    $embeds = $message['embeds'] ?? [];
    if (!$embeds || !isset($embeds[0]) || !is_array($embeds[0])) {
        return ['error' => 'No embeds found.'];
    }

    $fields = $embeds[0]['fields'] ?? [];
    if (!$fields || !is_array($fields)) {
        return ['error' => 'No fields in embed.'];
    }

    $brainrotName = '';
    $moneyPerSec  = '';
    $joinScript   = '';

    foreach ($fields as $field) {
        $fname = $field['name'] ?? '';
        $fval  = $field['value'] ?? '';

        if ($fname === '' || $fval === '') continue;

        if (stripos($fname, 'Name') !== false) {
            $brainrotName = trim($fval);
        } elseif (stripos($fname, 'Money per sec') !== false) {
            $moneyPerSec = trim(str_replace('**', '', $fval));
        } elseif (stripos($fname, 'Join Script') !== false) {
            // remove markdown code fences/backticks
            $joinScript = trim(str_replace('```', '', $fval));
            // also un-inline triple backticks variants
            $joinScript = trim(preg_replace('/^`+|`+$/', '', $joinScript));
        }
    }

    if ($whitelistedKeyword !== null && stripos(strtolower($brainrotName), $whitelistedKeyword) === false) {
        return ['error' => "No active brainrots (brainrot_name '$brainrotName' does not match whitelisted '$whitelistedKeyword')."];
    }

    if ($threshold !== null) {
        // normalize "$1.2M/s", "$350K/s", "900,000/s" → float number
        $moneyValue = floatval(preg_replace('/[^0-9.]/', '', str_replace(['$', 'M', 'K', ','], ['', '000000', '000', ''], $moneyPerSec)));
        if (!is_finite($moneyValue) || $moneyValue <= $threshold) {
            return ['error' => "No active brainrots (money_per_sec {$moneyValue} <= threshold {$threshold})."];
        }
    }

    if ($brainrotName === '' || $moneyPerSec === '' || $joinScript === '') {
        return ['error' => 'No active brainrots (missing brainrot_name, money_per_sec, or join_script).'];
    }

    return [
        'ok'            => true,
        'brainrot_name' => $brainrotName,
        'money_per_sec' => $moneyPerSec,
        'join_script'   => $joinScript,
        'message_time'  => $messageTime,
    ];
}

// --- Main: find the first channel with a valid, fresh message ---
$lastError = 'Unknown error';
foreach ($channels as $url) {
    [$json, $code, $err] = http_get_json($url, $discordtoken);
    if ($err) {
        $lastError = $err;
        continue;
    }

    if (empty($json) || !is_array($json)) {
        $lastError = 'Empty message response';
        continue;
    }

    $message = $json[0] ?? null;
    if (!$message || !is_array($message)) {
        $lastError = 'No message object';
        continue;
    }

    $parsed = parse_message($message, $threshold, $whitelistedKeyword);

    if (!isset($parsed['ok'])) {
        $lastError = $parsed['error'] ?? 'Parse error';
        continue;
    }

    echo json_encode([
        'brainrot_name' => $parsed['brainrot_name'],
        'money_per_sec' => $parsed['money_per_sec'],
        'join_script'   => $parsed['join_script'],
    ]);
    exit;
}

// If no valid results
echo json_encode(['error' => $lastError]);
exit;

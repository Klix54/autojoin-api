<?php
header('Content-Type: application/json');

// Set timezone to UTC to align with Discord timestamps
date_default_timezone_set('UTC');

// Configuration for a single channel

$discordtoken = getenv('DISCORD_TOKEN') ?: '';
if (empty($discordtoken)) {
        return ['error' => true, 'httpCode' => 500, 'message' => 'discord token not configured'];
}

$channel = [
    'url' => 'https://discord.com/api/v9/channels/1401775181025775738/messages?limit=1',
    'type' => 'basic'
];

// Common headers
$headers = [
    "Authorization": $discordtoken,
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
    "Accept: */*",
    "Referer: https://discord.com/channels/@me/1401775061706346536",
];

// Parse query parameters
$morethan = isset($_GET['morethan']) && trim($_GET['morethan']) !== '' ? trim($_GET['morethan']) : null;
$threshold = null;
if ($morethan !== null) {
    $morethan = str_replace([',', 'm', 'M', 'k', 'K'], ['', '000000', '000000', '000', '000'], $morethan);
    $threshold = floatval(preg_replace('/[^0-9.]/', '', $morethan));
}
$whitelistedKeyword = isset($_GET['whitelisted']) && trim($_GET['whitelisted']) !== '' ? strtolower(trim($_GET['whitelisted'])) : null;

// Function to fetch and process a message from a channel
function processChannel($url, $headers, $type, $threshold = null, $whitelistedKeyword = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        return ["error" => "Failed to fetch brainrots, HTTP code: $httpCode"];
    }

    $messages = json_decode($response, true);
    if (empty($messages)) {
        return ["error" => "No active brainrots (empty message response)."];
    }

    $message = $messages[0];
    $messageTime = strtotime($message['timestamp']);
    $currentTime = time();
    $timeDiff = $currentTime - $messageTime;

    // Allow up to 3 seconds
    if ($timeDiff < 1 || $timeDiff > 3) {
        return ["error" => "No active brainrots (timestamp outside 1-3s window: $timeDiff seconds)."];
    }

    $embed = $message['embeds'][0] ?? [];
    $fields = $embed['fields'] ?? [];
    $brainrotName = '';
    $moneyPerSec = '';
    $jobId = '';

    // Parse fields for data
    foreach ($fields as $field) {
        if (stripos($field['name'], 'Name') !== false) {
            $brainrotName = trim($field['value']);
        }
        if (stripos($field['name'], 'Money per sec') !== false) {
            $moneyPerSec = trim(str_replace('**', '', $field['value']));
        }
        if (stripos($field['name'], 'Job ID') !== false) {
            // Remove any additional backticks
            $jobId = str_replace('```', '', $field['value']);
            $jobId = trim($jobId);
        }
    }

    // Apply whitelisted filter
    if ($whitelistedKeyword !== null && stripos(strtolower($brainrotName), $whitelistedKeyword) === false) {
        return ["error" => "No active brainrots (brainrot_name '$brainrotName' does not match whitelisted '$whitelistedKeyword')."];
    }

    // Apply money threshold filter
    if ($threshold !== null) {
        $moneyValue = floatval(preg_replace('/[^0-9.]/', '', str_replace(['$', 'M', 'K', ','], ['', '000000', '000', ''], $moneyPerSec)));
        if ($moneyValue <= $threshold) {
            return ["error" => "No active brainrots (money_per_sec $moneyValue <= threshold $threshold)."];
        }
    }

    // Ensure required fields are present
    if (!$brainrotName || !$moneyPerSec || !$jobId) {
        return ["error" => "No active brainrots (missing brainrot_name, money_per_sec, or job_id)."];
    }

    return [
        "brainrot_name" => $brainrotName,
        "money_per_sec" => $moneyPerSec,
        "job_id" => $jobId,
        "message_time" => $messageTime
    ];
}

// Process the channel
$result = processChannel($channel['url'], $headers, $channel['type'], $threshold, $whitelistedKeyword);

// Check for errors
if (isset($result['error'])) {
    echo json_encode(["error" => $result['error']]);
    exit;
}

// Return brainrot name, money per sec, and job_id
echo json_encode([
    "brainrot_name" => $result['brainrot_name'],
    "money_per_sec" => $result['money_per_sec'],
    "job_id" => $result['job_id']
]);
?>

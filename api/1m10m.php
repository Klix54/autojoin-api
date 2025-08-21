<?php
header('Content-Type: application/json');

// Set timezone to UTC to align with Discord timestamps
date_default_timezone_set('UTC');

// Configuration for multiple channels and API
$channels = [
    [
        'url' => 'https://discord.com/api/v9/channels/1401775061706346536/messages?limit=1',
        'type' => 'basic'
    ],
    [
        'url' => 'https://discord.com/api/v9/channels/1401775125765947442/messages?limit=1',
        'type' => 'basic'
    ],
    [
        'url' => 'https://brainrotss.up.railway.app/brainrots',
        'type' => 'api'
    ]
];

// Common headers for Discord API
$discordHeaders = [
    "Authorization: MTM0OTk4Njk1NzAyMjk5MDM1Mw.G69Fcz.K2Jb6LHXCoq3vnuVRwENr5SHNVz8VJRNkdOTiw", // Add your Discord token here
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

// Function to fetch and process a message from a Discord channel
function processDiscordChannel($url, $headers, $threshold = null, $whitelistedKeyword = null) {
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
    $joinScript = '';

    // Parse fields for data
    foreach ($fields as $field) {
        if (stripos($field['name'], 'Name') !== false) {
            $brainrotName = trim($field['value']);
        }
        if (stripos($field['name'], 'Money per sec') !== false) {
            $moneyPerSec = trim(str_replace('**', '', $field['value']));
        }
        if (stripos($field['name'], 'Join Script') !== false) {
            $joinScript = str_replace('```', '', $field['value']);
            $joinScript = trim($joinScript);
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
    if (!$brainrotName || !$moneyPerSec || !$joinScript) {
        return ["error" => "No active brainrots (missing brainrot_name, money_per_sec, or join_script)."];
    }

    return [
        "brainrot_name" => $brainrotName,
        "money_per_sec" => $moneyPerSec,
        "join_script" => $joinScript,
        "message_time" => $messageTime
    ];
}

// Function to process the new API
function processApi($url, $threshold = null, $whitelistedKeyword = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
        "Accept: application/json"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode != 200) {
        return ["error" => "Failed to fetch brainrots from API, HTTP code: $httpCode"];
    }

    $data = json_decode($response, true);
    if (empty($data)) {
        return ["error" => "No active brainrots from API (empty response)."];
    }

    // Get the first entry
    $entry = $data[0];
    $brainrotName = $entry['name'] ?? '';
    $moneyPerSec = $entry['moneyPerSec'] ?? '';
    $jobId = $entry['jobId'] ?? '';
    $serverId = $entry['serverId'] ?? '';
    $lastSeen = $entry['lastSeen'] ?? 0;

    // Generate join script
    $joinScript = "game:GetService(\"TeleportService\"):TeleportToPlaceInstance($serverId, \"$jobId\", game.Players.LocalPlayer)";

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
    if (!$brainrotName || !$moneyPerSec || !$jobId || !$serverId) {
        return ["error" => "No active brainrots (missing name, moneyPerSec, jobId, or serverId)."];
    }

    // Check timestamp (within 3 seconds)
    $currentTime = round(microtime(true) * 1000); // Current time in milliseconds
    $timeDiff = ($currentTime - $lastSeen) / 1000; // Convert to seconds
    if ($timeDiff < 1 || $timeDiff > 3) {
        return ["error" => "No active brainrots (timestamp outside 1-3s window: $timeDiff seconds)."];
    }

    return [
        "brainrot_name" => $brainrotName,
        "money_per_sec" => $moneyPerSec,
        "join_script" => $joinScript,
        "message_time" => $lastSeen
    ];
}

// Process all channels and API, return the first valid result
foreach ($channels as $channel) {
    if ($channel['type'] === 'api') {
        $result = processApi($channel['url'], $threshold, $whitelistedKeyword);
    } else {
        $result = processDiscordChannel($channel['url'], $discordHeaders, $threshold, $whitelistedKeyword);
    }

    // If no error, return the first valid result
    if (!isset($result['error'])) {
        echo json_encode([
            "brainrot_name" => $result['brainrot_name'],
            "money_per_sec" => $result['money_per_sec'],
            "join_script" => $result['join_script']
        ]);
        exit;
    }
}

// If no valid results from any channel or API, return the last error
echo json_encode(["error" => $result['error']]);
?>

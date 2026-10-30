<?php

$sonyUrl   = 'https://thenexusempire.com/nexusempire/sony.m3u';
$eventsUrl = 'https://thenexusempire.com/nexusempire/sonyevents.m3u';

$customGroupName = 'Nexus IND vs NZ';
$customGroupLogo = 'https://ik.imagekit.io/7henexusempire/nexusprime.png?updatedAt=1793203628547';
$creditsLine     = 'created by t.me/suxeneh7';

// --- Helper: Fetch URL with User-Agent ---
function fetchUrl($url) {
    $options = [
        'http' => [
            'method'  => 'GET',
            'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\nAccept: */*\r\n",
            'timeout' => 35
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false
        ]
    ];
    $context = stream_context_create($options);
    return @file_get_contents($url, false, $context);
}

// --- Helper: Parse M3U into channels array (preserves headers/drm tags) ---
function parseM3U($content) {
    $lines = explode("\n", str_replace("\r", "", $content));
    $channels = [];
    $currentChannel = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;

        if (strpos($line, '#EXTINF:') === 0) {
            $currentChannel = [
                'raw_extinf' => $line,
                'id'         => '',
                'name'       => '',
                'logo'       => '',
                'group'      => '',
                'group_logo' => '',
                'title'      => '',
                'extras'     => [],
                'url'        => ''
            ];

            if (preg_match('/tvg-id="([^"]*)"/', $line, $m)) $currentChannel['id'] = $m[1];
            if (preg_match('/tvg-name="([^"]*)"/', $line, $m)) $currentChannel['name'] = $m[1];
            if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) $currentChannel['logo'] = $m[1];
            if (preg_match('/group-title="([^"]*)"/', $line, $m)) $currentChannel['group'] = $m[1];
            if (preg_match('/group-logo="([^"]*)"/', $line, $m)) $currentChannel['group_logo'] = $m[1];
            if (preg_match('/,(.*)$/', $line, $m)) $currentChannel['title'] = trim($m[1]);

            if (empty($currentChannel['name'])) {
                $currentChannel['name'] = $currentChannel['title'];
            }
        } elseif ($currentChannel !== null) {
            if ($line[0] === '#') {
                // Stores intermediate lines (e.g. #EXTVLCOPT, #EXTHTTP, #KODIPROP)
                $currentChannel['extras'][] = $line;
            } else {
                $currentChannel['url'] = $line;
                $channels[] = $currentChannel;
                $currentChannel = null;
            }
        }
    }
    return $channels;
}

// --- Filter: Sony Liv Sports 1, 2, 4, 5 (Hindi, Kannada, etc.) excluding 3 ---
function isTargetChannel($channel) {
    $text = strtolower($channel['name'] . ' ' . $channel['title']);

    // Check if it's related to Sony / Sports / LIV / Ten
    $isSony = (
        strpos($text, 'sony') !== false ||
        strpos($text, 'liv') !== false ||
        strpos($text, 'sports') !== false ||
        strpos($text, 'ten') !== false
    );

    // Matches numbers 1, 2, 4, 5 (e.g., Sports 1, Ten 2, LIV 4, 5 Kannada/Hindi)
    $hasTargetNum = preg_match('/\b[1245]\b|(?:sports|ten|liv)\s*[1245]/i', $text);
    $hasThreeOnly = preg_match('/\b3\b|(?:sports|ten|liv)\s*3/i', $text) && !$hasTargetNum;

    return ($isSony && $hasTargetNum && !$hasThreeOnly);
}

// 1. Fetch playlists
echo "Fetching primary playlist: $sonyUrl\n";
$sonyContent = fetchUrl($sonyUrl);
$sonyChannels = !empty($sonyContent) ? parseM3U($sonyContent) : [];
echo "Fetched " . count($sonyChannels) . " channels from primary playlist.\n";

echo "Fetching events playlist: $eventsUrl\n";
$eventsContent = fetchUrl($eventsUrl);
$eventsChannels = !empty($eventsContent) ? parseM3U($eventsContent) : [];
echo "Fetched " . count($eventsChannels) . " channels from events playlist.\n";

// 2. Filter & format events channels
$filteredEventChannels = [];
foreach ($eventsChannels as $channel) {
    if (isTargetChannel($channel)) {
        // Set new category & group logo; preserve original tvg-logo
        $channel['group'] = $customGroupName;
        $channel['group_logo'] = $customGroupLogo;

        // Rebuild #EXTINF line
        $extinfParts = ['#EXTINF:-1'];
        if (!empty($channel['id'])) $extinfParts[] = 'tvg-id="' . $channel['id'] . '"';
        if (!empty($channel['name'])) $extinfParts[] = 'tvg-name="' . $channel['name'] . '"';
        if (!empty($channel['logo'])) $extinfParts[] = 'tvg-logo="' . $channel['logo'] . '"'; // kept untouched
        $extinfParts[] = 'group-title="' . $channel['group'] . '"';
        $extinfParts[] = 'group-logo="' . $channel['group_logo'] . '"';

        $title = !empty($channel['title']) ? $channel['title'] : $channel['name'];
        $channel['raw_extinf'] = implode(' ', $extinfParts) . ',' . $title;

        $filteredEventChannels[] = $channel;
    }
}
echo "Filtered " . count($filteredEventChannels) . " target channels from events.\n";

// 3. Merge playlists (filtered event channels added first under the new category)
$allChannels = array_merge($filteredEventChannels, $sonyChannels);

// 4. Build playlist.m3u with credits on top
$m3uOutput  = "#EXTM3U\n";
$m3uOutput .= "##############################################################\n";
$m3uOutput .= "# " . $creditsLine . "\n";
$m3uOutput .= "##############################################################\n\n";

foreach ($allChannels as $ch) {
    $m3uOutput .= $ch['raw_extinf'] . "\n";
    foreach ($ch['extras'] as $extra) {
        $m3uOutput .= $extra . "\n";
    }
    $m3uOutput .= $ch['url'] . "\n";
}

file_put_contents('playlist.m3u', $m3uOutput);
echo "Successfully generated playlist.m3u.\n";

// 5. Build playlist.json
$cleanChannels = array_map(function($ch) {
    return [
        'id'         => $ch['id'],
        'name'       => $ch['name'],
        'title'      => $ch['title'],
        'tvg_logo'   => $ch['logo'],
        'group'      => $ch['group'],
        'group_logo' => $ch['group_logo'],
        'url'        => $ch['url']
    ];
}, $allChannels);

$jsonData = [
    'credits'    => $creditsLine,
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'total'      => count($cleanChannels),
    'channels'   => $cleanChannels
];

file_put_contents('playlist.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Successfully generated playlist.json with " . count($cleanChannels) . " total items.\n";

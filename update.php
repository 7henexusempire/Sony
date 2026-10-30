<?php

// Only 1 source URL
$sourceUrl = 'https://thenexusempire.com/nexusempire/sony.m3u';

$customGroupName = 'Nexus IND vs NZ';
$customGroupLogo = 'https://ik.imagekit.io/7henexusempire/nexusprime.png?updatedAt=1793203628547';
$creditsLine     = 'created by t.me/suxeneh7';

// --- 1. Fetch source M3U ---
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
$content = @file_get_contents($sourceUrl, false, $context);

if ($content === false || empty(trim($content))) {
    fwrite(STDERR, "Error: Failed to fetch source M3U from $sourceUrl\n");
    exit(1);
}

// --- 2. Parse all channels (preserves headers/drm tags) ---
$lines = explode("\n", str_replace("\r", "", $content));
$allChannels = [];
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
            // Intermediate tags like #EXTVLCOPT, #EXTHTTP, #KODIPROP
            $currentChannel['extras'][] = $line;
        } else {
            $currentChannel['url'] = $line;
            $allChannels[] = $currentChannel;
            $currentChannel = null;
        }
    }
}

echo "Fetched " . count($allChannels) . " total channels.\n";

// --- Helper: Build M3U string ---
function buildM3uString($channels, $credits) {
    $out  = "#EXTM3U\n";
    $out .= "##############################################################\n";
    $out .= "# " . $credits . "\n";
    $out .= "##############################################################\n\n";

    foreach ($channels as $ch) {
        $out .= $ch['raw_extinf'] . "\n";
        foreach ($ch['extras'] as $extra) {
            $out .= $extra . "\n";
        }
        $out .= $ch['url'] . "\n";
    }
    return $out;
}

// --- 3. Generate playlist.m3u & playlist.json (ALL CONTENT) ---
file_put_contents('playlist.m3u', buildM3uString($allChannels, $creditsLine));

$allJsonData = [
    'credits'    => $creditsLine,
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'total'      => count($allChannels),
    'channels'   => array_map(function($ch) {
        return [
            'id'         => $ch['id'],
            'name'       => $ch['name'],
            'title'      => $ch['title'],
            'tvg_logo'   => $ch['logo'],
            'group'      => $ch['group'],
            'group_logo' => $ch['group_logo'],
            'url'        => $ch['url']
        ];
    }, $allChannels)
];
file_put_contents('playlist.json', json_encode($allJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Generated playlist.m3u and playlist.json\n";

// --- 4. Filter for Sony LIV Sports / Ten (1, 2, 3, 4, 5, Hindi, Kannada, etc.) ---
$eventChannels = [];
foreach ($allChannels as $channel) {
    $text = strtolower($channel['name'] . ' ' . $channel['title']);

    // Match Sony / LIV channels that are Sports / Ten / Numbers 1-5
    $isSonyOrLiv = (strpos($text, 'sony') !== false || strpos($text, 'liv') !== false);
    $isSports    = preg_match('/(?:sports|ten|six|[1-5])/i', $text);

    if ($isSonyOrLiv && $isSports) {
        // Set custom category and group-logo (tvg-logo remains untouched)
        $channel['group'] = $customGroupName;
        $channel['group_logo'] = $customGroupLogo;

        // Re-construct the EXTINF tag with the new group attributes
        $parts = ['#EXTINF:-1'];
        if (!empty($channel['id'])) $parts[] = 'tvg-id="' . $channel['id'] . '"';
        if (!empty($channel['name'])) $parts[] = 'tvg-name="' . $channel['name'] . '"';
        if (!empty($channel['logo'])) $parts[] = 'tvg-logo="' . $channel['logo'] . '"'; // untouched original logo
        $parts[] = 'group-title="' . $channel['group'] . '"';
        $parts[] = 'group-logo="' . $channel['group_logo'] . '"';

        $title = !empty($channel['title']) ? $channel['title'] : $channel['name'];
        $channel['raw_extinf'] = implode(' ', $parts) . ',' . $title;

        $eventChannels[] = $channel;
    }
}

// --- 5. Generate sonyevents.m3u & sonyevents.json (EVENTS ONLY) ---
file_put_contents('sonyevents.m3u', buildM3uString($eventChannels, $creditsLine));

$eventsJsonData = [
    'credits'    => $creditsLine,
    'category'   => $customGroupName,
    'group_logo' => $customGroupLogo,
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'total'      => count($eventChannels),
    'channels'   => array_map(function($ch) {
        return [
            'id'         => $ch['id'],
            'name'       => $ch['name'],
            'title'      => $ch['title'],
            'tvg_logo'   => $ch['logo'],
            'group'      => $ch['group'],
            'group_logo' => $ch['group_logo'],
            'url'        => $ch['url']
        ];
    }, $eventChannels)
];
file_put_contents('sonyevents.json', json_encode($eventsJsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Generated sonyevents.m3u and sonyevents.json with " . count($eventChannels) . " event channels.\n";

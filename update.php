<?php

$sourceUrl = 'https://thenexusempire.com/nexusempire/sony.m3u';

$customGroupName = 'Nexus IND vs NZ';
$customGroupLogo = 'https://ik.imagekit.io/7henexusempire/nexusprime.png?updatedAt=1793203628547';
$creditsLine     = 'created by t.me/suxeneh7';

/**
 * Smart IPTV Player Request Engine
 * Simulates genuine TiviMate, OTT Navigator & ExoPlayer hardware headers
 */
function smartIptvFetch($url) {
    $playerProfiles = [
        'TiviMate_AndroidTV' => [
            'ua' => 'TiviMate/5.0.4 (Linux; Android 12; BRAVIA 4K Build/BRAVIA_ATV3_EU) ExoPlayerLib/2.19.1',
            'headers' => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'Connection: Keep-Alive',
                'User-Agent: TiviMate/5.0.4'
            ]
        ],
        'OTT_Navigator' => [
            'ua' => 'OTT Navigator/1.6.7.4 (Linux; Android 11; SHIELD Android TV Build/RQ1A.210105.003)',
            'headers' => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'Accept-Language: en-US,en;q=0.9,hi;q=0.8',
                'Connection: Keep-Alive'
            ]
        ],
        'ExoPlayer_Direct' => [
            'ua' => 'ExoPlayerLib/2.18.5 (Linux; Android 11) okhttp/4.9.2',
            'headers' => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'Icy-MetaData: 1'
            ]
        ],
        'Chrome_AndroidTV' => [
            'ua' => 'Mozilla/5.0 (Linux; Android 12; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'headers' => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/x-mpegURL,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Upgrade-Insecure-Requests: 1'
            ]
        ]
    ];

    $cookieJar = tempnam(sys_get_temp_dir(), 'iptv_cookie_');

    foreach ($playerProfiles as $name => $profile) {
        echo ">> Trying fetch with profile: [{$name}]...\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_AUTOREFERER    => true,
            CURLOPT_USERAGENT      => $profile['ua'],
            CURLOPT_HTTPHEADER     => $profile['headers'],
            CURLOPT_ENCODING       => '', // Automatically decodes gzip, deflate, and brotli
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 35,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_COOKIEJAR      => $cookieJar,
            CURLOPT_COOKIEFILE     => $cookieJar,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
            // Strip UTF-8 BOM if present
            $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
            if (stripos($response, '#EXT') !== false) {
                echo "Successfully fetched playlist! (" . strlen($response) . " bytes)\n";
                @unlink($cookieJar);
                return $response;
            } else {
                echo "Warning: Response received but does not contain valid M3U tags.\n";
            }
        } else {
            echo "Failed with [{$name}]. HTTP Status: {$httpCode} | Error: {$error}\n";
            if (!empty($response)) {
                $snippet = substr(trim(strip_tags($response)), 0, 140);
                echo "   Server response preview: \"{$snippet}\"\n";
            }
        }

        // Brief delay before trying next player profile
        usleep(400000);
    }

    @unlink($cookieJar);
    return false;
}

// --- 1. Execute Smart Fetch ---
$content = smartIptvFetch($sourceUrl);

if (!$content) {
    fwrite(STDERR, "FATAL ERROR: All player profiles were rejected by the remote server.\n");
    exit(1);
}

// --- 2. Parse all channels ---
$lines = preg_split("/\r\n|\n|\r/", $content);
$allChannels = [];
$currentChannel = null;

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    if (stripos($line, '#EXTINF:') === 0) {
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

        if (preg_match('/tvg-id="([^"]*)"/i', $line, $m)) $currentChannel['id'] = $m[1];
        if (preg_match('/tvg-name="([^"]*)"/i', $line, $m)) $currentChannel['name'] = $m[1];
        if (preg_match('/tvg-logo="([^"]*)"/i', $line, $m)) $currentChannel['logo'] = $m[1];
        if (preg_match('/group-title="([^"]*)"/i', $line, $m)) $currentChannel['group'] = $m[1];
        if (preg_match('/group-logo="([^"]*)"/i', $line, $m)) $currentChannel['group_logo'] = $m[1];
        if (preg_match('/,(.*)$/', $line, $m)) $currentChannel['title'] = trim($m[1]);

        if (empty($currentChannel['name'])) {
            $currentChannel['name'] = $currentChannel['title'];
        }
    } elseif ($currentChannel !== null) {
        if ($line[0] === '#') {
            $currentChannel['extras'][] = $line;
        } else {
            $currentChannel['url'] = $line;
            $allChannels[] = $currentChannel;
            $currentChannel = null;
        }
    }
}

echo "Parsed " . count($allChannels) . " total channels from source.\n";

// --- Helper: Build M3U file string ---
function buildM3u($channels, $credits) {
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

// --- 3. Save playlist.m3u & playlist.json (All Channels) ---
file_put_contents('playlist.m3u', buildM3u($allChannels, $creditsLine));

$allJson = [
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
file_put_contents('playlist.json', json_encode($allJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Generated playlist.m3u and playlist.json\n";

// --- 4. Filter for Sony LIV Sports / Ten (1, 2, 3, 4, 5, regional Hindi, Kannada, etc.) ---
$eventChannels = [];
foreach ($allChannels as $channel) {
    $text = strtolower($channel['name'] . ' ' . $channel['title']);

    // Check if it's Sony or LIV
    $isSonyOrLiv = (strpos($text, 'sony') !== false || strpos($text, 'liv') !== false);
    
    // Check if it's Sports/Ten/Six/Cricket/Event numbered channels
    $isSports = (
        strpos($text, 'sports') !== false ||
        strpos($text, 'ten') !== false ||
        strpos($text, 'six') !== false ||
        strpos($text, 'cricket') !== false ||
        preg_match('/(?:liv|sports|ten)\s*[1-5]/i', $text)
    );

    // Filter out standard entertainment channels (Sony MAX, SAB, Pal, etc.) unless they are Sports
    $isEntertainment = (
        (strpos($text, 'max') !== false || strpos($text, 'sab') !== false || strpos($text, 'pal') !== false || strpos($text, 'yay') !== false || strpos($text, 'wah') !== false)
        && strpos($text, 'sports') === false
    );

    if ($isSonyOrLiv && $isSports && !$isEntertainment) {
        // Assign the new group attributes while preserving tvg-logo
        $channel['group']      = $customGroupName;
        $channel['group_logo'] = $customGroupLogo;

        $parts = ['#EXTINF:-1'];
        if (!empty($channel['id'])) $parts[] = 'tvg-id="' . $channel['id'] . '"';
        if (!empty($channel['name'])) $parts[] = 'tvg-name="' . $channel['name'] . '"';
        if (!empty($channel['logo'])) $parts[] = 'tvg-logo="' . $channel['logo'] . '"'; // Untouched original logo
        $parts[] = 'group-title="' . $channel['group'] . '"';
        $parts[] = 'group-logo="' . $channel['group_logo'] . '"';

        $title = !empty($channel['title']) ? $channel['title'] : $channel['name'];
        $channel['raw_extinf'] = implode(' ', $parts) . ',' . $title;

        $eventChannels[] = $channel;
    }
}

// --- 5. Save sonyevents.m3u & sonyevents.json (Events Only) ---
file_put_contents('sonyevents.m3u', buildM3u($eventChannels, $creditsLine));

$eventsJson = [
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
file_put_contents('sonyevents.json', json_encode($eventsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "Generated sonyevents.m3u and sonyevents.json with " . count($eventChannels) . " event channels.\n";

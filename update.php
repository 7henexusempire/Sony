<?php

$sourceUrl = 'https://thenexusempire.com/nexusempire/sony.m3u';

$customGroupName = 'Nexus IND vs NZ';
$customGroupLogo = 'https://ik.imagekit.io/7henexusempire/nexusprime.png?updatedAt=1793203628547';
$creditsLine     = 'created by t.me/suxeneh7';

// --- 1. Helper: Resolve relative stream URLs to absolute URLs ---
function resolveUrl($relative, $base) {
    if (empty($relative) || preg_match('~^(?:f|ht)tps?://~i', $relative)) {
        return $relative;
    }
    $baseParts = parse_url($base);
    if (strpos($relative, '//') === 0) {
        return $baseParts['scheme'] . ':' . $relative;
    }
    if ($relative[0] === '/') {
        return $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '') . $relative;
    }
    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('~/[^/]*$~', '/', $path);
    return $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '') . $dir . $relative;
}

// --- 2. Smart IPTV Player Fetcher Engine ---
function smartIptvFetch($url) {
    $playerProfiles = [
        'TiviMate' => [
            'ua' => 'TiviMate/5.0.4 (Linux; Android 12; BRAVIA 4K Build/BRAVIA_ATV3_EU) ExoPlayerLib/2.19.1',
            'headers' => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'Connection: Keep-Alive',
                'User-Agent: TiviMate/5.0.4'
            ]
        ],
        'OTT_Navigator' => [
            'ua' => 'OTT Navigator/1.6.7.4 (Linux; Android 11; Mobile)',
            'headers' => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'Connection: Keep-Alive'
            ]
        ],
        'ExoPlayer' => [
            'ua' => 'ExoPlayerLib/2.18.5 (Linux; Android 11) okhttp/4.9.2',
            'headers' => ['Accept: */*']
        ]
    ];

    $cookieJar = tempnam(sys_get_temp_dir(), 'iptv_cookie_');

    foreach ($playerProfiles as $name => $profile) {
        echo ">> Fetching using profile: [{$name}]...\n";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_USERAGENT      => $profile['ua'],
            CURLOPT_HTTPHEADER     => $profile['headers'],
            CURLOPT_ENCODING       => '',
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
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && !empty($response)) {
            $response = preg_replace('/^\xEF\xBB\xBF/', '', $response);
            if (stripos($response, '#EXT') !== false) {
                echo "Successfully fetched playlist! (" . strlen($response) . " bytes)\n";
                @unlink($cookieJar);
                return $response;
            }
        }
        usleep(300000);
    }

    @unlink($cookieJar);
    return false;
}

$rawContent = smartIptvFetch($sourceUrl);

if (!$rawContent) {
    fwrite(STDERR, "Error: Failed to fetch M3U from {$sourceUrl}\n");
    exit(1);
}

// --- 3. Parse channels and preserve DRM tags ---
$lines = preg_split("/\r\n|\n|\r/", $rawContent);
$rawChannels = [];

$pendingPreTags = [];
$currentExtinf = null;
$pendingPostTags = [];

foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line)) continue;

    if (stripos($line, '#EXTM3U') === 0) continue;

    if (stripos($line, '#EXTINF:') === 0) {
        $currentExtinf = $line;
        $pendingPostTags = [];
    } elseif ($line[0] === '#') {
        if ($currentExtinf === null) {
            $pendingPreTags[] = $line;
        } else {
            $pendingPostTags[] = $line;
        }
    } else {
        if ($currentExtinf !== null) {
            $fullUrl = resolveUrl($line, $sourceUrl);

            $ch = [
                'pre_tags'   => $pendingPreTags,
                'extinf'     => $currentExtinf,
                'post_tags'  => $pendingPostTags,
                'url'        => $fullUrl,
                'id'         => '',
                'name'       => '',
                'logo'       => '',
                'group'      => '',
                'group_logo' => '',
                'title'      => ''
            ];

            if (preg_match('/tvg-id="([^"]*)"/i', $currentExtinf, $m)) $ch['id'] = $m[1];
            if (preg_match('/tvg-name="([^"]*)"/i', $currentExtinf, $m)) $ch['name'] = $m[1];
            if (preg_match('/tvg-logo="([^"]*)"/i', $currentExtinf, $m)) $ch['logo'] = $m[1];
            if (preg_match('/group-title="([^"]*)"/i', $currentExtinf, $m)) $ch['group'] = $m[1];
            if (preg_match('/group-logo="([^"]*)"/i', $currentExtinf, $m)) $ch['group_logo'] = $m[1];
            if (preg_match('/,(.*)$/', $currentExtinf, $m)) $ch['title'] = trim($m[1]);
            if (empty($ch['name'])) $ch['name'] = $ch['title'];

            $rawChannels[] = $ch;

            $pendingPreTags = [];
            $currentExtinf = null;
            $pendingPostTags = [];
        }
    }
}

// --- 4. Deduplication Function ---
function deduplicateList($list) {
    $unique = [];
    $seenUrls = [];
    $seenTitles = [];

    foreach ($list as $ch) {
        $url = trim($ch['url']);
        $cleanTitle = strtolower(trim(preg_replace('/\s+/', ' ', $ch['title'])));

        if (empty($url)) continue;
        if (isset($seenUrls[$url])) continue;
        if (!empty($cleanTitle) && isset($seenTitles[$cleanTitle])) continue;

        $seenUrls[$url] = true;
        if (!empty($cleanTitle)) $seenTitles[$cleanTitle] = true;

        $unique[] = $ch;
    }
    return $unique;
}

$allChannels = deduplicateList($rawChannels);
echo "Total unique channels: " . count($allChannels) . "\n";

// --- Helper: Render M3U output ---
function renderM3u($channels, $credits) {
    $out  = "#EXTM3U\n";
    $out .= "##############################################################\n";
    $out .= "# " . $credits . "\n";
    $out .= "##############################################################\n\n";

    foreach ($channels as $ch) {
        foreach ($ch['pre_tags'] as $pre) $out .= $pre . "\n";
        $out .= $ch['extinf'] . "\n";
        foreach ($ch['post_tags'] as $post) $out .= $post . "\n";
        $out .= $ch['url'] . "\n";
    }
    return $out;
}

// --- Helper: Apply custom group title and group-logo ---
function applyGroupBranding($ch, $groupName, $groupLogo) {
    $extinf = $ch['extinf'];

    if (preg_match('/group-title="[^"]*"/i', $extinf)) {
        $extinf = preg_replace('/group-title="[^"]*"/i', 'group-title="' . $groupName . '"', $extinf);
    } else {
        $extinf = preg_replace('/,(.*)$/', ' group-title="' . $groupName . '",$1', $extinf);
    }

    if (preg_match('/group-logo="[^"]*"/i', $extinf)) {
        $extinf = preg_replace('/group-logo="[^"]*"/i', 'group-logo="' . $groupLogo . '"', $extinf);
    } else {
        $extinf = preg_replace('/,(.*)$/', ' group-logo="' . $groupLogo . '",$1', $extinf);
    }

    $ch['extinf']     = $extinf;
    $ch['group']      = $groupName;
    $ch['group_logo'] = $groupLogo;
    return $ch;
}

// --- 5. Generate playlist.m3u & playlist.json (ALL CHANNELS) ---
file_put_contents('playlist.m3u', renderM3u($allChannels, $creditsLine));
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

// --- 6. Filter for t20i.m3u & t20i.json ---
$t20iChannels = [];

foreach ($allChannels as $ch) {
    $text = strtolower($ch['name'] . ' ' . $ch['title']);

    // 1. HARD EXCLUSIONS: Block Sony Yay (including Sony Yay Tamil), all Sony LIV Sports linear channels, and entertainment
    if (
        strpos($text, 'yay') !== false || 
        strpos($text, 'liv sports') !== false || 
        strpos($text, 'sony liv sports') !== false ||
        strpos($text, 'sab') !== false ||
        strpos($text, 'max') !== false ||
        strpos($text, 'pal') !== false ||
        strpos($text, 'wah') !== false
    ) {
        continue;
    }

    // 2. Match: 4th T20 event streams (Hindi, Kannada, Tamil, Telugu, English feeds)
    $is4thT20 = (
        strpos($text, '4th t20') !== false ||
        (strpos($text, 't20') !== false && strpos($text, '4th') !== false) ||
        (strpos($text, 't20') !== false && (strpos($text, 'ind') !== false || strpos($text, 'india') !== false) && (strpos($text, 'nz') !== false || strpos($text, 'new zealand') !== false))
    );

    // 3. Match: Specific Sony TEN linear channels: TEN 1 HD, TEN 3 HD, TEN 4 (Tamil), TEN 5 (Kannada)
    $isSonyTenTarget = (
        strpos($text, 'ten') !== false &&
        preg_match('/\b(?:ten\s*1|ten\s*3|ten\s*4|ten\s*5|ten1|ten3|ten4|ten5)\b/i', $text)
    );

    if ($is4thT20 || $isSonyTenTarget) {
        $ch = applyGroupBranding($ch, $customGroupName, $customGroupLogo);
        $t20iChannels[] = $ch;
    }
}

$t20iChannels = deduplicateList($t20iChannels);

file_put_contents('t20i.m3u', renderM3u($t20iChannels, $creditsLine));
$t20iJson = [
    'credits'    => $creditsLine,
    'category'   => $customGroupName,
    'group_logo' => $customGroupLogo,
    'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'total'      => count($t20iChannels),
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
    }, $t20iChannels)
];
file_put_contents('t20i.json', json_encode($t20iJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Generated t20i.m3u and t20i.json with " . count($t20iChannels) . " channels.\n";

// --- 7. Generate sonyevents.m3u & sonyevents.json ---
$eventChannels = [];
foreach ($allChannels as $ch) {
    $text = strtolower($ch['name'] . ' ' . $ch['title']);
    $isSonyOrLiv = (strpos($text, 'sony') !== false || strpos($text, 'liv') !== false);
    $isSports    = (strpos($text, 'sports') !== false || strpos($text, 'ten') !== false || strpos($text, 'cricket') !== false || preg_match('/(?:liv|sports|ten)\s*[1-5]/i', $text));
    $isEntertainment = ((strpos($text, 'max') !== false || strpos($text, 'sab') !== false || strpos($text, 'pal') !== false || strpos($text, 'yay') !== false || strpos($text, 'wah') !== false) && strpos($text, 'sports') === false);

    if ($isSonyOrLiv && $isSports && !$isEntertainment) {
        $ch = applyGroupBranding($ch, $customGroupName, $customGroupLogo);
        $eventChannels[] = $ch;
    }
}
$eventChannels = deduplicateList($eventChannels);
file_put_contents('sonyevents.m3u', renderM3u($eventChannels, $creditsLine));
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
echo "Generated sonyevents.m3u and sonyevents.json with " . count($eventChannels) . " channels.\n";

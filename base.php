<?php

// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

/**
 * ========= SETTINGS =========
 */
// قابلیت پینگ‌گیری و فیلتر کانفیگ‌ها. (برای فعال شدن به "yes" تغییر دهید)
$ENABLE_PING = "no"; 

/**
 * ========= Telegram pagination helpers (3 pages) =========
 */
function extractMinPostIdFromTelegramHtml(string $channel, string $html): ?int
{
    $ids = [];

    // data-post="channel/12345"
    if (preg_match_all('~data-post="' . preg_quote($channel, "~") . '/(\d+)"~', $html, $m)) {
        foreach ($m[1] as $id) $ids[] = (int)$id;
    }

    // fallback: /channel/12345
    if (empty($ids) && preg_match_all("~/" . preg_quote($channel, "~") . "/(\d+)~", $html, $m2)) {
        foreach ($m2[1] as $id) $ids[] = (int)$id;
    }

    if (empty($ids)) return null;

    return min($ids); // oldest post id in this page
}

//page
function fetchTelegramChannelHtmlPages(string $channel, int $pages = 2): string
{
    $pages = max(1, min(10, $pages));
    $allHtml = "";
    $before = null;

    $ctx = stream_context_create([
        "http" => [
            "header"  => "User-Agent: Mozilla/5.0\r\n",
            "timeout" => 15,
        ],
    ]);

    for ($p = 1; $p <= $pages; $p++) {
        $url = "https://t.me/s/" . $channel;
        if ($before !== null) $url .= "?before=" . $before;

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false || trim($html) === "") break;

        $allHtml .= "\n<!-- PAGE {$p} -->\n" . $html;

        $minId = extractMinPostIdFromTelegramHtml($channel, $html);
        if ($minId === null) break;

        $before = $minId;

        // light anti rate-limit
        usleep(250000); // 0.25s
    }

    return $allHtml;
}

/**
 * ========= REAL DEDUP (across all channels) =========
 */
function sanitizeConfigString(string $config): string
{
    $config = removeAngleBrackets($config);
    $config = str_replace("amp;", "", $config);
    return trim($config);
}

function buildDedupKeyFromRawConfig(string $rawConfig, string $type): ?string
{
    $clean = sanitizeConfigString($rawConfig);
    if ($clean === "") return null;

    $parsed = configParse($clean, $type);
    if (empty($parsed) || !is_array($parsed)) return null;

    // VMESS: ignore ps, keep true identity fields
    if ($type === "vmess") {
        $identity = [
            "v"    => $parsed["v"]    ?? "",
            "id"   => $parsed["id"]   ?? "",
            "aid"  => $parsed["aid"]  ?? "",
            "add"  => $parsed["add"]  ?? "",
            "port" => $parsed["port"] ?? "",
            "net"  => $parsed["net"]  ?? "",
            "type" => $parsed["type"] ?? "",
            "tls"  => $parsed["tls"]  ?? "",
            "sni"  => $parsed["sni"]  ?? "",
            "host" => $parsed["host"] ?? "",
            "path" => $parsed["path"] ?? "",
            "alpn" => $parsed["alpn"] ?? "",
            "fp"   => $parsed["fp"]   ?? "",
            "scy"  => $parsed["scy"]  ?? "",
            "flow" => $parsed["flow"] ?? "",
        ];
        ksort($identity);
        return "vmess:" . md5(json_encode($identity));
    }

    // SS: ignore #name
    if ($type === "ss") {
        $identity = [
            "server_address"    => $parsed["server_address"]    ?? "",
            "server_port"       => $parsed["server_port"]       ?? "",
            "encryption_method" => $parsed["encryption_method"] ?? "",
            "password"          => $parsed["password"]          ?? "",
        ];
        ksort($identity);
        return "ss:" . md5(json_encode($identity));
    }

    $params = $parsed["params"] ?? [];
    if (!is_array($params)) $params = [];

    unset(
        $params["name"], $params["ps"], $params["hash"],
        $params["remark"], $params["remarks"], $params["title"]
    );
    ksort($params);

    $identity = [
        "protocol" => $type,
        "username" => $parsed["username"] ?? "",
        "hostname" => $parsed["hostname"] ?? "",
        "port"     => $parsed["port"] ?? "",
        "pass"     => $parsed["pass"] ?? "",
        "params"   => $params,
    ];
    return $type . ":" . md5(json_encode($identity));
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

function getTelegramChannelConfigs($username)
{
    global $ENABLE_PING; // دسترسی به تنظیمات پینگ

    $sourceArray = array_filter(array_map("trim", explode(",", $username)));

    $typeBuckets = [
        "mix" => "",
        "vmess" => "",
        "vless" => "",
        "trojan" => "",
        "ss" => "",
        "tuic" => "",
        "hysteria" => "",
        "hysteria2" => "",
    ];
    $sourceBuckets = []; 

    $seen = []; 

    foreach ($sourceArray as $source) {
        echo "@{$source} => PROGRESS: 0%\n";

        $html = fetchTelegramChannelHtmlPages($source, 2);

        $types = [
            "vmess",
            "vless",
            "trojan",
            "ss",
            "tuic",
            "hysteria",
            "hysteria2",
            "hy2",
        ];

        $configs = [];
        foreach ($types as $type) {
            if ($type === "hy2") {
                $configs["hysteria2"] = array_merge(
                    getConfigItems($type, $html),
                    $configs["hysteria2"] ?? []
                );
            } else {
                $configs[$type] = array_unique(getConfigItems($type, $html));
            }
        }

        echo "@{$source} => PROGRESS: 50%\n";

        $sourceBuckets[$source] = "";

        foreach ($configs as $theType => $configsArray) {
            foreach ($configsArray as $config) {
                if (!is_valid($config)) continue;

                $fixedConfig = sanitizeConfigString($config);
                if ($fixedConfig === "") continue;

                $dedupKey = buildDedupKeyFromRawConfig($fixedConfig, $theType);
                if ($dedupKey === null) continue;

                if (isset($seen[$dedupKey])) {
                    continue; 
                }
                $seen[$dedupKey] = true;

                $parsedConfig = configParse($fixedConfig, $theType);
                if ($parsedConfig === null) continue;

                $configsIpName = [
                    "vmess" => "add", "vless" => "hostname", "trojan" => "hostname",
                    "tuic" => "hostname", "hysteria" => "hostname", "hysteria2" => "hostname",
                    "hy2" => "hostname", "ss" => "server_address"
                ];
                $configsPortName = [
                    "vmess" => "port", "vless" => "port", "trojan" => "port",
                    "tuic" => "port", "hysteria" => "port", "hysteria2" => "port",
                    "hy2" => "port", "ss" => "server_port"
                ];

                $configIpName = $configsIpName[$theType];
                $configPortName = $configsPortName[$theType];

                $configIp = $parsedConfig[$configIpName] ?? "";
                $configPort = $parsedConfig[$configPortName] ?? "";

                $latency = "N/A";
                
                // بررسی روشن یا خاموش بودن قابلیت پینگ
                if (strtolower($ENABLE_PING) === "yes") {
                    $latency = ($configIp !== "" && $configPort !== "") ? ping($configIp, $configPort, 1) : "N/A";

                    // ==========================================
                    // ✅ فیلتر شرایط ایران: فقط کانفیگ‌های down
                    // ==========================================
                    if ($latency !== "down" && $latency !== "N/A") {
                        continue; // هر کانفیگی که پینگ موفق داشته باشد را دور می‌ریزیم
                    }
                }

                $correctedConfig = correctConfig($fixedConfig, $theType, $source, $latency);

                $typeBuckets["mix"] .= $correctedConfig . "\n";
                if (isset($typeBuckets[$theType])) {
                    $typeBuckets[$theType] .= $correctedConfig . "\n";
                }

                $sourceBuckets[$source] .= $correctedConfig . "\n";
            }
        }

        if (trim($sourceBuckets[$source]) !== "") {
            ensureDir("subscription/source/normal");
            ensureDir("subscription/source/base64");
            ensureDir("subscription/source/hiddify");

            $configsSource =
                generateUpdateTime() . $sourceBuckets[$source] . generateEndofConfiguration();

            file_put_contents("subscription/source/normal/" . $source, $configsSource);
            file_put_contents("subscription/source/base64/" . $source, base64_encode($configsSource));
            file_put_contents(
                "subscription/source/hiddify/" . $source,
                base64_encode(generateHiddifyTags("@" . $source) . "\n" . $configsSource)
            );

            echo "@{$source} => PROGRESS: 100%\n";
        } else {
            echo "@{$source} => NO CONFIG FOUND (NOT REMOVED - TEMP DISABLED)\n";
        }
    }

    ensureDir("subscription/normal");
    ensureDir("subscription/base64");
    ensureDir("subscription/hiddify");

    $typesOut = [
        "mix",
        "vmess",
        "vless",
        "trojan",
        "ss",
        "tuic",
        "hysteria",
        "hysteria2",
    ];

    foreach ($typesOut as $filename) {
        if (trim($typeBuckets[$filename]) !== "") {
            $configsType =
                generateUpdateTime() .
                $typeBuckets[$filename] .
                generateEndofConfiguration();

            file_put_contents("subscription/normal/" . $filename, $configsType);
            file_put_contents("subscription/base64/" . $filename, base64_encode($configsType));
            file_put_contents(
                "subscription/hiddify/" . $filename,
                base64_encode(generateHiddifyTags(strtoupper($filename)) . "\n" . $configsType)
            );
            echo "#{$filename} => CREATED SUCCESSFULLY!!\n";
        } else {
            removeFileInDirectory("subscription/normal/", $filename);
            removeFileInDirectory("subscription/base64/", $filename);
            removeFileInDirectory("subscription/hiddify/", $filename);
            echo "#{$filename} => WAS EMPTY, I REMOVED IT!\n";
        }
    }
}

function configParse($input, $configType)
{
    if ($configType === "vmess") {
        $vmess_data = substr($input, 8);
        $decoded_data = json_decode(base64_decode($vmess_data), true);
        return $decoded_data;
    } elseif (
        in_array($configType, [
            "vless",
            "trojan",
            "tuic",
            "hysteria",
            "hysteria2",
            "hy2",
        ])
    ) {
        $parsedUrl = parse_url($input);
        $params = [];
        if (isset($parsedUrl["query"])) {
            parse_str($parsedUrl["query"], $params);
        }
        $output = [
            "protocol" => $configType,
            "username" => isset($parsedUrl["user"]) ? $parsedUrl["user"] : "",
            "hostname" => isset($parsedUrl["host"]) ? $parsedUrl["host"] : "",
            "port" => isset($parsedUrl["port"]) ? $parsedUrl["port"] : "",
            "params" => $params,
            "hash" => isset($parsedUrl["fragment"])
                ? $parsedUrl["fragment"]
                : "TVC" . getRandomName(),
        ];

        if ($configType === "tuic") {
            $output["pass"] = isset($parsedUrl["pass"])
                ? $parsedUrl["pass"]
                : "";
        }
        return $output;
    } elseif ($configType === "ss") {
        $url = parse_url($input);
        if (!isset($url["user"], $url["host"], $url["port"])) return null;

        if (isBase64($url["user"])) {
            $url["user"] = base64_decode($url["user"]);
        }
        if (strpos($url["user"], ":") === false) return null;

        list($encryption_method, $password) = explode(":", $url["user"], 2);
        $server_address = $url["host"];
        $server_port = $url["port"];
        $name = isset($url["fragment"])
            ? urldecode($url["fragment"])
            : "TVC" . getRandomName();
        $server = [
            "encryption_method" => $encryption_method,
            "password" => $password,
            "server_address" => $server_address,
            "server_port" => $server_port,
            "name" => $name,
        ];
        return $server;
    }

    return null;
}

function reparseConfig($configArray, $configType)
{
    if ($configType === "vmess") {
        $encoded_data = base64_encode(json_encode($configArray));
        $vmess_config = "vmess://" . $encoded_data;
        return $vmess_config;
    } elseif (
        in_array($configType, [
            "vless",
            "trojan",
            "tuic",
            "hysteria",
            "hysteria2",
            "hy2",
        ])
    ) {
        $url = $configType . "://";
        $url .= addUsernameAndPassword($configArray);
        $url .= $configArray["hostname"];
        $url .= addPort($configArray);
        $url .= addParams($configArray);
        $url .= addHash($configArray);
        return $url;
    } elseif ($configType === "ss") {
        $user = base64_encode(
            $configArray["encryption_method"] . ":" . $configArray["password"]
        );
        $url = "ss://$user@{$configArray["server_address"]}:{$configArray["server_port"]}";
        if (!empty($configArray["name"])) {
            $url .= "#" . str_replace(" ", "%20", $configArray["name"]);
        }
        return $url;
    }
    return null;
}

function addUsernameAndPassword($obj)
{
    $url = "";
    if (($obj["username"] ?? "") !== "") {
        $url .= $obj["username"];
        if (isset($obj["pass"]) && $obj["pass"] !== "") {
            $url .= ":" . $obj["pass"];
        }
        $url .= "@";
    }
    return $url;
}

function addPort($obj)
{
    $url = "";
    if (isset($obj["port"]) && $obj["port"] !== "") {
        $url .= ":" . $obj["port"];
    }
    return $url;
}

function addParams($obj)
{
    $url = "";
    if (!empty($obj["params"])) {
        $url .= "?" . http_build_query($obj["params"]);
    }
    return $url;
}

function addHash($obj)
{
    $url = "";
    if (isset($obj["hash"]) && $obj["hash"] !== "") {
        $url .= "#" . str_replace(" ", "%20", $obj["hash"]);
    }
    return $url;
}

function removeFileInDirectory($directory, $fileName)
{
    if (!is_dir($directory)) {
        return false;
    }

    $filePath = rtrim($directory, "/") . "/" . $fileName;

    if (!file_exists($filePath)) {
        return false;
    }

    if (!@unlink($filePath)) {
        return false;
    }

    return true;
}

function generateReadmeTable($titles, $data)
{
    $table = "| " . implode(" | ", $titles) . " |" . PHP_EOL;

    $separator =
        "| " .
        implode(
            " | ",
            array_map(function ($title) {
                return str_repeat("-", strlen($title));
            }, $titles)
        ) .
        " |" .
        PHP_EOL;

    $table .= $separator;

    foreach ($data as $row) {
        $table .= "| " . implode(" | ", $row) . " |" . PHP_EOL;
    }

    return $table;
}

function listFilesInDirectory($directory)
{
    if (!is_dir($directory)) {
        throw new InvalidArgumentException("Directory does not exist.");
    }

    $filePaths = [];

    if ($handle = opendir($directory)) {
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != "..") {
                $fullPath = $directory . "/" . $entry;
                if (is_dir($fullPath)) {
                    $filePaths = array_merge(
                        $filePaths,
                        listFilesInDirectory($fullPath)
                    );
                } else {
                    $filePaths[] = $fullPath;
                }
            }
        }
        closedir($handle);
    } else {
        throw new RuntimeException("Failed to open directory.");
    }

    return $filePaths;
}

function getFileNamesInDirectory($filePaths)
{
    $fileNames = [];

    foreach ($filePaths as $filePath) {
        $filePathArray = explode("/", $filePath);
        $partNumber = count($filePathArray) - 1;
        $fileNames[] = $filePathArray[$partNumber];
    }

    return $fileNames;
}

function convertArrays()
{
    $arrays = func_get_args();

    $result = [];

    if (empty($arrays)) {
        return $result;
    }

    $count = count($arrays[0]);

    for ($i = 0; $i < $count; $i++) {
        $subArray = [];

        foreach ($arrays as $array) {
            $subArray[] = $array[$i];
        }

        $result[] = $subArray;
    }

    return $result;
}

function isBase64($input)
{
    if ($input === "" || $input === null) return false;
    return base64_encode(base64_decode($input, true)) === $input;
}

function getRandomName()
{
    $alphabet = "abcdefghijklmnopqrstuvwxyz";
    $name = "";
    for ($i = 0; $i < 10; $i++) {
        $randomLetter = $alphabet[rand(0, strlen($alphabet) - 1)];
        $name .= $randomLetter;
    }
    return $name;
}

function correctConfig($config, $type, $source, $latency = "N/A")
{
    $configsHashName = [
        "vmess" => "ps",
        "vless" => "hash",
        "trojan" => "hash",
        "tuic" => "hash",
        "hysteria" => "hash",
        "hysteria2" => "hash",
        "hy2" => "hash",
        "ss" => "name",
    ];
    $configHashName = $configsHashName[$type];

    $parsedConfig = configParse($config, $type);
    if ($parsedConfig === null) return $config;

    $configHashTag = generateName($parsedConfig, $type, $source, $latency);
    $parsedConfig[$configHashName] = $configHashTag;

    $rebuildedConfig = reparseConfig($parsedConfig, $type);
    return $rebuildedConfig;
}

function maskUrl($url)
{
    return "https://itsyebekhe.github.io/urlmskr/" . base64_encode($url);
}

function convertToJson($input)
{
    $lines = explode("\n", $input);
    $data = [];

    foreach ($lines as $line) {
        $parts = explode("=", $line);

        if (count($parts) == 2 && !empty($parts[0]) && !empty($parts[1])) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            $data[$key] = $value;
        }
    }

    return json_encode($data);
}

// ==========================================
// ✅ تابع جدید جایگزین شد
// ==========================================
function generateName($config, $type, $source, $latency)
{
    static $uniqueId = 1;

    $configsTypeName = [
        "vmess" => "VM",
        "vless" => "VL",
        "trojan" => "TR",
        "tuic" => "TU",
        "hysteria" => "HY",
        "hysteria2" => "HY2",
        "hy2" => "HY2",
        "ss" => "SS",
    ];

    $isEncrypted = isEncrypted($config, $type) ? "🔒" : "🔓";
    $configType = $configsTypeName[$type];
    
    $configNetwork = getNetwork($config, $type);
    $configTLS = getTLS($config, $type);

    $netStr = ($configNetwork !== "N/A" && $configNetwork !== "") ? "-{$configNetwork}" : "";
    $tlsStr = ($configTLS !== "N/A" && $configTLS !== "") ? "-{$configTLS}" : "";

    $finalName = "🆔{$source} {$isEncrypted} {$configType}{$netStr}{$tlsStr}-{$uniqueId} {$latency}";
    
    $uniqueId++;

    return $finalName;
}

function getNetwork($config, $type)
{
    if ($type === "vmess") {
        return strtoupper($config["net"] ?? "N/A");
    }
    if (in_array($type, ["vless", "trojan"])) {
        return strtoupper($config["params"]["type"] ?? "N/A");
    }
    if (in_array($type, ["tuic", "hysteria", "hysteria2", "hy2"])) {
        return "UDP";
    }
    if ($type === "ss") {
        return "TCP";
    }
    return "N/A";
}

function getTLS($config, $type)
{
    if (($type === "vmess" && ($config["tls"] ?? "") === "tls") || $type === "ss") {
        return "TLS";
    }
    if (
        ($type === "vmess" && ($config["tls"] ?? "") === "") ||
        (in_array($type, ["vless", "trojan"]) && (($config["params"]["security"] ?? "") === "tls")) ||
        (in_array($type, ["vless", "trojan"]) && (($config["params"]["security"] ?? "") === "none")) ||
        (in_array($type, ["vless", "trojan"]) && empty($config["params"]["security"])) ||
        in_array($type, ["tuic", "hysteria", "hysteria2", "hy2"])
    ) {
        return "N/A";
    }
    return "N/A";
}

function isEncrypted($config, $type)
{
    if (
        $type === "vmess" &&
        ($config["tls"] ?? "") !== "" &&
        ($config["scy"] ?? "") !== "none"
    ) {
        return true;
    } elseif (
        in_array($type, ["vless", "trojan"]) &&
        !empty($config["params"]["security"]) &&
        $config["params"]["security"] !== "none"
    ) {
        return true;
    } elseif (in_array($type, ["ss", "tuic", "hysteria", "hysteria2", "hy2"])) {
        return true;
    }
    return false;
}

function getConfigItems($prefix, $string)
{
    $regex = "~[a-z]+://\\S+~i";
    preg_match_all($regex, $string, $matches);
    $count = strlen($prefix) + 3;
    $output = [];
    foreach ($matches[0] as $match) {
        $newMatches = explode("<br/>", $match);
        foreach ($newMatches as $newMatch) {
            if (substr($newMatch, 0, $count) === "{$prefix}://") {
                $output[] = $newMatch;
            }
        }
    }
    return array_unique($output);
}

function is_valid($input)
{
    if (stripos($input, "…") !== false or stripos($input, "...") !== false) {
        return false;
    }
    return true;
}

function removeAngleBrackets($link)
{
    return preg_replace("/<.*?>/", "", $link);
}

function ping($host, $port, $timeout)
{
    $tB = microtime(true);
    $fP = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fP) {
        return "down";
    }
    fclose($fP);
    $tA = microtime(true);
    return round(($tA - $tB) * 1000, 0) . "ms";
}

function sendMessage($botToken, $chatId, $message, $parse_mode, $keyboard)
{
    $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

    $data = [
        "chat_id" => $chatId,
        "text" => $message,
        "parse_mode" => $parse_mode,
        "disable_web_page_preview" => true,
        "reply_markup" => json_encode([
            "inline_keyboard" => $keyboard,
        ]),
    ];

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($curl);
    curl_close($curl);

    echo $response;
}

function generateHiddifyTags($type)
{
    $profileTitle = base64_encode("{$type} | VPNineh 🫧");
    return "#profile-title: base64:{$profileTitle}\n#profile-update-interval: 1\n#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire=2546249531\n";
}

function gregorianToJalali($gy, $gm, $gd)
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    if ($gy > 1600) {
        $jy = 979;
        $gy -= 1600;
    } else {
        $jy = 0;
        $gy -= 621;
    }
    $gy2 = $gm > 2 ? $gy + 1 : $gy;
    $days =
        365 * $gy +
        ((int)(($gy2 + 3) / 4)) -
        ((int)(($gy2 + 99) / 100)) +
        ((int)(($gy2 + 399) / 400)) -
        80 +
        $gd +
        $g_d_m[$gm - 1];
    $jy += 33 * ((int)($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = $days < 186 ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return [$jy, $jm, $jd];
}

function getTehranTime()
{
    date_default_timezone_set("Asia/Tehran");
    $date = new DateTime();

    $dayOfWeek = $date->format("D");
    $day = $date->format("d");
    $month = (int)$date->format("m");
    $year = (int)$date->format("Y");

    list($jy, $jm, $jd) = gregorianToJalali($year, $month, $day);

    $monthNames = [
        1 => "FAR",
        2 => "ORD",
        3 => "KHORDAD",
        4 => "TIR",
        5 => "MORDAD",
        6 => "SHAHRIVAR",
        7 => "MEHR",
        8 => "ABAN",
        9 => "AZAR",
        10 => "DEY",
        11 => "BAHMAN",
        12 => "ESFAND",
    ];
    $shortMonth = $monthNames[$jm];

    $time = $date->format("H:i");

    return sprintf("%s-%02d-%s-%04d 🕑 %s", $dayOfWeek, $jd, $shortMonth, $jy, $time);
}

function generateUpdateTime()
{
    $tehranTime = getTehranTime();
    return "vless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#⚠️%20FREE%20TO%20USE!\n" .
        "vless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#🔄%20LATEST-UPDATE%20📅%20{$tehranTime}\n";
}

function generateEndofConfiguration()
{
    return "";
}

function addStringToBeginning($array, $string)
{
    $modifiedArray = [];
    foreach ($array as $item) {
        $modifiedArray[] = $string . $item;
    }
    return $modifiedArray;
}

function generateReadme($table1, $table2)
{
    $base = "";
    return $base;
}

/**
 * ========= RUN =========
 */
$source = trim(@file_get_contents("source.conf"));
getTelegramChannelConfigs($source);

$normals = addStringToBeginning(
    listFilesInDirectory("subscription/normal"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$base64 = addStringToBeginning(
    listFilesInDirectory("subscription/base64"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$hiddify = addStringToBeginning(
    listFilesInDirectory("subscription/hiddify"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$protocolColumn = getFileNamesInDirectory(
    listFilesInDirectory("subscription/normal")
);

$title1Array = ["Protocol", "Normal", "Base64", "Hiddify"];
$cells1Array = convertArrays($protocolColumn, $normals, $base64, $hiddify);

$sourceNormals = addStringToBeginning(
    listFilesInDirectory("subscription/source/normal"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$sourceBase64 = addStringToBeginning(
    listFilesInDirectory("subscription/source/base64"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$sourceHiddify = addStringToBeginning(
    listFilesInDirectory("subscription/source/hiddify"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$sourcesColumn = getFileNamesInDirectory(
    listFilesInDirectory("subscription/source/normal")
);

$title2Array = ["Source", "Normal", "Base64", "Hiddify"];
$cells2Array = convertArrays(
    $sourcesColumn,
    $sourceNormals,
    $sourceBase64,
    $sourceHiddify
);

$table1 = generateReadmeTable($title1Array, $cells1Array);
$table2 = generateReadmeTable($title2Array, $cells2Array);

$readmeMdNew = generateReadme($table1, $table2);
file_put_contents("README.md", $readmeMdNew);

$randKey = array_rand($hiddify);
$randType = $hiddify[$randKey];

$tehranTime = getTehranTime();
$botToken = getenv("TELEGRAM_BOT_TOKEN");
$keyboard = [
    [
        [
            "text" => "📲 STREISAND",
            "url" => maskUrl("streisand://import/" . $randType),
        ],
        [
            "text" => "📲 HIDDIFY",
            "url" => maskUrl("hiddify://import/" . $randType),
        ],
    ],
    [
        [
            "text" => "🚹 گیتهاب VPNineh VPN 🚹",
            "url" => "https://github.com/vpnineh/tlgrm/blob/main/README.md",
        ],
    ],
];

$message = "🔺 لینک های اشتراک VPNineh بروزرسانی شدن! 🔻

⏱ آخرین آپدیت:
{$tehranTime}

🔎 <code>{$randType}</code>

💥 برای لینک های بیشتر وارد گیتهاب پروژه بشید

🌐 VPNineh";

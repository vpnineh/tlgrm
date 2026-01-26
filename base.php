<?php

// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

/**
 * ---------- DEDUP (REAL DUPLICATES) ----------
 * Real-duplicate = same connection identity, regardless of tag/hash/latency.
 */

function buildDedupKeyFromRawConfig(string $rawConfig, string $type): ?string
{
    $clean = sanitizeConfigString($rawConfig);
    if ($clean === "") return null;

    $parsed = configParse($clean, $type);
    if (empty($parsed) || !is_array($parsed)) return null;

    // Remove non-identity fields that change run-to-run
    if ($type === "vmess") {
        // Keep only fields that define the connection
        $identity = [
            "v"    => $parsed["v"] ?? "",
            "id"   => $parsed["id"] ?? "",
            "aid"  => $parsed["aid"] ?? "",
            "add"  => $parsed["add"] ?? "",
            "port" => $parsed["port"] ?? "",
            "net"  => $parsed["net"] ?? "",
            "type" => $parsed["type"] ?? "",
            "tls"  => $parsed["tls"] ?? "",
            "sni"  => $parsed["sni"] ?? "",
            "host" => $parsed["host"] ?? "",
            "path" => $parsed["path"] ?? "",
            "alpn" => $parsed["alpn"] ?? "",
            "fp"   => $parsed["fp"] ?? "",
            "scy"  => $parsed["scy"] ?? "",
            "flow" => $parsed["flow"] ?? "",
        ];

        // Sort for stable hashing
        ksort($identity);
        return "vmess:" . md5(json_encode($identity));
    }

    if ($type === "ss") {
        $identity = [
            "server_address"     => $parsed["server_address"] ?? "",
            "server_port"        => $parsed["server_port"] ?? "",
            "encryption_method"  => $parsed["encryption_method"] ?? "",
            "password"           => $parsed["password"] ?? "",
        ];
        ksort($identity);
        return "ss:" . md5(json_encode($identity));
    }

    // vless / trojan / tuic / hysteria / hysteria2 / hy2
    $params = $parsed["params"] ?? [];
    if (!is_array($params)) $params = [];

    // Remove fragment-like / non-identity params if they exist
    // (Keep most params because they may affect connection.)
    unset($params["remarks"], $params["remark"], $params["name"], $params["hash"], $params["ps"]);

    // Stable order
    ksort($params);

    $identity = [
        "protocol" => $type,
        "username" => $parsed["username"] ?? "",
        "hostname" => $parsed["hostname"] ?? "",
        "port"     => $parsed["port"] ?? "",
        "pass"     => $parsed["pass"] ?? "", // important for tuic
        "params"   => $params,
    ];

    return $type . ":" . md5(json_encode($identity));
}

function sanitizeConfigString(string $config): string
{
    $config = removeAngleBrackets($config);
    $config = str_replace("amp;", "", $config);
    $config = trim($config);
    return $config;
}

/**
 * ---------- MAIN ----------
 */

function getTelegramChannelConfigs($username)
{
    $sourceArray = array_filter(array_map("trim", explode(",", $username)));
    if (empty($sourceArray)) return;

    // Buckets for outputs
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

    // Per source bucket
    $sourceBuckets = []; // [source => string]

    // Global dedup map (REAL dedup across all sources & types)
    $seen = []; // [dedupKey => true]

    foreach ($sourceArray as $source) {
        echo "@{$source} => PROGRESS: 0%\n";

        $html = @file_get_contents("https://t.me/s/" . $source);
        if ($html === false || trim($html) === "") {
            // treat as empty source
            handleEmptySource($source, $username);
            echo "@{$source} => NO CONFIG FOUND (FETCH FAILED), I REMOVED CHANNEL!\n";
            continue;
        }

        $typesToScan = [
            "vmess",
            "vless",
            "trojan",
            "ss",
            "tuic",
            "hysteria",
            "hysteria2",
            "hy2", // alias of hysteria2
        ];

        // Collect raw configs by type
        $configs = [
            "vmess" => [],
            "vless" => [],
            "trojan" => [],
            "ss" => [],
            "tuic" => [],
            "hysteria" => [],
            "hysteria2" => [],
        ];

        foreach ($typesToScan as $t) {
            $items = getConfigItems($t, $html);

            if ($t === "hy2") {
                // merge hy2 into hysteria2 bucket
                $configs["hysteria2"] = array_merge($configs["hysteria2"], $items);
            } elseif ($t === "hysteria2") {
                $configs["hysteria2"] = array_merge($configs["hysteria2"], $items);
            } else {
                $configs[$t] = array_merge($configs[$t], $items);
            }
        }

        // Unique raw strings per type (still text-level)
        foreach ($configs as $t => $arr) {
            $configs[$t] = array_values(array_unique($arr));
        }

        echo "@{$source} => PROGRESS: 50%\n";

        $sourceBuckets[$source] = "";

        foreach ($configs as $theType => $configsArray) {
            foreach ($configsArray as $config) {
                if (!is_valid($config)) continue;

                $fixedConfig = sanitizeConfigString($config);
                if ($fixedConfig === "") continue;

                // Build REAL dedup key before tagging/ping/hash changes
                $dedupKey = buildDedupKeyFromRawConfig($fixedConfig, $theType);
                if ($dedupKey === null) continue;

                if (isset($seen[$dedupKey])) {
                    continue; // REAL duplicate filtered
                }
                $seen[$dedupKey] = true;

                $correctedConfig = correctConfig($fixedConfig, $theType, $source);

                // Append to global buckets
                $typeBuckets["mix"] .= $correctedConfig . "\n";
                if (isset($typeBuckets[$theType])) {
                    $typeBuckets[$theType] .= $correctedConfig . "\n";
                }

                // Append to source bucket
                $sourceBuckets[$source] .= $correctedConfig . "\n";
            }
        }

        // Write per-source outputs if non-empty (real check)
        if (trim($sourceBuckets[$source]) !== "") {
            $configsSource = generateUpdateTime() . $sourceBuckets[$source] . generateEndofConfiguration();

            @mkdir("subscription/source/normal", 0777, true);
            @mkdir("subscription/source/base64", 0777, true);
            @mkdir("subscription/source/hiddify", 0777, true);

            file_put_contents("subscription/source/normal/" . $source, $configsSource);
            file_put_contents("subscription/source/base64/" . $source, base64_encode($configsSource));
            file_put_contents(
                "subscription/source/hiddify/" . $source,
                base64_encode(generateHiddifyTags("@" . $source) . "\n" . $configsSource)
            );

            echo "@{$source} => PROGRESS: 100%\n";
        } else {
            // No config found after parsing
            handleEmptySource($source, $username);
            echo "@{$source} => NO CONFIG FOUND, I REMOVED CHANNEL!\n";
        }
    }

    // Write protocol outputs
    $typesToWrite = [
        "mix",
        "vmess",
        "vless",
        "trojan",
        "ss",
        "tuic",
        "hysteria",
        "hysteria2",
    ];

    @mkdir("subscription/normal", 0777, true);
    @mkdir("subscription/base64", 0777, true);
    @mkdir("subscription/hiddify", 0777, true);

    foreach ($typesToWrite as $filename) {
        if (trim($typeBuckets[$filename]) !== "") {
            $configsType = generateUpdateTime() . $typeBuckets[$filename] . generateEndofConfiguration();

            file_put_contents("subscription/normal/" . $filename, $configsType);
            file_put_contents("subscription/base64/" . $filename, base64_encode($configsType));
            file_put_contents(
                "subscription/hiddify/" . $filename,
                base64_encode(generateHiddifyTags(strtoupper($filename)) . "\n" . $configsType)
            );
            echo "#{$filename} => CREATED SUCCESSFULLY!!\n";
        } else {
            removeFileInDirectory("subscription/normal", $filename);
            removeFileInDirectory("subscription/base64", $filename);
            removeFileInDirectory("subscription/hiddify", $filename);
            echo "#{$filename} => WAS EMPTY, I REMOVED IT!\n";
        }
    }
}

function handleEmptySource(string $source, string $username)
{
    // TEMP DISABLED:
    // فعلاً هیچ کانالی از source.conf حذف نشود و داخل empty.conf ثبت نشود
    // و فایل‌های مربوطه هم پاک نشوند.
    return;

    /*
    // Remove the source from source.conf
    $username = str_replace($source . ",", "", $username);
    $username = str_replace("," . $source, "", $username);
    $username = str_replace($source, "", $username);
    $username = trim($username, ", \n\r\t");

    file_put_contents("source.conf", $username);

    // Add to empty.conf
    $emptySource = @file_get_contents("empty.conf");
    if ($emptySource === false) $emptySource = "";
    $emptyArray = array_filter(array_map("trim", explode(",", $emptySource)));

    if (!in_array($source, $emptyArray)) {
        $emptyArray[] = $source;
    }
    file_put_contents("empty.conf", implode(",", $emptyArray));

    // Remove files
    removeFileInDirectory("subscription/source/normal", $source);
    removeFileInDirectory("subscription/source/base64", $source);
    removeFileInDirectory("subscription/source/hiddify", $source);
    */
}


/**
 * ---------- ORIGINAL FUNCTIONS (kept) ----------
 */

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
        if (!isset($url["user"]) || !isset($url["host"]) || !isset($url["port"])) {
            return null;
        }
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
    if (!is_dir($directory)) return false;

    $filePath = rtrim($directory, "/") . "/" . $fileName;
    if (!file_exists($filePath)) return false;

    return @unlink($filePath);
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

function correctConfig($config, $type, $source)
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

    $configHashTag = generateName($parsedConfig, $type, $source);
    $parsedConfig[$configHashName] = $configHashTag;

    $rebuildedConfig = reparseConfig($parsedConfig, $type);
    return $rebuildedConfig;
}

function is_ip($string)
{
    $ip_pattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
    return preg_match($ip_pattern, $string) === 1;
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

    $json = json_encode($data);

    return $json;
}

function ip_info($ip)
{
    if (is_ip($ip) === false) {
        $ip_address_array = @dns_get_record($ip, DNS_A);
        if (empty($ip_address_array)) {
            return null;
        }
        $randomKey = array_rand($ip_address_array);
        $ip = $ip_address_array[$randomKey]["ip"];
    }

    $endpoints = [
        "https://ipapi.co/{ip}/json/",
        "https://ipwhois.app/json/{ip}",
        "http://www.geoplugin.net/json.gp?ip={ip}",
        "https://api.ipbase.com/v1/json/{ip}",
    ];

    $result = (object) [
        "country" => "XX",
    ];

    foreach ($endpoints as $endpoint) {
        $url = str_replace("{ip}", $ip, $endpoint);

        $options = [
            "http" => [
                "header" =>
                    "User-Agent: Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.10\r\n",
            ],
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response !== false) {
            $data = json_decode($response);

            if ($endpoint == $endpoints[0]) {
                $result->country = $data->country_code ?? "XX";
            } elseif ($endpoint == $endpoints[1]) {
                $result->country = $data->country_code ?? "XX";
            } elseif ($endpoint == $endpoints[2]) {
                $result->country = $data->geoplugin_countryCode ?? "XX";
            } elseif ($endpoint == $endpoints[3]) {
                $result->country = $data->country_code ?? "XX";
            }
            break;
        }
    }

    return $result;
}

function getFlags($country_code)
{
    $flag = mb_convert_encoding(
        "&#" . (127397 + ord($country_code[0])) . ";",
        "UTF-8",
        "HTML-ENTITIES"
    );
    $flag .= mb_convert_encoding(
        "&#" . (127397 + ord($country_code[1])) . ";",
        "UTF-8",
        "HTML-ENTITIES"
    );
    return $flag;
}

function generateName($config, $type, $source)
{
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
    $configsIpName = [
        "vmess" => "add",
        "vless" => "hostname",
        "trojan" => "hostname",
        "tuic" => "hostname",
        "hysteria" => "hostname",
        "hysteria2" => "hostname",
        "hy2" => "hostname",
        "ss" => "server_address",
    ];
    $configsPortName = [
        "vmess" => "port",
        "vless" => "port",
        "trojan" => "port",
        "tuic" => "port",
        "hysteria" => "port",
        "hysteria2" => "port",
        "hy2" => "port",
        "ss" => "server_port",
    ];

    $configIpName = $configsIpName[$type];
    $configPortName = $configsPortName[$type];

    $configIp = $config[$configIpName] ?? "";
    $configPort = $config[$configPortName] ?? "";

    $info = ($configIp !== "") ? ip_info($configIp) : null;
    $configLocation = $info->country ?? "XX";

    $configFlag =
        $configLocation === "XX"
            ? "❔"
            : ($configLocation === "CF"
                ? "🚩"
                : getFlags($configLocation));

    $isEncrypted = isEncrypted($config, $type) ? "🔒" : "🔓";
    $configType = $configsTypeName[$type];
    $configNetwork = getNetwork($config, $type);
    $configTLS = getTLS($config, $type);

    $lantency = ($configIp !== "" && $configPort !== "") ? ping($configIp, $configPort, 1) : "N/A";

    return "🆔{$source} {$isEncrypted} {$configType}-{$configNetwork}-{$configTLS} {$configFlag} {$configLocation} {$lantency}";
}

function getNetwork($config, $type)
{
    if ($type === "vmess") {
        return strtoupper($config["net"] ?? "");
    }
    if (in_array($type, ["vless", "trojan"])) {
        return strtoupper($config["params"]["type"] ?? "");
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
 * ---------- RUN ----------
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

<?php

// Enable error reporting
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

function getTheType($input)
{
    $types = [
        "vmess",
        "vless",
        "trojan",
        "ss",
        "tuic",
        "hysteria",
        "hysteria2",
        "hy2"
    ];
    foreach ($types as $type) {
        if (substr($input, 0, strlen($type) + 3) === $type . "://") {
            if ($type === "hy2") return "hysteria2";
            return $type;
        }
    }
    return false;
}

function fetchGitHubContent($owner, $repo, $path, $token)
{
    $ch = curl_init();

    $url = "https://api.github.com/repos/$owner/$repo/contents/$path";
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

    $headers = [];
    $headers[] = "Accept: application/vnd.github+json";
    $headers[] = "Authorization: Bearer $token";
    $headers[] = "X-GitHub-Api-Version: 2022-11-28";
    $headers[] = "User-Agent: VPNineh"; // Add User-Agent header
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        echo "Error:" . curl_error($ch);
    }
    curl_close($ch);

    return $result;
}

function getGitHubFileContent($owner, $repo, $path, $token)
{
    $content = json_decode(
        fetchGitHubContent($owner, $repo, $path, $token),
        true
    );

    if (isset($content["content"])) {
        $output = json_decode(base64_decode($content["content"]), true);
    }

    return $output;
}

function modifyString($inputString, $itemToRemove)
{
    $array = explode(",", $inputString);

    if (($key = array_search($itemToRemove, $array)) !== false) {
        unset($array[$key]);
    }

    $resultString = implode(",", $array);

    return $resultString;
}

function modifyStringAddItem($inputString, $itemToAdd)
{
    $array = explode(",", $inputString);

    if (!in_array($itemToAdd, $array)) {
        $array[] = $itemToAdd;
    }

    $resultString = implode(",", $array);

    return $resultString;
}

function getTelegramChannelConfigs($username)
{
    $sourceArray = explode(",", $username);
    $mix = "";
    $GIT_TOKEN = getenv("GIT_TOKEN");
    $locationsArray = [];

    $configs = getGitHubFileContent(
        "itsyebekhe",
        "cGrabber",
        "configs.json",
        $GIT_TOKEN
    );
    
    echo "Configs Arrived!⚡️\n";
    if ($configs["status"] === "OK") {
        unset($configs["status"]);
        foreach ($configs as $source => $configsArray) {
            //channel timer
            $time_start = microtime(true);

            // Limit the configsArray to the first 20 items
            $limitedConfigsArray = array_slice($configsArray, 0, 20);

            if (!empty($limitedConfigsArray)) {
                foreach ($limitedConfigsArray as $config) {
                    $configType = getTheType($config);
                    $fixedConfig = $config;
                    $correctedConfigArray = correctConfig(
                        "{$fixedConfig}",
                        $configType,
                        $source
                    );
                    if ($correctedConfigArray !== false) {
                        $configLocation =  getFlags($correctedConfigArray["loc"]) . " " . $correctedConfigArray["loc"];
                        $correctedConfig = $correctedConfigArray["config"];
                        $mix .= $correctedConfig . "\n";
                        $$configType .= $correctedConfig . "\n";
                        $$source .= $correctedConfig . "\n";
                        if (!in_array($configLocation, $locationsArray) && $configLocation !== getFlags("") . " ") {
                            $locationsArray[] = $configLocation;
                        }
                        $$configLocation .= $correctedConfig . "\n";
                    }
                }

                $configsSource =
                    generateUpdateTime() .
                    $$source .
                    generateEndofConfiguration();
                file_put_contents(
                    "subscription/source/normal/" . $source,
                    $configsSource
                );
                file_put_contents(
                    "subscription/source/base64/" . $source,
                    base64_encode($configsSource)
                );
                file_put_contents(
                    "subscription/source/hiddify/" . $source,
                    base64_encode(
                        generateHiddifyTags("@" . $source) .
                            "\n" .
                            $configsSource
                    )
                );
                echo "@{$source} ✅\n";
            } else {
                file_put_contents(
                    "source.conf",
                    modifyString($username, $source)
                );

                $emptySource = file_get_contents("empty.conf");
                file_put_contents(
                    "empty.conf",
                    modifyStringAddItem($emptySource, $source)
                );

                removeFileInDirectory("subscription/source/normal/", $source);
                removeFileInDirectory("subscription/source/base64/", $source);
                removeFileInDirectory("subscription/source/hiddify/", $source);

                echo "@{$source} ❌\n";
            }
            //channel timer
            echo "Total channel exec time in seconds: " .
                (microtime(true) - $time_start) .
                "\n\n";
        }

        $types = [
            "mix",
            "vmess",
            "vless",
            "trojan",
            "ss",
            "tuic",
            "hysteria",
            "hysteria2",
        ];
        foreach ($types as $filename) {
            // Trim the content and check if it's empty
            if (empty(trim($$filename))) {
                removeFileInDirectory("subscription/normal/", $filename);
                removeFileInDirectory("subscription/base64/", $filename);
                removeFileInDirectory("subscription/hiddify/", $filename);
                echo "#{$filename} ❌\n";
            } else {
                $configsType =
                    generateUpdateTime() .
                    $$filename .
                    generateEndofConfiguration();
                file_put_contents(
                    "subscription/normal/" . $filename,
                    $configsType
                );
                file_put_contents(
                    "subscription/base64/" . $filename,
                    base64_encode($configsType)
                );
                file_put_contents(
                    "subscription/hiddify/" . $filename,
                    base64_encode(
                        generateHiddifyTags(strtoupper($filename)) .
                            "\n" .
                            $configsType
                    )
                );
                echo "#{$filename} ✅\n";
            }
        }

        // Check and clean up the location directory
        $locationFiles = listFilesInDirectory("subscription/location/normal/");
        foreach ($locationFiles as $filePath) {
            $fileName = basename($filePath);
            if (!in_array($fileName, $locationsArray)) {
                removeFileInDirectory("subscription/location/normal/", $fileName);
                removeFileInDirectory("subscription/location/base64/", $fileName);
                removeFileInDirectory("subscription/location/hiddify/", $fileName);
                echo "#{$fileName} ❌\n";
            }
        }

        foreach ($locationsArray as $location) {
            // Trim the content and check if it's empty
            if (empty(trim($$location))) {
                removeFileInDirectory(
                    "subscription/location/normal/",
                    $location
                );
                removeFileInDirectory(
                    "subscription/location/base64/",
                    $location
                );
                removeFileInDirectory(
                    "subscription/location/hiddify/",
                    $location
                );
                echo "#{$location} ❌\n";
            } else {
                $configsLocation =
                    generateUpdateTime() .
                    $$location .
                    generateEndofConfiguration();
                file_put_contents(
                    "subscription/location/normal/" . $location,
                    $configsLocation
                );
                file_put_contents(
                    "subscription/location/base64/" . $location,
                    base64_encode($configsLocation)
                );
                file_put_contents(
                    "subscription/location/hiddify/" . $location,
                    base64_encode(
                        generateHiddifyTags(strtoupper($location)) .
                            "\n" .
                            $configsLocation
                    )
                );
                echo "#{$location} ✅\n";
            }
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
        if (isBase64($url["user"])) {
            $url["user"] = base64_decode($url["user"]);
        }
        list($encryption_method, $password) = explode(":", $url["user"]);
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
}

function addUsernameAndPassword($obj)
{
    $url = "";
    if ($obj["username"] !== "") {
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

    $filePath = $directory . "/" . $fileName;

    if (!file_exists($filePath)) {
        return false;
    }

    if (!unlink($filePath)) {
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
        $table .= "| " . urldecode(implode(" | ", $row)) . " |" . PHP_EOL;
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
                $fullPath = $directory . "/" . str_replace("+", "%20", urlencode($entry));
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
    if (base64_encode(base64_decode($input)) === $input) {
        return true;
    }

    return false;
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
    $generateName = generateName($parsedConfig, $type, $source);
    if ($generateName !== false) {
        $configLocation = $generateName["loc"];
        $configHashTag = $generateName["name"];
        $parsedConfig[$configHashName] = $configHashTag;

        $rebuildedConfig = reparseConfig($parsedConfig, $type);
        return [
            "loc" => $configLocation,
            "config" => $rebuildedConfig,
        ];
    }
    return false;
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

function getIPLocation($ip)
{
    $token = getenv("FINDIP_TOKEN");
    $result = [];

    $urls = [
        "iplocation" => "https://api.iplocation.net/?ip={$ip}",
        "country" => "https://api.country.is/$ip",
        "findip" => "https://api.findip.net/{$ip}/?token={$token}",
        "ipapi" => "http://ip-api.com/json/{$ip}",
        "ipwiki" => "https://ip.wiki/{$ip}/json",
    ];

    $chs = [];
    $mh = curl_multi_init();

    foreach ($urls as $apiName => $url) {
        $chs[$apiName] = curl_init($url);
        curl_setopt($chs[$apiName], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chs[$apiName], CURLOPT_TIMEOUT, 1); // 1 seconds timeout
        curl_multi_add_handle($mh, $chs[$apiName]);
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    $responses = [];
    foreach ($chs as $apiName => $ch) {
        $responses[$apiName] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    $locs = [];
    $errors = [];

    foreach ($responses as $apiName => $apiResponse) {
        if ($apiResponse !== false) {
            $data = json_decode($apiResponse, true);
            switch ($apiName) {
                case "iplocation":
                    if (isset($data["country_code2"])) {
                        $locs[] = [
                            "loc" => $data["country_code2"],
                            "cloudflare" => $data["isp"] === "CloudFlare Inc.",
                        ];
                    } elseif (
                        isset($data["response_code"]) &&
                        ($data["response_code"] === "400" ||
                            $data["response_code"] === "404")
                    ) {
                        $errors[] =
                            "IP Location API error: " .
                            ($data["response_message"] ?? "Unknown error");
                    }
                    break;
                case "country":
                    if (isset($data["country"])) {
                        $locs[] = [
                            "loc" => $data["country"],
                            "cloudflare" => false,
                        ];
                    } elseif (isset($data["error"])) {
                        $errors[] =
                            "Country API error: " . $data["error"]["message"];
                    }
                    break;
                case "findip":
                    if (isset($data["country"])) {
                        $locs[] = [
                            "loc" => $data["country"]["iso_code"],
                            "cloudflare" =>
                                $data["traits"]["organization"] ===
                                "CloudFlare, Inc.",
                        ];
                    } elseif (isset($data["Message"]) || is_null($data)) {
                        $errors[] =
                            "FindIP API error: " .
                            ($data["Message"] ?? "Unknown error");
                    }
                    break;
                case "ipapi":
                    if (isset($data["countryCode"])) {
                        $locs[] = [
                            "loc" => $data["countryCode"],
                            "cloudflare" => $data["org"] === "CloudFlare, Inc.",
                        ];
                    } elseif (isset($data["message"]) || is_null($data)) {
                        $errors[] =
                            "IP-API error: " .
                            ($data["message"] ?? "Unknown error");
                    }
                    break;
                case "ipwiki":
                    if (isset($data["country_code"])) {
                        $locs[] = [
                            "loc" => $data["country_code"],
                            "cloudflare" =>
                                $data["asn"]["name"] === "Cloudflare, Inc.",
                        ];
                    } elseif (isset($data["error"])) {
                        $errors[] = "IP Wiki API error: " . $data["error"];
                    }
                    break;
            }
        } else {
            $errors[] = "Failed to fetch {$apiName} information or timed out after 1 second";
        }
    }

    if (!empty($locs)) {
        $locCounts = array_count_values(array_column($locs, "loc"));
        $maxCount = max($locCounts);
        $mostCommonLocs = array_keys($locCounts, $maxCount);

        if (count($mostCommonLocs) === 1) {
            $result["loc"] = $mostCommonLocs[0];
            $result["cloudflare"] = false; // Default to false
            foreach ($locs as $loc) {
                if ($loc["loc"] === $mostCommonLocs[0] && $loc["cloudflare"]) {
                    $result["cloudflare"] = true;
                    break;
                }
            }
        } else {
            $cloudflareLocs = array_filter($locs, function ($loc) {
                return $loc["cloudflare"];
            });
            if (!empty($cloudflareLocs)) {
                $cloudflareLocCounts = array_count_values(
                    array_column($cloudflareLocs, "loc")
                );
                $result["loc"] = array_search(
                    max($cloudflareLocCounts),
                    $cloudflareLocCounts
                );
                $result["cloudflare"] = true;
            } else {
                $result["loc"] = $mostCommonLocs[0];
                $result["cloudflare"] = false;
            }
        }

        $result["ok"] = true;
        $result["messages"] = $errors;
    } else {
        $result["ok"] = false;
        $result["messages"] = $errors;
    }

    return $result;
}

function generateHTMLTable($columnTitles, $columnData)
{
    // Start the HTML table with Bootstrap classes
    $html = '<div class="table-responsive"><table class="table table-striped">' . "\n";

    // Add the table header
    $html .= "  <thead>" . "\n";
    $html .= "    <tr>" . "\n";
    foreach ($columnTitles as $title) {
        $html .=
            '      <th scope="col">' .
            htmlspecialchars($title) .
            "</th>" .
            "\n";
    }
    $html .= "    </tr>" . "\n";
    $html .= "  </thead>" . "\n";

    // Add the table rows
    $html .= "  <tbody>" . "\n";
    foreach ($columnData as $row) {
        $html .= "    <tr>" . "\n";
        foreach ($row as $index => $cell) {
            if ($index == 0) {
                $html .=
                    "      <td>" . htmlspecialchars($cell) . "</td>" . "\n";
            } else {
                $html .=
                    '      <td><button class="btn btn-primary btn-copy" data-text="' .
                    htmlspecialchars($cell) .
                    '">𝗖𝗢𝗣𝗬 𝗨𝗥𝗟 📎</button></td>' .
                    "\n";
            }
        }
        $html .= "    </tr>" . "\n";
    }
    $html .= "  </tbody>" . "\n";

    // Close the HTML table
    $html .= "</table></div>" . "\n";

    return $html;
}

function generateDropdownMenu($columnTitles, $columnData, $selectWhat)
{
    // Generate a unique identifier for this instance
    $uniqueId = uniqid();

    // Start the HTML structure
    $html = '<div class="dropdown-menu-container">' . "\n";

    // Add the first dropdown menu for the first column data
    $html .= '  <select class="form-select" id="first-dropdown-' . $uniqueId . '">' . "\n";
    $html .= '    <option value="">Select a ' . $selectWhat . '</option>' . "\n";
    foreach ($columnData as $row) {
        $html .= '    <option value="' . htmlspecialchars($row[0]) . '">' . urldecode(htmlspecialchars($row[0])) . '</option>' . "\n";
    }
    $html .= '  </select>' . "\n";

    // Add the second dropdown menu for the titles
    $html .= '  <select class="form-select mt-3" id="second-dropdown-' . $uniqueId . '" disabled>' . "\n";
    $html .= '    <option value="">Select a configuration type</option>' . "\n";
    $html .= '  </select>' . "\n";

    // Add the label to show the related item with Bootstrap styling
    $html .= '  <div class="input-group mt-3">' . "\n";
    $html .= '    <input type="text" class="form-control" id="related-item-' . $uniqueId . '" readonly>' . "\n";
    $html .= '    <button class="btn btn-outline-secondary" type="button" id="copy-button-' . $uniqueId . '">Copy</button>' . "\n";
    $html .= '  </div>' . "\n";

    // Close the HTML structure
    $html .= '</div>' . "\n";

    // Add JavaScript to handle the dropdown change and populate the second dropdown
    $html .= '<script>' . "\n";
    $html .= '  (function() {' . "\n";
    $html .= '    var columnData = ' . json_encode($columnData) . ';' . "\n";
    $html .= '    var columnTitles = ' . json_encode($columnTitles) . ';' . "\n";
    $html .= '    var firstDropdown = document.getElementById("first-dropdown-' . $uniqueId . '");' . "\n";
    $html .= '    var secondDropdown = document.getElementById("second-dropdown-' . $uniqueId . '");' . "\n";
    $html .= '    var relatedItem = document.getElementById("related-item-' . $uniqueId . '");' . "\n";
    $html .= '    var copyButton = document.getElementById("copy-button-' . $uniqueId . '");' . "\n";
    $html .= '    firstDropdown.addEventListener("change", function() {' . "\n";
    $html .= '      var selectedValue = this.value;' . "\n";
    $html .= '      secondDropdown.innerHTML = \'<option value="">Select a title</option>\';' . "\n";
    $html .= '      secondDropdown.disabled = true;' . "\n";
    $html .= '      if (selectedValue) {' . "\n";
    $html .= '        secondDropdown.disabled = false;' . "\n";
    $html .= '        var selectedRow = null;' . "\n";
    $html .= '        for (var i = 0; i < columnData.length; i++) {' . "\n";
    $html .= '          if (columnData[i][0] === selectedValue) {' . "\n";
    $html .= '            selectedRow = columnData[i];' . "\n";
    $html .= '            break;' . "\n";
    $html .= '          }' . "\n";
    $html .= '        }' . "\n";
    $html .= '        if (selectedRow) {' . "\n";
    $html .= '          for (var j = 1; j < selectedRow.length; j++) {' . "\n";
    $html .= '            var option = document.createElement("option");' . "\n";
    $html .= '            option.value = selectedRow[j];' . "\n";
    $html .= '            option.text = columnTitles[j - 1];' . "\n";
    $html .= '            secondDropdown.appendChild(option);' . "\n";
    $html .= '          }' . "\n";
    $html .= '        }' . "\n";
    $html .= '      }' . "\n";
    $html .= '    });' . "\n";

    // Add JavaScript to handle the second dropdown change and show the related item
    $html .= '    secondDropdown.addEventListener("change", function() {' . "\n";
    $html .= '      var selectedValue = this.value;' . "\n";
    $html .= '      relatedItem.value = selectedValue;' . "\n";
    $html .= '    });' . "\n";

    // Add JavaScript to handle the copy button click
    $html .= '    copyButton.addEventListener("click", function() {' . "\n";
    $html .= '      relatedItem.select();' . "\n";
    $html .= '      document.execCommand("copy");' . "\n";
    $html .= '      alert("Copied to clipboard: " + relatedItem.value);' . "\n";
    $html .= '    });' . "\n";
    $html .= '  })();' . "\n";
    $html .= '</script>' . "\n";

    return $html;
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

    $configIp = $config[$configIpName];
    $configPort = $config[$configPortName];
    $lantency = ping($configIp, $configPort, 1);
    if ($lantency !== "down") {
        $getIPLocation = getIPLocation($configIp);
        $configLocation = $getIPLocation["loc"] ?? "XX";
        $configFlag =
            $configLocation === "XX" ? "❔" : getFlags($configLocation);
        $isEncrypted = isEncrypted($config, $type) ? "🔒" : "🔓";
        $configType = $configsTypeName[$type];
        $configNetwork = getNetwork($config, $type);
        $configTLS = getTLS($config, $type);

        return [
            "loc" => $configLocation,
            "name" => "🆔{$source} {$isEncrypted} {$configType}-{$configNetwork}-{$configTLS} {$configFlag} {$configLocation} {$lantency}",
        ];
    }
    return false;
}

function getNetwork($config, $type)
{
    if ($type === "vmess") {
        return strtoupper($config["net"]);
    }
    if (in_array($type, ["vless", "trojan"])) {
        return strtoupper($config["params"]["type"]);
    }
    if (in_array($type, ["tuic", "hysteria", "hysteria2", "hy2"])) {
        return "UDP";
    }
    if ($type === "ss") {
        return "TCP";
    }
    return null;
}

function getTLS($config, $type)
{
    if (($type === "vmess" && $config["tls"] === "tls") || $type === "ss") {
        return "TLS";
    }
    if (
        ($type === "vmess" && $config["tls"] === "") ||
        (in_array($type, ["vless", "trojan"]) &&
            $config["params"]["security"] === "tls") ||
        (in_array($type, ["vless", "trojan"]) &&
            $config["params"]["security"] === "none") ||
        (in_array($type, ["vless", "trojan"]) &&
            empty($config["params"]["security"])) ||
        in_array($type, ["tuic", "hysteria", "hysteria2", "hy2"])
    ) {
        return "N/A";
    }
    return null;
}

function isEncrypted($config, $type)
{
    if (
        $type === "vmess" &&
        $config["tls"] !== "" &&
        $config["scy"] !== "none"
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
    return $output;
}

function ping($host, $port, $timeout)
{
    $tB = microtime(true);
    $fP = fSockOpen($host, $port, $errno, $errstr, $timeout);
    if (!$fP) {
        return "down";
    }
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

    echo /** @scrutinizer ignore-type */ $response;
}

function generateHiddifyTags($type)
{
    $profileTitle = base64_encode("{$type} | VPNineh 🫧");
    return "#profile-title: base64:{$profileTitle}\n#profile-update-interval: 1\n#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire=2546249531\n#support-url: https://hingroup.t.me\n#profile-web-page-url: https://Here_is_Nowhere.t.me
";
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
        ((int) (($gy2 + 3) / 4)) -
        ((int) (($gy2 + 99) / 100)) +
        ((int) (($gy2 + 399) / 400)) -
        80 +
        $gd +
        $g_d_m[$gm - 1];
    $jy += 33 * ((int) ($days / 12053));
    $days %= 12053;
    $jy += 4 * ((int) ($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $jm = $days < 186 ? 1 + (int) ($days / 31) : 7 + (int) (($days - 186) / 30);
    $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);
    return [$jy, $jm, $jd];
}

function getTehranTime()
{
    date_default_timezone_set("Asia/Tehran");

    $date = new DateTime();

    $dayOfWeek = $date->format("D");

    $day = $date->format("d");

    $month = (int) $date->format("m");
    $year = (int) $date->format("Y");

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

    $formattedString = sprintf(
        "%s-%02d-%s-%04d 🕑 %s",
        $dayOfWeek,
        $jd,
        $shortMonth,
        $jy,
        $time
    );

    return $formattedString;
}

function generateUpdateTime()
{
    $tehranTime = getTehranTime();
    return "vless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#⚠️%20FREE%20TO%20USE!\nvless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#🔄%20LATEST-UPDATE%20📅%20{$tehranTime}\n";
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


$source = trim(file_get_contents("source.conf"));
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
$title1ArrayHtml = ["Normal", "Base64", "Hiddify"];
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
$title2ArrayHtml = ["Normal", "Base64", "Hiddify"];
$cells2Array = convertArrays(
    $sourcesColumn,
    $sourceNormals,
    $sourceBase64,
    $sourceHiddify
);

$locationNormals = addStringToBeginning(
    listFilesInDirectory("subscription/location/normal"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$locationBase64 = addStringToBeginning(
    listFilesInDirectory("subscription/location/base64"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$locationHiddify = addStringToBeginning(
    listFilesInDirectory("subscription/location/hiddify"),
    "https://raw.githubusercontent.com/vpnineh/tlgrm/refs/heads/main/"
);
$locationColumn = getFileNamesInDirectory(
    listFilesInDirectory("subscription/location/normal")
);

$title3Array = ["Location", "Normal", "Base64", "Hiddify"];
$title3ArrayHtml = ["Normal", "Base64", "Hiddify"];
$cells3Array = convertArrays(
    $locationColumn,
    $locationNormals,
    $locationBase64,
    $locationHiddify
);

$table1 = generateReadmeTable($title1Array, $cells1Array);
$table2 = generateReadmeTable($title2Array, $cells2Array);
$table3 = generateReadmeTable($title3Array, $cells3Array);

$readmeMdNew = generateReadme($table1, $table2, $table3);
file_put_contents("README.md", $readmeMdNew);

$drop1Html = generateDropdownMenu($title1ArrayHtml, $cells1Array, "Protocol");
$drop2Html = generateDropdownMenu($title2ArrayHtml, $cells2Array, "Source");
$drop3Html = generateDropdownMenu($title3ArrayHtml, $cells3Array, "Location");

$readmeHtmlNew = generateReadmeWeb($drop1Html, $drop2Html, $drop3Html);
file_put_contents("index.html", $readmeHtmlNew);

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
    ]
];

$message = "⏱ {$tehranTime}

<blockquote>📥 Copy => Import config from Clipboard (<a href='https://github.com/mahsanet/NikaNG/releases/latest'>NikaNG</a>): </blockquote>
---- 𝗖𝗢𝗣𝗬 ----
🔎 <code>{$randType}</code>
---- 𝗖𝗢𝗣𝗬 ----

sendMessage($botToken, -1002043507701, $message, "html", $keyboard);

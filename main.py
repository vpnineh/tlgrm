import os
import re
import json
import time
import base64
import socket
import hashlib
import urllib.request
import urllib.error
import urllib.parse
from datetime import datetime, timezone, timedelta

# ================= SETTINGS =================
# قابلیت پینگ‌گیری و فیلتر کانفیگ‌ها. (برای فعال شدن به "yes" تغییر دهید)
ENABLE_PING = "no"

# ================= HELPER FUNCTIONS =================

def ensure_dir(directory):
    if not os.path.exists(directory):
        os.makedirs(directory, exist_ok=True)

def remove_file_in_directory(directory, file_name):
    file_path = os.path.join(directory, file_name)
    if os.path.exists(file_path):
        try:
            os.remove(file_path)
            return True
        except:
            return False
    return False

def add_padding_base64(b64_string):
    """Adds missing padding to a base64 string."""
    return b64_string + "=" * (-len(b64_string) % 4)

def is_base64(s):
    if not s:
        return False
    try:
        decoded = base64.b64decode(add_padding_base64(s)).decode('utf-8', errors='ignore')
        re_encoded = base64.b64encode(decoded.encode('utf-8')).decode('utf-8').rstrip('=')
        s_clean = s.rstrip('=')
        return re_encoded == s_clean
    except Exception:
        return False

def get_random_name():
    import random
    import string
    return ''.join(random.choices(string.ascii_lowercase, k=10))

def remove_angle_brackets(link):
    return re.sub(r"<.*?>", "", link)

def ping(host, port, timeout=1):
    try:
        start_time = time.time()
        with socket.create_connection((host, int(port)), timeout):
            end_time = time.time()
        return f"{int((end_time - start_time) * 1000)}ms"
    except Exception:
        return "down"

# ================= DATE & TIME =================

def gregorian_to_jalali(gy, gm, gd):
    g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    if gy > 1600:
        jy = 979
        gy -= 1600
    else:
        jy = 0
        gy -= 621

    gy2 = gy + 1 if gm > 2 else gy
    days = (365 * gy + int((gy2 + 3) / 4) - int((gy2 + 99) / 100) +
            int((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1])

    jy += 33 * int(days / 12053)
    days %= 12053
    jy += 4 * int(days / 1461)
    days %= 1461

    if days > 365:
        jy += int((days - 1) / 365)
        days = (days - 1) % 365

    jm = 1 + int(days / 31) if days < 186 else 7 + int((days - 186) / 30)
    jd = 1 + (days % 31 if days < 186 else (days - 186) % 30)
    return jy, jm, jd

def get_tehran_time():
    # Tehran Timezone: UTC+3:30
    tz = timezone(timedelta(hours=3, minutes=30))
    now = datetime.now(tz)
    
    gy, gm, gd = now.year, now.month, now.day
    jy, jm, jd = gregorian_to_jalali(gy, gm, gd)
    
    month_names = {
        1: "FAR", 2: "ORD", 3: "KHORDAD", 4: "TIR", 5: "MORDAD", 6: "SHAHRIVAR",
        7: "MEHR", 8: "ABAN", 9: "AZAR", 10: "DEY", 11: "BAHMAN", 12: "ESFAND"
    }
    short_month = month_names.get(jm, "")
    
    day_of_week = now.strftime("%a")
    time_str = now.strftime("%H:%M")
    
    return f"{day_of_week}-{jd:02d}-{short_month}-{jy:04d} 🕑 {time_str}"

def generate_update_time():
    tehran_time = get_tehran_time()
    return (f"vless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#⚠️%20FREE%20TO%20USE!\n"
            f"vless://aaacbbc-cbaa-aabc-dacb-acbacbbcaacb@127.0.0.1:1080?security=tls&type=tcp#🔄%20LATEST-UPDATE%20📅%20{urllib.parse.quote(tehran_time)}\n")

def generate_end_of_configuration():
    return ""

def generate_hiddify_tags(config_type):
    profile_title = base64.b64encode(f"{config_type} | VPNineh 🫧".encode('utf-8')).decode('utf-8')
    return f"#profile-title: base64:{profile_title}\n#profile-update-interval: 1\n#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire=2546249531\n"

# ================= TELEGRAM SCRAPING =================

def extract_min_post_id_from_telegram_html(channel, html):
    ids = []
    # Match data-post="channel/12345"
    pattern1 = re.compile(rf'data-post="{re.escape(channel)}/(\d+)"')
    ids.extend([int(x) for x in pattern1.findall(html)])
    
    # Fallback match /channel/12345
    if not ids:
        pattern2 = re.compile(rf'/{re.escape(channel)}/(\d+)')
        ids.extend([int(x) for x in pattern2.findall(html)])
        
    return min(ids) if ids else None

def fetch_telegram_channel_html_pages(channel, pages=2):
    pages = max(1, min(10, pages))
    all_html = ""
    before = None
    
    headers = {'User-Agent': 'Mozilla/5.0'}
    
    for p in range(1, pages + 1):
        url = f"https://t.me/s/{channel}"
        if before is not None:
            url += f"?before={before}"
            
        try:
            req = urllib.request.Request(url, headers=headers)
            with urllib.request.urlopen(req, timeout=15) as response:
                html = response.read().decode('utf-8', errors='ignore')
        except Exception:
            break
            
        if not html.strip():
            break
            
        all_html += f"\n<!-- PAGE {p} -->\n" + html
        
        min_id = extract_min_post_id_from_telegram_html(channel, html)
        if min_id is None:
            break
            
        before = min_id
        time.sleep(0.25)
        
    return all_html

def get_config_items(prefix, html_string):
    regex = re.compile(r"[a-z]+://\S+", re.IGNORECASE)
    matches = regex.findall(html_string)
    
    output = []
    for match in matches:
        new_matches = match.split("<br/>")
        for new_match in new_matches:
            if new_match.lower().startswith(f"{prefix}://"):
                output.append(new_match)
                
    return list(dict.fromkeys(output)) # Unique items

def is_valid(config_input):
    if "…" in config_input or "..." in config_input:
        return False
    return True

# ================= CONFIG PARSING & DEDUP =================

def sanitize_config_string(config):
    config = remove_angle_brackets(config)
    config = config.replace("amp;", "")
    return config.strip()

def config_parse(config_input, config_type):
    try:
        if config_type == "vmess":
            vmess_data = config_input[8:]
            decoded_data = json.loads(base64.b64decode(add_padding_base64(vmess_data)).decode('utf-8'))
            return decoded_data
            
        elif config_type in ["vless", "trojan", "tuic", "hysteria", "hysteria2", "hy2"]:
            parsed_url = urllib.parse.urlparse(config_input)
            params = dict(urllib.parse.parse_qsl(parsed_url.query))
            
            output = {
                "protocol": config_type,
                "username": parsed_url.username or "",
                "hostname": parsed_url.hostname or "",
                "port": str(parsed_url.port) if parsed_url.port else "",
                "params": params,
                "hash": urllib.parse.unquote(parsed_url.fragment) if parsed_url.fragment else "TVC" + get_random_name()
            }
            if config_type == "tuic":
                output["pass"] = parsed_url.password or ""
            return output
            
        elif config_type == "ss":
            parsed_url = urllib.parse.urlparse(config_input)
            user_part = parsed_url.username or ""
            
            if is_base64(user_part):
                user_part = base64.b64decode(add_padding_base64(user_part)).decode('utf-8', errors='ignore')
                
            if ":" not in user_part:
                # Some ss:// links have the entire user@host:port as base64 in the netloc
                netloc = parsed_url.netloc
                if is_base64(netloc):
                    decoded_netloc = base64.b64decode(add_padding_base64(netloc)).decode('utf-8', errors='ignore')
                    if "@" in decoded_netloc:
                        u_part, h_part = decoded_netloc.split("@", 1)
                        if ":" in u_part:
                            enc, pwd = u_part.split(":", 1)
                            h, p = h_part.split(":", 1)
                            return {
                                "encryption_method": enc, "password": pwd,
                                "server_address": h, "server_port": p,
                                "name": urllib.parse.unquote(parsed_url.fragment) if parsed_url.fragment else "TVC" + get_random_name()
                            }
                return None
                
            encryption_method, password = user_part.split(":", 1)
            return {
                "encryption_method": encryption_method,
                "password": password,
                "server_address": parsed_url.hostname or "",
                "server_port": str(parsed_url.port) if parsed_url.port else "",
                "name": urllib.parse.unquote(parsed_url.fragment) if parsed_url.fragment else "TVC" + get_random_name()
            }
    except Exception:
        return None
    return None

def reparse_config(config_array, config_type):
    if config_type == "vmess":
        encoded_data = base64.b64encode(json.dumps(config_array, separators=(',', ':')).encode('utf-8')).decode('utf-8')
        return "vmess://" + encoded_data
        
    elif config_type in ["vless", "trojan", "tuic", "hysteria", "hysteria2", "hy2"]:
        url = config_type + "://"
        if config_array.get("username"):
            url += config_array["username"]
            if config_array.get("pass"):
                url += ":" + config_array["pass"]
            url += "@"
        url += config_array.get("hostname", "")
        if config_array.get("port"):
            url += ":" + str(config_array["port"])
        if config_array.get("params"):
            url += "?" + urllib.parse.urlencode(config_array["params"])
        if config_array.get("hash"):
            url += "#" + urllib.parse.quote(config_array["hash"])
        return url
        
    elif config_type == "ss":
        user = base64.b64encode(f"{config_array.get('encryption_method', '')}:{config_array.get('password', '')}".encode('utf-8')).decode('utf-8')
        url = f"ss://{user}@{config_array.get('server_address', '')}:{config_array.get('server_port', '')}"
        if config_array.get("name"):
            url += "#" + urllib.parse.quote(config_array["name"])
        return url
        
    return None

def build_dedup_key_from_raw_config(raw_config, config_type):
    clean = sanitize_config_string(raw_config)
    if not clean: return None
    
    parsed = config_parse(clean, config_type)
    if not parsed or not isinstance(parsed, dict): return None
    
    if config_type == "vmess":
        identity = {k: parsed.get(k, "") for k in ["v", "id", "aid", "add", "port", "net", "type", "tls", "sni", "host", "path", "alpn", "fp", "scy", "flow"]}
        sorted_json = json.dumps(identity, sort_keys=True)
        return "vmess:" + hashlib.md5(sorted_json.encode('utf-8')).hexdigest()
        
    elif config_type == "ss":
        identity = {k: parsed.get(k, "") for k in ["server_address", "server_port", "encryption_method", "password"]}
        sorted_json = json.dumps(identity, sort_keys=True)
        return "ss:" + hashlib.md5(sorted_json.encode('utf-8')).hexdigest()
        
    else:
        params = parsed.get("params", {}).copy()
        for key in ["name", "ps", "hash", "remark", "remarks", "title"]:
            params.pop(key, None)
            
        identity = {
            "protocol": config_type,
            "username": parsed.get("username", ""),
            "hostname": parsed.get("hostname", ""),
            "port": parsed.get("port", ""),
            "pass": parsed.get("pass", ""),
            "params": params
        }
        sorted_json = json.dumps(identity, sort_keys=True)
        return config_type + ":" + hashlib.md5(sorted_json.encode('utf-8')).hexdigest()

# ================= NAMING & FORMATTING =================

def get_network(config, config_type):
    if config_type == "vmess":
        return str(config.get("net", "N/A")).upper()
    if config_type in ["vless", "trojan"]:
        return str(config.get("params", {}).get("type", "N/A")).upper()
    if config_type in ["tuic", "hysteria", "hysteria2", "hy2"]:
        return "UDP"
    if config_type == "ss":
        return "TCP"
    return "N/A"

def get_tls(config, config_type):
    if config_type == "vmess" and config.get("tls", "") == "tls": return "TLS"
    if config_type == "ss": return "TLS"
    return "N/A"

def is_encrypted(config, config_type):
    if config_type == "vmess" and config.get("tls", "") != "" and config.get("scy", "") != "none":
        return True
    if config_type in ["vless", "trojan"] and config.get("params", {}).get("security", "") not in ["", "none"]:
        return True
    if config_type in ["ss", "tuic", "hysteria", "hysteria2", "hy2"]:
        return True
    return False

unique_id_counter = 1

def generate_name(config, config_type, source, latency):
    global unique_id_counter
    
    configs_type_name = {
        "vmess": "VM", "vless": "VL", "trojan": "TR", "tuic": "TU",
        "hysteria": "HY", "hysteria2": "HY2", "hy2": "HY2", "ss": "SS"
    }
    
    is_enc = "🔒" if is_encrypted(config, config_type) else "🔓"
    c_type = configs_type_name.get(config_type, "UN")
    
    config_network = get_network(config, config_type)
    config_tls = get_tls(config, config_type)
    
    net_str = f"-{config_network}" if config_network not in ["N/A", ""] else ""
    tls_str = f"-{config_tls}" if config_tls not in ["N/A", ""] else ""
    latency_str = f" {latency}" if latency not in ["N/A", ""] else ""
    
    final_name = f"🆔{source} {is_enc} {c_type}{net_str}{tls_str}-{unique_id_counter}{latency_str}".strip()
    unique_id_counter += 1
    return final_name

def correct_config(config_str, config_type, source, latency="N/A"):
    configs_hash_name = {
        "vmess": "ps", "vless": "hash", "trojan": "hash", "tuic": "hash",
        "hysteria": "hash", "hysteria2": "hash", "hy2": "hash", "ss": "name"
    }
    hash_key = configs_hash_name.get(config_type)
    
    parsed = config_parse(config_str, config_type)
    if not parsed: return config_str
    
    parsed[hash_key] = generate_name(parsed, config_type, source, latency)
    return reparse_config(parsed, config_type) or config_str

# ================= MAIN LOGIC =================

def get_telegram_channel_configs(username_str):
    global ENABLE_PING
    source_array = [s.strip() for s in username_str.split(",") if s.strip()]
    
    type_buckets = {k: "" for k in ["mix", "vmess", "vless", "trojan", "ss", "tuic", "hysteria", "hysteria2"]}
    source_buckets = {}
    seen = {}
    
    types = ["vmess", "vless", "trojan", "ss", "tuic", "hysteria", "hysteria2", "hy2"]
    
    for source in source_array:
        print(f"@{source} => PROGRESS: 0%")
        html = fetch_telegram_channel_html_pages(source, 2)
        
        configs = {t: [] for t in type_buckets}
        configs["hy2"] = []
        
        for t in types:
            items = get_config_items(t, html)
            if t == "hy2":
                configs["hysteria2"].extend(items)
            else:
                configs[t].extend(items)
                
        # Deduplicate within lists
        for k in configs: configs[k] = list(dict.fromkeys(configs[k]))
        
        print(f"@{source} => PROGRESS: 50%")
        source_buckets[source] = ""
        
        for the_type, configs_array in configs.items():
            if the_type == "hy2": continue # Already merged into hysteria2
            
            for config_str in configs_array:
                if not is_valid(config_str): continue
                fixed_config = sanitize_config_string(config_str)
                if not fixed_config: continue
                
                dedup_key = build_dedup_key_from_raw_config(fixed_config, the_type)
                if dedup_key is None or dedup_key in seen: continue
                seen[dedup_key] = True
                
                parsed_config = config_parse(fixed_config, the_type)
                if not parsed_config: continue
                
                config_ip_names = {
                    "vmess": "add", "vless": "hostname", "trojan": "hostname",
                    "tuic": "hostname", "hysteria": "hostname", "hysteria2": "hostname",
                    "hy2": "hostname", "ss": "server_address"
                }
                config_port_names = {
                    "vmess": "port", "vless": "port", "trojan": "port",
                    "tuic": "port", "hysteria": "port", "hysteria2": "port",
                    "hy2": "port", "ss": "server_port"
                }
                
                c_ip = parsed_config.get(config_ip_names.get(the_type), "")
                c_port = parsed_config.get(config_port_names.get(the_type), "")
                
                latency = "N/A"
                if ENABLE_PING.lower() == "yes":
                    latency = ping(c_ip, c_port, 1) if c_ip and c_port else "N/A"
                    if latency not in ["down", "N/A"]:
                        continue # Filter Iran conditions: only keep 'down'
                        
                corrected_config = correct_config(fixed_config, the_type, source, latency)
                
                type_buckets["mix"] += corrected_config + "\n"
                if the_type in type_buckets:
                    type_buckets[the_type] += corrected_config + "\n"
                source_buckets[source] += corrected_config + "\n"
                
        if source_buckets[source].strip():
            ensure_dir("subscription/source/normal")
            ensure_dir("subscription/source/base64")
            ensure_dir("subscription/source/hiddify")
            
            configs_source = generate_update_time() + source_buckets[source] + generate_end_of_configuration()
            
            with open(f"subscription/source/normal/{source}", "w", encoding='utf-8') as f:
                f.write(configs_source)
            with open(f"subscription/source/base64/{source}", "w", encoding='utf-8') as f:
                f.write(base64.b64encode(configs_source.encode('utf-8')).decode('utf-8'))
            with open(f"subscription/source/hiddify/{source}", "w", encoding='utf-8') as f:
                f.write(base64.b64encode((generate_hiddify_tags(f"@{source}") + "\n" + configs_source).encode('utf-8')).decode('utf-8'))
                
            print(f"@{source} => PROGRESS: 100%")
        else:
            print(f"@{source} => NO CONFIG FOUND (NOT REMOVED - TEMP DISABLED)")
            
    ensure_dir("subscription/normal")
    ensure_dir("subscription/base64")
    ensure_dir("subscription/hiddify")
    
    types_out = ["mix", "vmess", "vless", "trojan", "ss", "tuic", "hysteria", "hysteria2"]
    
    for filename in types_out:
        if type_buckets[filename].strip():
            configs_type = generate_update_time() + type_buckets[filename] + generate_end_of_configuration()
            
            with open(f"subscription/normal/{filename}", "w", encoding='utf-8') as f:
                f.write(configs_type)
            with open(f"subscription/base64/{filename}", "w", encoding='utf-8') as f:
                f.write(base64.b64encode(configs_type.encode('utf-8')).decode('utf-8'))
            with open(f"subscription/hiddify/{filename}", "w", encoding='utf-8') as f:
                f.write(base64.b64encode((generate_hiddify_tags(filename.upper()) + "\n" + configs_type).encode('utf-8')).decode('utf-8'))
                
            print(f"#{filename} => CREATED SUCCESSFULLY!!")
        else:
            remove_file_in_directory("subscription/normal", filename)
            remove_file_in_directory("subscription/base64", filename)
            remove_file_in_directory("subscription/hiddify", filename)
            print(f"#{filename} => WAS EMPTY, I REMOVED IT!")

# ================= RUN =================

if __name__ == "__main__":
    try:
        with open("source.conf", "r", encoding='utf-8') as f:
            source_conf = f.read().strip()
    except FileNotFoundError:
        source_conf = "" # Fallback or handle error if file missing

    if source_conf:
        get_telegram_channel_configs(source_conf)
    else:
        print("source.conf not found or empty.")

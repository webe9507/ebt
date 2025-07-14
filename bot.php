<?php

class Ebm {
    public string $host;

    private string $cookie, $ua;
    private bool $cookieSet = false, $uaSet = false;

    public function setCookie(string $cookie): void {
        $this->cookie = $cookie;
        $this->cookieSet = true;
    }

    public function setUserAgent(string $ua): void {
        $this->ua = $ua;
        $this->uaSet = true;
    }

    private function ensureReady(): void {
        if (!$this->cookieSet || !$this->uaSet) {
            exit("[ERROR] Cookie dan User-Agent harus di-set terlebih dahulu.\nGunakan setCookie() dan setUserAgent().\n");
        }
    }

    private function buildGlobalHeaders(): array {
        return [
            "Host: " . parse_url($this->host, PHP_URL_HOST),
            "cookie: " . $this->cookie,
            "user-agent: " . $this->ua
        ];
    }

    private function mergeHeaders(array $headers): array {
        $global = $this->buildGlobalHeaders();
        $mergedAssoc = [];

        foreach (array_merge($global, $headers) as $header) {
            [$key, $value] = explode(":", $header, 2);
            $mergedAssoc[strtolower(trim($key))] = trim($value);
        }

        $final = [];
        foreach ($mergedAssoc as $key => $value) {
            $final[] = "$key: $value";
        }

        return $final;
    }

    private function requests(string $method, string $endpoint, array $headers, $data = null) {
        $this->ensureReady();

        $url = $this->host . $endpoint;
        $method = strtoupper($method);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        //curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);
        curl_setopt($curl, CURLOPT_RESOLVE, [
        	"earnbitmoon.club:443:104.26.13.122",
        //	"earnbitmoon.club:443:172.67.72.62",
        //	"earnbitmoon.club:443:104.26.12.122"
        ]);

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
        }

        $headers = $this->mergeHeaders($headers);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            print_r(['error' => $err]);
            exit;
        }

        return ["httpCode" => $httpCode, "body" => $response];
    }

    public function getDashboard(): string {
        return $this->requests("GET", '/', [])["body"];
    }

    public function verifFaucet(string $data): string {
        $headers = [
            'referer: https://earnbitmoon.club/',
            'content-length: ' . strlen($data),
            'accept: application/json, text/javascript, */*; q=0.01',
            'content-type: application/x-www-form-urlencoded; charset=UTF-8',
            'x-requested-with: XMLHttpRequest'
        ];
        return $this->requests("POST", '/system/ajax.php', $headers, $data)["body"];
    }

    public function claimFaucet(string $token)
    {
        $captcha = $this->bypassCaptcha();
        if (!$captcha) return;

        $data = array_merge([
            "a" => "getFaucet",
            "token" => $token
        ], $captcha);

        $payload = http_build_query($data);
        return json_decode($this->verifFaucet($payload),1);
    }

    private function bypassCaptcha()
    {
        
        $payloadStage1 = ['payload' => base64_encode(json_encode([
            "i" => 1,
            "a" => 1,
            "t" => "dark",
            "ts" => round(microtime(true) * 1000)
        ]))];

        $res1 = $this->requests("POST", "/system/libs/captcha/request.php", ['x-requested-with: XMLHttpRequest'], $payloadStage1);
        $response = json_decode(base64_decode($res1["body"]), true);
        if (isset($response["error"])) {
            print "[LOG] GetCaptcha Error\n";
            sleep(60);
            return;
        }

        // Stage 2: Get captcha image
        $payloadStage2 = base64_encode(json_encode([
            "i" => 1,
            "ts" => round(microtime(true) * 1000)
        ]));
        
        $img = $this->requests("GET", "/system/libs/captcha/request.php?payload={$payloadStage2}", ["accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8"])["body"];
        if (!$img) {
            print "[LOG] GetImgCaptcha Error\n";
            sleep(5);
            return;
        }

        sleep(2);
        $captcha = [74, 128, 184, 287, 278];
        foreach ($captcha as $x) {
            $data = ['payload' => base64_encode(json_encode([
                "i" => 1,
                "x" => $x,
                "y" => 32,
                "w" => 279.562,
                "a" => 2,
                "ts" => round(microtime(true) * 1000)
            ]))];
            $verify = $this->requests("POST", "/system/libs/captcha/request.php", ['x-requested-with: XMLHttpRequest'], $data);
            if ($verify["httpCode"] == 200) {
                return [
                    "captcha_type" => "0",
                    "challenge" => "false",
                    "response" => "false",
                    "ic-hf-id" => 1,
                    "ic-hf-se" => "$x,32,320",
                    "ic-hf-hp" => "",
                    "hp_field" => ""
                ];
            }
            sleep(2);
        }
        return;
    }
}

function simpan($data, $file = "data.json") {
    if (file_exists($file)) {
        $json = file_get_contents($file);
        $existingData = json_decode($json, true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    } else {
        $existingData = [];
    }
    if (is_string($data)) {
        if (array_key_exists($data, $existingData)) {
            return $existingData[$data];
        }
        $value = readline("Input `$data`: ");
        $data = [$data => $value];
    } else {
        $value = reset($data);
    }
    $mergedData = array_merge($existingData, $data);
    file_put_contents($file, json_encode($mergedData, JSON_PRETTY_PRINT));
    return $value;
}

function hapus($key, $file = "data.json") {
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data) || !array_key_exists($key, $data)) {
        return false; // Key tidak ada
    }
    unset($data[$key]);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    return true;
}
function tmr($detik) {
    while ($detik > 0) {
        echo "\rCooldown: {$detik}s ";
        sleep(1);
        $detik--;
    }
    echo "\r              \r";
}
function line(){
	print str_repeat("~", 50)."\n";
}
function clear() {
    (PHP_OS == "Linux") ? system('clear') : pclose(popen('cls','w'));
}
function banner(){
    clear();
	print "Author\t:: BANGJOY\nTitle\t:: earnbitmoon\nRegist\t:: https://earnbitmoon.club/?ref=1110219\n";
	line();
}

error_reporting(0);
banner();
cookie:
$cookie = simpan("cookie");
$user_agent = simpan("user-agent");
banner();

$api = new Ebm();

$api->host = "https://earnbitmoon.club";
$api->setCookie($cookie);
$api->setUserAgent($user_agent);


$dash = $api->getDashboard();
$user = explode("'", explode("siteUserFullName: '", $dash)[1])[0];
$balance = explode('</b>', explode('<b id="sidebarCoins">', $dash)[1])[0];
if(!$user){
	if(preg_match('/Just a moment.../', $dash)){
		print "cloudflare\n";
		goto cookie;
	}
	hapus("cookie");
	hapus("user-agent");
	goto cookie;
}

print "user\t:: $user\n";
print "balance\t:: $balance\n";
line();


/* Faucet */

$cok = 0;
while(true)
{
	if($cok == 10){
		hapus("cookie");
		hapus("user-agent");
		goto cookie;
	}
	$r = $api->getDashboard();
	if(preg_match('/Just a moment.../', $dash)){
		$cok += 1;
		print "cloudflare {$cok}\n";
		continue;
	}
	$cok = 0;
	
	$token = explode("'",explode("var token = '", $r)[1])[0];
	
	if(preg_match("/You can claim again in/", $r))
	{
		$claim = explode(',', explode('$("#claimTime").countdown(', $r)[1])[0];//1751436274000,
		$nextClaim = $claim/1000;
		$now = time();
		$timer = $nextClaim-$now;
		if($timer){
			tmr($timer);
			continue;
		}else{
			exit("eror\n");
		}
	}
	
	$claimFaucet = $api->claimFaucet($token);
	if(isset($claimFaucet["status"]) && $claimFaucet["status"] == 200){
		print strip_tags($claimFaucet["message"])."\n";
		$dash = $api->getDashboard();
		$balance = explode('</b>', explode('<b id="sidebarCoins">', $dash)[1])[0];
		print "balance\t:: $balance\n";
		line();
	}
}



?>
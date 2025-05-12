<?php
class KakaoLinkException extends Exception {}
class KakaoLinkReceiverNotFoundException extends KakaoLinkException {}
class KakaoLinkLoginException extends KakaoLinkException {}
class KakaoLinkSendException extends KakaoLinkException {}
class KakaoLink2FAException extends KakaoLinkException {}

class Zakao {
    private string $appKey;
    private string $origin;
    private string $authToken;
    private string $cookieJarFile = './cookies/cookiejar.txt';

    public function __construct(string $appKey, string $origin, string $authToken = '') {
        $this->appKey = $appKey;
        $this->origin = $origin;
        $this->authToken = $authToken;
    }

    public function send(string $receiverName, int $templateId, array $templateArgs, bool $searchExact = true, string $searchFrom = 'ALL', string $searchRoomType = 'ALL'): void {
        if (!file_exists($this->cookieJarFile) || !$this->checkAuthorized()) {
            $this->login();
        }
        $pickerData = $this->getPickerData($templateId, $templateArgs);
        foreach (['checksum','csrfToken','shortKey'] as $k) {
            if (!isset($pickerData[$k])) {
                throw new KakaoLinkSendException('Invalid picker data'.json_encode($pickerData, true));
            }
        }
        $receiver = $this->searchReceiver($receiverName, $pickerData, $searchExact, $searchFrom, $searchRoomType);
        $this->pickerSend($pickerData, $receiver);
    }

    private function httpRequest(string $url, string $method = 'GET', array $headers = [], $data = null): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJarFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR,  $this->cookieJarFile);
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            throw new KakaoLinkException('cURL Error: ' . curl_error($ch));
        }
        $info = curl_getinfo($ch);
        $headerSize = $info['header_size'];
        curl_close($ch);
        return [
            'body'   => substr($resp, $headerSize),
            'status' => $info['http_code'],
        ];
    }

    private function checkAuthorized(): bool {
        $url = 'https://e.kakao.com/api/v1/users/me';
        $h = $this->getWebHeaders();
        $h[] = 'referer: https://e.kakao.com/';
        $resp = $this->httpRequest($url, 'GET', $h);
        $json = json_decode($resp['body'], true);
        return isset($json['result']['status']) && $json['result']['status'] === 'VALID';
    }

    private function login(): void {
        $tgt = $this->getTgtToken();
        $this->submitTgtToken($tgt);
        if (!$this->checkAuthorized()) {
            throw new KakaoLinkLoginException('Login failed');
        }
    }

    private function getTgtToken(): string {
        $url = 'https://api-account.kakao.com/v1/auth/tgt';
        $headers = [];
        foreach ($this->getAppHeaders($this->authToken) as $k => $v) {
            $headers[] = "$k: $v";
        }
        $data = http_build_query([
            'key_type' => 'talk_session_info',
            'key' => $this->authToken,
            'referer' => 'talk',
        ]);
        $resp = $this->httpRequest($url, 'POST', $headers, $data);
        $json = json_decode($resp['body'], true);
        if (!isset($json['code'], $json['token']) || $json['code'] !== 0) {
            throw new KakaoLinkLoginException('TGT token error'.$resp['body']);
        }
        return $json['token'];
    }

    private function submitTgtToken(string $tgt): void {
        $url = 'https://e.kakao.com';
        $h = $this->getWebHeaders();
        $h[] = "ka-tgt: $tgt";
        $this->httpRequest($url, 'GET', $h);
    }

    private function getPickerData(int $templateId, array $templateArgs) {
        $url = 'https://sharer.kakao.com/picker/link';
        $h = $this->getWebHeaders();
        $data = http_build_query([
            'app_key' => $this->appKey,
            'ka' => $this->getKa(),
            'validation_action' => 'custom',
            'validation_params' => json_encode([
                'link_ver' => '4.0',
                'template_id' => $templateId,
                'template_args' => $templateArgs,
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $resp = $this->httpRequest($url, 'POST', $h, $data);
        if (strpos($resp['body'], '/talk_tms_auth/service') !== false) {
            $continue = $this->solveTwoFactorAuth($resp['body']);
            $resp = $this->httpRequest($continue, 'GET', $this->getWebHeaders());
        }
        if (!preg_match('/window\.serverData\s*=\s*"([^"]+)"/', $resp['body'], $m)) {
            throw new KakaoLinkSendException('Failed to extract picker data');
        }
        $b64 = $m[1];
        $json = json_decode(base64_url_decode($b64), true);
        return $json['data'] ?? [base64_url_decode($m[1])];
    }

    private function solveTwoFactorAuth(string $html): string {
        if (!preg_match('/<script id="__NEXT_DATA__".*?>(.*?)<\/script>/s', $html, $m)) {
            throw new KakaoLink2FAException('2FA JSON not found');
        }
        $props = json_decode($m[1], true);
        $ctx = $props['props']['pageProps']['pageContext']['context'];
        $common = $props['props']['pageProps']['pageContext']['commonContext'];
        $token = $ctx['token'];
        $continueUrl = $ctx['continueUrl'];
        $csrf = $common['_csrf'];
        $this->confirmToken($token);
        $pollUrl = 'https://accounts.kakao.com/api/v2/talk_tms_auth/poll_from_service.json';
        $body = json_encode(['_csrf' => $csrf, 'token' => $token]);
        $resp = $this->httpRequest($pollUrl, 'POST', ['Content-Type: application/json'], $body);
        $j = json_decode($resp['body'], true);
        if (($j['status'] ?? -1) !== 0) {
            throw new KakaoLink2FAException('2FA poll failed');
        }
        return $continueUrl;
    }

    private function confirmToken(string $token): void {
        $params = http_build_query([
            'os' => 'android',
            'country_iso' => 'KR',
            'lang' => 'ko',
            'v' => '25.2.1',
            'os_version' => '33',
            'page' => 'additional_auth_with_token',
            'additional_auth_token' => $token,
            'close_on_completion' => 'true',
            'talk_tms_auth_type' => 'from_service',
        ]);
        $url = 'https://auth.kakao.com/fa/main.html?' . $params;
        $this->httpRequest($url, 'GET', $this->getWebHeaders());
    }

    private function searchReceiver(string $name, array $pickerData, bool $exact, string $from, string $roomType): array {
        $c = [];
        if (in_array($from, ['ALL','CHATROOMS'])) {
            $c = array_merge($c, $pickerData['chats'] ?? []);
        }
        if (in_array($from, ['ALL','FRIENDS'])) {
            $c = array_merge($c, $pickerData['friends'] ?? []);
        }
        if (in_array($from, ['ALL','ME'])) {
            $c[] = $pickerData['me'];
        }
        foreach ($c as $r) {
            $ct = $r['chat_room_type'] ?? null;
            $t = $r['title'] ?? ($r['profile_nickname'] ?? '');
            if ($ct && $roomType !== 'ALL' && $roomType !== $ct) {
                continue;
            }
            if (($exact && $t === $name) || (!$exact && mb_strpos($t, $name) !== false)) {
                return $r;
            }
        }
        throw new KakaoLinkReceiverNotFoundException("Receiver '{$name}' not found");
    }

    private function pickerSend(array $pickerData, array $receiver): void {
        $url = 'https://sharer.kakao.com/picker/send';
        $enc = rtrim(strtr(base64_encode(json_encode($receiver, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        $data = http_build_query([
            'app_key' => $this->appKey,
            'short_key' => $pickerData['shortKey'],
            'checksum' => $pickerData['checksum'],
            '_csrf' => $pickerData['csrfToken'],
            'receiver' => $enc,
        ]);
        $resp = $this->httpRequest($url, 'POST', [], $data);
        if ($resp['status'] >= 400) {
            throw new KakaoLinkSendException('Send failed, HTTP ' . $resp['status']);
        }
    }

    private function getKa(): string {
        return 'sdk/1.43.5 os/javascript sdk_type/javascript lang/ko-KR device/Linux armv7l origin/' . rawurlencode($this->origin);
    }

    private function getWebHeaders(): array {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-G998B Build/TP1A.220624.014; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/114.0.5735.60 Mobile Safari/537.36 KAKAOTALK/25.2.1 (INAPP)';
        return ["User-Agent: {$ua}", "X-Requested-With: com.kakao.talk"];
    }

    private function generateUuidV4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function getAppHeaders(string $token): array {
        return [
            'A' => 'android/25.2.1/ko',
            'C' => $this->generateUuidV4(),
            'User-Agent' => 'KT/25.2.1 An/13 ko',
            'Authorization' => $token,
        ];
    }
}

function base64_url_decode($input) {
    $input = strtr($input, '-_', '+/');
    $padding = strlen($input) % 4;
    if ($padding > 0) {
        $input .= str_repeat('=', 4 - $padding);
    }
    return base64_decode($input);
}

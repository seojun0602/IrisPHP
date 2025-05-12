<?php

interface Attachment {
    public function getType(): int;
    public function build(): array;
}

class ChatHelper {

    private string $authToken = '';
    private string $deviceUuid = '';
        private string $url = 'https://talk-api.naijun.dev/api/v1/send';
    public function setCred($cred) {
        $this->authToken = $cred->access_token;
        $this->deviceUuid = $cred->d_id;
    }

    private function generateMsgId(int $counter = 0): int {
        $timestamp = round(microtime(true) * 1000);
        $modValue = 2147483547;
        $roundedTime = floor(($timestamp % $modValue) / 100) * 100;
        return $roundedTime + $counter;
    }

    public function sendMessage(int $chatId, string $message, $attachment = null) {
        if(gettype($attachment)=="object"){
        $type = $attachment?->getType() ?? 1;
        $attachmentData = $attachment ? json_encode($attachment->build(), JSON_UNESCAPED_UNICODE) : '{}';
        } else if($attachment!=null){
        $type = 1;
        $attachmentData = $attachment ? json_encode($attachment, JSON_UNESCAPED_UNICODE) : '{}';
        }else{
         $type = 1;
         $attachmentData = '{}';
        }

        $payload = [
            'chatId' => $chatId,
            'type' => $type,
            'message' => $message,
            'attachment' => json_decode($attachmentData),
            'msgId' => $this->generateMsgId()
        ];
        
        $headers = [
            'Authorization: ' . $this->authToken.'-'. $this->deviceUuid,
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: okhttp/4.12.0',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive'
        ];

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => ''
        ]);
        
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $response;
        return [
            'status' => $status,
            'body' => $response
        ];
    }
}


class TextAttachment implements Attachment {
    public function getType(): int {
        return 1;
    }

    public function build(): array {
        return [];
    }
}

class MentionAttachment implements Attachment {
    public function __construct(
        private int $userId,
        private string $nickname,
        private array $at = []
    ) {}

    public function getType(): int {
        return 1;
    }

    public function build(): array {
        return [
            'mentions' => [
                [
                    'at' => $this->at,
                    'len' => mb_strlen($this->nickname),
                    'user_id' => $this->userId,
                ]
            ]
        ];
    }
}

class ReplyAttachment implements Attachment {

    private int $logId;
    private int $userId;
    private string $message;
    private $type;
    private bool $attachOnly;
    private array $mentions;
    private ?array $subAttachment;

    public function __construct(
        int $logId,
        int $userId,
        string $message,
        int $link_id = null,
        $type = 1,
        bool $attachOnly = false,
        array $mentions = [],
        ?array $subAttachment = null
    ) {
        $this->logId = $logId;
        $this->userId = $userId;
        $this->message = $message;
        $this->link_id = $link_id;
        $this->type = $type;
        $this->attachOnly = $attachOnly;
        $this->mentions = $mentions;
        $this->subAttachment = $subAttachment;
    }

    public function getType(): int {
        return 26;
    }

    public function build(): array {
        $data = [
            'attach_only' => $this->attachOnly,
            'src_logId' => $this->logId,
            'src_userId' => $this->userId,
            'src_message' => $this->message,
            'src_type' => $this->type,
        ];
        
        if (!empty($this->link_id)) {
            $data['src_linkId'] = $this->link_id;
        }
        if (!empty($this->mentions)) {
            $data['src_mentions'] = $this->mentions;
        }

        if ($this->subAttachment) {
            $data['attach_type'] = $this->subAttachment['type'] ?? null;
            $data = array_merge($data, $this->subAttachment['payload'] ?? []);
        }

        return $data;
    }
}

class WebClient {
    private string $baseUrl;

    public function __construct(string $url) {
        $this->baseUrl = $url;
    }

    public function requestMultipartText(string $method, string $endpoint, array $data, array $headers): string {
        $ch = curl_init($this->baseUrl . '/' . $endpoint);

        $headerList = [];
        foreach ($headers as $key => $value) {
            $headerList[] = $key . ': ' . $value;
        }

        foreach ($data as $key => &$value) {
            if (is_array($value) && isset($value['value'], $value['options'])) {
                $value = new CURLFile(
                    $value['value'],
                    $value['options']['mime_type'] ?? '',
                    $value['options']['filename'] ?? ''
                );
            }
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerList,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);
        return $response;
    }
}

class AttachmentApi {
    private WebClient $mediaClient;
    private WebClient $videoClient;
    private WebClient $audioClient;

    public function __construct() {
        $this->mediaClient = new WebClient('https://up-m.talk.kakao.com');
        $this->videoClient = new WebClient('https://up-v.talk.kakao.com');
        $this->audioClient = new WebClient('https://up-a.talk.kakao.com');
    }

    private function getUserAgent(): string {
        return 'okhttp/4.12.0';
    }

    private function getReqClient(string $type): WebClient {
        return match ($type) {
            'video' => $this->videoClient,
            'audio' => $this->audioClient,
            default => $this->mediaClient
        };
    }

    private function getMimeType(string $type): string {
        return match ($type) {
            'photo', 'multi-photo' => 'image/jpeg',
            'contact' => 'text/x-vcard',
            'video' => 'video/mp4',
            'audio' => 'audio/m4a',
            default => 'application/octet-stream'
        };
    }

    public function upload(string $type, string $filePath): array {
    if (!file_exists($filePath)) {
        throw new Exception("File does not exist: $filePath");
    }

    $mimeType = $this->getMimeType($type);

    $curl = curl_init();

    $postFields = [
        'file' => new CURLFile($filePath, $mimeType, $filePath),
        'user_id' => '-1',
        'attachment_type' => $mimeType,
    ];

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://up-m.talk.kakao.com/upload',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPHEADER => [
            'A: An/25.2.1/ko',
        ],
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($httpCode !== 200) {
        throw new Exception("Upload failed with status code $httpCode: $response");
    }

    return [
        'success' => true,
        'status' => 'SUCCESS',
        'result' => [
            'path' => $response,
            'size' => filesize($filePath),
        ],
    ];
}

}

?>

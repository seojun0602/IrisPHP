<?php
/*
  [Option]
  - If you remove this comment, the response will be handled by talk-api.naijun.dev.
  - If you leave this comment, the response will be handled by Iris.
*/

/*
@include "./modules/ChatHelper.php";
@include "./modules/cred.php";
*/

function onMessage(callable $callback)
{
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid data"]);
        return;
    }
    [
        "msg" => $msg,
        "room" => $room,
        "sender" => $sender,
        "json" => $json
    ] = $data;

    [
        "chat_id" => $chatId,
        "user_id" => $userId,
        "attachment" => $attachment,
        "created_at" => $createdAt,
        "deleted_at" => $deletedAt,
        "id" => $logId,
        "_id" => $id,
        "type" => $type
    ] = $json;
    $message = new Message($msg, $room, $chatId, $sender, $userId, $attachment, $logId, $id, $type, $createdAt, $deletedAt);
    $callback($message);
}

class Http
{
    public static function request(mixed $option, ?callable $callback = null)
    {
        if (is_string($option)) {
            $option = ['url' => $option];
        }

        $url = $option['url'] ?? '';
        $method = strtoupper($option['method'] ?? 'GET');
        $headers = $option['headers'] ?? [];
        $data = $option['data'] ?? null;
        $dataType = $option['dataType'] ?? 'json';
        $verifySsl = $option['verifySsl'] ?? false;
        $cookieFile = $option['cookieFile'] ?? null;
        $useCookies = $option['useCookies'] ?? false;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        
        if ($useCookies && $cookieFile) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        }
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($method !== 'GET') {
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            if ($data !== null) {
                $payload = $dataType === 'json' ? json_encode($data) : $data;
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
        }

        if (!empty($headers)) {
            $curlHeaders = [];
            foreach ($headers as $key => $value) {
                $curlHeaders[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        }

        if ($callback !== null) {
            $response = curl_exec($ch);
            $error = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);
            $doc = $response;
            $callback($error, $response, $doc);
            return;
        } else {
            $response = curl_exec($ch);
            $error = curl_errno($ch) ? curl_error($ch) : null;
            curl_close($ch);
            if ($error) {
                return $error;
            }
            return $response;
        }
    }

    public static function requestSync(mixed $option)
    {
        if (is_string($option)) {
            $option = ['url' => $option];
        }
        return self::request($option);
    }
    
    public static function requestAsync(array $options, callable $callback)
    {
        $multiCurl = curl_multi_init();
        $curlHandles = [];
        $responses = [];

        foreach ($options as $index => $option) {
            $url = $option['url'] ?? '';
            $method = strtoupper($option['method'] ?? 'GET');
            $headers = $option['headers'] ?? [];
            $data = $option['data'] ?? null;
            $dataType = $option['dataType'] ?? 'json';
            $verifySsl = $option['verifySsl'] ?? false;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

            if ($method !== 'GET') {
                if ($method === 'POST') {
                    curl_setopt($ch, CURLOPT_POST, true);
                } else {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                }
                if ($data !== null) {
                    $payload = $dataType === 'json' ? json_encode($data) : $data;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            }

            if (!empty($headers)) {
                $curlHeaders = [];
                foreach ($headers as $key => $value) {
                    $curlHeaders[] = "$key: $value";
                }
                curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
            }

            $curlHandles[$index] = $ch;
            curl_multi_add_handle($multiCurl, $ch);
        }

        do {
            $status = curl_multi_exec($multiCurl, $active);
            if ($active) {
                curl_multi_select($multiCurl);
            }
        } while ($active && $status == CURLM_OK);

        foreach ($curlHandles as $index => $ch) {
            $response = curl_multi_getcontent($ch);
            $error = curl_errno($ch) ? curl_error($ch) : null;
            curl_multi_remove_handle($multiCurl, $ch);
            $responses[$index] = ['error' => $error, 'response' => $response];
        }

        curl_multi_close($multiCurl);
        $callback($responses);
    }
}


class Iris
{
    public array $urls = [];
    public string $url;

    public function __construct(string $jsonPath)
    {
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid URL config format");
        }

        $this->urls = array_map(fn($u) => rtrim($u, '/'), array_values($data));
        $this->url = $this->urls[0];
    }

    public function endPoints(string $endpoint): array
    {
        return array_map(fn($base) => $base . '/' . ltrim($endpoint, '/'), $this->urls);
    }
    
    public function endPoint(string $endpoint)
    {
        return (rtrim($this->urls[0], '/') . '/' . ltrim($endpoint, '/'));
    }

    public function __toString(): string
    {
        return implode(", ", $this->urls);
    }
}

class Ztring
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function includes(string $needle): bool
    {
        return str_contains($this->value, $needle);
    }

    public function replace(string $search, string $replace): self
    {
        $this->value = str_replace($search, $replace, $this->value);
        return $this;
    }
    
    public function match($pattern) {
      $matches = [];
      if (@preg_match($pattern, '') === false) {
          $pattern = '/' . preg_quote($pattern, '/') . '/';
      }

      if (preg_match_all($pattern, $this->value, $matches)) {
         return $matches[0];
       } else {
         return null;
       }
    }

    public function startsWith(string $needle): bool
    {
        return str_starts_with($this->value, $needle);
    }

    public function toUpper(): self
    {
        $this->value = strtoupper($this->value);
        return $this;
    }
    
    public function substr(int $start, ?int $length = null): self
    {
        $newValue = $length === null
            ? substr($this->value, $start)
            : substr($this->value, $start, $length);

        return new self($newValue);
    }

    public function get(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function split(string $delimiter): Zrray
    {
        return new Zrray(array_map(
            fn($s) => new Ztring($s),
            explode($delimiter, $this->value)
        ));
    }
    
    public function __get(string $name): mixed
    {
        return match ($name) {
            'length' => mb_strlen($this->value),
            default => null
        };
    }
}

class Zrray implements ArrayAccess, Iterator, Countable
{
    private array $items;
    private int $position = 0;

    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'length' => count($this->items),
            default => null
        };
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
        $this->items = array_values($this->items);
    }

    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    public function key(): int
    {
        return $this->position;
    }

    public function next(): void
    {
        ++$this->position;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function push(mixed ...$values): self
    {
        array_push($this->items, ...$values);
        return $this;
    }

    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    public function unshift(mixed ...$values): self
    {
        array_unshift($this->items, ...$values);
        return $this;
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items));
    }

    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback)));
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function forEach(callable $callback): self
    {
        foreach ($this->items as $index => $value) {
            $callback($value, $index, $this);
        }
        return $this;
    }

    public function includes(mixed $value): bool
    {
        return in_array($value, $this->items, true);
    }

    public function indexOf(mixed $value): int
    {
        $index = array_search($value, $this->items, true);
        return $index === false ? -1 : $index;
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length));
    }

    public function concat(array|Zrray ...$arrays): self
    {
        $result = $this->items;
        foreach ($arrays as $arr) {
            $result = array_merge($result, $arr instanceof self ? $arr->toArray() : $arr);
        }
        return new self($result);
    }

    public function reverse(): self
    {
        return new self(array_reverse($this->items));
    }

    public function sort(?callable $comparator = null): self
    {
        $sorted = $this->items;
        $comparator
            ? usort($sorted, $comparator)
            : usort($sorted, fn($a, $b) => strcmp((string)$a, (string)$b));
        return new self($sorted);
    }

    public function join(string $separator = ","):Ztring
    {
        return new Ztring(implode($separator, $this->items));
    }

    public function find(callable $callback): mixed
    {
        foreach ($this->items as $i => $item) {
            if ($callback($item, $i, $this)) return $item;
        }
        return null;
    }

    public function findIndex(callable $callback): int
    {
        foreach ($this->items as $i => $item) {
            if ($callback($item, $i, $this)) return $i;
        }
        return -1;
    }

    public function every(callable $callback): bool
    {
        foreach ($this->items as $i => $item) {
            if (!$callback($item, $i, $this)) return false;
        }
        return true;
    }

    public function some(callable $callback): bool
    {
        foreach ($this->items as $i => $item) {
            if ($callback($item, $i, $this)) return true;
        }
        return false;
    }

    public function flat(int $depth = 1): self
    {
        $flatten = function ($array, $depth) use (&$flatten) {
            $result = [];
            foreach ($array as $item) {
                if (($item instanceof self || is_array($item)) && $depth > 0) {
                    $itemArray = $item instanceof self ? $item->toArray() : $item;
                    $result = array_merge($result, $flatten($itemArray, $depth - 1));
                } else {
                    $result[] = $item;
                }
            }
            return $result;
        };
        return new self($flatten($this->items, $depth));
    }

    public function flatMap(callable $callback): self
    {
        $result = [];
        foreach ($this->items as $i => $item) {
            $res = $callback($item, $i, $this);
            $result = array_merge($result, is_array($res) ? $res : [$res]);
        }
        return new self($result);
    }

    public function fill(mixed $value, int $start = 0, ?int $end = null): self
    {
        $length = count($this->items);
        $end ??= $length;
        for ($i = $start; $i < $end; $i++) {
            if ($i >= 0 && $i < $length) {
                $this->items[$i] = $value;
            }
        }
        return $this;
    }

    public function splice(int $start, ?int $deleteCount = null, mixed ...$items): self
    {
        $length = count($this->items);
        $start = $start < 0 ? $length + $start : $start;
        $deleteCount = $deleteCount ?? ($length - $start);
        $removed = array_splice($this->items, $start, $deleteCount, $items);
        return new self($removed);
    }

    public function copyWithin(int $target, int $start, ?int $end = null): self
    {
        $length = count($this->items);
        $target = $target < 0 ? $length + $target : $target;
        $start = $start < 0 ? $length + $start : $start;
        $end = $end === null ? $length : ($end < 0 ? $length + $end : $end);
        $copy = array_slice($this->items, $start, $end - $start);
        for ($i = 0; $i < count($copy); $i++) {
            $to = $target + $i;
            if ($to < $length) {
                $this->items[$to] = $copy[$i];
            }
        }
        return $this;
    }

    public function values(): array
    {
        return array_values($this->items);
    }

    public function keys(): array
    {
        return array_keys($this->items);
    }

    public function entries(): array
    {
        $entries = [];
        foreach ($this->items as $key => $val) {
            $entries[] = [$key, $val];
        }
        return $entries;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function clone(): self
    {
        return new self($this->items);
    }

    public function equals(Zrray $other): bool
    {
        return $this->items === $other->toArray();
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function __toString(): string
    {
        return json_encode($this->items, JSON_UNESCAPED_UNICODE);
    }
}

class Message
{
    public Ztring $content;
    public Author $author;
    public $room;
    public $chatId;
    public $logId;
    public $type;
    public $attachment;
    public $mentions;
    public $isMention;
    public $image;
    
    public $src_id;
    public $is_src;
    private $_is_src;
    private $src_cache;
    
    public function __construct($msg, $room='', $chatId='', $sender='', $userId='', $attachment='', $logId='', $id='', $type='', $createdAt='', $deletedAt='', $is_src=false)
    {
        $this->content = new Ztring($msg);
        $this->room = new Room($room, $chatId);
        $this->chatId = $chatId;
        $this->logId = $logId;
        $this->type = $type;
        $this->author = new Author($sender, $userId, $chatId);
        $this->attachment = $attachment;
        $attachmentData = json_decode($attachment, true);
        $this->isMention = is_array($attachmentData) && array_key_exists("mentions", $attachmentData);
        $this->mentions = ($this->isMention)?array_column($attachmentData["mentions"], "user_id"):[];
        $this->image = new Image($attachmentData, $type);
        if(is_array($attachmentData) && array_key_exists("src_logId", $attachmentData)){
        $this->src_id = $attachmentData["src_logId"];
        $this->is_src = true;
        $this->_is_src = $is_src;
        }
    }
    
    public function reply($data, string $data_type = "text")
    {
    global $chatHelper;
    if((gettype($data) == "object" ) && (get_class($data) == "Ztring")) $data = $data->get();
    if (!empty($chatHelper) && $chatHelper instanceof ChatHelper && $this->_is_src==true) {
            $json = query("SELECT link_id FROM chat_rooms WHERE id = ?", [$this->chatId]);
            $link_id = json_decode($json, true)["data"][0]["link_id"] ?? null;
            $reply = new ReplyAttachment($this->src->logId,$this->src->author->userId,$this->src->content, $link_id);
            $chatHelper->sendMessage($this->chatId, $data, $reply);
      } else {
          reply($this->chatId, $data, $data_type);
      }
   }
    
    public function __get($name)
{
    if ($name === 'src') {

        $result = json_decode(query("SELECT * FROM chat_logs WHERE id={$this->src_id}"),true);

        if (!is_array($result) || (isset($result["status"]) && $result["status"] === false)) {
            return null;
        }

        $log = $result["data"][0] ?? null;
        if (!$log) return null;

        $room = getRoomName($log['chat_id']);
        $sender = getUserName($log['user_id'], $log['chat_id']);
        return new Message(
            $log['message'],
            $room,
            $log['chat_id'],
            $sender,
            $log['user_id'],
            $log['attachment'],
            $log['id'],
            $log['_id'],
            $log['type'],
            $log['created_at'],
            $log['deleted_at'], 
            true
        );   
        return null;
      }
    return match ($name) {
            'hasMention' => $this->isMention,
            'channelId' => $this->chatId,
            'sender' => $this->author,
            'prev' => $this->getPrev($this->chatId, $this->logId),
            'next' => $this->getNext($this->chatId, $this->logId),
            'feed' => $this->getFeed($this->type, $this->content),
            default => null
    };
  }
  
  private function getPrev($chatId, $logId){

        $result = json_decode(query("SELECT * FROM chat_logs WHERE chat_id = ? AND id < ? ORDER BY id DESC LIMIT 1",[$chatId, $logId]),true);

        if (!is_array($result) || (isset($result["status"]) && $result["status"] === false)) {
            return null;
        }

        $log = $result["data"][0] ?? null;
        if (!$log) return null;

        $room = getRoomName($log['chat_id']);
        $sender = getUserName($log['user_id'], $log['chat_id']);

        return new Message(
            $log['message'],
            $room,
            $log['chat_id'],
            $sender,
            $log['user_id'],
            $log['attachment'],
            $log['id'],
            $log['_id'],
            $log['type'],
            $log['created_at'],
            $log['deleted_at'], 
            false
        );   
    }
 
  private function getNext($chatId, $logId){

        $result = json_decode(query("SELECT * FROM chat_logs WHERE chat_id = ? AND id > ? ORDER BY id ASC LIMIT 1",[$chatId, $logId]),true);

        if (!is_array($result) || (isset($result["status"]) && $result["status"] === false)) {
            return null;
        }

        $log = $result["data"][0] ?? null;
        if (!$log) return null;

        $room = getRoomName($log['chat_id']);
        $sender = getUserName($log['user_id'], $log['chat_id']);

        return new Message(
            $log['message'],
            $room,
            $log['chat_id'],
            $sender,
            $log['user_id'],
            $log['attachment'],
            $log['id'],
            $log['_id'],
            $log['type'],
            $log['created_at'],
            $log['deleted_at'], 
            false
        );   
    }
}

class Room
{
    public Ztring $name;
    public $id;

    public function __construct($name, $id)
    {
        $this->name = new Ztring($name);
        $this->id = $id;
    }
    
    public function __toString(): string
    {
        return $this->name;
    }
}

class Author
{
    public Ztring $name;
    public Ztring $hash;
    public $userId;
    private $chatId;

    public function __construct($name, $userId, $chatId)
    {
        $this->name = new Ztring($name);
        $this->userId = $userId;
        $this->hash = new Ztring(hash('sha256', "person_$chatId:$userId"));
        $this->chatId = $chatId;
    }
    
public function __get($name)
{
        return match ($name) {
               'id' => $this->userId,
               'avatar' => new Image(["url"=>$this->getProfile()],"2"),
               default => null
        };
    }
    
    private function getProfile(){
        if(config()["bot_id"]==$this->userId) return json_decode(query("SELECT f_profile_image_url FROM db2.open_profile WHERE link_id = (SELECT link_id FROM chat_rooms WHERE id = ?)",[$this->chatId]), true)["data"][0]["f_profile_image_url"];
        list($table, $column) = (new Ztring($this->chatId))->length > 15 ? ["db2.open_chat_member", "user_id"] : ["friends", "id"];
        $data = query("SELECT original_profile_image_url, enc FROM {$table} WHERE {$column} = ?", [$this->userId]);
       return json_decode($data, true)["data"][0]["original_profile_image_url"];
    }
    
}

class Image
{
    public array $urls = [];
    
    public function __construct($attachmentData, $type)
    {   
        if ($type >= 16384) $type = $type - 16384;
        if($type != "2" && $type != "12" && $type != "20" && $type != "27" && $type != "71") return;
        if (!empty($attachmentData["url"])) {
            $this->urls[] = $attachmentData["url"];
        }        
        if (!empty($attachmentData["path"])) {
            $this->urls[] = 'https://item.kakaocdn.net/dw/'.$attachmentData["path"];
        }
        if (!empty($attachmentData["imageUrls"]) && is_array($attachmentData["imageUrls"])) {
            $this->urls = (array_merge($this->urls, $attachmentData["imageUrls"]));
        }
        if (!empty($attachmentData['C']['THL'])) {
            $this->urls = array_merge($this->urls, array_values(array_filter(array_map(function($v) {
            $url = $v["TH"]["THU"] ?? null;
            if (!$url) return null;
               $parsed = parse_url($url);
        parse_str($parsed['query'] ?? '', $q);
               return $q['fname'] ?? $url;
    }, $attachmentData["C"]["THL"] ?? []))));
       }
       

    }
    
    public function getImageFromUrl($url)
    {
        $data = Http::requestSync([
            'url' => $url,
            'method' => 'GET',
            'verifySsl' => true
        ]);
        if(preg_match('/^https:\/\/item\.kakaocdn\.net\/dw\/.*\.(webp|gif)$/', $url)){
           return emoticon_decrypt($data);
        }
        return $data;
    }
    
    public function getBase64()
    {
        if (empty($this->urls)) {
            return [];
        }
        $imageData = $this->getImageFromUrl($this->urls[0]);
        return $imageData ? base64_encode($imageData) : null;
    }
    
    public function getBase64s()
    {
        $results = [];
        foreach ($this->urls as $url) {
            $imageData = $this->getImageFromUrl($url);
            $results[] = $imageData ? base64_encode($imageData) : [];
        }
        return $results;
    }
    
     public function getBase64sAsync(callable $callback)
    {
        $requests = [];
        foreach ($this->urls as $url) {
            $requests[] = [
                'url' => $url,
                'method' => 'GET',
                'verifySsl' => true
            ];
        }

        Http::requestAsync($requests, function($responses) use ($callback) {
            $results = [];
            foreach ($responses as $response) {
                if ($response['error']) {
                    $results[] = [];
                } else {
                    $results[] = base64_encode($response['response']);
                }
            }
            $callback($results);
        });
    }
}

class Type {
    private int $value;

    private static array $KnownChatType = [
        0 => "FEED",
        1 => "TEXT",
        2 => "PHOTO",
        3 => "VIDEO",
        4 => "CONTACT",
        5 => "AUDIO",
        6 => "DITEMEMOTICON",
        7 => "DITEMGIFT",
        8 => "DITEMIMG",
        9 => "KAKAOLINKV1",
        11 => "AVATAR",
        12 => "STICKER",
        13 => "SCHEDULE",
        14 => "VOTE",
        15 => "LOTTERY",
        16 => "MAP",
        17 => "PROFILE",
        18 => "FILE",
        20 => "STICKERANI",
        21 => "NUDGE",
        22 => "ACTIONCON",
        23 => "SEARCH",
        24 => "POST",
        25 => "STICKERGIF",
        26 => "REPLY",
        27 => "MULTIPHOTO",
        51 => "VOIP",
        52 => "LIVETALK",
        71 => "CUSTOM",
        72 => "ALIM",
        81 => "PLUSFRIEND",
        82 => "PLUSEVENT",
        83 => "PLUSFRIENDVIRAL",
        96 => "OPEN_SCHEDULE",
        97 => "OPEN_VOTE",
        98 => "OPEN_POST"
    ];

    private static array $KnownFeedType = [
        -1 => "LOCAL_LEAVE",
        1 => "INVITE",
        2 => "LEAVE",
        3 => "SECRET_LEAVE",
        4 => "OPENLINK_JOIN",
        5 => "OPENLINK_DELETE_LINK",
        6 => "OPENLINK_KICKED",
        7 => "CHANNEL_KICKED",
        8 => "CHANNEL_DELETED",
        10 => "RICH_CONTENT",
        11 => "OPEN_MANAGER_GRANT",
        12 => "OPEN_MANAGER_REVOKE",
        13 => "OPENLINK_REWRITE_FEED",
        14 => "DELETE_TO_ALL",
        15 => "OPENLINK_HAND_OVER_HOST",
        18 => "TEAM_CHANNEL_EVENT"
    ];

    public function __construct(int $value) {
        $this->value = $value;
    }

    public function __toString(): string {
        return (string) $this->value;
    }

    public function chatType(): ?string {
         if ($this->value >= 16384) {
             $original = $this->value - 16384;
             $name = self::$KnownChatType[$original] ?? "UNKNOWN";
          return "DELETED_" . $name;
        }
        return self::$KnownChatType[$this->value] ?? null;
    }

    public function feedType(): ?string {
        return self::$KnownFeedType[$this->value] ?? null;
    }
}


class FileStream {
    private function handle($method, $args) {
        switch ($method) {
            case 'write':
                file_put_contents($args[0], $args[1]) !== false;
                return $args[1];

            case 'read':
                return file_exists($args[0]) ? file_get_contents($args[0]) : null;

            case 'writeJson':
                return $this->write($args[0], json_encode($args[1], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            case 'readJson':
                $raw = $this->read($args[0]);
                return $raw ? json_decode($raw, true) : null;

            default:
                throw new BadMethodCallException("Unknown method: $method");
        }
    }

    public function __call($method, $args) {
        return $this->handle($method, $args);
    }

    public static function __callStatic($method, $args) {
        return (new self())->handle($method, $args);
    }
}

class Cred {
   public $access_token;
   public $d_id;
   public function __construct($aot) {
        $data = json_decode($aot, true)["aot"];
        $this->access_token = $data['access_token'];
        $this->d_id = $data['d_id'];
    }
}

function reply($chatId, $data, string $type = "text")
{
    global $chatHelper;
    if (is_object($data) && method_exists($data, '__toString')){
       $data->__toString();
    }
    if(!empty($chatHelper) && $chatHelper instanceof ChatHelper && $type=="text") {
         $chatHelper->sendMessage($chatId, strval($data));
    } else {
    global $iris;
    if((gettype($data) == "object" ) && (get_class($data) == "Image") && $type!="text") $data = ($type=="image")?$data->getBase64():$data->getBase64s();
    $payload = [
        "type" => $type,
        "room" => $chatId,
        "data" => $data,
    ];
    foreach ($iris->endPoints("reply") as $url) {
        Http::request([
            'url' => $url,
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'data' => $payload,
            'dataType' => 'json',
            'verifySsl' => false
        ]);
    }
  } 
}

function query($sql, $bind = []) {
    global $iris;
    
    $response = Http::requestSync([
        'url' => $iris->endPoint("query"),
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json'],
        'data' => [
            "query" => $sql,
            "bind" => $bind
        ],
        'verifySsl' => false
    ]);
    
    return $response;
}

function decrypt($encType, $b64Ciphertext, $userId = null) {
    global $iris;
    
    $payload = [
        "enc" => $encType,
        "b64_ciphertext" => $b64Ciphertext
    ];

    if ($userId !== null) {
        $payload["user_id"] = $userId;
    }

    $response = Http::requestSync([
        'url' => $iris->endPoint("decrypt"),
        'method' => 'POST',
        'headers' => ['Content-Type' => 'application/json'],
        'data' => $payload,
        'verifySsl' => false
    ]);
    
    return $response;
}

function config(): array {
    global $iris;

    $res = Http::requestSync([
        'url' => $iris->endPoint("config"),
        'method' => 'GET',
        'verifySsl' => false
    ]);

    return json_decode($res, true) ?: ["bot_id" => 0, "bot_name" => "UnknownBot"];
}

function aot(): string {
    global $iris;

    $res = Http::requestSync([
        'url' => $iris->endPoint("aot"),
        'method' => 'GET',
        'verifySsl' => false
    ]);

    return $res;
}

function isNewDb(): bool {
    $res = json_decode(query("SELECT name FROM db2.sqlite_master WHERE type='table' AND name='open_chat_member'"),true);
    return isset($res["data"]) && count($res["data"]) > 0;
}

function getUserName($userId, $chatId=null): ?string {
    $config = config();

    if ($userId == $config["bot_id"]) {
        if(strlen($chatId)>15) return json_decode(query("SELECT nickname FROM db2.open_profile WHERE link_id = (SELECT link_id FROM chat_rooms WHERE id = ?)",[$chatId]),true)["data"][0]["nickname"] ?? $config["bot_name"];
        return $config["bot_name"];
    }

    if (isNewDb()) {
        $query = "
            WITH info AS (
                SELECT ? AS user_id
            )
            SELECT
                COALESCE(open_chat_member.nickname, friends.name) AS name,
                COALESCE(open_chat_member.enc, friends.enc) AS enc
            FROM info
            LEFT JOIN db2.open_chat_member
                ON open_chat_member.user_id = info.user_id
            LEFT JOIN db2.friends
                ON friends.id = info.user_id
        ";
    } else {
        $query = "SELECT name, enc FROM db2.friends WHERE id = ?";
    }

    $json = query($query, [$userId]);
    $res = json_decode($json, true);

    if (!isset($res["data"][0])) return null;

    $row = $res["data"][0];
    $name = $row["name"];
    $encType = $row["enc"];

    return $name;
}

function getUserNames(array $userIds) {
    $config = config();
    $botId = $config["bot_id"];
    $botName = $config["bot_name"];

    $results = array_fill_keys(
        array_filter($userIds, fn($id) => $id == $botId),
        $botName
    );

    $ids = array_values(array_filter($userIds, fn($id) => $id != $botId));
    if (empty($ids)) return $results;
    $bind = new Zrray($ids)->join(",");
    if (isNewDb()) {
        $query = "
            SELECT
    open_chat_member.user_id AS user_id,
    COALESCE(open_chat_member.nickname, friends.name) AS name,
    COALESCE(open_chat_member.enc, friends.enc) AS enc
FROM db2.open_chat_member
LEFT JOIN db2.friends ON open_chat_member.user_id = friends.id
WHERE open_chat_member.user_id IN ($bind)

UNION

SELECT
    friends.id AS user_id,
    friends.name AS name,
    friends.enc AS enc
FROM db2.friends
WHERE friends.id IN ($bind)
        ";
    } else {
        $query = "
            SELECT name, enc, id AS user_id
            FROM db2.friends
            WHERE id IN ($bind)
        ";
    }
    $json = query($query, []);
    $res = json_decode($json, true);

    if (!isset($res["data"])) return $results;

    foreach ($res["data"] as $row) {
        $name = $row["name"];
        $encType = $row["enc"];
        $userId = $row["user_id"];
        $results[$userId] = $name ?? null;
    }
    return $results;
}

function getRoomName($chatId) {
    $json = query("SELECT * FROM chat_rooms WHERE id = ?", [$chatId]);
    $res = json_decode($json, true);
    if (isset($res["data"][0]["private_meta"])) {
        $metaRaw = $res["data"][0]["private_meta"];
        if (!empty($metaRaw)&&($metaRaw!==null)) {
            $meta = json_decode($metaRaw, true);
            if (isset($meta["name"])&&!empty($meta["name"])) {
                return $meta["name"];
            }
        }
    }
    
    if (isset($res["data"][0]["meta"])) {
        $metaRaw = $res["data"][0]["meta"];
        if (!empty($metaRaw)) {
            $meta = json_decode($metaRaw, true)[0];
            if (isset($meta["content"])&&$meta["type"]==3) {
                return $meta["content"];
            }
        }
    }

    $json = query("SELECT name FROM db2.open_link WHERE id = (SELECT link_id FROM chat_rooms WHERE id = ?)", [$chatId]);
    $res2 = json_decode($json, true);

    if (isset($res2["data"][0]["name"])) {
        return $res2["data"][0]["name"];
    }
    
    $ids = json_decode($res["data"][0]["active_member_ids"], true);
    $ids = new Zrray($ids);
    if($ids->length>0){
    /* $sliced = $ids->length > 4
        ? $ids->slice(0, 4)
        : $ids;
    */
    $names = getUserNames($ids->toArray());
    $sortedArr = $names;
    usort($sortedArr, fn($a, $b) => kakaoSort($a, $b));
    $sorted = new Zrray($names); //new Zrray($sortedArr);
    return implode(", ", $sorted->toArray()) . (($sorted->length>1) ? "" : "");    
    }
    return config()["bot_name"] ?? null;
}

function kakaoSort($a, $b) {
    $fa = mb_substr($a, 0, 1, 'UTF-8');
    $fb = mb_substr($b, 0, 1, 'UTF-8');

    $pa = preg_match('/[가-힣]/u', $fa) ? 0 : (preg_match('/[a-zA-Z]/u', $fa) ? 1 : 2);
    $pb = preg_match('/[가-힣]/u', $fb) ? 0 : (preg_match('/[a-zA-Z]/u', $fb) ? 1 : 2);

    return $pa === $pb ? strcmp($a, $b) : $pa - $pb;
}

function emoticon_decrypt($data, $key = "a271730728cbe141e47fd9d677e9006d") {
    $key = str_repeat($key, 2); 
    $state = [0x12000032, 0x2527ac91, 0x888c1214];

    for ($i = 0; $i < 4; $i++) {
        $state[0] = ord($key[$i]) | ($state[0] << 8);
        $state[1] = ord($key[4 + $i]) | ($state[1] << 8);
        $state[2] = ord($key[8 + $i]) | ($state[2] << 8);
    }

    $state[0] &= 0xffffffff;
    $state[1] &= 0xffffffff;
    $state[2] &= 0xffffffff;

    $xorByte = function($byte) use (&$state) {
        $flag1 = 1;
        $flag2 = 0;
        $res = 0;

        for ($i = 0; $i < 8; $i++) {
            $aa = $state[0] >> 1;
            if ($state[0] & 1) {
                $state[0] = $aa ^ 0xC0000031;
                $bb = $state[1] >> 1;
                $flag1 = $state[1] & 1;
                if ($flag1) {
                    $state[1] = (($bb | 0xC0000000) ^ 0x20000010);
                } else {
                    $state[1] = $bb & 0x3FFFFFFF;
                }
            } else {
                $state[0] = $aa;
                $c = $state[2] >> 1;
                $flag2 = $state[2] & 1;
                if ($flag2) {
                    $state[2] = (($c | 0xF0000000) ^ 0x8000001);
                } else {
                    $state[2] = $c & 0xFFFFFFF;
                }
            }

            $res = ($flag1 ^ $flag2) | ($res << 1);
        }

        return $res ^ $byte;
    };
    $dat = str_split($data);
    for ($i = 0; $i < 128; $i++) {
        $dat[$i] = chr($xorByte(ord($dat[$i])));
    }

    return implode('', $dat);
}

function parseAuth($aot){
   $data = json_decode($aot, true);
   $accessToken = $data['aot']['access_token'];
   $dId = $data['aot']['d_id'];
   $result = $accessToken . "-" . $dId;
   return $result;
}

?>
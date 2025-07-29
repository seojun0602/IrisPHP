<?php
@include_once "./modules/iris_api.php";
@include_once "./modules/kakaolink.php";
$iris = new Iris("./data/endpoints.json");
$detail = str_repeat("\u{200B}", 1000);

$cred = class_exists('Cred') ? new Cred(aot()) : "";
$chatHelper = class_exists('ChatHelper') ? new ChatHelper() : "";
if(!empty($chatHelper)) $chatHelper->setCred($cred);

$Kakao = class_exists('Zakao') ? new Zakao($appKey='app key', $origin='origin') : "";

$adminHash = [""];

function onMessage($msg) {
    global $Kakao, $detail, $adminHash;

    if ($msg->content == "!hi") {
        $msg->reply("hi " . $msg->author->name);
    }

    if ($msg->content == "​​!ping") {
        $msg->reply("pong!");
    }

    if($msg->content == "대충파일스트림예시") {
       FileStream::write("./data/filepath.txt", "파일저장");
       $msg->reply(FileStream::read("./data/filepath.txt"));
    } 
    
    if (($msg->content->startsWith("#")) && (in_array($msg->author->hash->get(), $adminHash))){
       $code = trim($msg->content->substr(1)->get());
       extract(['msg' => $msg]);
        eval($code);
    }

    if ($msg->content == "!와") {
        $msg->reply("샌즈 아시는구나");
    }

    If($msg->content == "!hash"){
       $msg->reply($msg->author->hash->get());
    }

    if ($msg->content->startsWith("!멜론 ")) {
        $keyword = $msg->content->substr(strlen("!멜론 "));
        $room    = (strlen($msg->chatId) > 15)
                 ? $msg->room->name
                 : getRoomName($msg->chatId);
        sendMelon($keyword, $room, function($text) use ($msg) {
            $msg->reply($text);
        });
    }
    
    if ($msg->content == ".image"){
         $msg->reply($msg->src->image, "image_multiple");
    }
    
    if ($msg->content->startsWith(".프사링")){
         try{
         $Kakao->send(((strlen($msg->chatId)>15)?$msg->room->name : getRoomName($msg->chatId)), 3139,['IMAGE_URL' => ($msg->isMention ? (new Author("", $msg->mentions[0], $msg->chatId))->avatar->urls[0] : ($msg->is_src ? $msg->src->author->avatar->urls[0] : $msg->author->avatar->urls[0]))], true, 'ALL', 'ALL');
         } catch (Exception $e) {
           $msg->reply($e->getMessage());
         }
    }

};

$bot = BotManager::getCurrentBot();
$bot->addListener(Event::MESSAGE, 'onMessage');

?>

<?
function getInfoById($id) {
    $url   = "https://kkosvc.melon.com/mwk/sharelisten/sharelisten.json?songIds=" . $id;
    $json  = Http::requestSync([
        'url'       => $url,
        'method'    => 'GET',
        'verifySsl' => false
    ]);
    if (!$json || !is_string($json)) {
        return [];
    }
    $data = json_decode($json, true);
    if (!isset($data['status']) || $data['status'] != 0) {
        return [];
    }
    $d = $data['contents']['ShareListenList'][0];
    return [
        'KMA'       => $d['adult'],
        'THUMB_URL' => $d['albumImgPath'],
        'TITLE'     => $d['songName'],
        'ARTIST'    => $d['artistList'][0]['artistName'],
        'SONG_ID'   => $d['songId']
    ];
}

function getInfoByNm($keyword) {
    $url = "https://www.melon.com/search/keyword/index.json?j&query=" . urlencode($keyword);
    $json = Http::requestSync([
        'url'       => $url,
        'method'    => 'GET',
        'headers'   => ['User-Agent' => 'Mozilla/5.0'],
        'verifySsl' => false
    ]);
    if (!$json || !is_string($json)) {
        return [];
    }
    $data = json_decode($json, true);
    if (isset($data['ERROR']) || empty($data['SONGCONTENTS'])) {
        return [];
    }
    return getInfoById($data['SONGCONTENTS'][0]['SONGID']);
}

function sendMelon($keyword, $room, $reply) {
    $Kakao = class_exists('Zakao') ? new Zakao('4d545a185d172754667d621049004aa1', 'https://melon.com', parseAuth(aot())) : null;
    $info = getInfoByNm($keyword);
    if (empty($info)) {
        $reply("곡이 존재하지 않습니다.");
        return;
    }
    if(empty($Kakao)) return $reply("kakaolink 모듈이 존재하지 않습니다.");
    try {
        $Kakao->send(
            $room, 17141, $info, true, 'ALL', 'ALL'
        );
    } catch (Exception $e) {
        $reply("전송 실패: " . $e->getMessage());
    }
}

?>


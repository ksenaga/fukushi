<?php
declare(strict_types=1);

mb_language("Japanese");
mb_internal_encoding("UTF-8");

const REDIRECT_BASE = "contact";

// 文字数の上限
const MAX_LENGTH_NAME    = 100;
const MAX_LENGTH_TEL     = 20;
const MAX_LENGTH_EMAIL   = 254;
const MAX_LENGTH_MESSAGE = 5000;

// レート制限（同一IPからの連続送信を抑制）
const RATE_LIMIT_COUNT  = 5;   // この回数まで
const RATE_LIMIT_WINDOW = 600; // 秒（10分）

function redirect(string $query)
{
    header("Location: " . REDIRECT_BASE . "?" . $query);
    exit;
}

/**
 * 同一IPからの送信回数を記録し、上限を超えていないか判定する。
 * ファイルが書き込めない環境ではフォームの可用性を優先し、制限しない。
 */
function isRateLimited(string $ip): bool
{
    $dir = rtrim(sys_get_temp_dir(), "/") . "/fukushi_ratelimit";
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $file = $dir . "/ratelimit_" . sha1($ip) . ".json";
    $fp = @fopen($file, "c+");
    if ($fp === false) {
        return false;
    }

    flock($fp, LOCK_EX);

    $now = time();
    $timestamps = [];
    $contents = stream_get_contents($fp);
    if ($contents !== false && $contents !== "") {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $timestamps = $decoded;
        }
    }

    $timestamps = array_values(array_filter(
        $timestamps,
        static fn($t) => is_int($t) && ($now - $t) < RATE_LIMIT_WINDOW
    ));

    $limited = count($timestamps) >= RATE_LIMIT_COUNT;

    if (!$limited) {
        $timestamps[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($timestamps));
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $limited;
}

/**
 * フォームの送信元がこのサイト自身かどうかを Origin/Referer から判定する。
 * ヘッダーは詐称可能なため簡易的な軽減策であり、完全な防止にはならない。
 */
function isSameOriginRequest(): bool
{
    $host = $_SERVER["HTTP_HOST"] ?? "";
    if ($host === "") {
        return false;
    }
    // HTTP_HOST はポート番号を含む場合があるため、Origin/Referer 側と
    // 同じ形式（ホスト名のみ）に揃えてから比較する
    $host = parse_url("http://" . $host, PHP_URL_HOST) ?? "";
    if ($host === "") {
        return false;
    }

    $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
    if ($origin !== "") {
        $originHost = parse_url($origin, PHP_URL_HOST) ?? "";
        return $originHost !== "" && $originHost === $host;
    }

    $referer = $_SERVER["HTTP_REFERER"] ?? "";
    if ($referer !== "") {
        $refererHost = parse_url($referer, PHP_URL_HOST) ?? "";
        return $refererHost !== "" && $refererHost === $host;
    }

    // Origin も Referer も送られていない場合は判定できないため拒否する
    return false;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    redirect("error=invalid_request");
}

if (!isSameOriginRequest()) {
    redirect("error=invalid_request");
}

$clientIp = $_SERVER["REMOTE_ADDR"] ?? "";
if ($clientIp !== "" && isRateLimited($clientIp)) {
    redirect("error=rate_limited");
}

// ハニーポット対策：人間には見えない欄が埋まっていたら送信せず成功扱いにする
if (!empty($_POST["website"] ?? "")) {
    redirect("sent=1");
}

$name    = trim((string)($_POST["name"] ?? ""));
$tel     = trim((string)($_POST["tel"] ?? ""));
$email   = trim((string)($_POST["email"] ?? ""));
$message = trim((string)($_POST["message"] ?? ""));

if ($name === "" || $tel === "" || $email === "" || $message === "") {
    redirect("error=required");
}

if (
    mb_strlen($name) > MAX_LENGTH_NAME
    || mb_strlen($tel) > MAX_LENGTH_TEL
    || mb_strlen($email) > MAX_LENGTH_EMAIL
    || mb_strlen($message) > MAX_LENGTH_MESSAGE
) {
    redirect("error=too_long");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect("error=email");
}

// メールヘッダーインジェクション対策：改行を除去
$stripNewlines = static fn(string $value): string => str_replace(["\r", "\n"], "", $value);

$name  = $stripNewlines($name);
$tel   = $stripNewlines($tel);
$email = $stripNewlines($email);

// TODO: 実際の問い合わせ受信用メールアドレスに変更してください
$to = "example@exm.com";

$subject = "【FUJI THREE】お問い合わせがありました";

$body = "FUJI THREEのお問い合わせフォームより送信がありました。\n\n"
    . "お名前: {$name}\n"
    . "電話番号: {$tel}\n"
    . "メールアドレス: {$email}\n"
    . "お問い合わせ内容:\n{$message}\n";

// TODO: 実際の送信元ドメインのメールアドレスに変更してください
$headers = "From: no-reply@example.com\r\n"
    . "Reply-To: {$email}\r\n";

$sent = mb_send_mail($to, $subject, $body, $headers);

if ($sent) {
    redirect("sent=1");
}

redirect("error=send_failed");

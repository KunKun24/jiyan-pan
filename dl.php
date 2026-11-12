<?php
/*
 * 极验云网盘
 * 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 */
/* 极验云盘 - 直链下载入口  /d/<token>  ->  ?t=<token> */
define('PANDISK', 1);
require __DIR__ . '/lib.php';

function fail_page($code, $msg) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $title = h(cfg('title'));
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($msg) . ' - ' . $title . '</title></head>';
    echo '<body style="margin:0;font-family:system-ui,-apple-system,\'Segoe UI\',\'PingFang SC\',\'Microsoft YaHei\',sans-serif;background:#fbfbfa;color:#37352f;display:flex;align-items:center;justify-content:center;min-height:100vh">';
    echo '<div style="background:#fff;border:1px solid #e9e9e7;border-radius:10px;padding:40px 48px;text-align:center;max-width:90vw;box-shadow:0 1px 3px rgba(0,0,0,.04)">';
    echo '<div style="width:40px;height:40px;margin:0 auto 16px;border:1.5px solid #d3d1cb;border-radius:50%;display:flex;align-items:center;justify-content:center">';
    echo '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#787774" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/></svg>';
    echo '</div>';
    echo '<div style="font-size:16px;font-weight:600;margin-bottom:6px">' . h($msg) . '</div>';
    echo '<div style="font-size:13px;color:#787774">' . $title . '</div>';
    echo '</div></body></html>';
    exit;
}

$token = $_GET['t'] ?? '';
if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
    fail_page(404, '链接无效');
}

$db = db();
$st = $db->prepare('SELECT s.*, f.name, f.fname, f.size FROM shares s JOIN files f ON f.id = s.file_id WHERE s.token = ?');
$st->execute([$token]);
$row = $st->fetch();

if (!$row) {
    fail_page(404, '链接不存在或已被删除');
}
if ((int)$row['expire_at'] > 0 && time() > (int)$row['expire_at']) {
    fail_page(410, '链接已过期');
}
if ((int)$row['max_downloads'] > 0 && (int)$row['downloads'] >= (int)$row['max_downloads']) {
    fail_page(410, '下载次数已用完');
}

$path = storage_path($row['fname']);
if (!is_file($path)) {
    fail_page(404, '文件已丢失');
}

/*
 * 下载计数：断点续传（Range 起点 > 0）不重复计数，
 * 正常下载请求才累加；用带条件的 UPDATE 保证并发不超限。
 */
$range   = $_SERVER['HTTP_RANGE'] ?? '';
$resume  = false;
if ($range !== '' && preg_match('/bytes=(\d+)-/', $range, $m) && (int)$m[1] > 0) {
    $resume = true;
}

if ($resume) {
    if ((int)$row['max_downloads'] > 0 && (int)$row['downloads'] >= (int)$row['max_downloads']) {
        fail_page(410, '下载次数已用完');
    }
} else {
    $up = $db->prepare('UPDATE shares SET downloads = downloads + 1 WHERE id = ? AND (max_downloads = 0 OR downloads < max_downloads)');
    $up->execute([$row['id']]);
    if ($up->rowCount() === 0) {
        fail_page(410, '下载次数已用完');
    }
    /* 记录下载 IP / 归属地 / UA（只在真正计数的请求上记录） */
    $ip = client_ip();
    log_download((int)$row['file_id'], (int)$row['id'], $ip, ip_region($ip), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
}

/* 交给 nginx 内部 location 发送文件，支持断点续传、省 PHP 内存 */
$name  = (string)$row['name'];
$ascii = preg_replace('/[^\x20-\x7e]/', '_', $name);
$ascii = str_replace(['"', '\\'], '', $ascii);
if (trim($ascii, '_') === '') {
    $ascii = 'download';
}

header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
header('X-Accel-Redirect: /__f/' . $row['fname']);
header('Cache-Control: private, no-store');
exit;

/* ================================================================
 * 极验云网盘 · 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * ================================================================ */

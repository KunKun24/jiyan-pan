<?php
/*
 * 极验云网盘
 * 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * 本源码由 DeepSeek V4.1 V4pro 开发 转载请尊重原创
 */
/* 极验云盘 - 公共函数库 */
if (!defined('PANDISK')) { http_response_code(404); exit; }

date_default_timezone_set('Asia/Shanghai');

/* ---------- 配置 ---------- */
function cfg($key = null) {
    static $c = null;
    if ($c === null) {
        $c = require __DIR__ . '/config.php';
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

/* ---------- 数据库 ---------- */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $d   = cfg('db');
        $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/* ---------- 设置项 ---------- */
function setting($key, $default = null) {
    try {
        $st = db()->prepare('SELECT sval FROM settings WHERE skey = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Throwable $e) {
        return $default;
    }
}

function set_setting($key, $val) {
    db()->prepare('INSERT INTO settings (skey, sval) VALUES (?, ?) ON DUPLICATE KEY UPDATE sval = VALUES(sval)')
        ->execute([$key, $val]);
}

/* 规则读取：管理员可在 settings 里用 rule_<key> 覆盖 config 默认值 */
function rule($key) {
    $v = setting('rule_' . $key, null);
    if ($v !== null && $v !== '') {
        return (int)$v;
    }
    return (int)cfg($key);
}

/* ---------- 登录态 ---------- */
function is_logged_in() {
    return !empty($_SESSION['uid']);
}

function current_uid() {
    return (int)($_SESSION['uid'] ?? 0);
}

function current_user_row() {
    static $row = null;
    if ($row === null) {
        $uid = current_uid();
        if ($uid > 0) {
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$uid]);
            $row = $st->fetch() ?: false;
        } else {
            $row = false;
        }
    }
    return $row ?: null;
}

function is_admin() {
    $row = current_user_row();
    return $row && $row['role'] === 'admin';
}

function require_login() {
    if (!is_logged_in()) {
        json_out(['ok' => false, 'msg' => '未登录或登录已过期'], 401);
    }
    $row = current_user_row();
    if (!$row) {
        $_SESSION = [];
        json_out(['ok' => false, 'msg' => '登录已失效，请重新登录'], 401);
    }
    if ($row['status'] === 'banned') {
        $_SESSION = [];
        session_destroy();
        json_out(['ok' => false, 'msg' => '账号已被封禁，不予解封'], 403);
    }
    return $row;
}

function require_admin() {
    $row = require_login();
    if ($row['role'] !== 'admin') {
        json_out(['ok' => false, 'msg' => '没有权限'], 403);
    }
    return $row;
}

/* ---------- 输出 ---------- */
function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ---------- CSRF ---------- */
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        json_out(['ok' => false, 'msg' => '请求校验失败，请刷新页面'], 419);
    }
}

/* ---------- 工具 ---------- */
function human_size($n) {
    $n = (float)$n;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($n >= 1024 && $i < 4) { $n /= 1024; $i++; }
    return ($i == 0 ? (int)$n : round($n, 2)) . ' ' . $u[$i];
}

function base_url() {
    $u = cfg('site_url');
    if ($u) {
        return rtrim($u, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function storage_path($fname = null) {
    $base = rtrim(cfg('storage'), '/');
    return $fname === null ? $base : $base . '/' . $fname;
}

/* ---------- 账号 ---------- */
function current_user() {
    $row = current_user_row();
    return $row ? (string)$row['email'] : '';
}

/* ---------- 积分 / 签到 / 兑换 ---------- */
function add_points($uid, $delta, $reason, $note = '') {
    $db = db();
    $db->prepare('UPDATE users SET points = points + ? WHERE id = ?')->execute([$delta, $uid]);
    $db->prepare('INSERT INTO points_log (user_id, delta, reason, note, created_at) VALUES (?,?,?,?,?)')
        ->execute([$uid, $delta, $reason, $note, time()]);
    $st = $db->prepare('SELECT points FROM users WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

function checkin_today($uid) {
    $st = db()->prepare('SELECT last_checkin_date FROM users WHERE id = ?');
    $st->execute([$uid]);
    return $st->fetchColumn() === date('Y-m-d');
}

function do_checkin($uid) {
    if (checkin_today($uid)) {
        json_out(['ok' => false, 'msg' => '今天已经签到过了'], 400);
    }
    $gain = (int)rule('checkin_points');
    $new  = add_points($uid, $gain, 'checkin', '每日签到');
    db()->prepare('UPDATE users SET last_checkin_date = ? WHERE id = ?')->execute([date('Y-m-d'), $uid]);
    return ['ok' => true, 'gain' => $gain, 'points' => $new];
}

function next_quota_mb($quota) {
    $max  = (int)rule('quota_max_mb');
    $step = (int)rule('quota_step_mb');
    if ((int)$quota >= $max) {
        return null;
    }
    return min((int)$quota + $step, $max);
}

function do_exchange_quota($uid) {
    $row = current_user_row();
    $next = next_quota_mb((int)$row['quota_mb']);
    if ($next === null) {
        json_out(['ok' => false, 'msg' => '已达到最大容量 ' . rule('quota_max_mb') . 'MB'], 400);
    }
    $cost = (int)rule('quota_step_points');
    if ((int)$row['points'] < $cost) {
        json_out(['ok' => false, 'msg' => "积分不足，需要 {$cost} 积分"], 400);
    }
    $new = add_points($uid, -$cost, 'exchange', "兑换单文件上限至 {$next}MB");
    db()->prepare('UPDATE users SET quota_mb = ? WHERE id = ?')->execute([$next, $uid]);
    return ['ok' => true, 'quota_mb' => $next, 'points' => $new];
}

/* ---------- 客户端真实 IP（兼容 CDN 反代） ---------- */
function client_ip() {
    $cands = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']) as $p) {
            $cands[] = trim($p);
        }
    }
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $cands[] = trim($_SERVER['HTTP_X_REAL_IP']);
    }
    $cands[] = $_SERVER['REMOTE_ADDR'] ?? '';
    foreach ($cands as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '';
}

/* ---------- IP 归属地（离线 ip2region，精确到城市） ---------- */
function ip_region($ip) {
    static $s = null;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return '';
    }
    if ($s === null) {
        $cls = __DIR__ . '/lib/Searcher.class.php';
        $db  = __DIR__ . '/data/ip2region_v4.xdb';
        if (is_file($cls) && is_file($db)) {
            require_once $cls;
            try {
                $s = \ip2region\xdb\Searcher::newWithFileOnly(\ip2region\xdb\IPv4::default(), $db);
            } catch (\Throwable $e) {
                $s = false;
            }
        } else {
            $s = false;
        }
    }
    if ($s === false) {
        return '';
    }
    try {
        $r = $s->search($ip);
    } catch (\Throwable $e) {
        return '';
    }
    $p = explode('|', (string)$r);
    if (count($p) < 3) {
        return '';
    }
    $clean = function ($v) {
        $v = trim($v);
        return in_array($v, ['0', 'Reserved', ''], true) ? '' : $v;
    };
    $country = $clean($p[0]);
    $prov    = $clean($p[1]);
    $city    = $clean($p[2]);
    if ($prov === '' && $city === '') {
        return $country;
    }
    $segs = array_values(array_unique(array_filter([$prov, $city])));
    return implode(' ', $segs);
}

/* ---------- 下载记录 ---------- */
function log_download($fileId, $shareId, $ip, $region, $ua) {
    try {
        db()->prepare('INSERT INTO downloads (file_id, share_id, ip, region, ua, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([(int)$fileId, (int)$shareId, $ip, $region, $ua, time()]);
    } catch (\Throwable $e) {
        /* 记录失败不影响下载 */
    }
}

/* ---------- 文件删除（物理文件 + files/shares/downloads 级联） ---------- */
function delete_file_physical($id) {
    $db   = db();
    $st   = $db->prepare('SELECT fname FROM files WHERE id = ?');
    $st->execute([(int)$id]);
    $fname = $st->fetchColumn();
    if ($fname === false) {
        return false;
    }
    $db->prepare('DELETE FROM downloads WHERE file_id = ?')->execute([(int)$id]);
    $db->prepare('DELETE FROM shares WHERE file_id = ?')->execute([(int)$id]);
    $db->prepare('DELETE FROM files WHERE id = ?')->execute([(int)$id]);
    @unlink(storage_path($fname));
    return true;
}

/* ---------- 清理过期文件（普通用户 expire_at<now；管理员永久跳过） ---------- */
function cleanup_expired_files() {
    $db  = db();
    $now = time();
    $st  = $db->prepare("SELECT f.id FROM files f JOIN users u ON u.id = f.user_id
                         WHERE u.role = 'user' AND f.expire_at > 0 AND f.expire_at < ?");
    $st->execute([$now]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $n   = 0;
    foreach ($ids as $id) {
        if (delete_file_physical($id)) {
            $n++;
        }
    }
    return $n;
}

/* ================================================================
 * 极验云网盘 · 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * 本源码由 DeepSeek V4.1 V4pro 开发 转载请尊重原创
 * ================================================================ */

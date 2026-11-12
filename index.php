<?php
/*
 * 极验云网盘
 * 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 */
/* 极验云盘 - 多用户网盘（邮箱注册 + 公开文件列表 + 签到积分 + 兑换容量 + 管理员后台） */
define('PANDISK', 1);
require __DIR__ . '/lib.php';
session_start();

$action = $_GET['a'] ?? '';
if ($action !== '') {
    handle_action($action);
}

if (!is_logged_in()) {
    if (isset($_GET['login'])) {
        render_auth_page();
    } else {
        render_home_page();
    }
} elseif (isset($_GET['admin'])) {
    require_admin();
    render_admin_page();
} else {
    render_app_page();
}
exit;


/* ==================================================================
 *  接口处理
 * ================================================================== */

function require_post() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(['ok' => false, 'msg' => '请求方式错误'], 405);
    }
}

function valid_email($e) {
    $e = trim((string)$e);
    if ($e === '' || mb_strlen($e) < 3 || mb_strlen($e) > 100) {
        return false;
    }
    if (preg_match('/[\x00-\x1f\x7f]/', $e)) {
        return false;
    }
    return true;
}

function send_file_headers($name, $mime, $fname) {
    $ascii = preg_replace('/[^\x20-\x7e]/', '_', (string)$name);
    $ascii = str_replace(['"', '\\'], '', $ascii);
    if (trim($ascii, '_') === '') {
        $ascii = 'download';
    }
    header('Content-Type: ' . ($mime !== '' ? (string)$mime : 'application/octet-stream'));
    header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode((string)$name));
    header('X-Accel-Redirect: /__f/' . $fname);
    header('Cache-Control: private, no-store');
}

/* 取某个文件行，校验当前用户可读（自己的 / 管理员），否则拒绝 */
function own_file_or_admin($id) {
    $row = require_login();
    $st  = db()->prepare('SELECT * FROM files WHERE id = ?');
    $st->execute([(int)$id]);
    $f = $st->fetch();
    if (!$f) {
        json_out(['ok' => false, 'msg' => '文件不存在'], 404);
    }
    if (!is_admin() && (int)$f['user_id'] !== (int)$row['id']) {
        json_out(['ok' => false, 'msg' => '没有权限'], 403);
    }
    return $f;
}

function handle_action($action) {
    switch ($action) {

        case 'state': {
            $row = current_user_row();
            json_out([
                'ok'             => true,
                'logged_in'      => is_logged_in(),
                'user'           => current_user(),
                'is_admin'       => is_admin(),
                'title'          => cfg('title'),
                'quota_mb'       => $row ? (int)$row['quota_mb'] : (int)rule('quota_default_mb'),
                'points'         => $row ? (int)$row['points'] : 0,
                'checkin_today'  => $row ? checkin_today((int)$row['id']) : false,
                'checkin_points' => (int)rule('checkin_points'),
                'quota_max_mb'   => (int)rule('quota_max_mb'),
                'quota_step_mb'  => (int)rule('quota_step_mb'),
                'quota_step_points' => (int)rule('quota_step_points'),
                'retention_days' => (int)rule('file_retention_days'),
            ]);
        }

        case 'register': {
            require_post();
            $email = trim((string)($_POST['email'] ?? ''));
            $pass  = (string)($_POST['password'] ?? '');
            if (!valid_email($email)) {
                json_out(['ok' => false, 'msg' => '邮箱需为 3-100 个字符，且不含控制字符'], 400);
            }
            if (mb_strlen($pass) < 6) {
                json_out(['ok' => false, 'msg' => '密码至少 6 位'], 400);
            }
            $st = db()->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetchColumn()) {
                json_out(['ok' => false, 'msg' => '该邮箱已被注册'], 400);
            }
            $ip = client_ip();
            db()->prepare('INSERT INTO users (email, password_hash, role, status, points, quota_mb, created_at, reg_ip) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$email, password_hash($pass, PASSWORD_DEFAULT), 'user', 'active', 0, (int)rule('quota_default_mb'), time(), $ip]);
            $_SESSION['uid'] = (int)db()->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            json_out(['ok' => true, 'user' => $email, 'is_admin' => false]);
        }

        case 'login': {
            require_post();
            if ((int)($_SESSION['lock_until'] ?? 0) > time()) {
                $left = (int)($_SESSION['lock_until'] ?? 0) - time();
                json_out(['ok' => false, 'msg' => "尝试过于频繁，请 {$left} 秒后再试"], 429);
            }
            $email = trim((string)($_POST['email'] ?? ''));
            $pass  = (string)($_POST['password'] ?? '');
            $st = db()->prepare('SELECT * FROM users WHERE email = ?');
            $st->execute([$email]);
            $row = $st->fetch();
            if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
                $_SESSION['fails'] = (int)($_SESSION['fails'] ?? 0) + 1;
                if ($_SESSION['fails'] >= 5) {
                    $_SESSION['fails']      = 0;
                    $_SESSION['lock_until'] = time() + 300;
                    json_out(['ok' => false, 'msg' => '错误次数过多，已锁定 5 分钟'], 429);
                }
                json_out(['ok' => false, 'msg' => '账号或密码错误'], 403);
            }
            if ($row['status'] === 'banned') {
                json_out(['ok' => false, 'msg' => '账号已被封禁，不予解封'], 403);
            }
            $_SESSION['fails']      = 0;
            $_SESSION['lock_until'] = 0;
            $_SESSION['uid']        = (int)$row['id'];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            json_out(['ok' => true, 'user' => $row['email'], 'is_admin' => $row['role'] === 'admin']);
        }

        case 'logout': {
            $_SESSION = [];
            session_destroy();
            json_out(['ok' => true]);
        }

        case 'account': {
            $row = require_login();
            csrf_check();
            $old = (string)($_POST['old_password'] ?? '');
            if (!password_verify($old, (string)$row['password_hash'])) {
                json_out(['ok' => false, 'msg' => '当前密码不正确'], 403);
            }
            $newEmail = trim((string)($_POST['email'] ?? ''));
            $newPass  = (string)($_POST['password'] ?? '');
            if ($newEmail !== '' && $newEmail !== $row['email']) {
                if (!valid_email($newEmail)) {
                    json_out(['ok' => false, 'msg' => '邮箱需为 3-100 个字符，且不含控制字符'], 400);
                }
                $st = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
                $st->execute([$newEmail, $row['id']]);
                if ($st->fetchColumn()) {
                    json_out(['ok' => false, 'msg' => '该邮箱已被占用'], 400);
                }
                db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $row['id']]);
            }
            if ($newPass !== '') {
                if (mb_strlen($newPass) < 6) {
                    json_out(['ok' => false, 'msg' => '新密码至少 6 位'], 400);
                }
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($newPass, PASSWORD_DEFAULT), $row['id']]);
                session_regenerate_id(true);
            }
            json_out(['ok' => true, 'user' => $newEmail !== '' ? $newEmail : $row['email']]);
        }

        case 'upload': {
            $row = require_login();
            csrf_check();
            $quota = (int)$row['quota_mb'];

            if (empty($_FILES['file'])) {
                json_out(['ok' => false, 'msg' => '没有收到文件'], 400);
            }
            $f = $_FILES['file'];
            if (is_array($f['error'])) {
                json_out(['ok' => false, 'msg' => '一次只能上传一个文件'], 400);
            }
            $err = (int)$f['error'];
            if ($err !== UPLOAD_ERR_OK) {
                $map = [
                    UPLOAD_ERR_INI_SIZE   => '文件超过 PHP 上传上限',
                    UPLOAD_ERR_FORM_SIZE  => '文件超过表单上限',
                    UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
                    UPLOAD_ERR_NO_FILE    => '没有选择文件',
                    UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
                    UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
                    UPLOAD_ERR_EXTENSION  => '被 PHP 扩展拦截',
                ];
                json_out(['ok' => false, 'msg' => '上传失败：' . ($map[$err] ?? '错误 ' . $err)], 400);
            }
            if ((int)$f['size'] > $quota * 1024 * 1024) {
                json_out(['ok' => false, 'msg' => "文件超过单文件上限 {$quota} MB"], 400);
            }

            $orig = str_replace('\\', '/', (string)$f['name']);
            $orig = trim(basename($orig));
            if ($orig === '') {
                $orig = 'unnamed';
            }
            $orig = mb_substr($orig, 0, 200, 'UTF-8');

            $fname = bin2hex(random_bytes(16));
            $dest  = storage_path($fname);
            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                json_out(['ok' => false, 'msg' => '保存失败，请检查 storage 目录权限'], 500);
            }
            @chmod($dest, 0644);
            clearstatcache(true, $dest);
            $size = (int)filesize($dest);

            $public = ($_POST['public'] ?? '1') === '0' ? 0 : 1;
            $ip     = client_ip();
            $expire = 0;
            if ($row['role'] !== 'admin') {
                $ret = (int)rule('file_retention_days');
                $expire = $ret > 0 ? time() + $ret * 86400 : 0;
            }

            db()->prepare('INSERT INTO files (user_id, name, fname, size, mime, upload_ip, upload_region, public, created_at, expire_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$row['id'], $orig, $fname, $size, (string)($f['type'] ?? ''), $ip, ip_region($ip), $public, time(), $expire]);

            json_out(['ok' => true, 'name' => $orig, 'size' => $size, 'size_h' => human_size($size)]);
        }

        case 'files': {
            $row   = require_login();
            $files = db()->prepare('SELECT * FROM files WHERE user_id = ? ORDER BY id DESC');
            $files->execute([$row['id']]);
            $rows = $files->fetchAll();
            json_out(['ok' => true, 'files' => decorate_files($rows)]);
        }

        case 'public_files': {
            require_login();
            $now = time();
            $st = db()->prepare('SELECT f.*, u.email AS owner FROM files f JOIN users u ON u.id = f.user_id
                                 WHERE f.public = 1 AND u.status = "active"
                                   AND (f.expire_at = 0 OR f.expire_at > ?)
                                 ORDER BY f.id DESC LIMIT 500');
            $st->execute([$now]);
            json_out(['ok' => true, 'files' => decorate_files($st->fetchAll(), true)]);
        }

        case 'rename': {
            $f = own_file_or_admin((int)($_POST['id'] ?? 0));
            csrf_check();
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                json_out(['ok' => false, 'msg' => '文件名不能为空'], 400);
            }
            $name = mb_substr($name, 0, 200, 'UTF-8');
            db()->prepare('UPDATE files SET name = ? WHERE id = ?')->execute([$name, $f['id']]);
            json_out(['ok' => true, 'name' => $name]);
        }

        case 'set_public': {
            $f = own_file_or_admin((int)($_POST['id'] ?? 0));
            csrf_check();
            $val = ($_POST['public'] ?? '1') === '0' ? 0 : 1;
            db()->prepare('UPDATE files SET public = ? WHERE id = ?')->execute([$val, $f['id']]);
            json_out(['ok' => true, 'public' => $val]);
        }

        case 'share': {
            $row = require_login();
            csrf_check();
            $fileId = (int)($_POST['file_id'] ?? 0);
            $max    = max(0, (int)($_POST['max'] ?? 0));
            $days   = max(0, (int)($_POST['days'] ?? 0));

            $f = own_file_or_admin($fileId);

            $token  = bin2hex(random_bytes(16));
            $expire = $days > 0 ? time() + $days * 86400 : 0;

            $db = db();
            $db->beginTransaction();
            try {
                $oldIds = array_map('intval', $db->query('SELECT id FROM shares WHERE file_id = ' . (int)$f['id'])->fetchAll(PDO::FETCH_COLUMN));
                if ($oldIds) {
                    $in = implode(',', array_fill(0, count($oldIds), '?'));
                    $db->prepare("DELETE FROM downloads WHERE share_id IN ($in)")->execute($oldIds);
                }
                $db->prepare('DELETE FROM shares WHERE file_id = ?')->execute([$f['id']]);
                $db->prepare('INSERT INTO shares (file_id, token, max_downloads, downloads, expire_at, created_at) VALUES (?,?,?,0,?,?)')
                    ->execute([$f['id'], $token, $max, $expire, time()]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                json_out(['ok' => false, 'msg' => '生成链接失败'], 500);
            }

            json_out([
                'ok'     => true,
                'token'  => $token,
                'url'    => base_url() . '/d/' . $token,
                'expire' => $expire ? date('Y-m-d H:i', $expire) : '永久',
            ]);
        }

        case 'del_share': {
            $row = require_login();
            csrf_check();
            $id = (int)($_POST['id'] ?? 0);
            $st = db()->prepare('SELECT s.file_id, f.user_id FROM shares s JOIN files f ON f.id = s.file_id WHERE s.id = ?');
            $st->execute([$id]);
            $s = $st->fetch();
            if (!$s) {
                json_out(['ok' => false, 'msg' => '链接不存在'], 404);
            }
            if (!is_admin() && (int)$s['user_id'] !== (int)$row['id']) {
                json_out(['ok' => false, 'msg' => '没有权限'], 403);
            }
            db()->prepare('DELETE FROM downloads WHERE share_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM shares WHERE id = ?')->execute([$id]);
            json_out(['ok' => true]);
        }

        case 'del_file': {
            $f = own_file_or_admin((int)($_POST['id'] ?? 0));
            csrf_check();
            delete_file_physical((int)$f['id']);
            json_out(['ok' => true]);
        }

        case 'logs': {
            $f = own_file_or_admin((int)($_GET['file_id'] ?? 0));
            $st = db()->prepare('SELECT ip, region, ua, share_id, created_at FROM downloads WHERE file_id = ? ORDER BY id DESC LIMIT 100');
            $st->execute([(int)$f['id']]);
            $rows = $st->fetchAll();
            foreach ($rows as &$r) {
                $r['time'] = date('Y-m-d H:i', (int)$r['created_at']);
                $r['via']  = (int)$r['share_id'] > 0 ? '外链' : '站内';
            }
            unset($r);
            json_out(['ok' => true, 'logs' => $rows]);
        }

        case 'download': {
            $row = require_login();
            $id  = (int)($_GET['id'] ?? 0);
            $st  = db()->prepare('SELECT * FROM files WHERE id = ?');
            $st->execute([$id]);
            $f = $st->fetch();
            if (!$f) {
                http_response_code(404);
                exit;
            }
            $now = time();
            $can = false;
            if (is_admin()) {
                $can = true;
            } elseif ((int)$f['user_id'] === (int)$row['id']) {
                $can = true;
            } elseif ((int)$f['public'] === 1 && ((int)$f['expire_at'] === 0 || (int)$f['expire_at'] > $now)) {
                $can = true;
            }
            if (!$can) {
                http_response_code(403);
                exit;
            }
            $path = storage_path($f['fname']);
            if (!is_file($path)) {
                http_response_code(404);
                exit;
            }
            $ip = client_ip();
            log_download((int)$f['id'], 0, $ip, ip_region($ip), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
            send_file_headers($f['name'], $f['mime'], $f['fname']);
            exit;
        }

        case 'checkin': {
            $row = require_login();
            csrf_check();
            json_out(do_checkin((int)$row['id']));
        }

        case 'exchange': {
            $row = require_login();
            csrf_check();
            json_out(do_exchange_quota((int)$row['id']));
        }

        /* ---------------- 管理员 ---------------- */

        case 'admin_users': {
            require_admin();
            $rows = db()->query('SELECT u.id, u.email, u.role, u.status, u.points, u.quota_mb, u.last_checkin_date, u.created_at, u.reg_ip,
                                        (SELECT COUNT(*) FROM files f WHERE f.user_id = u.id) AS file_count
                                 FROM users u ORDER BY u.id')->fetchAll();
            foreach ($rows as &$r) {
                $r['created_h'] = date('Y-m-d H:i', (int)$r['created_at']);
            }
            unset($r);
            json_out(['ok' => true, 'users' => $rows]);
        }

        case 'admin_user': {
            require_admin();
            csrf_check();
            $uid = (int)($_POST['id'] ?? 0);
            $act = (string)($_POST['do'] ?? '');
            $st = db()->prepare('SELECT * FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            if (!$u) {
                json_out(['ok' => false, 'msg' => '用户不存在'], 404);
            }
            if ($uid === current_uid() && in_array($act, ['ban', 'delete'], true)) {
                json_out(['ok' => false, 'msg' => '不能对自己执行此操作'], 400);
            }
            switch ($act) {
                case 'ban':
                    db()->prepare('UPDATE users SET status = "banned" WHERE id = ?')->execute([$uid]);
                    json_out(['ok' => true]);
                case 'unban':
                    db()->prepare('UPDATE users SET status = "active" WHERE id = ?')->execute([$uid]);
                    json_out(['ok' => true]);
                case 'reset_pass':
                    $pwd = (string)($_POST['password'] ?? '');
                    if (mb_strlen($pwd) < 6) {
                        json_out(['ok' => false, 'msg' => '密码至少 6 位'], 400);
                    }
                    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($pwd, PASSWORD_DEFAULT), $uid]);
                    json_out(['ok' => true]);
                case 'points':
                    $delta = (int)($_POST['delta'] ?? 0);
                    if ($delta === 0) {
                        json_out(['ok' => false, 'msg' => '调整量不能为 0'], 400);
                    }
                    $new = add_points($uid, $delta, 'admin', '管理员调整');
                    json_out(['ok' => true, 'points' => $new]);
                case 'quota':
                    $mb = max(0, (int)($_POST['quota_mb'] ?? 0));
                    db()->prepare('UPDATE users SET quota_mb = ? WHERE id = ?')->execute([$mb, $uid]);
                    json_out(['ok' => true, 'quota_mb' => $mb]);
                case 'delete':
                    $fileIds = array_map('intval', db()->query('SELECT id FROM files WHERE user_id = ' . $uid)->fetchAll(PDO::FETCH_COLUMN));
                    foreach ($fileIds as $fid) {
                        delete_file_physical($fid);
                    }
                    db()->prepare('DELETE FROM points_log WHERE user_id = ?')->execute([$uid]);
                    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
                    json_out(['ok' => true]);
                default:
                    json_out(['ok' => false, 'msg' => '未知操作'], 400);
            }
        }

        case 'admin_files': {
            require_admin();
            $rows = db()->query('SELECT f.*, u.email AS owner FROM files f JOIN users u ON u.id = f.user_id ORDER BY f.id DESC')->fetchAll();
            json_out(['ok' => true, 'files' => decorate_files($rows, true)]);
        }

        case 'admin_file': {
            require_admin();
            csrf_check();
            $id  = (int)($_POST['id'] ?? 0);
            $act = (string)($_POST['do'] ?? '');
            if ($act === 'delete') {
                if (!delete_file_physical($id)) {
                    json_out(['ok' => false, 'msg' => '文件不存在'], 404);
                }
                json_out(['ok' => true]);
            }
            if ($act === 'public') {
                $val = ($_POST['public'] ?? '1') === '0' ? 0 : 1;
                db()->prepare('UPDATE files SET public = ? WHERE id = ?')->execute([$val, $id]);
                json_out(['ok' => true, 'public' => $val]);
            }
            json_out(['ok' => false, 'msg' => '未知操作'], 400);
        }

        case 'admin_settings': {
            require_admin();
            $users = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $files = (int)db()->query('SELECT COUNT(*) FROM files')->fetchColumn();
            $size  = (int)db()->query('SELECT COALESCE(SUM(size),0) FROM files')->fetchColumn();
            $expired = (int)db()->query("SELECT COUNT(*) FROM files f JOIN users u ON u.id=f.user_id WHERE u.role='user' AND f.expire_at>0 AND f.expire_at<" . time())->fetchColumn();
            json_out([
                'ok'             => true,
                'checkin_points' => (int)rule('checkin_points'),
                'retention_days' => (int)rule('file_retention_days'),
                'user_count'     => $users,
                'file_count'     => $files,
                'storage_size'   => $size,
                'storage_h'      => human_size($size),
                'expired_count'  => $expired,
            ]);
        }

        case 'admin_save_settings': {
            require_admin();
            csrf_check();
            $checkin   = max(1, (int)($_POST['checkin_points'] ?? 10));
            $retention = max(0, (int)($_POST['retention_days'] ?? 30));
            set_setting('rule_checkin_points', $checkin);
            set_setting('rule_file_retention_days', $retention);
            json_out(['ok' => true, 'checkin_points' => $checkin, 'retention_days' => $retention]);
        }

        case 'admin_cleanup': {
            require_admin();
            csrf_check();
            $n = cleanup_expired_files();
            json_out(['ok' => true, 'removed' => $n]);
        }

        default:
            json_out(['ok' => false, 'msg' => '未知操作'], 404);
    }
}

/* 给文件行附加展示字段 */
function decorate_files($rows, $withOwner = false) {
    $out = [];
    $now = time();
    foreach ($rows as $f) {
        $item = [
            'id'           => (int)$f['id'],
            'user_id'      => (int)$f['user_id'],
            'name'         => $f['name'],
            'size'         => (int)$f['size'],
            'size_h'       => human_size($f['size']),
            'mime'         => $f['mime'],
            'public'       => (int)$f['public'],
            'upload_ip'    => $f['upload_ip'],
            'upload_region'=> $f['upload_region'],
            'created_h'    => date('Y-m-d H:i', (int)$f['created_at']),
            'expire_at'    => (int)$f['expire_at'],
            'expire_h'     => (int)$f['expire_at'] > 0 ? date('Y-m-d H:i', (int)$f['expire_at']) : '永久',
            'expired'      => ((int)$f['expire_at'] > 0 && $now > (int)$f['expire_at']),
        ];
        if ($withOwner && isset($f['owner'])) {
            $item['owner'] = $f['owner'];
        }
        $out[] = $item;
    }
    return $out;
}


/* ==================================================================
 *  页面公共部分
 * ================================================================== */

function icon_set() {
    return [
        'upload'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>',
        'file'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/></svg>',
        'link'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7"/></svg>',
        'copy'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
        'trash'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>',
        'gear'     => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M18.8 5.2l-1.6 1.6M6.8 17.2l-1.6 1.6"/></svg>',
        'logout'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>',
        'clock'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
        'list'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M3 6h.01"/><path d="M3 12h.01"/><path d="M3 18h.01"/></svg>',
        'close'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>',
        'check'    => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        'external' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>',
        'download' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12"/><path d="m7 11 5 5 5-5"/><path d="M4 19h16"/></svg>',
        'edit'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>',
        'eye'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>',
        'eyeoff'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M1 1l22 22"/></svg>',
        'coin'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M14.8 9.2a3 3 0 0 0-4.6-1.2 3 3 0 0 0 0 5.2 3 3 0 0 1 0 5.2 3 3 0 0 1-4.6-1.2"/></svg>',
        'shield'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-3.6 8-10V5l-8-3-8 3v7c0 6.4 8 10 8 10z"/></svg>',
        'users'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    ];
}

function icon($name) {
    $s = icon_set();
    return $s[$name] ?? '';
}

function page_head($title) {
    $t = cfg('title');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?></title>
<link rel="icon" href="assets/logo.png">
<style>
  :root{
    --bg:#fbfbfa; --panel:#fff; --text:#37352f; --text2:#787774; --text3:#a8a5a1;
    --line:#e9e9e7; --line2:#d9d8d5; --hover:#f1f1ef; --danger:#d44c47;
  }
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--text);font-size:14px;line-height:1.6;
       font-family:system-ui,-apple-system,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
       -webkit-font-smoothing:antialiased}
  button,input,select{font-family:inherit}
  a{color:inherit;text-decoration:none}
  .muted{color:var(--text2)}
  .btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:7px 12px;
       border:1px solid var(--line2);background:var(--panel);color:var(--text);border-radius:6px;
       font-size:13px;cursor:pointer;transition:background .12s,border-color .12s;white-space:nowrap}
  .btn:hover{background:var(--hover)}
  .btn.primary{background:var(--text);border-color:var(--text);color:#fff}
  .btn.primary:hover{background:#2f2e29}
  .btn.ghost{border-color:transparent;background:transparent;color:var(--text2)}
  .btn.ghost:hover{background:var(--hover);color:var(--text)}
  .btn.sm{padding:4px 9px;font-size:12px}
  .btn.danger{color:var(--danger)}
  .btn.danger:hover{background:#fdf1f0;border-color:#f0d0ce}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .btn.block{width:100%;padding:9px}
  .input{width:100%;padding:8px 11px;border:1px solid var(--line2);border-radius:6px;font-size:14px;
         background:#fff;color:var(--text);outline:none;transition:border-color .12s,box-shadow .12s}
  .input:focus{border-color:#b8b5ae;box-shadow:0 0 0 3px rgba(55,53,47,.06)}
  .field{display:block;margin-bottom:14px}
  .field > span{display:block;font-size:12px;color:var(--text2);margin-bottom:5px}
  .auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .auth-card{width:100%;max-width:380px;background:var(--panel);border:1px solid var(--line);
             border-radius:10px;padding:34px 30px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .auth-logo{display:block;width:56px;height:56px;object-fit:contain;margin:0 auto 14px;mix-blend-mode:multiply}
  .auth-card h1{font-size:20px;font-weight:600;text-align:center;margin:0 0 4px}
  .auth-sub{text-align:center;color:var(--text2);font-size:13px;margin:0 0 18px}
  .auth-tabs{display:flex;border:1px solid var(--line2);border-radius:7px;overflow:hidden;margin-bottom:20px}
  .auth-tabs button{flex:1;padding:8px;border:0;background:var(--panel);color:var(--text2);font-size:13px;cursor:pointer}
  .auth-tabs button.on{background:var(--text);color:#fff}
  .err{color:var(--danger);font-size:12px;text-align:center;min-height:16px;margin:10px 0 0}
  .err.left{text-align:left}
  .topbar{height:52px;border-bottom:1px solid var(--line);background:rgba(251,251,250,.92);
          backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);position:sticky;top:0;z-index:40;
          display:flex;align-items:center;justify-content:space-between;padding:0 22px;gap:12px}
  .brand{display:flex;align-items:center;gap:9px;font-weight:600;font-size:15px;flex:none}
  .brand img{width:26px;height:26px;object-fit:contain;mix-blend-mode:multiply}
  .top-actions{display:flex;align-items:center;gap:6px;flex:none}
  .who{font-size:12px;color:var(--text2);margin-right:2px}
  .points-pill{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--text2);
               border:1px solid var(--line);border-radius:999px;padding:3px 10px;background:var(--panel)}
  .points-pill b{color:var(--text);font-weight:600}
  .wrap{max-width:900px;margin:0 auto;padding:24px 18px 90px}
  .tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:18px}
  .tabs button{border:0;background:transparent;padding:9px 14px;font-size:14px;color:var(--text2);cursor:pointer;
               border-bottom:2px solid transparent;margin-bottom:-1px;transition:color .12s}
  .tabs button.on{color:var(--text);font-weight:600;border-bottom-color:var(--text)}
  .tab-panel{display:none}
  .tab-panel.on{display:block}
  .drop{border:1.5px dashed var(--line2);border-radius:10px;padding:28px 20px;text-align:center;
        cursor:pointer;transition:.15s;background:var(--panel)}
  .drop:hover,.drop.over{border-color:#b8b5ae;background:#fafaf9}
  .drop-ic{color:var(--text3);margin-bottom:8px;display:flex;justify-content:center}
  .drop-ic svg{width:22px;height:22px}
  .drop-t{font-size:14px;font-weight:500}
  .drop-s{font-size:12px;color:var(--text3);margin-top:3px}
  .prog{font-size:12px;color:var(--text2);margin-top:10px;min-height:0;word-break:break-all}
  .opt-row{display:flex;align-items:center;gap:8px;margin-top:14px;font-size:13px;color:var(--text2);flex-wrap:wrap}
  .opt-row label{display:flex;align-items:center;gap:6px;cursor:pointer}
  .opt-row input[type=checkbox]{accent-color:var(--text);width:15px;height:15px;cursor:pointer}
  .notice{margin-top:12px;font-size:12px;color:#8a6d1f;background:#fbf6e9;border:1px solid #efe3c2;
          border-radius:6px;padding:8px 11px;line-height:1.5}
  .list-head{display:flex;align-items:center;justify-content:space-between;margin:6px 2px 10px;
             font-size:13px;font-weight:600}
  .list-head .muted{font-weight:400;font-size:12px}
  .list{background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}
  .file-row{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border-bottom:1px solid var(--line);
            transition:background .12s}
  .file-row:last-child{border-bottom:0}
  .file-row:hover{background:#fafaf9}
  .file-ic{color:var(--text3);margin-top:2px;flex:none}
  .file-main{flex:1;min-width:0}
  .file-name{font-size:14px;font-weight:500;word-break:break-all}
  .file-meta{font-size:12px;color:var(--text3);margin-top:1px}
  .share-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:7px;font-size:12px;color:var(--text2)}
  .share-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px;
              background:#f6f6f4;border:1px solid var(--line);border-radius:4px;padding:1px 6px;color:var(--text2)}
  .badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;padding:1px 7px;border-radius:999px;
         border:1px solid var(--line);color:var(--text2);background:#fafaf9}
  .badge.ok{color:#2f6f4f;background:#eef7f1;border-color:#d6ebe0}
  .badge.used{color:#8a6d1f;background:#fbf6e9;border-color:#efe3c2}
  .badge.expired{color:var(--danger);background:#fdf1f0;border-color:#f0d0ce}
  .file-actions{display:flex;align-items:center;gap:2px;flex:none;margin-top:-2px;flex-wrap:wrap;justify-content:flex-end}
  .empty{padding:46px 20px;text-align:center;color:var(--text3);font-size:13px}
  .overlay{position:fixed;inset:0;background:rgba(15,15,15,.32);display:none;align-items:center;
           justify-content:center;z-index:100;padding:18px}
  .overlay.show{display:flex}
  .modal{background:#fff;border:1px solid var(--line);border-radius:10px;width:100%;max-width:460px;
         padding:22px 22px 18px;box-shadow:0 14px 44px rgba(0,0,0,.16);max-height:86vh;overflow:auto}
  .modal h3{font-size:15px;font-weight:600;margin:0 0 4px;display:flex;align-items:center;justify-content:space-between}
  .modal .sub{font-size:12px;color:var(--text2);margin:0 0 16px;word-break:break-all}
  .modal-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
  .modal-close{border:0;background:transparent;color:var(--text3);cursor:pointer;padding:3px;border-radius:4px;display:flex}
  .modal-close:hover{background:var(--hover);color:var(--text)}
  .row2{display:flex;gap:10px}
  .row2 > *{flex:1}
  .logs{width:100%;border-collapse:collapse;font-size:12px}
  .logs th{text-align:left;color:var(--text3);font-weight:500;padding:6px 8px;border-bottom:1px solid var(--line)}
  .logs td{padding:6px 8px;border-bottom:1px solid #f2f2f0;vertical-align:top;word-break:break-all}
  .logs tr:last-child td{border-bottom:0}
  .logs .ip{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:11.5px}
  .logs-empty{padding:26px 0;text-align:center;color:var(--text3);font-size:12px}
  .toast{position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:var(--text);color:#fff;
         padding:8px 15px;border-radius:6px;font-size:13px;z-index:300;box-shadow:0 6px 24px rgba(0,0,0,.2);
         animation:tin .16s ease-out}
  @keyframes tin{from{opacity:0;transform:translate(-50%,6px)}to{opacity:1;transform:translate(-50%,0)}}
  .table-wrap{background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow-x:auto}
  table.tbl{width:100%;border-collapse:collapse;font-size:13px;min-width:640px}
  table.tbl th{text-align:left;color:var(--text3);font-weight:500;padding:9px 12px;border-bottom:1px solid var(--line);
               white-space:nowrap;font-size:12px}
  table.tbl td{padding:9px 12px;border-bottom:1px solid #f2f2f0;vertical-align:middle;word-break:break-all}
  table.tbl tr:last-child td{border-bottom:0}
  table.tbl .tag{font-size:11px;padding:1px 8px;border-radius:999px;border:1px solid var(--line);color:var(--text2);white-space:nowrap}
  table.tbl .tag.ban{color:var(--danger);background:#fdf1f0;border-color:#f0d0ce}
  table.tbl .tag.admin{color:#2f6f4f;background:#eef7f1;border-color:#d6ebe0}
  .stat-grid{display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap}
  .stat{flex:1;min-width:130px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .stat b{display:block;font-size:20px;font-weight:600}
  .stat span{font-size:12px;color:var(--text3)}
  .admin-section{margin-bottom:30px}
  .admin-section > h2{font-size:15px;font-weight:600;margin:0 0 12px;display:flex;align-items:center;gap:8px}
  .admin-section > h2 svg{color:var(--text2)}
  .backlink{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--text2);margin-bottom:16px}
  .backlink:hover{color:var(--text)}
  @media (max-width:640px){
    .topbar{padding:0 14px;gap:8px}
    .wrap{padding:18px 12px 80px}
    .who{display:none}
    .btn.ghost span{display:none}
    .file-actions .label{display:none}
    .points-pill span{display:none}
  }
</style>
</head>
<body>
    <?php
}

function page_end() {
    echo '</body></html>';
}


/* ==================================================================
 *  页面：官网首页（iOS 风格）
 * ================================================================== */
function render_home_page() {
    $title = cfg('title');
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> - 私有云盘</title>
<link rel="icon" href="assets/logo.png">
<style>
  :root{
    --text:#1d1d1f; --text2:#6e6e73; --text3:#86868b;
    --bg:#ffffff; --bg2:#f5f5f7; --line:#d2d2d7;
    --accent:#0071e3; --btn:#1d1d1f;
  }
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"SF Pro Text","Helvetica Neue","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
       color:var(--text);background:var(--bg);-webkit-font-smoothing:antialiased;line-height:1.5}
  a{color:inherit;text-decoration:none}
  .nav{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.8);
       backdrop-filter:saturate(180%) blur(20px);-webkit-backdrop-filter:saturate(180%) blur(20px);
       border-bottom:1px solid rgba(0,0,0,.06)}
  .nav-in{max-width:1024px;margin:0 auto;padding:0 22px;height:48px;display:flex;align-items:center;justify-content:space-between}
  .nav-logo{display:flex;align-items:center;gap:8px;font-weight:600;font-size:15px}
  .nav-logo img{width:26px;height:26px;object-fit:contain}
  .nav-login{font-size:14px;color:#fff;background:var(--accent);padding:5px 14px;border-radius:980px;transition:background .2s}
  .nav-login:hover{background:#0077ed}
  .hero{text-align:center;padding:110px 22px 90px}
  .hero h1{font-size:clamp(40px,7vw,72px);font-weight:700;letter-spacing:-0.02em;line-height:1.06}
  .hero .sub{font-size:clamp(17px,2.5vw,22px);font-weight:400;color:var(--text2);margin-top:14px;letter-spacing:-0.01em}
  .hero-cta{display:flex;gap:14px;justify-content:center;margin-top:30px;flex-wrap:wrap}
  .cta{font-size:17px;padding:11px 24px;border-radius:980px;transition:background .2s}
  .cta.primary{background:var(--btn);color:#fff}
  .cta.primary:hover{background:#000}
  .cta.link{color:var(--accent)}
  .cta.link:hover{text-decoration:underline}
  .features{background:var(--bg2);padding:80px 22px 90px}
  .features-in{max-width:1024px;margin:0 auto}
  .features h2{text-align:center;font-size:clamp(28px,4vw,40px);font-weight:700;letter-spacing:-0.02em;margin-bottom:44px}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px}
  .card{background:var(--bg);border-radius:18px;padding:28px 26px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
  .card svg{width:34px;height:34px;color:var(--text);margin-bottom:16px}
  .card h3{font-size:18px;font-weight:600;letter-spacing:-0.01em;margin-bottom:8px}
  .card p{font-size:14px;color:var(--text2);line-height:1.6}
  .footer{background:var(--bg2);border-top:1px solid var(--line);padding:26px 22px;text-align:center}
  .footer p{font-size:12px;color:var(--text3)}
  @media (max-width:640px){
    .hero{padding:70px 20px 60px}
    .features{padding:50px 20px 60px}
  }
</style>
</head>
<body>
<nav class="nav">
  <div class="nav-in">
    <a class="nav-logo" href="./"><img src="assets/logo.png" alt=""><span><?= h($title) ?></span></a>
    <a class="nav-login" href="?login">登录</a>
  </div>
</nav>

<header class="hero">
  <h1>你的文件，随存随取。</h1>
  <p class="sub">超大文件直链分享。私有自建，数据掌控在自己手里。</p>
  <div class="hero-cta">
    <a class="cta primary" href="?login">进入网盘</a>
    <a class="cta link" href="#features">了解特性 ›</a>
  </div>
</header>

<section class="features" id="features">
  <div class="features-in">
    <h2>强大。简单。私有。</h2>
    <div class="grid">
      <div class="card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4"/><path d="m7 9 5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>
        <h3>超大文件</h3>
        <p>任意格式，单文件最高 2GB，拖拽即传。</p>
      </div>
      <div class="card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.1 0l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.1 0l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7"/></svg>
        <h3>直链分享</h3>
        <p>一键生成直链，可限制下载次数与有效期。</p>
      </div>
      <div class="card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
        <h3>下载留痕</h3>
        <p>记录每次下载的 IP 与归属地，心中有数。</p>
      </div>
      <div class="card">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-3.6 8-10V5l-8-3-8 3v7c0 6.4 8 10 8 10z"/></svg>
        <h3>私有自建</h3>
        <p>数据只存在你的服务器，不开放注册。</p>
      </div>
    </div>
  </div>
</section>

<footer class="footer">
  <p><?= h($title) ?> · 私有云盘</p>
</footer>
</body>
</html>
    <?php
}

/* ==================================================================
 *  页面：登录 / 注册
 * ================================================================== */
function render_auth_page() {
    $csrf  = csrf_token();
    $title = cfg('title');
    page_head('登录 - ' . $title);
?>
<div class="auth-wrap">
  <div class="auth-card">
    <img src="assets/logo.png" class="auth-logo" alt="">
    <h1><?= h($title) ?></h1>
    <p class="auth-sub">登录或注册你的账号</p>
    <div class="auth-tabs">
      <button type="button" id="tabLogin" class="on">登录</button>
      <button type="button" id="tabReg">注册</button>
    </div>
    <div id="formLogin">
      <label class="field"><span>邮箱</span><input id="lgEmail" class="input" autocomplete="username" placeholder="邮箱"></label>
      <label class="field"><span>密码</span><input id="lgPwd" type="password" class="input" autocomplete="current-password" placeholder="密码"></label>
      <button class="btn primary block" id="btnLogin">登录</button>
    </div>
    <div id="formReg" style="display:none">
      <label class="field"><span>邮箱</span><input id="rgEmail" class="input" autocomplete="username" placeholder="邮箱（3-100 字符，无需验证）"></label>
      <label class="field"><span>密码</span><input id="rgPwd" type="password" class="input" autocomplete="new-password" placeholder="至少 6 位"></label>
      <label class="field"><span>确认密码</span><input id="rgPwd2" type="password" class="input" autocomplete="new-password" placeholder="再次输入"></label>
      <button class="btn primary block" id="btnReg">注册并登录</button>
    </div>
    <p class="err" id="err"></p>
    <div style="text-align:center;margin-top:16px"><a href="./" style="color:var(--text3);font-size:12px">‹ 返回首页</a></div>
  </div>
</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const $ = s => document.querySelector(s);
const err = $('#err');
const tabL = $('#tabLogin'), tabR = $('#tabReg');

function switchTab(which) {
  const isLogin = which === 'login';
  tabL.classList.toggle('on', isLogin);
  tabR.classList.toggle('on', !isLogin);
  $('#formLogin').style.display = isLogin ? '' : 'none';
  $('#formReg').style.display = isLogin ? 'none' : '';
  err.textContent = '';
}
tabL.onclick = () => switchTab('login');
tabR.onclick = () => switchTab('register');

async function post(a, data) {
  const fd = new FormData();
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch('?a=' + a, { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
  return r.json().catch(() => ({ ok: false, msg: '网络错误' }));
}
$('#btnLogin').onclick = async () => {
  err.textContent = '';
  const email = $('#lgEmail').value.trim(), pwd = $('#lgPwd').value;
  if (!email || !pwd) { err.textContent = '请输入邮箱和密码'; return; }
  const b = $('#btnLogin'); b.disabled = true;
  const j = await post('login', { email, password: pwd });
  b.disabled = false;
  if (j.ok) { location.reload(); } else { err.textContent = j.msg || '登录失败'; }
};
$('#btnReg').onclick = async () => {
  err.textContent = '';
  const email = $('#rgEmail').value.trim(), p1 = $('#rgPwd').value, p2 = $('#rgPwd2').value;
  if (!email) { err.textContent = '请输入邮箱'; return; }
  if (p1.length < 6) { err.textContent = '密码至少 6 位'; return; }
  if (p1 !== p2) { err.textContent = '两次输入的密码不一致'; return; }
  const b = $('#btnReg'); b.disabled = true;
  const j = await post('register', { email, password: p1 });
  b.disabled = false;
  if (j.ok) { location.reload(); } else { err.textContent = j.msg || '注册失败'; }
};
$('#lgPwd').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btnLogin').click(); });
$('#rgPwd2').addEventListener('keydown', e => { if (e.key === 'Enter') $('#btnReg').click(); });
</script>
<?php
    page_end();
}


/* ==================================================================
 *  页面：用户主界面（三 Tab + 签到兑换）
 * ================================================================== */
function render_app_page() {
    $csrf  = csrf_token();
    $title = cfg('title');
    $user  = current_user();
    $isAdmin = is_admin();
    page_head($title);
?>
<header class="topbar">
  <a class="brand" href="./"><img src="assets/logo.png" alt=""><span><?= h($title) ?></span></a>
  <div class="top-actions">
    <span class="points-pill" id="pointsPill"><?= icon('coin') ?><span>积分 <b id="hPoints">0</b></span> · <span>上限 <b id="hQuota">0</b>MB</span></span>
    <button class="btn sm" id="btnCheckin" title="每日签到"><?= icon('check') ?><span class="label">签到</span></button>
    <button class="btn sm" id="btnExchange" title="积分兑换容量"><?= icon('coin') ?><span class="label">兑换</span></button>
    <?php if ($isAdmin): ?>
    <a class="btn sm" href="?admin=1" title="管理后台"><?= icon('shield') ?><span class="label">管理</span></a>
    <?php endif; ?>
    <span class="who" id="who"><?= h($user) ?></span>
    <button class="btn ghost sm" id="btnAccount" title="账号设置"><?= icon('gear') ?></button>
    <button class="btn ghost sm" id="btnLogout" title="退出登录"><?= icon('logout') ?></button>
  </div>
</header>

<main class="wrap">
  <div class="tabs">
    <button type="button" class="on" data-tab="mine">我的文件</button>
    <button type="button" data-tab="public">文件列表</button>
    <button type="button" data-tab="upload">上传文件</button>
  </div>

  <!-- 我的文件 -->
  <div class="tab-panel on" id="panel-mine">
    <div class="list-head"><span>我上传的文件</span><span class="muted" id="countMine"></span></div>
    <div class="list" id="listMine"></div>
  </div>

  <!-- 文件列表（公开） -->
  <div class="tab-panel" id="panel-public">
    <div class="list-head"><span>公开文件</span><span class="muted" id="countPublic"></span></div>
    <div class="list" id="listPublic"></div>
  </div>

  <!-- 上传 -->
  <div class="tab-panel" id="panel-upload">
    <div class="drop" id="drop">
      <div class="drop-ic"><?= icon('upload') ?></div>
      <div class="drop-t">拖拽文件到这里，或点击上传</div>
      <div class="drop-s" id="dropHint">任意格式 · 单文件最大 50 MB</div>
      <input type="file" id="pick" hidden>
    </div>
    <div class="prog" id="prog"></div>
    <div class="opt-row">
      <label><input type="checkbox" id="chkPublic" checked> 在「文件列表」公开显示</label>
    </div>
    <div class="notice">⚠️ 严禁上传任何违法违规文件，发现将清理文件并封号，不予解封。</div>
    <div class="opt-row">
      <label><input type="checkbox" id="chkAgree"> 我已阅读并同意上述规定</label>
    </div>
  </div>
</main>

<!-- 分享弹窗 -->
<div class="overlay" id="shareOv">
  <div class="modal">
    <h3>生成分享链接 <button class="modal-close" data-close="shareOv"><?= icon('close') ?></button></h3>
    <p class="sub" id="shareName"></p>
    <label class="field"><span>下载次数（0 = 不限制）</span><input id="shMax" class="input" type="number" min="0" value="0"></label>
    <label class="field"><span>有效期天数（0 = 永久）</span><input id="shDays" class="input" type="number" min="0" value="0"></label>
    <p class="err left" id="shareErr" style="margin-top:-4px"></p>
    <div class="modal-foot">
      <button class="btn" data-close="shareOv">取消</button>
      <button class="btn primary" id="shareGo">生成链接</button>
    </div>
  </div>
</div>

<!-- 下载记录弹窗 -->
<div class="overlay" id="logsOv">
  <div class="modal" style="max-width:560px">
    <h3>下载记录 <button class="modal-close" data-close="logsOv"><?= icon('close') ?></button></h3>
    <p class="sub" id="logsSub"></p>
    <div id="logsBody"><div class="logs-empty">加载中…</div></div>
  </div>
</div>

<!-- 重命名弹窗 -->
<div class="overlay" id="renameOv">
  <div class="modal">
    <h3>重命名文件 <button class="modal-close" data-close="renameOv"><?= icon('close') ?></button></h3>
    <p class="sub">修改文件显示名称（不影响已生成的链接）</p>
    <label class="field"><span>新文件名</span><input id="rnName" class="input" maxlength="200"></label>
    <p class="err left" id="rnErr"></p>
    <div class="modal-foot">
      <button class="btn" data-close="renameOv">取消</button>
      <button class="btn primary" id="rnGo">保存</button>
    </div>
  </div>
</div>

<!-- 账号设置弹窗 -->
<div class="overlay" id="accountOv">
  <div class="modal">
    <h3>账号设置 <button class="modal-close" data-close="accountOv"><?= icon('close') ?></button></h3>
    <p class="sub">修改邮箱或密码</p>
    <label class="field"><span>邮箱</span><input id="acEmail" class="input" autocomplete="username"></label>
    <label class="field"><span>当前密码（必填）</span><input id="acOld" class="input" type="password" autocomplete="current-password" placeholder="验证身份"></label>
    <div class="row2">
      <label class="field"><span>新密码（留空不改）</span><input id="acNew" class="input" type="password" autocomplete="new-password" placeholder="至少 6 位"></label>
      <label class="field"><span>确认新密码</span><input id="acNew2" class="input" type="password" autocomplete="new-password" placeholder="再次输入"></label>
    </div>
    <p class="err left" id="acErr"></p>
    <div class="modal-foot">
      <button class="btn" data-close="accountOv">取消</button>
      <button class="btn primary" id="acGo">保存</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const ICONS = <?= json_encode(icon_set(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const $ = s => document.querySelector(s);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let STATE = { user:'', is_admin:false, quota_mb:50, points:0, checkin_today:false,
              checkin_points:10, quota_max_mb:200, quota_step_mb:50, quota_step_points:100 };
let MY_FILES = [], PUB_FILES = [];
let shareFileId = 0, renameFileId = 0;

function toast(msg) {
  const d = document.createElement('div');
  d.className = 'toast'; d.textContent = msg;
  document.body.appendChild(d);
  setTimeout(() => d.remove(), 1900);
}
function openOv(id) { document.getElementById(id).classList.add('show'); }
function closeOv(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('[data-close]').forEach(b => b.onclick = () => closeOv(b.dataset.close));
document.querySelectorAll('.overlay').forEach(o => o.onclick = e => { if (e.target === o) o.classList.remove('show'); });

async function api(a, data) {
  const fd = new FormData();
  for (const k in (data || {})) fd.append(k, data[k]);
  const r = await fetch('?a=' + a, { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
  return r.json().catch(() => ({ ok: false, msg: '网络错误' }));
}
async function getJSON(a) {
  const r = await fetch('?a=' + a);
  return r.json().catch(() => ({ ok: false }));
}

/* ---------------- Tab ---------------- */
document.querySelectorAll('.tabs [data-tab]').forEach(b => b.onclick = () => {
  document.querySelectorAll('.tabs [data-tab]').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.tab-panel').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  document.getElementById('panel-' + b.dataset.tab).classList.add('on');
});

/* ---------------- 状态 / 顶栏 ---------------- */
function refreshPoints() {
  $('#hPoints').textContent = STATE.points;
  $('#hQuota').textContent = STATE.quota_mb;
  $('#dropHint').textContent = '任意格式 · 单文件最大 ' + STATE.quota_mb + ' MB';
  $('#btnCheckin').disabled = STATE.checkin_today;
  $('#btnCheckin').title = STATE.checkin_today ? '今天已签到' : '签到 +' + STATE.checkin_points + ' 积分';
}

async function loadState() {
  const j = await getJSON('state');
  if (j.ok) {
    STATE = Object.assign(STATE, j);
    refreshPoints();
  }
}

/* ---------------- 上传 ---------------- */
const drop = $('#drop'), pick = $('#pick');
drop.onclick = () => pick.click();
pick.onchange = () => { if (pick.files.length) uploadFiles([...pick.files]); pick.value = ''; };
['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', e => { const fs = [...(e.dataTransfer?.files || [])]; if (fs.length) uploadFiles(fs); });

async function uploadFiles(files) {
  const agree = $('#chkAgree');
  if (!agree.checked) { toast('请先勾选「我已阅读并同意上述规定」'); return; }
  const pub = $('#chkPublic').checked ? 1 : 0;
  const prog = $('#prog');
  for (const f of files) {
    if (f.size > STATE.quota_mb * 1024 * 1024) { toast('「' + f.name + '」超过 ' + STATE.quota_mb + ' MB'); continue; }
    await new Promise(resolve => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', '?a=upload');
      xhr.setRequestHeader('X-CSRF', CSRF);
      xhr.upload.onprogress = e => {
        if (e.lengthComputable) prog.textContent = f.name + '  ' + Math.round(e.loaded / e.total * 100) + '%';
      };
      xhr.onload = () => {
        let j = {}; try { j = JSON.parse(xhr.responseText); } catch (e) {}
        if (j.ok) toast('已上传「' + j.name + '」'); else toast(j.msg || '上传失败');
        resolve();
      };
      xhr.onerror = () => { toast('上传失败：网络错误'); resolve(); };
      const fd = new FormData(); fd.append('file', f); fd.append('public', pub); xhr.send(fd);
    });
  }
  prog.textContent = '';
  loadMine();
}

/* ---------------- 我的文件 ---------------- */
function shareHtml(f) {
  const s = f.shares && f.shares[0];
  if (!s) {
    return '<button class="btn sm" data-act="share" data-id="' + f.id + '">' + ICONS.link + '<span class="label">外链</span></button>';
  }
  const st = { ok: ['ok', '有效'], used: ['used', '已用完'], expired: ['expired', '已过期'] }[s.status] || ['ok', '有效'];
  const cnt = s.max_downloads > 0 ? (s.downloads + '/' + s.max_downloads) : (s.downloads + ' 次');
  return '<span class="share-code" title="' + esc(s.url) + '">/d/' + esc(s.token.slice(0, 8)) + '…</span>'
    + '<span class="badge ' + st[0] + '">' + st[1] + '</span>'
    + '<span>' + cnt + '</span>'
    + '<button class="btn ghost sm" data-act="copy" data-url="' + esc(s.url) + '">' + ICONS.copy + '<span class="label">复制</span></button>'
    + '<button class="btn ghost sm danger" data-act="unshare" data-id="' + s.id + '">' + ICONS.trash + '<span class="label">撤销</span></button>';
}

function renderMine() {
  const list = $('#listMine');
  $('#countMine').textContent = MY_FILES.length ? MY_FILES.length + ' 个文件' : '';
  if (!MY_FILES.length) {
    list.innerHTML = '<div class="empty">还没有文件，去「上传文件」试试</div>';
    return;
  }
  list.innerHTML = MY_FILES.map(f => {
    const pubBadge = f.public ? '<span class="badge ok">公开</span>' : '<span class="badge">私密</span>';
    const expBadge = f.expired ? '<span class="badge expired">已过期</span>' : '';
    const region = f.upload_region ? ' · ' + esc(f.upload_region) : '';
    return '<div class="file-row">'
      + '<div class="file-ic">' + ICONS.file + '</div>'
      + '<div class="file-main">'
        + '<div class="file-name">' + esc(f.name) + '</div>'
        + '<div class="file-meta">' + esc(f.size_h) + ' · ' + esc(f.created_h) + region + ' · 到期 ' + esc(f.expire_h) + '</div>'
        + '<div class="share-line">' + pubBadge + expBadge + shareHtml(f) + '</div>'
      + '</div>'
      + '<div class="file-actions">'
        + '<a class="btn ghost sm" href="?a=download&id=' + f.id + '" title="下载">' + ICONS.download + '</a>'
        + '<button class="btn ghost sm" data-act="rename" data-id="' + f.id + '" data-name="' + esc(f.name) + '" title="重命名">' + ICONS.edit + '</button>'
        + '<button class="btn ghost sm" data-act="togglepub" data-id="' + f.id + '" data-pub="' + f.public + '" title="公开/隐藏">' + (f.public ? ICONS.eyeoff : ICONS.eye) + '</button>'
        + '<button class="btn ghost sm" data-act="logs" data-fid="' + f.id + '" title="下载记录">' + ICONS.clock + '</button>'
        + '<button class="btn ghost sm danger" data-act="delfile" data-id="' + f.id + '" title="删除">' + ICONS.trash + '</button>'
      + '</div>'
    + '</div>';
  }).join('');
}

/* ---------------- 公开文件列表 ---------------- */
function renderPublic() {
  const list = $('#listPublic');
  $('#countPublic').textContent = PUB_FILES.length ? PUB_FILES.length + ' 个文件' : '';
  if (!PUB_FILES.length) {
    list.innerHTML = '<div class="empty">暂时没有公开文件</div>';
    return;
  }
  list.innerHTML = PUB_FILES.map(f =>
    '<div class="file-row">'
      + '<div class="file-ic">' + ICONS.file + '</div>'
      + '<div class="file-main">'
        + '<div class="file-name">' + esc(f.name) + '</div>'
        + '<div class="file-meta">' + esc(f.size_h) + ' · ' + esc(f.created_h) + '</div>'
      + '</div>'
      + '<div class="file-actions">'
        + '<a class="btn sm" href="?a=download&id=' + f.id + '">' + ICONS.download + '<span class="label">下载</span></a>'
      + '</div>'
    + '</div>'
  ).join('');
}

/* ---------------- 加载 ---------------- */
async function loadMine() {
  const j = await getJSON('files');
  MY_FILES = (j.files || []);
  renderMine();
}
async function loadPublic() {
  const j = await getJSON('public_files');
  PUB_FILES = (j.files || []);
  renderPublic();
}

/* ---------------- 事件委托（我的文件） ---------------- */
$('#listMine').addEventListener('click', async e => {
  const btn = e.target.closest('[data-act],a');
  if (!btn) return;
  if (btn.tagName === 'A') return; // 下载链接交给浏览器
  const act = btn.dataset.act;
  if (act === 'share') {
    shareFileId = +btn.dataset.id;
    const f = MY_FILES.find(x => x.id === shareFileId);
    $('#shareName').textContent = f ? f.name : '';
    $('#shMax').value = '0'; $('#shDays').value = '0';
    $('#shareErr').textContent = '';
    openOv('shareOv');
  } else if (act === 'copy') {
    copyText(btn.dataset.url);
  } else if (act === 'unshare') {
    if (!confirm('撤销后该链接将立即失效，确定撤销吗？')) return;
    const j = await api('del_share', { id: btn.dataset.id });
    if (j.ok) { toast('链接已撤销'); loadMine(); } else toast(j.msg || '操作失败');
  } else if (act === 'rename') {
    renameFileId = +btn.dataset.id;
    $('#rnName').value = btn.dataset.name;
    $('#rnErr').textContent = '';
    openOv('renameOv');
  } else if (act === 'togglepub') {
    const target = btn.dataset.pub === '1' ? 0 : 1;
    const j = await api('set_public', { id: btn.dataset.id, public: target });
    if (j.ok) { toast(target ? '已设为公开' : '已设为私密'); loadMine(); loadPublic(); } else toast(j.msg || '操作失败');
  } else if (act === 'logs') {
    openLogs(+btn.dataset.fid);
  } else if (act === 'delfile') {
    if (!confirm('删除文件后无法恢复，确定删除吗？')) return;
    const j = await api('del_file', { id: btn.dataset.id });
    if (j.ok) { toast('文件已删除'); loadMine(); loadPublic(); } else toast(j.msg || '操作失败');
  }
});

/* ---------------- 分享 / 重命名 / 记录 ---------------- */
$('#shareGo').onclick = async () => {
  const err = $('#shareErr'); err.textContent = '';
  const max = Math.max(0, parseInt($('#shMax').value || '0', 10) || 0);
  const days = Math.max(0, parseInt($('#shDays').value || '0', 10) || 0);
  const j = await api('share', { file_id: shareFileId, max: max, days: days });
  if (!j.ok) { err.textContent = j.msg || '生成失败'; return; }
  closeOv('shareOv');
  toast('链接已生成');
  loadMine();
};
$('#rnGo').onclick = async () => {
  const err = $('#rnErr'); err.textContent = '';
  const name = $('#rnName').value.trim();
  if (!name) { err.textContent = '文件名不能为空'; return; }
  const j = await api('rename', { id: renameFileId, name: name });
  if (!j.ok) { err.textContent = j.msg || '重命名失败'; return; }
  closeOv('renameOv');
  toast('已重命名');
  loadMine(); loadPublic();
};

async function openLogs(fid) {
  $('#logsSub').textContent = '最近 100 条记录（含站内与外链下载）';
  $('#logsBody').innerHTML = '<div class="logs-empty">加载中…</div>';
  openOv('logsOv');
  const j = await getJSON('logs&file_id=' + fid);
  if (!j.ok) { $('#logsBody').innerHTML = '<div class="logs-empty">' + (j.msg || '加载失败') + '</div>'; return; }
  const rows = j.logs || [];
  if (!rows.length) { $('#logsBody').innerHTML = '<div class="logs-empty">还没有下载记录</div>'; return; }
  $('#logsBody').innerHTML = '<table class="logs"><thead><tr><th>时间</th><th>方式</th><th>IP</th><th>地区</th><th>设备</th></tr></thead><tbody>'
    + rows.map(r =>
      '<tr><td>' + esc(r.time) + '</td>'
      + '<td>' + esc(r.via) + '</td>'
      + '<td class="ip">' + esc(r.ip || '-') + '</td>'
      + '<td>' + esc(r.region || '未知') + '</td>'
      + '<td>' + esc(shortUA(r.ua)) + '</td></tr>').join('')
    + '</tbody></table>';
}

function shortUA(ua) {
  ua = ua || '';
  if (/Edg\//.test(ua)) return 'Edge';
  if (/Chrome\//.test(ua) && !/Edg|OPR/.test(ua)) return 'Chrome';
  if (/Firefox\//.test(ua)) return 'Firefox';
  if (/Safari\//.test(ua) && !/Chrome/.test(ua)) return 'Safari';
  if (/curl\//.test(ua)) return 'curl';
  if (/Wget\//.test(ua)) return 'wget';
  if (/Windows/.test(ua)) return 'Windows';
  if (/Android/.test(ua)) return 'Android';
  if (/iPhone|iPad/.test(ua)) return 'iOS';
  return ua ? ua.slice(0, 24) : '未知';
}

function copyText(t) {
  const done = () => toast('链接已复制');
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(t).then(done).catch(() => fallback());
  } else fallback();
  function fallback() {
    const ta = document.createElement('textarea');
    ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { toast('复制失败，请手动复制'); }
    ta.remove();
  }
}

/* ---------------- 签到 / 兑换 ---------------- */
$('#btnCheckin').onclick = async () => {
  const j = await api('checkin', {});
  if (j.ok) {
    STATE.points = j.points;
    STATE.checkin_today = true;
    refreshPoints();
    toast('签到成功 +' + j.gain + ' 积分');
  } else toast(j.msg || '签到失败');
};
$('#btnExchange').onclick = async () => {
  if (STATE.quota_mb >= STATE.quota_max_mb) { toast('已达到最大容量 ' + STATE.quota_max_mb + ' MB'); return; }
  const need = STATE.quota_step_points;
  const next = Math.min(STATE.quota_mb + STATE.quota_step_mb, STATE.quota_max_mb);
  if (!confirm('消耗 ' + need + ' 积分，将单文件上限提升到 ' + next + ' MB？\n当前积分：' + STATE.points)) return;
  const j = await api('exchange', {});
  if (j.ok) {
    STATE.points = j.points;
    STATE.quota_mb = j.quota_mb;
    refreshPoints();
    toast('兑换成功，上限提升到 ' + j.quota_mb + ' MB');
  } else toast(j.msg || '兑换失败');
};

/* ---------------- 账号设置 ---------------- */
$('#btnAccount').onclick = () => {
  $('#acEmail').value = STATE.user;
  $('#acOld').value = ''; $('#acNew').value = ''; $('#acNew2').value = '';
  $('#acErr').textContent = '';
  openOv('accountOv');
};
$('#acGo').onclick = async () => {
  const err = $('#acErr'); err.textContent = '';
  const email = $('#acEmail').value.trim();
  const old = $('#acOld').value;
  const np = $('#acNew').value;
  const np2 = $('#acNew2').value;
  if (!old) { err.textContent = '请输入当前密码'; return; }
  if (np && np.length < 6) { err.textContent = '新密码至少 6 位'; return; }
  if (np !== np2) { err.textContent = '两次输入的新密码不一致'; return; }
  const go = $('#acGo'); go.disabled = true;
  const j = await api('account', { email: email, old_password: old, password: np });
  go.disabled = false;
  if (!j.ok) { err.textContent = j.msg || '保存失败'; return; }
  closeOv('accountOv');
  toast('已保存');
  setTimeout(() => location.reload(), 500);
};

/* ---------------- 退出 ---------------- */
$('#btnLogout').onclick = async () => {
  await fetch('?a=logout', { method: 'POST' });
  location.reload();
};

/* ---------------- 初始化 ---------------- */
(async () => {
  await loadState();
  await Promise.all([loadMine(), loadPublic()]);
})();
</script>
<?php
    page_end();
}


/* ==================================================================
 *  页面：管理员后台
 * ================================================================== */
function render_admin_page() {
    $csrf = csrf_token();
    $title = cfg('title');
    page_head('管理后台 - ' . $title);
?>
<header class="topbar">
  <a class="brand" href="./"><img src="assets/logo.png" alt=""><span><?= h($title) ?></span></a>
  <div class="top-actions">
    <button class="btn ghost sm" id="btnLogout"><?= icon('logout') ?></button>
  </div>
</header>

<main class="wrap">
  <a class="backlink" href="./"><?= icon('list') ?>返回网盘</a>

  <div class="stat-grid" id="stats"></div>

  <div class="admin-section">
    <h2><?= icon('users') ?>账号管理</h2>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>ID</th><th>邮箱</th><th>角色</th><th>状态</th><th>积分</th><th>配额</th><th>文件数</th><th>注册</th><th>操作</th></tr></thead>
      <tbody id="userRows"><tr><td colspan="9" class="logs-empty">加载中…</td></tr></tbody>
    </table></div>
  </div>

  <div class="admin-section">
    <h2><?= icon('file') ?>文件管理</h2>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>ID</th><th>文件名</th><th>上传者</th><th>大小</th><th>公开</th><th>上传时间</th><th>到期</th><th>操作</th></tr></thead>
      <tbody id="fileRows"><tr><td colspan="8" class="logs-empty">加载中…</td></tr></tbody>
    </table></div>
  </div>

  <div class="admin-section">
    <h2><?= icon('gear') ?>系统设置</h2>
    <div class="list" style="padding:16px 18px">
      <div class="row2">
        <label class="field"><span>签到积分（每天）</span><input id="setCheckin" class="input" type="number" min="1"></label>
        <label class="field"><span>普通用户存储天数（0 = 永久）</span><input id="setRetention" class="input" type="number" min="0"></label>
      </div>
      <div class="modal-foot" style="margin-top:2px">
        <button class="btn" id="btnCleanup"><?= icon('trash') ?>清理过期文件</button>
        <button class="btn primary" id="btnSaveSetting">保存设置</button>
      </div>
      <p class="err left" id="setErr"></p>
    </div>
  </div>
</main>

<!-- 通用操作弹窗 -->
<div class="overlay" id="actOv">
  <div class="modal">
    <h3 id="actTitle">操作 <button class="modal-close" data-close="actOv"><?= icon('close') ?></button></h3>
    <div id="actBody"></div>
    <p class="err left" id="actErr"></p>
    <div class="modal-foot">
      <button class="btn" data-close="actOv">取消</button>
      <button class="btn primary" id="actGo">确定</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const ICONS = <?= json_encode(icon_set(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const $ = s => document.querySelector(s);
const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let actHandler = null;

function toast(msg) {
  const d = document.createElement('div');
  d.className = 'toast'; d.textContent = msg;
  document.body.appendChild(d);
  setTimeout(() => d.remove(), 1900);
}
function openOv(id) { document.getElementById(id).classList.add('show'); }
function closeOv(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('[data-close]').forEach(b => b.onclick = () => closeOv(b.dataset.close));
document.querySelectorAll('.overlay').forEach(o => o.onclick = e => { if (e.target === o) o.classList.remove('show'); });

async function api(a, data) {
  const fd = new FormData();
  for (const k in (data || {})) fd.append(k, data[k]);
  const r = await fetch('?a=' + a, { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF } });
  return r.json().catch(() => ({ ok: false, msg: '网络错误' }));
}
async function getJSON(a) {
  const r = await fetch('?a=' + a);
  return r.json().catch(() => ({ ok: false }));
}

/* 打开操作弹窗 */
function openAct(title, bodyHtml, handler) {
  $('#actTitle').textContent = title;
  $('#actBody').innerHTML = bodyHtml;
  $('#actErr').textContent = '';
  actHandler = handler;
  openOv('actOv');
}
$('#actGo').onclick = async () => {
  if (!actHandler) return;
  $('#actErr').textContent = '';
  const j = await actHandler();
  if (j && j.ok !== undefined && !j.ok) { $('#actErr').textContent = j.msg || '操作失败'; }
};

/* ---------------- 统计 ---------------- */
async function loadStats() {
  const j = await getJSON('admin_settings');
  if (!j.ok) return;
  $('#stats').innerHTML =
    '<div class="stat"><b>' + j.user_count + '</b><span>用户</span></div>'
    + '<div class="stat"><b>' + j.file_count + '</b><span>文件</span></div>'
    + '<div class="stat"><b>' + esc(j.storage_h) + '</b><span>存储占用</span></div>'
    + '<div class="stat"><b>' + j.expired_count + '</b><span>待清理过期文件</span></div>';
  $('#setCheckin').value = j.checkin_points;
  $('#setRetention').value = j.retention_days;
}

/* ---------------- 用户 ---------------- */
async function loadUsers() {
  const j = await getJSON('admin_users');
  if (!j.ok) return;
  const rows = j.users || [];
  $('#userRows').innerHTML = rows.map(u =>
    '<tr><td>' + u.id + '</td>'
    + '<td>' + esc(u.email) + '</td>'
    + '<td>' + (u.role === 'admin' ? '<span class="tag admin">管理员</span>' : '<span class="tag">用户</span>') + '</td>'
    + '<td>' + (u.status === 'banned' ? '<span class="tag ban">已封禁</span>' : '<span class="tag ok">正常</span>') + '</td>'
    + '<td>' + u.points + '</td>'
    + '<td>' + u.quota_mb + 'MB</td>'
    + '<td>' + u.file_count + '</td>'
    + '<td>' + esc(u.created_h) + '</td>'
    + '<td>' + userActions(u) + '</td></tr>'
  ).join('');
}
function userActions(u) {
  let b = '';
  if (u.status === 'banned') b += '<button class="btn sm" data-uact="unban" data-id="' + u.id + '">解封</button>';
  else b += '<button class="btn sm danger" data-uact="ban" data-id="' + u.id + '">封禁</button>';
  b += '<button class="btn sm" data-uact="reset" data-id="' + u.id + '" data-email="' + esc(u.email) + '">重置密码</button>';
  b += '<button class="btn sm" data-uact="points" data-id="' + u.id + '" data-email="' + esc(u.email) + '" data-cur="' + u.points + '">调积分</button>';
  b += '<button class="btn sm" data-uact="quota" data-id="' + u.id + '" data-email="' + esc(u.email) + '" data-cur="' + u.quota_mb + '">调配额</button>';
  b += '<button class="btn sm danger" data-uact="delete" data-id="' + u.id + '" data-email="' + esc(u.email) + '">删除</button>';
  return b;
}
$('#userRows').addEventListener('click', e => {
  const btn = e.target.closest('[data-uact]');
  if (!btn) return;
  const uact = btn.dataset.uact, id = btn.dataset.id, email = btn.dataset.email;
  if (uact === 'ban') {
    openAct('封禁账号', '<p class="sub">确定封禁「' + esc(email) + '」吗？封禁后该用户将无法登录。</p>', () => api('admin_user', { id, do: 'ban' }).then(j => { if (j.ok) { toast('已封禁'); loadUsers(); closeOv('actOv'); } return j; }));
  } else if (uact === 'unban') {
    openAct('解封账号', '<p class="sub">确定解封「' + esc(email) + '」吗？</p>', () => api('admin_user', { id, do: 'unban' }).then(j => { if (j.ok) { toast('已解封'); loadUsers(); closeOv('actOv'); } return j; }));
  } else if (uact === 'reset') {
    openAct('重置密码', '<label class="field"><span>「' + esc(email) + '」的新密码</span><input id="actPwd" class="input" type="text" placeholder="至少 6 位"></label>', () => api('admin_user', { id, do: 'reset_pass', password: $('#actPwd').value }));
  } else if (uact === 'points') {
    openAct('调整积分', '<p class="sub">「' + esc(email) + '」当前积分：' + btn.dataset.cur + '</p><label class="field"><span>增减量（正加负减）</span><input id="actDelta" class="input" type="number" value="10"></label>', () => api('admin_user', { id, do: 'points', delta: $('#actDelta').value }).then(j => { if (j.ok) { toast('调整成功'); loadUsers(); closeOv('actOv'); } return j; }));
  } else if (uact === 'quota') {
    openAct('调整配额', '<p class="sub">「' + esc(email) + '」当前单文件上限：' + btn.dataset.cur + 'MB</p><label class="field"><span>新配额（MB）</span><input id="actQuota" class="input" type="number" value="' + btn.dataset.cur + '"></label>', () => api('admin_user', { id, do: 'quota', quota_mb: $('#actQuota').value }).then(j => { if (j.ok) { toast('调整成功'); loadUsers(); closeOv('actOv'); } return j; }));
  } else if (uact === 'delete') {
    openAct('删除用户', '<p class="sub">将永久删除「' + esc(email) + '」及其所有文件，无法恢复。确定？</p>', () => api('admin_user', { id, do: 'delete' }).then(j => { if (j.ok) { toast('已删除'); loadUsers(); loadFiles(); loadStats(); closeOv('actOv'); } return j; }));
  }
});

/* ---------------- 文件 ---------------- */
async function loadFiles() {
  const j = await getJSON('admin_files');
  if (!j.ok) return;
  const rows = j.files || [];
  $('#fileRows').innerHTML = rows.map(f =>
    '<tr><td>' + f.id + '</td>'
    + '<td>' + esc(f.name) + '</td>'
    + '<td>' + esc(f.owner || '-') + '</td>'
    + '<td>' + esc(f.size_h) + '</td>'
    + '<td>' + (f.public ? '<span class="tag ok">公开</span>' : '<span class="tag">私密</span>') + '</td>'
    + '<td>' + esc(f.created_h) + '</td>'
    + '<td>' + (f.expired ? '<span class="tag ban">已过期</span>' : esc(f.expire_h)) + '</td>'
    + '<td><button class="btn sm" data-fact="toggle" data-id="' + f.id + '" data-pub="' + f.public + '">' + (f.public ? '隐藏' : '公开') + '</button>'
    + ' <button class="btn sm danger" data-fact="delete" data-id="' + f.id + '" data-name="' + esc(f.name) + '">删除</button></td></tr>'
  ).join('');
}
$('#fileRows').addEventListener('click', async e => {
  const btn = e.target.closest('[data-fact]');
  if (!btn) return;
  const fact = btn.dataset.fact, id = btn.dataset.id;
  if (fact === 'toggle') {
    const target = btn.dataset.pub === '1' ? 0 : 1;
    const j = await api('admin_file', { id, do: 'public', public: target });
    if (j.ok) { toast(target ? '已设为公开' : '已设为私密'); loadFiles(); } else toast(j.msg || '操作失败');
  } else if (fact === 'delete') {
    if (!confirm('确定删除「' + btn.dataset.name + '」？无法恢复。')) return;
    const j = await api('admin_file', { id, do: 'delete' });
    if (j.ok) { toast('已删除'); loadFiles(); loadStats(); } else toast(j.msg || '操作失败');
  }
});

/* ---------------- 设置 ---------------- */
$('#btnSaveSetting').onclick = async () => {
  const j = await api('admin_save_settings', { checkin_points: $('#setCheckin').value, retention_days: $('#setRetention').value });
  if (j.ok) { toast('已保存'); loadStats(); } else { $('#setErr').textContent = j.msg || '保存失败'; }
};
$('#btnCleanup').onclick = async () => {
  if (!confirm('将清理所有已过期的普通用户文件（含物理文件），确定？')) return;
  const j = await api('admin_cleanup', {});
  if (j.ok) { toast('已清理 ' + j.removed + ' 个文件'); loadStats(); loadFiles(); } else toast(j.msg || '清理失败');
};

/* ---------------- 退出 ---------------- */
$('#btnLogout').onclick = async () => {
  await fetch('?a=logout', { method: 'POST' });
  location.reload();
};

loadStats();
loadUsers();
loadFiles();
</script>
<?php
    page_end();
}

/* ================================================================
 * 极验云网盘 · 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * ================================================================ */

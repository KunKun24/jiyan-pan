<?php
/*
 * 极验云网盘
 * 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 */
/* 极验云盘 - 清理过期文件（cron 脚本） */
// cron 示例（每 5 分钟，路径改成你的实际路径）：
// */5 * * * * php /path/to/pan/cleanup.php >> /dev/null 2>&1

define('PANDISK', 1);
require __DIR__ . '/lib.php';

// 仅允许命令行运行，防止被网页访问
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$n = cleanup_expired_files();
echo date('Y-m-d H:i:s') . ' cleanup: removed ' . $n . ' expired file(s)' . PHP_EOL;

/* ================================================================
 * 极验云网盘 · 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * ================================================================ */

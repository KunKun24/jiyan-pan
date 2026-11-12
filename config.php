<?php
/*
 * 极验云网盘
 * 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 */
/* 极验云盘 - 配置文件（被 lib.php 引入，请勿直接访问） */
if (!defined('PANDISK')) { http_response_code(404); exit; }

return [
    // MySQL 数据库（部署时改成你自己的数据库信息）
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'your_database_name',
        'user'    => 'your_db_user',
        'pass'    => 'your_db_password',
        'charset' => 'utf8mb4',
    ],

    // 文件存储目录（必须在本站点目录内，且 nginx 已禁止直接访问）
    'storage' => __DIR__ . '/storage',

    // 站点标题
    'title' => '极验云盘',

    // 站点地址，留空则自动识别（如 https://cloud.example.com）
    'site_url' => '',

    // 单文件上传上限（MB），同时需 nginx / PHP 配置配合（管理员上限，普通用户按 quota 限制）
    'max_upload_mb' => 2048,

    // ===== 多用户规则 =====
    // 普通用户文件存储天数（0 = 永久），管理员文件始终永久
    'file_retention_days' => 30,
    // 签到：每日积分
    'checkin_points' => 10,
    // 积分兑换单文件大小上限：默认档 / 每档升多少 MB / 每档消耗积分 / 封顶 MB
    'quota_default_mb' => 50,
    'quota_step_mb'    => 50,
    'quota_step_points'=> 100,
    'quota_max_mb'     => 200,
];

/* ================================================================
 * 极验云网盘 · 由 TG 困困开源 @sad1881 困困承接各种业务 欢迎咨询
 * ================================================================ */

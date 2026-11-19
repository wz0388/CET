<?php
/**
 * 站点配置示例
 * 复制为 config.php 后按实际情况修改
 */

// ---- 数据库 ----
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'cet';
const DB_USER = 'cet';
const DB_PASS = 'change-me';

// ---- 初始管理员（仅在 users 表为空时写入）----
const SEED_ADMIN_USER = 'admin';
const SEED_ADMIN_PASS = 'change-this-password';

// 备案信息（页脚展示，留空则不显示）
const ICP_BEIAN = '';
const ICP_URL   = 'https://beian.miit.gov.cn/';

// ---- 访问控制 ----
// 仅允许中国境内访问；海外 IP 返回 403（解析失败时放行，避免误伤）
const BLOCK_FOREIGN = false;
// 白名单：不受上述限制的 IP
const FOREIGN_ALLOW_IPS = [];

// ---- 真实 IP 判定 ----
// 只有 REMOTE_ADDR 命中「本机 / 内网 / 下面登记的代理」时才采信 X-Forwarded-For
const TRUSTED_PROXIES = [];

// ---- 登录保护（防暴力破解）----
const LOGIN_GUARD        = true;
const LOGIN_MAX_PER_USER = 8;
const LOGIN_MAX_PER_IP   = 40;
const LOGIN_WINDOW_MIN   = 15;
const LOGIN_EXEMPT_IPS   = [];

// 账号不存在时用来消耗等量时间的哑哈希（防用户名枚举）
const DUMMY_PASS_HASH = '$2y$12$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

// 会话版本：改大这个数字会让所有已登录用户立即掉线
const SESSION_VERSION = 1;

// 静态资源版本号：改样式/脚本后改这里，强制浏览器刷新缓存
const ASSET_VER = '1';

date_default_timezone_set('Asia/Shanghai');

<?php
/**
 * 首页入口：走统一的访问控制与访问记录，再输出静态页面
 * （纯静态文件放在 home.html，避免绕过海外拦截与统计）
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: text/html; charset=utf-8');

$html = (string)@file_get_contents(__DIR__ . '/home.html');
$beian = defined('ICP_BEIAN') ? trim((string)ICP_BEIAN) : '';

// 备案号只存在于部署环境的 config.php，仓库里只有一个注释占位
if ($beian !== '') {
    $link = '<a class="icp" href="https://beian.miit.gov.cn/" target="_blank"'
          . ' rel="noopener noreferrer">' . htmlspecialchars($beian, ENT_QUOTES, 'UTF-8') . '</a>';
    $html = str_replace('<!--BEIAN-->', $link, $html);
}
echo $html;

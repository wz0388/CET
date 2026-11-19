<?php
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$me = current_user();
if (!$me) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录']);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'progress':
            $paper = (string)($_GET['paper'] ?? '');
            $data = progress_get((int)$me['id'], $paper);
            echo json_encode(['ok' => true, 'data' => $data ?: new stdClass()]);
            break;

        case 'save_progress':
            $in    = body();
            $paper = substr((string)($in['paper'] ?? ''), 0, 32);
            if ($paper === '') {
                echo json_encode(['ok' => false, 'msg' => '缺少试卷参数']);
                break;
            }
            $payload = [
                'answers' => (object)(is_array($in['answers'] ?? null) ? $in['answers'] : []),
                'done'    => is_array($in['done'] ?? null) ? array_values($in['done']) : [],
                'exam'    => (object)(is_array($in['exam'] ?? null) ? $in['exam'] : []),
                'ts'      => time(),
            ];
            progress_save((int)$me['id'], $paper, $payload);
            echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
            break;

        case 'words':
            $data = progress_get((int)$me['id'], 'words');
            // 读取侧也要把 map 转成对象：json_decode(assoc) 会把 {} 变成 []，
            // 再 encode 就回给前端一个数组，导致字符串键写不进去
            if (isset($data['state']) && is_array($data['state'])) {
                foreach (['done', 'wrong', 'words', 'fav', 'known'] as $k) {
                    if (isset($data['state'][$k]) && is_array($data['state'][$k])) {
                        $data['state'][$k] = (object)$data['state'][$k];
                    }
                }
            }
            echo json_encode(['ok' => true, 'data' => $data ?: new stdClass()]);
            break;

        case 'save_words':
            $in = body();
            $st = $in['state'] ?? null;
            if (!is_array($st)) {
                echo json_encode(['ok' => false, 'msg' => '参数错误']);
                break;
            }
            // done / wrong / words 都是 map，空的时候要序列化成 {} 而不是 []
            foreach (['done', 'wrong', 'words', 'fav', 'known'] as $k) {
                if (isset($st[$k]) && is_array($st[$k])) {
                    $st[$k] = (object)$st[$k];
                }
            }
            progress_save((int)$me['id'], 'words', ['state' => $st, 'ts' => time()]);
            echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
            break;

        case 'attempt':
            $in    = body();
            $paper = substr((string)($in['paper'] ?? ''), 0, 32);
            $mode  = substr((string)($in['mode'] ?? 'exam'), 0, 16);
            $st = db()->prepare('INSERT INTO attempts (user_id, paper_id, mode, answered, total, score, duration, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $st->execute([
                (int)$me['id'],
                $paper,
                $mode,
                (int)($in['answered'] ?? 0),
                (int)($in['total'] ?? 0),
                isset($in['score']) && $in['score'] !== null ? (int)$in['score'] : null,
                (int)($in['duration'] ?? 0),
            ]);
            log_action((int)$me['id'], 'attempt', $mode . ' ' . $paper);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'msg' => '未知操作']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => '服务器错误']);
}

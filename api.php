<?php
// ====== CÀI ĐẶT MẬT KHẨU ======
// Đổi các mật khẩu này trước khi up lên host. Mỗi mật khẩu phải khác nhau.
// 'all' = thêm, sửa, xóa được cho cả 3 người (dành cho anh quản lý).
// 'p1' = Sơ Kê, 'p2' = Đư Cờ, 'p3' = Cô Cô: xem được cả bảng, nhưng chỉ thêm, sửa, xóa phần của mình.
$USERS = [
  'muadong' => 'all',
  'soke'   => 'p1',
  'duco'   => 'p2',
  'muadong2'   => 'p3',
];
// ==============================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dir  = __DIR__ . '/data';
$file = $dir . '/entries.json';

function out($code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

$pass = $_SERVER['HTTP_X_PASS'] ?? '';
$role = null;
foreach ($USERS as $p => $r) { if ($pass !== '' && hash_equals((string)$p, $pass)) { $role = $r; break; } }
if ($role === null) out(401, ['error' => 'sai_mat_khau']);

if (!is_dir($dir) && !mkdir($dir, 0755, true)) out(500, ['error' => 'khong_tao_duoc_thu_muc_data']);

$fp = fopen($file, 'c+');
if (!$fp) out(500, ['error' => 'khong_mo_duoc_file_du_lieu']);
flock($fp, LOCK_EX);
$raw  = stream_get_contents($fp);
$days = json_decode($raw ?: '[]', true);
if (!is_array($days)) $days = [];

function mine($d, $role) { return $role === 'all' || ($d['who'] ?? '') === $role; }
// Ai cũng xem được bảng của cả 3 người; chỉ thêm, sửa, xóa được phần của mình.
function visible($days, $role) { return array_values($days); }

function clean($e, $role) {
  $s = in_array($e['s'] ?? '', ['win', 'lost', 'idle'], true) ? $e['s'] : 'win';
  $who = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['who'] ?? ''));
  if ($role !== 'all') $who = $role; // người thường chỉ ghi được cho chính mình
  return [
    'id'   => preg_replace('/[^A-Za-z0-9_-]/', '', (string)($e['id'] ?? '')),
    'who'  => $who,
    'num'  => mb_substr((string)($e['num'] ?? ''), 0, 60),
    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['date'] ?? '') ? $e['date'] : '',
    's'    => $s,
    'src'  => ($e['src'] ?? '') === 'new' ? 'new' : 'cash',
    'inv'  => (float)($e['inv'] ?? 0),
    'dt'   => (float)($e['dt'] ?? 0),
    't'    => (float)($e['t'] ?? 0),
  ];
}

function save_all($fp, $days) {
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode(array_values($days), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  fflush($fp);
}

$action = $_GET['action'] ?? 'list';

if ($action === 'list') {
  flock($fp, LOCK_UN); fclose($fp);
  out(200, ['role' => $role, 'days' => visible($days, $role)]);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) out(400, ['error' => 'du_lieu_khong_hop_le']);

if ($action === 'save') {
  $list = isset($body['entries']) ? $body['entries'] : [$body['entry'] ?? null];
  foreach ($list as $e) {
    if (!is_array($e)) continue;
    $c = clean($e, $role);
    if ($c['id'] === '' || $c['who'] === '') continue;
    $found = false;
    foreach ($days as $i => $d) {
      if (($d['id'] ?? '') === $c['id']) {
        if (mine($d, $role)) $days[$i] = $c; // không cho sửa dòng của người khác
        $found = true; break;
      }
    }
    if (!$found) $days[] = $c;
  }
  save_all($fp, $days);
} elseif ($action === 'delete') {
  $id = (string)($body['id'] ?? '');
  $days = array_values(array_filter($days, fn($d) => ($d['id'] ?? '') !== $id || !mine($d, $role)));
  save_all($fp, $days);
} else {
  out(400, ['error' => 'action_khong_hop_le']);
}

flock($fp, LOCK_UN); fclose($fp);
out(200, ['ok' => true, 'role' => $role, 'days' => visible($days, $role)]);

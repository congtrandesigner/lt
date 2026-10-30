<?php
// ====== CÀI ĐẶT MẬT KHẨU ======
// Mỗi người MỘT mật khẩu KHÁC NHAU. Nếu 2 dòng trùng mật khẩu, dòng dưới sẽ đè dòng trên.
// Chỉ sửa chữ bên trái, giữ nguyên 'all', 'p1', 'p2', 'p3'.
// 'all' = quản lý: thêm, sửa, xóa được cho cả 3 người.
// 'p1' = Sơ Kê, 'p2' = Đư Cờ, 'p3' = Cô Cô: xem được cả bảng, chỉ thêm, sửa, xóa phần của mình.
$USERS = array(
  'muadong' => 'all',
  'soke'   => 'p1',
  'duco'   => 'p2',
  'muadong2'   => 'p3',
);
// ==============================

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($code, $data) {
  if (function_exists('http_response_code')) http_response_code($code); else header('X-Status: ' . $code, true, $code);
  echo json_encode($data);
  exit;
}
function g($a, $k, $def) { return (is_array($a) && isset($a[$k])) ? $a[$k] : $def; }

// Mật khẩu gửi trong thân yêu cầu (không dùng header, vì vài host chặn header lạ)
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) $body = array();
$pass = trim((string)g($body, 'pass', ''));

$role = null;
foreach ($USERS as $p => $r) {
  if ($pass !== '' && (string)$p === $pass) { $role = $r; break; }
}
if ($role === null) out(401, array('error' => 'sai_mat_khau'));

$dir  = dirname(__FILE__) . '/data';
$file = $dir . '/entries.json';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) out(500, array('error' => 'khong_tao_duoc_thu_muc_data'));
if (!is_writable($dir)) out(500, array('error' => 'thu_muc_data_khong_ghi_duoc'));

$fp = @fopen($file, 'c+');
if (!$fp) out(500, array('error' => 'khong_mo_duoc_file_du_lieu'));
flock($fp, LOCK_EX);
$raw  = stream_get_contents($fp);
$days = json_decode($raw ? $raw : '[]', true);
if (!is_array($days)) $days = array();

function mine($d, $role) { return $role === 'all' || g($d, 'who', '') === $role; }
function cut($t, $n) { return function_exists('mb_substr') ? mb_substr($t, 0, $n, 'UTF-8') : substr($t, 0, $n * 3); }

function clean($e, $role) {
  $s = g($e, 's', '');
  if (!in_array($s, array('win', 'lost', 'idle'), true)) $s = 'win';
  $who = preg_replace('/[^A-Za-z0-9_-]/', '', (string)g($e, 'who', ''));
  if ($role !== 'all') $who = $role; // người thường chỉ ghi được cho chính mình
  $date = (string)g($e, 'date', '');
  return array(
    'id'   => preg_replace('/[^A-Za-z0-9_-]/', '', (string)g($e, 'id', '')),
    'who'  => $who,
    'num'  => cut((string)g($e, 'num', ''), 60),
    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
    's'    => $s,
    'src'  => g($e, 'src', '') === 'new' ? 'new' : 'cash',
    'inv'  => (float)g($e, 'inv', 0),
    'dt'   => (float)g($e, 'dt', 0),
    't'    => (float)g($e, 't', 0),
  );
}

function save_all($fp, $days) {
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode(array_values($days)));
  fflush($fp);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'list';

if ($action === 'list') {
  flock($fp, LOCK_UN); fclose($fp);
  out(200, array('role' => $role, 'days' => array_values($days)));
}

if ($action === 'save') {
  $list = isset($body['entries']) ? $body['entries'] : array(g($body, 'entry', null));
  foreach ($list as $e) {
    if (!is_array($e)) continue;
    $c = clean($e, $role);
    if ($c['id'] === '' || $c['who'] === '') continue;
    $found = false;
    foreach ($days as $i => $d) {
      if (g($d, 'id', '') === $c['id']) {
        if (mine($d, $role)) $days[$i] = $c; // không cho sửa dòng của người khác
        $found = true; break;
      }
    }
    if (!$found) $days[] = $c;
  }
  save_all($fp, $days);
} elseif ($action === 'delete') {
  $id = (string)g($body, 'id', '');
  $keep = array();
  foreach ($days as $d) {
    if (g($d, 'id', '') === $id && mine($d, $role)) continue; // chỉ xóa được dòng của mình
    $keep[] = $d;
  }
  $days = $keep;
  save_all($fp, $days);
} else {
  flock($fp, LOCK_UN); fclose($fp);
  out(400, array('error' => 'action_khong_hop_le'));
}

flock($fp, LOCK_UN); fclose($fp);
out(200, array('ok' => true, 'role' => $role, 'days' => array_values($days)));

<?php
session_start();
require_once '../db.php'; 

// Thiết lập múi giờ Việt Nam để khớp với thời gian thực tế tại VN
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// --- XỬ LÝ BỘ LỌC ---
$search_user = isset($_GET['search_user']) ? $conn->real_escape_string($_GET['search_user']) : '';
$action_filter = isset($_GET['action_filter']) ? $conn->real_escape_string($_GET['action_filter']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$sql = "SELECT * FROM system_logs WHERE 1=1";
if ($search_user) $sql .= " AND user_name LIKE '%$search_user%'";
if ($action_filter) $sql .= " AND action_type = '$action_filter'";
if ($date_from) $sql .= " AND DATE(created_at) >= '$date_from'";
if ($date_to) $sql .= " AND DATE(created_at) <= '$date_to'";
$sql .= " ORDER BY created_at DESC";

$result = $conn->query($sql);

// --- XỬ LÝ XUẤT CSV (BÁO CÁO) ---
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Bao_cao_nhat_ky_'.date('d-m-Y').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, array('ID', 'Nhân viên', 'Hành động', 'Đối tượng', 'Chi tiết', 'Thời gian'));
    // Lưu ý: Xuất CSV dựa trên kết quả đã lọc
    while ($row_exp = $result->fetch_assoc()) {
        fputcsv($output, array($row_exp['id'], $row_exp['user_name'], $row_exp['action_type'], $row_exp['target_object'], $row_exp['description'], $row_exp['created_at']));
    }
    fclose($output);
    exit();
}

// --- THỐNG KÊ DASHBOARD MINI (Sửa lỗi không cập nhật) ---
$today = date('Y-m-d');
// Query đếm trực tiếp từng loại hành động trong ngày hôm nay
$stats_query = $conn->query("SELECT action_type, COUNT(*) as count FROM system_logs WHERE DATE(created_at) = '$today' GROUP BY action_type");

$counts = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0, 'LOGIN' => 0];

if ($stats_query) {
    while($s = $stats_query->fetch_assoc()) { 
        $counts[$s['action_type']] = $s['count']; 
    }
}
?>

<?php
// Collect logs into array for JS + pagination
$all_logs = [];
if ($result) { while ($r = $result->fetch_assoc()) $all_logs[] = $r; }

// Stats tổng (toàn bộ, không chỉ hôm nay)
$total_all = count($all_logs);

// Tính hourly từ all_logs
$hourly = array_fill(0, 24, 0);
foreach ($all_logs as $r) { $hourly[(int)date('H', strtotime($r['created_at']))]++; }

// Top users
$user_counts = [];
foreach ($all_logs as $r) { $user_counts[$r['user_name']] = ($user_counts[$r['user_name']] ?? 0) + 1; }
arsort($user_counts);
$top_users = array_slice($user_counts, 0, 5, true);

// Stats by action (toàn bộ result đã filter)
$action_counts_all = ['INSERT'=>0,'UPDATE'=>0,'DELETE'=>0,'LOGIN'=>0];
foreach ($all_logs as $r) { if (isset($action_counts_all[$r['action_type']])) $action_counts_all[$r['action_type']]++; }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nhật Ký Hệ Thống — QA Tech</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── RESET & TOKENS ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --g9:#052e16;--g8:#14532d;--g7:#15803d;--g6:#16a34a;--g5:#22c55e;
  --g4:#4ade80;--g3:#86efac;--g1:#dcfce7;--g0:#f0fdf4;
  --sb:#07120a;--sb2:#0e1f12;
  --bg:#eef5ef;--card:#fff;
  --txt:#0e1f12;--muted:#607a66;--border:#cde3d3;
  --ins:#16a34a;--upd:#d97706;--del:#dc2626;--log:#2563eb;
  --ins0:#f0fdf4;--upd0:#fffbeb;--del0:#fef2f2;--log0:#eff6ff;
}
html{scroll-behavior:smooth}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--txt);display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sb{width:240px;min-height:100vh;background:var(--sb);position:fixed;top:0;left:0;display:flex;flex-direction:column;border-right:1px solid rgba(34,197,94,.1);z-index:100}
.sb-logo{padding:24px 20px 18px;border-bottom:1px solid rgba(34,197,94,.1)}
.sb-logo .brand{font-size:18px;font-weight:800;color:#fff;display:flex;align-items:center;gap:9px;letter-spacing:-.4px}
.pulse{width:8px;height:8px;border-radius:50%;background:var(--g5);flex-shrink:0;box-shadow:0 0 0 3px rgba(34,197,94,.2);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 3px rgba(34,197,94,.2)}50%{box-shadow:0 0 0 7px rgba(34,197,94,.04)}}
.sb-logo .sub{font-size:9.5px;color:var(--g4);font-weight:700;letter-spacing:2.5px;text-transform:uppercase;margin-top:4px}
.sb-sec{padding:16px 16px 4px;font-size:9px;font-weight:800;color:rgba(255,255,255,.2);letter-spacing:2.5px;text-transform:uppercase}
.sb-nav{padding:0 8px;flex:1}
.slink{display:flex;align-items:center;gap:9px;padding:10px 12px;border-radius:8px;color:rgba(255,255,255,.4);text-decoration:none;font-size:13px;font-weight:500;transition:.15s;margin-bottom:2px}
.slink i{width:15px;text-align:center;font-size:12.5px}
.slink:hover{background:rgba(34,197,94,.07);color:var(--g4)}
.slink.active{background:rgba(34,197,94,.14);color:var(--g4);border:1px solid rgba(34,197,94,.18)}
.slink.active i{color:var(--g5)}
.slink.out{color:rgba(239,68,68,.55)}
.slink.out:hover{background:rgba(239,68,68,.07);color:#ef4444}
.sb-foot{padding:12px 8px;border-top:1px solid rgba(34,197,94,.1)}

/* ── LAYOUT ── */
.main{margin-left:240px;flex:1;padding:30px 34px;min-height:100vh}

/* ── TOP BAR ── */
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:26px;padding-bottom:20px;border-bottom:1px solid var(--border)}
.topbar-left h1{font-size:21px;font-weight:800;letter-spacing:-.5px}
.topbar-left h1 em{font-style:normal;color:var(--g6)}
.topbar-left p{color:var(--muted);font-size:12.5px;margin-top:4px}
.topbar-right{display:flex;align-items:center;gap:10px}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:8px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;border:none;text-decoration:none;transition:.15s;white-space:nowrap}
.btn-green{background:linear-gradient(135deg,var(--g6),var(--g7));color:#fff;box-shadow:0 2px 8px rgba(22,163,74,.28)}
.btn-green:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(22,163,74,.38);color:#fff}
.btn-outline{background:#fff;border:1.5px solid var(--border);color:var(--muted)}
.btn-outline:hover{border-color:var(--g4);color:var(--g6);background:var(--g0)}
.btn-export{background:var(--g0);border:1.5px solid var(--g3);color:var(--g7)}
.btn-export:hover{background:var(--g6);color:#fff;border-color:var(--g6)}
.btn-red{background:#fef2f2;border:1.5px solid #fecaca;color:var(--del)}
.btn-red:hover{background:var(--del);color:#fff;border-color:var(--del)}
.btn-sm{padding:6px 12px;font-size:12px}
.btn-xs{padding:5px 9px;font-size:11px;border-radius:6px}

/* ── TOP ROW: stats + donut ── */
.top-row{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 280px;gap:14px;margin-bottom:18px}

.stat-card{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:18px;cursor:pointer;transition:.18s;position:relative;overflow:hidden}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px}
.sc-ins::after{background:var(--ins)} .sc-upd::after{background:var(--upd)}
.sc-del::after{background:var(--del)} .sc-log::after{background:var(--log)}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.07)}
.stat-card.active-filter{box-shadow:0 0 0 2.5px var(--g5),0 4px 14px rgba(34,197,94,.15)}
.si{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;margin-bottom:12px}
.si-ins{background:linear-gradient(135deg,#15803d,#22c55e)} .si-upd{background:linear-gradient(135deg,#b45309,#f59e0b)}
.si-del{background:linear-gradient(135deg,#b91c1c,#ef4444)} .si-log{background:linear-gradient(135deg,#1d4ed8,#3b82f6)}
.sv{font-size:28px;font-weight:800;font-family:'DM Mono',monospace;line-height:1;display:block}
.sl{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-top:5px;display:block}
.sc-today{font-size:10px;color:var(--g6);font-weight:700;margin-top:6px}

/* donut panel */
.donut-panel{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:18px;display:flex;flex-direction:column}
.donut-panel h4{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px}
.donut-wrap{display:flex;gap:14px;align-items:center;flex:1}
.donut-wrap canvas{width:110px!important;height:110px!important;flex-shrink:0}
.donut-legend{flex:1;display:flex;flex-direction:column;gap:8px}
.dl-row{display:flex;align-items:center;gap:7px;font-size:12px;font-weight:600;color:var(--muted)}
.dl-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}
.dl-num{margin-left:auto;font-family:'DM Mono',monospace;font-weight:700;font-size:13px;color:var(--txt)}

/* ── TOP USERS STRIP ── */
.users-strip{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;gap:20px}
.us-label{font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;flex-shrink:0}
.us-list{display:flex;gap:10px;flex:1;overflow-x:auto}
.us-item{display:flex;align-items:center;gap:8px;background:var(--g0);border:1px solid var(--g3);border-radius:24px;padding:6px 12px 6px 6px;white-space:nowrap;transition:.15s;cursor:pointer}
.us-item:hover{background:var(--g1);border-color:var(--g4)}
.us-avatar{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,var(--g6),var(--g4));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0}
.us-name{font-size:12px;font-weight:700;color:var(--g7)}
.us-cnt{font-size:11px;font-family:'DM Mono',monospace;color:var(--muted)}

/* ── FILTER BAR ── */
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:13px;padding:16px 18px;margin-bottom:16px}
.filter-bar form{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:10px;font-weight:800;color:var(--muted);letter-spacing:1px;text-transform:uppercase}
.finp{padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13px;color:var(--txt);background:#fff;transition:.15s}
.finp:focus{outline:none;border-color:var(--g5);box-shadow:0 0 0 3px rgba(34,197,94,.1)}
.search-wrap{position:relative;flex:1;min-width:200px}
.search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:13px;pointer-events:none}
.search-wrap .finp{padding-left:34px;width:100%}
.filter-chips{display:flex;gap:6px;margin-top:10px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:1.5px solid transparent;transition:.15s;user-select:none}
.chip-all{background:#f1f5f1;border-color:#cde3d3;color:var(--muted)}
.chip-ins{background:var(--ins0);border-color:#bbf7d0;color:var(--ins)}
.chip-upd{background:var(--upd0);border-color:#fde68a;color:var(--upd)}
.chip-del{background:var(--del0);border-color:#fecaca;color:var(--del)}
.chip-log{background:var(--log0);border-color:#bfdbfe;color:var(--log)}
.chip.sel,.chip:hover{opacity:.75}

/* ── TABLE CARD ── */
.tcard{background:var(--card);border:1px solid var(--border);border-radius:13px;overflow:hidden}
.tcard-head{display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--border);background:linear-gradient(to right,var(--g0),#f7fbf8)}
.tcard-head h3{font-size:12px;font-weight:800;color:var(--g8);text-transform:uppercase;letter-spacing:.5px}
.tcard-head h3 i{color:var(--g6);margin-right:6px}
.tcard-actions{display:flex;align-items:center;gap:8px}
.rec-badge{font-size:11px;font-weight:700;color:var(--muted);background:var(--g1);padding:4px 10px;border-radius:20px}

/* bulk bar */
.bulk-bar{display:none;align-items:center;gap:10px;padding:9px 18px;background:#fef3c7;border-bottom:1px solid #fde68a;font-size:13px;font-weight:700;color:#92400e}
.bulk-bar.show{display:flex}

/* table */
.ltable{width:100%;border-collapse:collapse}
.ltable th{padding:11px 14px;text-align:left;font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;border-bottom:1.5px solid var(--border);background:#fafdf9;white-space:nowrap}
.ltable td{padding:12px 14px;border-bottom:1px solid #edf5ee;font-size:13px;vertical-align:middle}
.ltable tbody tr{transition:.12s;cursor:pointer}
.ltable tbody tr:hover{background:var(--g0)}
.ltable tbody tr:last-child td{border-bottom:none}
.ltable tbody tr.sel-row{background:#f0fdf4}
.cb{accent-color:var(--g6);width:14px;height:14px;cursor:pointer}

/* cell styles */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.b-insert{background:var(--ins0);color:var(--ins);border:1px solid #bbf7d0}
.b-update{background:var(--upd0);color:var(--upd);border:1px solid #fde68a}
.b-delete{background:var(--del0);color:var(--del);border:1px solid #fecaca}
.b-login{background:var(--log0);color:var(--log);border:1px solid #bfdbfe}
.t-date{font-size:12px;font-weight:700;color:var(--txt)}
.t-time{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;margin-top:2px}
.uchip{display:inline-flex;align-items:center;gap:6px}
.av{width:27px;height:27px;border-radius:50%;background:linear-gradient(135deg,var(--g6),var(--g4));display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;flex-shrink:0}
.tgt{font-family:'DM Mono',monospace;font-size:11.5px;color:var(--g7);background:var(--g0);padding:2px 7px;border-radius:5px;border:1px solid var(--g3)}
.desc{color:var(--muted);max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:12.5px}
.ib{display:inline-flex;align-items:center;justify-content:center;width:29px;height:29px;border-radius:7px;border:none;cursor:pointer;font-size:12px;transition:.13s}
.ib-view{background:var(--g0);color:var(--g7)} .ib-view:hover{background:var(--g6);color:#fff}
.ib-del{background:#fef2f2;color:var(--del)} .ib-del:hover{background:var(--del);color:#fff}

/* ── PAGINATION ── */
.pag{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-top:1px solid var(--border);background:linear-gradient(to right,var(--g0),#f7fbf8)}
.pag-info{font-size:12px;color:var(--muted);font-weight:600}
.pag-btns{display:flex;gap:4px}
.pb{min-width:32px;height:32px;padding:0 8px;border-radius:7px;border:1.5px solid var(--border);background:#fff;font-family:inherit;font-size:12.5px;font-weight:700;color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.13s}
.pb:hover:not(:disabled){border-color:var(--g5);color:var(--g6);background:var(--g0)}
.pb.on{background:var(--g6);border-color:var(--g6);color:#fff}
.pb:disabled{opacity:.35;cursor:not-allowed}

/* ── MODAL ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(5,20,8,.55);backdrop-filter:blur(5px);z-index:300;align-items:center;justify-content:center}
.overlay.open{display:flex}
.modal{background:#fff;border-radius:18px;width:540px;max-width:95vw;box-shadow:0 30px 70px rgba(0,0,0,.22);overflow:hidden;animation:mIn .22s ease}
@keyframes mIn{from{opacity:0;transform:scale(.94) translateY(12px)}to{opacity:1;transform:none}}
.mhead{padding:18px 22px;border-bottom:1px solid var(--border);background:linear-gradient(to right,var(--g0),#f7fbf8);display:flex;justify-content:space-between;align-items:center}
.mhead h3{font-size:14px;font-weight:800;color:var(--g8)} .mhead h3 i{color:var(--g6);margin-right:7px}
.mclose{background:none;border:none;font-size:18px;color:var(--muted);cursor:pointer;transition:.13s} .mclose:hover{color:var(--del)}
.mbody{padding:22px}
.mrow{display:grid;grid-template-columns:110px 1fr;gap:8px;margin-bottom:13px;align-items:start}
.mkey{font-size:10px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;padding-top:3px}
.mval{font-size:13.5px;font-weight:600;color:var(--txt);word-break:break-word}
.mdesc{background:var(--g0);border:1px solid var(--g3);border-radius:8px;padding:11px 14px;font-size:12.5px;color:var(--muted);line-height:1.65;font-family:'DM Mono',monospace}
.mfoot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;background:#fafdf9}

/* ── TOAST ── */
.tstack{position:fixed;bottom:24px;right:24px;z-index:999;display:flex;flex-direction:column;gap:8px}
.toast{display:flex;align-items:center;gap:10px;background:#062c12;color:#fff;padding:13px 16px;border-radius:11px;font-size:13px;font-weight:600;box-shadow:0 8px 28px rgba(0,0,0,.28);min-width:240px;animation:tIn .28s ease;border-left:4px solid var(--g5)}
.toast.terr{border-left-color:var(--del);background:#1a0404}
@keyframes tIn{from{opacity:0;transform:translateX(28px)}to{opacity:1;transform:none}}

/* ── EMPTY ── */
.empty{text-align:center;padding:56px 20px}
.empty i{font-size:36px;color:var(--g3);display:block;margin-bottom:12px}
.empty p{color:var(--muted);font-size:14px}

/* ── MISC ── */
.sep{height:1px;background:var(--border);margin:4px 0}
.auto-label{font-size:11px;font-weight:700;color:var(--g6);background:var(--g0);border:1px solid var(--g3);padding:5px 11px;border-radius:7px;display:flex;align-items:center;gap:6px}
.auto-label .blink{animation:blink .9s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
</style>
</head>
<body>

<?php
$all_logs_json = json_encode($all_logs, JSON_UNESCAPED_UNICODE);
$action_counts_json = json_encode($action_counts_all);
?>

<!-- SIDEBAR -->
<nav class="sb">
  <div class="sb-logo">
    <div class="brand"><span class="pulse"></span>QA Tech</div>
    <div class="sub">Admin Panel</div>
  </div>
  <div class="sb-nav">
    <div class="sb-sec">Menu</div>
    <a href="index.php" class="slink"><i class="fas fa-house-chimney"></i>Tổng quan</a>
    <a href="advertising.php" class="slink"><i class="fas fa-rectangle-ad"></i>Bài viết quảng bá</a>
    <a href="system_logs.php" class="slink active"><i class="fas fa-shield-halved"></i>Nhật ký hệ thống</a>
    <a href="../index.php" target="_blank" class="slink"><i class="fas fa-arrow-up-right-from-square"></i>Xem Website</a>
  </div>
  <div class="sb-foot">
    <a href="../logout.php" class="slink out"><i class="fas fa-right-from-bracket"></i>Đăng xuất</a>
  </div>
</nav>

<!-- MAIN -->
<div class="main">

  <!-- TOP BAR -->
  <div class="topbar">
    <div class="topbar-left">
      <h1>Nhật Ký <em>Hệ Thống</em></h1>
      <p>Theo dõi toàn bộ hoạt động, thay đổi dữ liệu và truy cập hệ thống</p>
    </div>
    <div class="topbar-right">
      <div class="auto-label"><span class="blink">●</span> Tự làm mới: <strong id="cd">30</strong>s</div>
      <a href="?export=true&search_user=<?=urlencode($search_user)?>&action_filter=<?=urlencode($action_filter)?>&date_from=<?=urlencode($date_from)?>&date_to=<?=urlencode($date_to)?>" class="btn btn-export">
        <i class="fas fa-file-arrow-down"></i> Xuất CSV
      </a>
    </div>
  </div>

  <!-- STAT CARDS + DONUT -->
  <div class="top-row">
    <div class="stat-card sc-ins" onclick="filterByType('INSERT')" id="sc-INSERT">
      <div class="si si-ins"><i class="fas fa-plus"></i></div>
      <span class="sv"><?=$action_counts_all['INSERT']?></span>
      <span class="sl">Thêm mới</span>
      <div class="sc-today">Hôm nay: <?=$counts['INSERT']?></div>
    </div>
    <div class="stat-card sc-upd" onclick="filterByType('UPDATE')" id="sc-UPDATE">
      <div class="si si-upd"><i class="fas fa-pen"></i></div>
      <span class="sv"><?=$action_counts_all['UPDATE']?></span>
      <span class="sl">Cập nhật</span>
      <div class="sc-today">Hôm nay: <?=$counts['UPDATE']?></div>
    </div>
    <div class="stat-card sc-del" onclick="filterByType('DELETE')" id="sc-DELETE">
      <div class="si si-del"><i class="fas fa-trash"></i></div>
      <span class="sv"><?=$action_counts_all['DELETE']?></span>
      <span class="sl">Xóa dữ liệu</span>
      <div class="sc-today">Hôm nay: <?=$counts['DELETE']?></div>
    </div>
    <div class="stat-card sc-log" onclick="filterByType('LOGIN')" id="sc-LOGIN">
      <div class="si si-log"><i class="fas fa-user-shield"></i></div>
      <span class="sv"><?=$action_counts_all['LOGIN']?></span>
      <span class="sl">Đăng nhập</span>
      <div class="sc-today">Hôm nay: <?=$counts['LOGIN']?></div>
    </div>

    <!-- DONUT -->
    <div class="donut-panel">
      <h4><i class="fas fa-chart-pie" style="color:var(--g6);margin-right:5px"></i>Phân bổ hành động</h4>
      <div class="donut-wrap">
        <canvas id="donut"></canvas>
        <div class="donut-legend">
          <div class="dl-row"><span class="dl-dot" style="background:var(--ins)"></span>Thêm<span class="dl-num"><?=$action_counts_all['INSERT']?></span></div>
          <div class="dl-row"><span class="dl-dot" style="background:var(--upd)"></span>Sửa<span class="dl-num"><?=$action_counts_all['UPDATE']?></span></div>
          <div class="dl-row"><span class="dl-dot" style="background:var(--del)"></span>Xóa<span class="dl-num"><?=$action_counts_all['DELETE']?></span></div>
          <div class="dl-row"><span class="dl-dot" style="background:var(--log)"></span>Login<span class="dl-num"><?=$action_counts_all['LOGIN']?></span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- TOP USERS -->
  <?php if(!empty($top_users)): ?>
  <div class="users-strip">
    <div class="us-label"><i class="fas fa-crown" style="color:var(--g5);margin-right:5px"></i>Hoạt động nhiều nhất</div>
    <div class="us-list">
      <?php foreach($top_users as $uname => $ucnt): 
        $ini = mb_strtoupper(mb_substr($uname,0,1));
      ?>
      <div class="us-item" onclick="searchUser('<?=htmlspecialchars($uname,ENT_QUOTES)?>')">
        <div class="us-avatar"><?=$ini?></div>
        <span class="us-name"><?=htmlspecialchars($uname)?></span>
        <span class="us-cnt"><?=$ucnt?> lần</span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- FILTER BAR -->
  <div class="filter-bar">
    <form method="GET" id="fform" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <div class="fg" style="flex:1;min-width:180px">
        <label>Tìm kiếm</label>
        <div class="search-wrap">
          <i class="fas fa-magnifying-glass"></i>
          <input id="liveQ" class="finp" type="text" name="search_user" placeholder="Nhân viên, nội dung..." value="<?=htmlspecialchars($search_user)?>">
        </div>
      </div>
      <div class="fg">
        <label>Loại hành động</label>
        <select name="action_filter" id="actSel" class="finp" style="min-width:140px">
          <option value="">Tất cả</option>
          <option value="INSERT" <?=$action_filter=='INSERT'?'selected':''?>>Thêm mới</option>
          <option value="UPDATE" <?=$action_filter=='UPDATE'?'selected':''?>>Cập nhật</option>
          <option value="DELETE" <?=$action_filter=='DELETE'?'selected':''?>>Xóa dữ liệu</option>
          <option value="LOGIN"  <?=$action_filter=='LOGIN' ?'selected':''?>>Đăng nhập</option>
        </select>
      </div>
      <div class="fg">
        <label>Từ ngày</label>
        <input type="date" name="date_from" class="finp" value="<?=htmlspecialchars($date_from)?>">
      </div>
      <div class="fg">
        <label>Đến ngày</label>
        <input type="date" name="date_to" class="finp" value="<?=htmlspecialchars($date_to)?>">
      </div>
      <button type="submit" class="btn btn-green"><i class="fas fa-filter"></i>Lọc</button>
      <a href="system_logs.php" class="btn btn-outline"><i class="fas fa-rotate-left"></i>Đặt lại</a>
    </form>
    <!-- Quick chips -->
    <div class="filter-chips">
      <span class="chip chip-all <?=!$action_filter?'sel':''?>" onclick="setChip('')">Tất cả</span>
      <span class="chip chip-ins <?=$action_filter=='INSERT'?'sel':''?>" onclick="setChip('INSERT')"><i class="fas fa-plus"></i>Thêm mới</span>
      <span class="chip chip-upd <?=$action_filter=='UPDATE'?'sel':''?>" onclick="setChip('UPDATE')"><i class="fas fa-pen"></i>Cập nhật</span>
      <span class="chip chip-del <?=$action_filter=='DELETE'?'sel':''?>" onclick="setChip('DELETE')"><i class="fas fa-trash"></i>Xóa</span>
      <span class="chip chip-log <?=$action_filter=='LOGIN'?'sel':''?>"  onclick="setChip('LOGIN')"><i class="fas fa-user"></i>Đăng nhập</span>
    </div>
  </div>

  <!-- TABLE CARD -->
  <div class="tcard">
    <div class="tcard-head">
      <h3><i class="fas fa-list-ul"></i>Danh sách nhật ký</h3>
      <div class="tcard-actions">
        <span class="rec-badge" id="recBadge"><?=count($all_logs)?> bản ghi</span>
        <button class="btn btn-red btn-sm" id="bulkDelBtn" style="display:none" onclick="bulkDelete()"><i class="fas fa-trash"></i>Xóa đã chọn (<span id="bulkN">0</span>)</button>
      </div>
    </div>

    <table class="ltable" id="ltbl">
      <thead>
        <tr>
          <th style="width:36px"><input type="checkbox" class="cb" id="chkAll" onchange="toggleAll(this)"></th>
          <th style="width:120px;cursor:pointer" onclick="sortBy('created_at')">Thời gian <i class="fas fa-sort" style="opacity:.4"></i></th>
          <th>Nhân viên</th>
          <th>Hành động</th>
          <th>Đối tượng</th>
          <th>Nội dung</th>
          <th style="width:72px"></th>
        </tr>
      </thead>
      <tbody id="tbody">
      <?php if(empty($all_logs)): ?>
        <tr><td colspan="7"><div class="empty"><i class="fas fa-inbox"></i><p>Chưa có dữ liệu nhật ký nào.</p></div></td></tr>
      <?php else: foreach($all_logs as $i=>$row):
        $bc='b-'.strtolower($row['action_type']);
        $ini=mb_strtoupper(mb_substr($row['user_name'],0,1));
      ?>
        <tr onclick="openModal(<?=$i?>)" data-idx="<?=$i?>">
          <td onclick="event.stopPropagation()"><input type="checkbox" class="cb rcb" value="<?=$row['id']?>" onchange="updBulk()"></td>
          <td>
            <div class="t-date"><?=date('d/m/Y',strtotime($row['created_at']))?></div>
            <div class="t-time"><?=date('H:i:s',strtotime($row['created_at']))?></div>
          </td>
          <td>
            <div class="uchip">
              <div class="av"><?=$ini?></div>
              <strong><?=htmlspecialchars($row['user_name'])?></strong>
            </div>
          </td>
          <td><span class="badge <?=$bc?>"><?=$row['action_type']?></span></td>
          <td><span class="tgt"><?=htmlspecialchars($row['target_object'])?></span></td>
          <td><div class="desc"><?=htmlspecialchars($row['description'])?></div></td>
          <td onclick="event.stopPropagation()" style="text-align:right;white-space:nowrap">
            <button class="ib ib-view" onclick="openModal(<?=$i?>)" title="Chi tiết"><i class="fas fa-eye"></i></button>
            <button class="ib ib-del"  onclick="delOne(<?=$row['id']?>,<?=$i?>)" title="Xóa"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <!-- PAGINATION -->
    <div class="pag">
      <div class="pag-info" id="pagInfo"></div>
      <div class="pag-btns" id="pagBtns"></div>
    </div>
  </div>

</div><!-- /main -->

<!-- DETAIL MODAL -->
<div class="overlay" id="ov" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="mhead">
      <h3><i class="fas fa-file-lines"></i>Chi tiết bản ghi</h3>
      <button class="mclose" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="mbody" id="mbody"></div>
    <div class="mfoot">
      <button class="btn btn-red btn-sm" id="mDelBtn"><i class="fas fa-trash"></i>Xóa log này</button>
      <button class="btn btn-outline btn-sm" onclick="closeModal()">Đóng</button>
    </div>
  </div>
</div>

<!-- TOAST STACK -->
<div class="tstack" id="tstack"></div>

<script>
/* ── DATA ── */
const LOGS = <?=$all_logs_json?>;
const BC   = {INSERT:'b-insert',UPDATE:'b-update',DELETE:'b-delete',LOGIN:'b-login'};
const IC   = {INSERT:'fas fa-plus',UPDATE:'fas fa-pen',DELETE:'fas fa-trash',LOGIN:'fas fa-user-shield'};

/* ── DONUT CHART ── */
new Chart(document.getElementById('donut'),{
  type:'doughnut',
  data:{
    datasets:[{
      data:[<?=$action_counts_all['INSERT']?>,<?=$action_counts_all['UPDATE']?>,<?=$action_counts_all['DELETE']?>,<?=$action_counts_all['LOGIN']?>],
      backgroundColor:['#16a34a','#d97706','#dc2626','#2563eb'],
      borderWidth:0,hoverOffset:6
    }]
  },
  options:{cutout:'68%',responsive:false,plugins:{legend:{display:false}}}
});

/* ── PAGINATION ── */
const PER=20;let page=1;
const allRows=()=>[...document.querySelectorAll('#tbody tr[data-idx]')];

function paginate(){
  const vis=allRows().filter(r=>r.style.display!=='none');
  const tot=vis.length,pages=Math.max(1,Math.ceil(tot/PER));
  if(page>pages)page=pages;
  vis.forEach((r,i)=>{ r.style.display=(i>=(page-1)*PER&&i<page*PER)?'':'none'; });
  const from=(page-1)*PER+1,to=Math.min(page*PER,tot);
  document.getElementById('pagInfo').textContent=tot?`Hiển thị ${from}–${to} / ${tot} bản ghi`:'Không có kết quả';
  document.getElementById('recBadge').textContent=`${tot} bản ghi`;
  // buttons
  let h=`<button class="pb" onclick="goP(${page-1})" ${page===1?'disabled':''}>‹</button>`;
  for(let p=1;p<=pages;p++){
    if(pages>7&&p>2&&p<pages-1&&Math.abs(p-page)>1){if(p===3||p===pages-2)h+='<span class="pb" style="pointer-events:none">…</span>';continue;}
    h+=`<button class="pb ${p===page?'on':''}" onclick="goP(${p})">${p}</button>`;
  }
  h+=`<button class="pb" onclick="goP(${page+1})" ${page===pages?'disabled':''}>›</button>`;
  document.getElementById('pagBtns').innerHTML=h;
}
function goP(p){page=p;paginate()}
paginate();

/* ── LIVE SEARCH (tìm realtime trong kết quả đang hiển thị) ── */
document.getElementById('liveQ').addEventListener('input',function(){
  const q=this.value.toLowerCase();
  allRows().forEach(r=>{ r.style.display=r.textContent.toLowerCase().includes(q)?'':'none'; });
  page=1;paginate();
});

/* ── QUICK CHIP FILTER ── */
function setChip(val){
  document.getElementById('actSel').value=val;
  document.getElementById('fform').submit();
}
function filterByType(type){setChip(type);}
function searchUser(name){
  document.getElementById('liveQ').value=name;
  document.getElementById('liveQ').dispatchEvent(new Event('input'));
}

/* ── SORT ── */
let sortDir=1;
function sortBy(col){
  sortDir*=-1;
  const tb=document.getElementById('tbody');
  const rows=[...tb.querySelectorAll('tr[data-idx]')];
  rows.sort((a,b)=>{
    const ia=parseInt(a.dataset.idx),ib=parseInt(b.dataset.idx);
    const va=LOGS[ia]?.[col]||'',vb=LOGS[ib]?.[col]||'';
    return va<vb?-sortDir:va>vb?sortDir:0;
  });
  rows.forEach(r=>tb.appendChild(r));
  page=1;paginate();
}

/* ── BULK SELECT ── */
function toggleAll(cb){document.querySelectorAll('.rcb').forEach(c=>c.checked=cb.checked);updBulk();}
function updBulk(){
  const n=document.querySelectorAll('.rcb:checked').length;
  document.getElementById('bulkN').textContent=n;
  document.getElementById('bulkDelBtn').style.display=n?'':'none';
  document.querySelectorAll('#tbody tr[data-idx]').forEach(r=>{
    const c=r.querySelector('.rcb');r.classList.toggle('sel-row',c&&c.checked);
  });
}
function bulkDelete(){
  const ids=[...document.querySelectorAll('.rcb:checked')].map(c=>c.value);
  if(!ids.length)return;
  if(!confirm(`Xóa ${ids.length} bản ghi đã chọn?`))return;
  // TODO: wire → window.location.href=`?bulk_delete=1&ids=${ids.join(',')}`;
  ids.forEach(id=>{document.querySelector(`.rcb[value="${id}"]`)?.closest('tr')?.remove();});
  document.getElementById('chkAll').checked=false;updBulk();paginate();
  toast(`Đã xóa ${ids.length} bản ghi`);
}

/* ── SINGLE DELETE ── */
let curModalIdx=null;
function delOne(id,idx){
  if(!confirm(`Xóa bản ghi #${id}?`))return;
  // TODO: window.location.href=`?delete_log=${id}`;
  document.querySelector(`.rcb[value="${id}"]`)?.closest('tr')?.remove();
  paginate();toast(`Đã xóa log #${id}`);
  if(document.getElementById('ov').classList.contains('open'))closeModal();
}

/* ── MODAL ── */
function openModal(idx){
  const r=LOGS[idx];if(!r)return;
  curModalIdx=idx;
  const bc=BC[r.action_type]||'b-login',ic=IC[r.action_type]||'fas fa-info';
  const dt=new Date(r.created_at);
  document.getElementById('mbody').innerHTML=`
    <div class="mrow"><div class="mkey">ID</div><div class="mval" style="font-family:'DM Mono',monospace">#${r.id}</div></div>
    <div class="mrow"><div class="mkey">Thời gian</div><div class="mval">${dt.toLocaleString('vi-VN')}</div></div>
    <div class="mrow"><div class="mkey">Nhân viên</div><div class="mval"><div class="uchip"><div class="av">${esc(r.user_name)[0].toUpperCase()}</div><strong>${esc(r.user_name)}</strong></div></div></div>
    <div class="mrow"><div class="mkey">Hành động</div><div class="mval"><span class="badge ${bc}"><i class="${ic}"></i> ${r.action_type}</span></div></div>
    <div class="mrow"><div class="mkey">Đối tượng</div><div class="mval"><span class="tgt">${esc(r.target_object)}</span></div></div>
    <div class="mrow"><div class="mkey">Chi tiết</div><div class="mval"><div class="mdesc">${esc(r.description||'—')}</div></div></div>
  `;
  document.getElementById('mDelBtn').onclick=()=>delOne(r.id,idx);
  document.getElementById('ov').classList.add('open');
}
function closeModal(){document.getElementById('ov').classList.remove('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal();});
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

/* ── TOAST ── */
function toast(msg,type='ok'){
  const w=document.getElementById('tstack');
  const t=document.createElement('div');
  t.className='toast'+(type==='err'?' terr':'');
  t.innerHTML=`<i class="fas fa-${type==='ok'?'circle-check':'circle-exclamation'}"></i>${msg}`;
  w.appendChild(t);
  setTimeout(()=>{t.style.animation='tIn .28s ease reverse';setTimeout(()=>t.remove(),280);},3000);
}

/* ── AUTO REFRESH ── */
let s=30;const el=document.getElementById('cd');
setInterval(()=>{s--;if(el)el.textContent=s;if(s<=0)location.reload();},1000);
</script>
</body>
</html>
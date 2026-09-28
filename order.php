<?php
// =====================================================================
// order.php — หน้าสั่งอาหารสำหรับลูกค้า (สแกน QR บนโต๊ะ)
// ไม่ต้อง Login ลูกค้าสแกน QR -> เห็นเมนู -> สั่งอาหาร -> พนักงานเห็นออเดอร์ที่หน้า "ออเดอร์จากลูกค้า" ใน index.php
// วางไฟล์นี้ไว้ในโฟลเดอร์เดียวกับ index.php ของระบบ POS
// =====================================================================
session_start();

$host = 'localhost';
$username = 'mysql';
$password = 'password';
$dbname = 'pos_system';

$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลไม่ได้: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// รองรับสถานะรอชำระสำหรับฐานข้อมูลเดิม
$coStatusRes = $conn->query("SHOW COLUMNS FROM customer_orders LIKE 'status'");
$coStatusRow = $coStatusRes ? $coStatusRes->fetch_assoc() : null;
if ($coStatusRow && strpos((string)$coStatusRow['Type'], "'payment_pending'") === false) {
    $conn->query("ALTER TABLE customer_orders MODIFY status ENUM('pending','accepted','served','payment_pending','cancelled','loaded') NOT NULL DEFAULT 'pending'");
}

function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function money($v) { return number_format((float)$v, 2); }

// ===================== AJAX ACTIONS (ไม่ต้อง Login) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'submit_order') {
        $token = trim($_POST['token'] ?? '');
        $cart = json_decode($_POST['cart'] ?? '[]', true);
        $note = trim($_POST['note'] ?? '');

        if ($token === '') jsonResponse(['success'=>false,'message'=>'ไม่พบโต๊ะ กรุณาสแกน QR อีกครั้ง']);
        if (!is_array($cart) || count($cart) === 0) jsonResponse(['success'=>false,'message'=>'กรุณาเลือกเมนูอย่างน้อย 1 รายการ']);

        $stmt = $conn->prepare("SELECT id, table_no FROM restaurant_tables WHERE qr_token=? AND is_active=1 LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $table = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$table) jsonResponse(['success'=>false,'message'=>'ไม่พบโต๊ะนี้ หรือโต๊ะถูกปิดใช้งาน กรุณาเรียกพนักงาน']);

        $validated = [];
        $qtyByProduct = []; // สะสมจำนวนต่อสินค้า 1 ชนิด เผื่อลูกค้าสั่งสินค้าเดียวกันหลายรายการในตะกร้า
        $outOfStockNames = [];
        foreach ($cart as $item) {
            $pid = (int)($item['id'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            $itemNote = trim((string)($item['note'] ?? ''));
            if ($pid <= 0 || $qty <= 0) continue;
            $stmt = $conn->prepare("SELECT id, name, price, stock FROM products WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $pid);
            $stmt->execute();
            $p = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$p) continue;

            // ตรวจสอบสต็อกฝั่งเซิร์ฟเวอร์เสมอ เพราะข้อมูลสต็อกที่ลูกค้าเห็นบนหน้าจออาจไม่ทันปัจจุบัน (Race Condition)
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + $qty;
            if ($qtyByProduct[$pid] > (int)$p['stock']) {
                $outOfStockNames[] = $p['name'];
                continue;
            }

            $validated[] = ['id'=>$p['id'],'name'=>$p['name'],'price'=>(float)$p['price'],'qty'=>$qty,'note'=>$itemNote];
        }
        if ($outOfStockNames) {
            jsonResponse(['success'=>false,'message'=>'สินค้าต่อไปนี้มีไม่เพียงพอ กรุณาปรับจำนวนแล้วลองใหม่: '.implode(', ', array_unique($outOfStockNames))]);
        }
        if (!count($validated)) jsonResponse(['success'=>false,'message'=>'ไม่พบเมนูที่เลือก กรุณาลองใหม่']);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO customer_orders(table_id, customer_note, status) VALUES(?,?,'pending')");
            $stmt->bind_param('is', $table['id'], $note);
            $stmt->execute();
            $orderId = $stmt->insert_id;
            $stmt->close();

            $itemStmt = $conn->prepare("INSERT INTO customer_order_items(order_id, product_id, product_name, price, quantity, note) VALUES(?,?,?,?,?,?)");
            foreach ($validated as $v) {
                $itemStmt->bind_param('iisdis', $orderId, $v['id'], $v['name'], $v['price'], $v['qty'], $v['note']);
                $itemStmt->execute();
            }
            $itemStmt->close();
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            jsonResponse(['success'=>false,'message'=>'ส่งออเดอร์ไม่สำเร็จ กรุณาลองใหม่']);
        }

        jsonResponse(['success'=>true,'message'=>'ส่งออเดอร์เรียบร้อยแล้ว พนักงานจะมารับออเดอร์เร็วๆ นี้','order_id'=>$orderId]);
    }

    if ($action === 'order_status') {
        $ids = array_filter(array_map('intval', explode(',', $_POST['order_ids'] ?? '')));
        if (!count($ids)) jsonResponse(['success'=>true,'data'=>[]]);
        $in = implode(',', $ids);
        $result = $conn->query("SELECT id, status, created_at FROM customer_orders WHERE id IN ($in) ORDER BY id DESC");
        $rows = []; while ($r = $result->fetch_assoc()) $rows[] = $r;
        jsonResponse(['success'=>true,'data'=>$rows]);
    }

    jsonResponse(['success'=>false,'message'=>'ไม่รู้จักคำสั่งนี้']);
}

// ===================== แสดงหน้าเมนู =====================
$token = trim($_GET['t'] ?? '');
$table = null;
if ($token !== '') {
    $stmt = $conn->prepare("SELECT id, table_no FROM restaurant_tables WHERE qr_token=? AND is_active=1 LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $table = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$shopName = 'ร้านอาหาร';
$settingsRes = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key='shopName'");
if ($settingsRes && ($row = $settingsRes->fetch_assoc()) && !empty($row['setting_value'])) {
    $shopName = $row['setting_value'];
}

$products = [];
if ($table) {
    $result = $conn->query("SELECT id, name, category, price, stock, image FROM products ORDER BY category ASC, name ASC");
    while ($r = $result->fetch_assoc()) $products[] = $r;
}
$categories = array_values(array_unique(array_filter(array_map(fn($p) => $p['category'], $products))));
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>สั่งอาหาร - <?= htmlspecialchars($shopName) ?></title>
<link rel="stylesheet" href="tailwind.css">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
*{font-family:'Sarabun',sans-serif;box-sizing:border-box}
body{margin:0;background:#f8fafc;padding-bottom:90px}
.hdr{background:#4f46e5;color:#fff;padding:16px;position:sticky;top:0;z-index:20;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.chips{display:flex;gap:8px;overflow-x:auto;padding:10px 12px;background:#fff;position:sticky;top:70px;z-index:19;border-bottom:1px solid #eef2f7}
.chip{white-space:nowrap;padding:8px 14px;border-radius:9999px;background:#f1f5f9;color:#334155;font-size:12px;font-weight:700;border:0;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.chip.active{background:#4f46e5;color:#fff}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:12px}
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04);cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent;transition:transform .08s ease,box-shadow .08s ease}.card:not(.out):active{transform:scale(.985);box-shadow:0 2px 8px rgba(79,70,229,.18)}.card.out{cursor:not-allowed}
.card .img{height:100px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:28px}
.card .img img{width:100%;height:100%;object-fit:cover}
.card .info{padding:8px 10px}
.card .name{font-size:12.5px;font-weight:700;color:#1e293b;min-height:32px;line-height:1.3}
.card .bottom{display:flex;justify-content:space-between;align-items:center;margin-top:6px}
.card .price{color:#4f46e5;font-weight:800;font-size:13px}
.card button.add{background:#4f46e5;color:#fff;border:0;width:40px;height:40px;min-width:40px;border-radius:10px;font-size:22px;font-weight:800;display:flex;align-items:center;justify-content:center;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent;position:relative;z-index:5}.card button.add:active{transform:scale(.94);background:#4338ca}
.card.out{opacity:.5}
.cartbar{position:fixed;touch-action:manipulation;cursor:pointer;left:0;right:0;bottom:0;background:#1e293b;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;z-index:30;cursor:pointer;-webkit-tap-highlight-color:transparent}
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:flex-end;z-index:40}
.modal-bg.show{display:flex}
.sheet{background:#fff;border-radius:18px 18px 0 0;width:100%;max-height:85vh;display:flex;flex-direction:column}
.sheet-head{padding:14px 16px;border-bottom:1px solid #eef2f7;display:flex;justify-content:space-between;align-items:center;font-weight:800}
.sheet-body{overflow-y:auto;padding:12px 16px;flex:1}
.cart-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed #eef2f7}
.qty-ctrl{display:flex;align-items:center;gap:8px}
.qty-ctrl button{width:38px;height:38px;border-radius:8px;background:#f1f5f9;border:0;font-weight:800;font-size:20px;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent}.qty-ctrl button:active{transform:scale(.94)}
.sheet-foot{padding:12px 16px;border-top:1px solid #eef2f7}
.btn-primary{background:#4f46e5;color:#fff;border:0;width:100%;padding:12px;border-radius:10px;font-weight:800;font-size:14px}
textarea,input.note{width:100%;border:1px solid #e2e8f0;border-radius:8px;padding:8px;font-size:12px}
.empty{text-align:center;color:#94a3b8;padding:30px;font-size:13px}
.status-pill{font-size:10px;font-weight:800;padding:2px 8px;border-radius:9999px}
.st-pending{background:#fef3c7;color:#92400e}
.st-accepted{background:#dbeafe;color:#1e40af}
.st-served{background:#dcfce7;color:#166534}
.st-payment{background:#fef3c7;color:#92400e}
.order-live-card.status-payment_pending{background:linear-gradient(135deg,#78350f,#f59e0b)!important}
.order-step[data-step="payment_pending"].active span{background:#f59e0b;color:#fff;border-color:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.20)}

/* สถานะขั้นที่ 3: เสร็จแล้ว = สีเขียว */
.order-live-card.status-served{
  background:linear-gradient(135deg,#14532d,#16a34a)!important;
}
.order-live-card.status-served .order-live-icon{
  background:rgba(255,255,255,.20);
}
.order-live-card.status-served .order-live-main,
.order-live-card.status-served .order-live-sub{
  color:#fff;
}
.order-step[data-step="served"].active span{
  background:#22c55e;
  color:#fff;
  border-color:#22c55e;
  box-shadow:0 0 0 4px rgba(34,197,94,.20);
}


/* สถานะกำลังเตรียมอาหาร = สีเขียว และขั้นรับออเดอร์เป็นสีเขียวว่าเสร็จแล้ว */
.order-live-card.status-accepted{background:linear-gradient(135deg,#166534,#22c55e)!important}
.order-live-card.status-accepted .order-live-icon{background:rgba(255,255,255,.20)}
.order-live-card.status-accepted .order-live-main,.order-live-card.status-accepted .order-live-sub{color:#fff}
.order-step[data-step="accepted"].active span{background:#22c55e;color:#fff;border-color:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.20)}
.order-step[data-step="pending"].done span{background:#22c55e;color:#fff;border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.15)}

/* ===== SUNTORN CUSTOMER ORDER / SIGNATURE MENU ===== */
:root{--oc-1:#4338ca;--oc-2:#7c3aed;--oc-3:#06b6d4;--oc-bg:#f7f8fc;--oc-ink:#111827}
body{
 background:
 radial-gradient(circle at 8% 5%,rgba(124,58,237,.12),transparent 24%),
 radial-gradient(circle at 94% 12%,rgba(6,182,212,.10),transparent 22%),
 linear-gradient(145deg,#fafbff,#f3f6fb 60%,#eef2ff);
 color:var(--oc-ink);
}
.hdr{
 padding:18px 16px 22px!important;
 background:linear-gradient(120deg,#211b62 0%,#4338ca 48%,#0e7490 125%)!important;
 box-shadow:0 12px 30px rgba(49,46,129,.20)!important;
 position:sticky;top:0;z-index:20;overflow:hidden;
}
.hdr:before{content:"";position:absolute;width:180px;height:180px;right:-55px;top:-100px;border:35px solid rgba(255,255,255,.08);border-radius:50%}
.hdr>div{position:relative;z-index:1}
.hdr .shop-mark{display:inline-flex;width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.14);align-items:center;justify-content:center;margin-right:8px;vertical-align:middle}
.chips{
 top:82px!important;background:rgba(255,255,255,.90)!important;
 backdrop-filter:blur(14px);border-bottom:1px solid #e8ebf2!important;
 padding:10px 12px!important;box-shadow:0 6px 16px rgba(15,23,42,.04);
}
.chip{
 padding:8px 15px!important;background:#f8fafc!important;border:1px solid #e4e7ef!important;
 color:#475569!important;box-shadow:0 2px 7px rgba(15,23,42,.03);
}
.chip.active{background:linear-gradient(135deg,#5b5ce2,#7c3aed)!important;color:#fff!important;border-color:transparent!important;box-shadow:0 7px 15px rgba(91,92,226,.22)}
.grid{grid-template-columns:repeat(2,1fr)!important;gap:11px!important;padding:14px!important}
.card{
 border-radius:17px!important;border:1px solid #e6e9f0!important;
 box-shadow:0 5px 18px rgba(15,23,42,.045)!important;
 transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease!important;
}
.card:not(.out):hover{transform:translateY(-3px);border-color:#c7d2fe;box-shadow:0 12px 28px rgba(79,70,229,.11)}
.card .img{height:125px!important;background:linear-gradient(135deg,#f1f5ff,#f8fafc)!important}
.card .info{padding:10px 11px!important}
.card .name{font-size:13px!important;min-height:34px!important}
.card .price{font-size:14px!important;color:#5146d9!important}
.card button.add{
 width:42px!important;height:42px!important;border-radius:13px!important;
 background:linear-gradient(135deg,#5b5ce2,#7c3aed)!important;
 box-shadow:0 7px 14px rgba(91,92,226,.22);
}
.card button.add:active{transform:scale(.9)!important}
.cartbar{
 background:linear-gradient(105deg,#17153f,#4338ca 58%,#0e7490)!important;
 padding:13px 17px!important;box-shadow:0 -8px 25px rgba(15,23,42,.15);
}
.modal-bg{background:rgba(15,23,42,.58)!important;backdrop-filter:blur(4px)}
.sheet{border-radius:22px 22px 0 0!important;box-shadow:0 -20px 55px rgba(15,23,42,.18)}
.sheet-head{padding:16px!important;font-size:15px!important}
.cart-row{padding:11px 0!important}
.qty-ctrl{gap:7px!important}
.qty-ctrl button{
 width:40px!important;height:40px!important;border-radius:12px!important;
 background:#eef2ff!important;color:#4338ca;border:1px solid #e0e7ff!important;
}
.btn-primary{
 background:linear-gradient(135deg,#4338ca,#7c3aed)!important;
 border-radius:12px!important;padding:14px!important;
 box-shadow:0 8px 18px rgba(67,56,202,.20);
}
textarea,input.note{border-color:#e2e8f0!important;border-radius:10px!important}
.empty{padding:35px 20px!important}
@media(min-width:700px){.grid{grid-template-columns:repeat(4,1fr)!important}.card .img{height:145px!important}}
@media(max-width:420px){.grid{gap:8px!important;padding:10px!important}.card .img{height:105px!important}.card .info{padding:8px!important}.card button.add{width:38px!important;height:38px!important}}


/* ===== LIVE CUSTOMER ORDER STATUS ===== */
.order-live-wrap{padding:10px 12px 2px;background:#f8fafc}
.order-live-card{background:linear-gradient(135deg,#111827 0%,#312e81 55%,#4f46e5 100%);color:#fff;border-radius:18px;padding:14px;box-shadow:0 10px 26px rgba(49,46,129,.20);overflow:hidden;position:relative}
.order-live-card:after{content:"";position:absolute;width:130px;height:130px;border-radius:50%;right:-55px;top:-60px;background:rgba(255,255,255,.08)}
.order-live-head{display:flex;align-items:center;gap:10px;position:relative;z-index:1}
.order-live-icon{width:42px;height:42px;min-width:42px;border-radius:13px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;font-size:18px}
.order-live-title{min-width:0;flex:1}
.order-live-kicker{font-size:10px;opacity:.72;font-weight:700}
.order-live-main{font-size:15px;font-weight:800;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.order-live-detail{border:1px solid rgba(255,255,255,.28);background:rgba(255,255,255,.10);color:#fff;border-radius:10px;padding:7px 9px;font-size:10px;font-weight:800;cursor:pointer;white-space:nowrap}
.order-live-sub{position:relative;z-index:1;font-size:11px;color:rgba(255,255,255,.78);margin:9px 0 12px 52px;line-height:1.45}
.order-progress{position:relative;z-index:1;display:flex;align-items:flex-start;gap:5px}
.order-step{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:70px;opacity:.48;flex:1}
.order-step span{width:25px;height:25px;border-radius:50%;border:1px solid rgba(255,255,255,.35);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800}
.order-step b{font-size:9px;text-align:center;white-space:nowrap}
.order-step.active,.order-step.done{opacity:1}
.order-step.active span{background:#fff;color:#312e81;border-color:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.10)}
.order-step.done span{background:#22c55e;color:#fff;border-color:#22c55e}
.order-line{height:1px;background:rgba(255,255,255,.24);flex:1;margin-top:12px}
.order-live-card.status-pending{background:linear-gradient(135deg,#78350f,#d97706)}
.order-live-card.status-accepted{background:linear-gradient(135deg,#1e3a8a,#2563eb)}
.order-live-card.status-served{background:linear-gradient(135deg,#14532d,#16a34a)}
@media(max-width:420px){.order-live-main{font-size:14px}.order-live-sub{margin-left:52px}.order-live-detail{padding:7px 8px}.order-step{min-width:60px}.order-step b{font-size:8px}}

</style>
</head>
<body>

<?php if (!$table): ?>
  <div style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px;text-align:center">
    <div>
      <div style="font-size:48px;color:#cbd5e1"><i class="fa-solid fa-qrcode"></i></div>
      <h1 style="font-size:18px;font-weight:800;color:#1e293b;margin-top:10px">ไม่พบโต๊ะนี้</h1>
      <p style="font-size:13px;color:#64748b">กรุณาสแกน QR Code บนโต๊ะของท่านอีกครั้ง หรือเรียกพนักงานเพื่อขอความช่วยเหลือ</p>
    </div>
  </div>
<?php else: ?>

<div class="hdr">
  <div style="font-size:17px;font-weight:800"><span class="shop-mark"><i class="fa-solid fa-store"></i></span><?= htmlspecialchars($shopName) ?></div>
  <div style="font-size:12px;opacity:.9"><i class="fa-solid fa-utensils"></i>&nbsp; โต๊ะ <?= htmlspecialchars($table['table_no']) ?> — สแกนแล้วสั่งอาหารได้เลย</div>
</div>

<!-- LIVE ORDER STATUS -->
<div class="order-live-wrap" id="orderLiveWrap" style="display:none">
  <div class="order-live-card" id="orderLiveCard">
    <div class="order-live-head">
      <div class="order-live-icon" id="orderLiveIcon"><i class="fa-solid fa-receipt"></i></div>
      <div class="order-live-title">
        <div class="order-live-kicker">สถานะออเดอร์ล่าสุด</div>
        <div class="order-live-main" id="orderLiveMain">กำลังตรวจสอบสถานะ...</div>
      </div>
      <button type="button" class="order-live-detail" onclick="openStatus()">ดูรายละเอียด</button>
    </div>
    <div class="order-live-sub" id="orderLiveSub">ระบบจะแจ้งสถานะให้อัตโนมัติ</div>
    <div class="order-progress" id="orderProgress">
      <div class="order-step" data-step="pending"><span>1</span><b>รับออเดอร์</b></div>
      <div class="order-line"></div>
      <div class="order-step" data-step="accepted"><span>2</span><b>กำลังเตรียมอาหาร</b></div>
      <div class="order-line"></div>
      <div class="order-step" data-step="served"><span>3</span><b>เสร็จแล้ว</b></div>
      <div class="order-line"></div>
      <div class="order-step" data-step="payment_pending"><span>4</span><b>รอชำระ</b></div>
    </div>
  </div>
</div>

<div class="chips" id="chips">
  <button type="button" class="chip active" data-category="all">ทั้งหมด</button>
  <?php foreach ($categories as $c): ?>
    <button class="chip" onclick="filterCat('<?= htmlspecialchars(addslashes($c), ENT_QUOTES) ?>',this)"><?= htmlspecialchars($c) ?></button>
  <?php endforeach; ?>
</div>

<div class="grid" id="grid">
  <?php foreach ($products as $p): $out = (int)$p['stock'] <= 0; ?>
  <div class="card <?= $out ? 'out' : '' ?>"
     data-cat="<?= htmlspecialchars($p['category'] ?? '', ENT_QUOTES) ?>"
     data-product-id="<?= (int)$p['id'] ?>"
     data-product-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
     data-product-price="<?= (float)$p['price'] ?>"
     role="<?= $out ? 'presentation' : 'button' ?>"
     tabindex="<?= $out ? '-1' : '0' ?>"
     aria-label="<?= htmlspecialchars('เพิ่ม '.$p['name'], ENT_QUOTES) ?>">
    <div class="img"><?php if (!empty($p['image'])): ?><img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"><?php else: ?><i class="fa-solid fa-utensils"></i><?php endif; ?></div>
    <div class="info">
      <div class="name"><?= htmlspecialchars($p['name']) ?></div>
      <div class="bottom">
        <span class="price">฿<?= money($p['price']) ?></span>
        <?php if ($out): ?>
          <span style="font-size:10px;color:#ef4444;font-weight:700">ของหมด</span>
        <?php else: ?>
          <button class="add" onclick='addItem(<?= (int)$p['id'] ?>, <?= json_encode($p['name'], JSON_UNESCAPED_UNICODE) ?>, <?= (float)$p['price'] ?>)'>+</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!count($products)): ?><div class="empty">ยังไม่มีเมนูในระบบ</div><?php endif; ?>
</div>

<div class="cartbar" onclick="openCart()">
  <span><i class="fa-solid fa-cart-shopping"></i>&nbsp; <span id="cartCount">0</span> รายการ</span>
  <span id="cartTotal">฿0.00</span>
</div>

<div class="modal-bg" id="cartModal" onclick="if(event.target===this)closeCart()">
  <div class="sheet">
    <div class="sheet-head"><span>ตะกร้าของฉัน</span><button type="button" onclick="closeCart()" style="background:none;border:0;font-size:18px">&times;</button></div>
    <div class="sheet-body" id="cartBody"></div>
    <div class="sheet-foot">
      <textarea id="orderNote" rows="2" placeholder="หมายเหตุถึงร้าน (ถ้ามี) เช่น ไม่ใส่ผัก" style="margin-bottom:8px"></textarea>
      <button type="button" class="btn-primary" onclick="submitOrder()">ส่งออเดอร์ — <span id="cartTotal2">฿0.00</span></button>
    </div>
  </div>
</div>

<div class="modal-bg" id="statusModal" onclick="if(event.target===this)closeStatus()">
  <div class="sheet" style="max-height:60vh">
    <div class="sheet-head"><span>สถานะออเดอร์ของฉัน</span><button type="button" onclick="closeStatus()" style="background:none;border:0;font-size:18px">&times;</button></div>
    <div class="sheet-body" id="statusBody"></div>
  </div>
</div>

<script>
const TOKEN = <?= json_encode($token) ?>;
let cart = [];
const STORAGE_KEY = 'orders_'+TOKEN;

function esc(s){return String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;')}
function money(v){return Number(v||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}
function api(action,data={}){
  const f=new URLSearchParams(); f.append('action',action);
  Object.entries(data).forEach(([k,v])=>f.append(k,v));
  return fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:f.toString()}).then(r=>r.json());
}
function filterCat(cat,el){
  document.querySelectorAll('#chips .chip').forEach(x=>x.classList.remove('active'));
  if(el) el.classList.add('active');
  document.querySelectorAll('#grid .card').forEach(c=>{
    c.style.display=(cat==='all'||c.dataset.cat===cat)?'':'none';
  });
}

function addItem(id,name,price){
  id=Number(id); price=Number(price);
  if(!id) return;
  let x=cart.find(i=>Number(i.id)===id);
  if(x) x.qty++;
  else cart.push({id:id,name:String(name),price:price,qty:1,note:''});
  renderCartBar();
  if(document.getElementById('cartModal').classList.contains('show')) renderCartBody();
}

function updateQty(id,d){
  let x=cart.find(i=>i.id===id); if(!x)return;
  x.qty+=d; if(x.qty<=0)cart=cart.filter(i=>i.id!==id);
  renderCartBar(); renderCartBody();
}
function renderCartBar(){
  const count=cart.reduce((s,x)=>s+x.qty,0), total=cart.reduce((s,x)=>s+x.qty*x.price,0);
  document.getElementById('cartCount').textContent=count;
  document.getElementById('cartTotal').textContent='฿'+money(total);
  document.getElementById('cartTotal2').textContent='฿'+money(total);
}
function renderCartBody(){
  const el=document.getElementById('cartBody');
  if(!cart.length){el.innerHTML='<div class="empty">ยังไม่ได้เลือกเมนู แตะเครื่องหมาย + ที่เมนูเพื่อเพิ่ม</div>';return;}
  el.innerHTML=cart.map(x=>`<div class="cart-row">
      <div><b>${esc(x.name)}</b><br><span style="color:#64748b;font-size:11px">฿${money(x.price)}</span></div>
      <div class="qty-ctrl">
        <button type="button" data-qty-id="${Number(x.id)}" data-qty-delta="-1" aria-label="ลด ${esc(x.name)}">−</button>
        <b>${x.qty}</b>
        <button type="button" data-qty-id="${Number(x.id)}" data-qty-delta="1" aria-label="เพิ่ม ${esc(x.name)}">+</button>
      </div>
    </div>`).join('');
}
function openCart(){ renderCartBody(); document.getElementById('cartModal').classList.add('show'); }
function closeCart(){ document.getElementById('cartModal').classList.remove('show'); }
// ใช้ event listener โดยตรง เพื่อให้ปุ่มทำงานได้ทั้ง Mouse และ Touch
document.addEventListener('click', function(e){
  const catBtn=e.target.closest('#chips .chip');
  if(catBtn){
    e.preventDefault();
    filterCat(catBtn.dataset.category || 'all', catBtn);
    return;
  }

  const addBtn=e.target.closest('#grid button.add');
  if(addBtn){
    e.preventDefault();
    e.stopPropagation();
    addItem(addBtn.dataset.id, addBtn.dataset.name, addBtn.dataset.price);
    return;
  }

  const card=e.target.closest('#grid .card:not(.out)');
  if(card){
    e.preventDefault();
    addItem(card.dataset.productId, card.dataset.productName, card.dataset.productPrice);
    return;
  }

  const qtyBtn=e.target.closest('[data-qty-id]');
  if(qtyBtn){
    e.preventDefault();
    e.stopPropagation();
    updateQty(Number(qtyBtn.dataset.qtyId), Number(qtyBtn.dataset.qtyDelta));
    return;
  }
});

// รองรับกด Enter / Space เมื่อเลือกการ์ดเมนูด้วยแป้นพิมพ์
document.addEventListener('keydown', function(e){
  if(e.key !== 'Enter' && e.key !== ' ') return;
  const card=e.target.closest('#grid .card:not(.out)');
  if(!card || e.target !== card) return;
  e.preventDefault();
  addItem(card.dataset.productId, card.dataset.productName, card.dataset.productPrice);
});

function submitOrder(){
  if(!cart.length)return alert('กรุณาเลือกเมนูก่อนส่งออเดอร์');
  const note=document.getElementById('orderNote').value.trim();
  const btn=document.querySelector('#cartModal .btn-primary');
  if(btn){btn.disabled=true;btn.style.opacity='.65';btn.style.pointerEvents='none';}
  api('submit_order',{token:TOKEN,cart:JSON.stringify(cart),note}).then(d=>{
    if(!d.success){ alert(d.message); return; }
    alert(d.message);
    saveOrderId(d.order_id);
    renderLiveStatus({id:d.order_id,status:'pending'});
    refreshStatus();
    cart=[]; document.getElementById('orderNote').value='';
    renderCartBar(); closeCart();
    openStatus();
  }).catch(()=>alert('ส่งออเดอร์ไม่สำเร็จ กรุณาลองใหม่'))
    .finally(()=>{
      if(btn){btn.disabled=false;btn.style.opacity='';btn.style.pointerEvents='';}
    });
}
function saveOrderId(id){
  let ids=JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]');
  ids.push(id); localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
}
function statusLabel(s){
  return {
    pending:['<span class="status-pill st-pending">รอรับออเดอร์</span>'],
    accepted:['<span class="status-pill st-accepted">กำลังเตรียม</span>'],
    served:['<span class="status-pill st-served">เสร็จแล้ว</span>'],
    payment_pending:['<span class="status-pill st-payment">รอชำระ</span>'],
    cancelled:['<span class="status-pill" style="background:#fee2e2;color:#991b1b">ยกเลิก</span>']
  }[s]?.[0] || s;
}
function statusText(s){
  return {
    pending:{main:'รอพนักงานรับออเดอร์',sub:'ออเดอร์ถูกส่งเข้าระบบแล้ว กำลังรอพนักงานกดยืนยันรับออเดอร์',icon:'fa-hourglass-half'},
    accepted:{main:'กำลังเตรียมอาหาร',sub:'พนักงานรับออเดอร์แล้ว ตอนนี้ครัวกำลังจัดเตรียมอาหารให้คุณ',icon:'fa-kitchen-set'},
    served:{main:'เสร็จแล้ว',sub:'ออเดอร์นี้ทำเสร็จและเสิร์ฟเรียบร้อยแล้ว',icon:'fa-circle-check'},
    payment_pending:{main:'รอชำระ',sub:'ออเดอร์เสิร์ฟเรียบร้อยแล้ว กรุณาชำระเงินกับพนักงาน',icon:'fa-credit-card'},
    cancelled:{main:'ออเดอร์ถูกยกเลิก',sub:'ออเดอร์นี้ถูกยกเลิก กรุณาติดต่อพนักงานหากต้องการสั่งใหม่',icon:'fa-circle-xmark'}
  }[s] || {main:'กำลังตรวจสอบสถานะ',sub:'กรุณารอสักครู่ ระบบกำลังตรวจสอบออเดอร์',icon:'fa-rotate'};
}
function renderLiveStatus(order){
  const wrap=document.getElementById('orderLiveWrap');
  if(!wrap || !order){ if(wrap) wrap.style.display='none'; return; }
  wrap.style.display='block';
  const info=statusText(order.status);
  const card=document.getElementById('orderLiveCard');
  card.className='order-live-card status-'+order.status;
  document.getElementById('orderLiveIcon').innerHTML='<i class="fa-solid '+info.icon+'"></i>';
  document.getElementById('orderLiveMain').textContent=info.main+' • #'+order.id;
  document.getElementById('orderLiveSub').textContent=info.sub;
  const orderSteps=['pending','accepted','served','payment_pending'];
  const idx=orderSteps.indexOf(order.status);
  document.querySelectorAll('#orderProgress .order-step').forEach((el,i)=>{
    el.classList.remove('active','done');
    if(idx>=0 && i<idx) el.classList.add('done');
    if(idx===i) el.classList.add('active');
  });
  if(order.status==='cancelled'){
    document.querySelectorAll('#orderProgress .order-step').forEach(el=>el.classList.remove('active','done'));
  }
}
function openStatus(){
  document.getElementById('statusModal').classList.add('show');
  refreshStatus();
}
function closeStatus(){ document.getElementById('statusModal').classList.remove('show'); }
function refreshStatus(){
  let ids=[];
  try{
    ids=JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]')
      .map(Number).filter(Boolean);
  }catch(e){ ids=[]; }

  const el=document.getElementById('statusBody');
  if(!el) return;

  if(!ids.length){
    el.innerHTML='<div class="empty">ยังไม่มีออเดอร์ที่ส่ง</div>';
    renderLiveStatus(null);
    return;
  }

  api('order_status',{order_ids:ids.join(',')})
    .then(d=>{
      if(!d || !d.success){
        el.innerHTML='<div class="empty" style="color:#b45309">ไม่สามารถตรวจสอบสถานะได้ กำลังลองใหม่...</div>';
        return;
      }

      const rows=Array.isArray(d.data)?d.data:[];
      if(!rows.length){
        el.innerHTML='<div class="empty">ไม่พบข้อมูลออเดอร์</div>';
        renderLiveStatus(null);
        return;
      }

      // API เรียง id DESC แล้ว จึงใช้ออเดอร์ล่าสุดเป็นสถานะหลัก
      const latest=rows.reduce((a,b)=>Number(b.id)>Number(a.id)?b:a, rows[0]);
      renderLiveStatus(latest);

      el.innerHTML=rows.map(o=>
        `<div class="cart-row">
          <span>ออเดอร์ #${o.id}</span>${statusLabel(o.status)}
        </div>`
      ).join('');
    })
    .catch(err=>{
      console.error('order_status:',err);
      el.innerHTML='<div class="empty" style="color:#b45309">กำลังเชื่อมต่อระบบตรวจสอบสถานะ...</div>';
    });
}

// ตรวจสถานะทุก 5 วินาที แม้ไม่ได้เปิดหน้าต่างสถานะ เพื่อให้ลูกค้าเห็นว่าออเดอร์กำลังทำอะไรอยู่
refreshStatus();
setInterval(refreshStatus, 3000);

// ถ้ามีออเดอร์เก่าของโต๊ะนี้ค้างอยู่ ให้แสดงปุ่มดูสถานะเล็กๆ มุมขวาบน
(function(){
  let ids=JSON.parse(localStorage.getItem(STORAGE_KEY)||'[]');
  if(ids.length){
    const b=document.createElement('button');
    b.textContent='ดูสถานะออเดอร์';
    b.style.cssText='position:fixed;top:12px;right:12px;background:#fff;color:#4f46e5;border:0;padding:6px 10px;border-radius:8px;font-size:11px;font-weight:800;z-index:25;box-shadow:0 1px 4px rgba(0,0,0,.2)';
    b.onclick=openStatus;
    document.body.appendChild(b);
  }
})();
</script>
<?php endif; ?>
</body>
</html>
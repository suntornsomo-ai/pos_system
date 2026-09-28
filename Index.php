<?php
// กำหนด Timezone ของ PHP ให้ตรงกับเวลาประเทศไทยเสมอ
// (บั๊กเดิม: ไม่ได้ตั้งค่า timezone ทำให้ date('Y-m-d') ของ PHP กับเวลาที่บันทึกจริงใน MySQL
//  คลาดเคลื่อนกันได้ถึง 7 ชั่วโมง ส่งผลให้รายงานย้อนหลัง/สรุปยอดแต่ละวันดึงข้อมูลผิดวัน โดยเฉพาะช่วงใกล้เที่ยงคืน)
date_default_timezone_set('Asia/Bangkok');
session_start();

$host = 'localhost';
$username = 'mysql';
$password = 'password';
$dbname = 'pos_system';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $username, $password, $dbname);
if ($conn->connect_error) {
    die("เชื่อมต่อฐานข้อมูลไม่ได้: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
// บังคับ timezone ของ session MySQL ให้เป็นเวลาไทย (+07:00) ให้ตรงกับ PHP ด้านบน
// เพื่อให้ NOW(), CURDATE(), CURRENT_TIMESTAMP ในฝั่ง DB สอดคล้องกับ date('Y-m-d') ฝั่ง PHP เสมอ
$conn->query("SET time_zone = '+07:00'");

function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function money($v) {
    return number_format((float)$v, 2);
}

// ========================= DATABASE / SCHEMA =========================
// สร้าง/ปรับโครงสร้างฐานข้อมูลก่อนรับ POST เสมอ เพื่อให้การติดตั้งใหม่
// สามารถ Login / ขาย / Void / ปิดรอบ / สั่งผ่าน QR ได้ตั้งแต่ request แรก

function ensureColumn(mysqli $conn, string $table, string $column, string $ddl): void {
    // ขั้นตอนติดตั้งต้องไม่ส่ง ? placeholder ให้ MariaDB โดยตรง
    // เพราะ DDL/metadata บางคำสั่งมีพฤติกรรมต่างกันระหว่าง MariaDB versions
    // ชื่อตารางและคอลัมน์มาจากโค้ดภายในเท่านั้น จึง escape ก่อนประกอบ SQL ได้อย่างปลอดภัย
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $sql = "SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$tableEsc}'
              AND COLUMN_NAME = '{$columnEsc}'";
    $row = $conn->query($sql)->fetch_assoc();
    $exists = ((int)($row['c'] ?? 0)) > 0;
    if (!$exists) {
        $conn->query($ddl);
    }
}

function initializeDatabase(mysqli $conn): void {
    static $initialized = false;
    if ($initialized || !empty($_SESSION['pos_schema_ready_v5'])) return;

    $ddl = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            fullname VARCHAR(150) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'staff',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            sku VARCHAR(50) DEFAULT '',
            category VARCHAR(100) DEFAULT '',
            cost_price DECIMAL(10,2) DEFAULT 0,
            price DECIMAL(10,2) NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            barcode VARCHAR(100) DEFAULT NULL,
            image MEDIUMTEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            receipt_no VARCHAR(20) DEFAULT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_given DECIMAL(10,2) NOT NULL DEFAULT 0,
            change_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
            created_by VARCHAR(150) NOT NULL DEFAULT '',
            vat_included TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            price DECIMAL(10,2) NOT NULL,
            quantity INT NOT NULL,
            voided_qty INT NOT NULL DEFAULT 0,
            void_reason VARCHAR(255) DEFAULT NULL,
            voided_at DATETIME NULL,
            voided_by VARCHAR(150) DEFAULT NULL,
            total DECIMAL(10,2) NOT NULL,
            KEY idx_order_items_order(order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL DEFAULT ''
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            movement_type ENUM('IN','OUT','VOID') NOT NULL,
            quantity INT NOT NULL,
            stock_before INT NULL,
            stock_after INT NULL,
            reference_type VARCHAR(50) DEFAULT NULL,
            reference_id INT NULL,
            reason VARCHAR(255) DEFAULT '',
            created_by VARCHAR(150) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_stock_product_date(product_id,created_at),
            INDEX idx_stock_type_date(movement_type,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS void_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            order_item_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            void_qty INT NOT NULL,
            refund_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            reason VARCHAR(255) NOT NULL DEFAULT 'Void สินค้า',
            voided_by VARCHAR(150) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_void_order(order_id),
            INDEX idx_void_item(order_item_id),
            INDEX idx_void_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS receipt_counter (
            id TINYINT PRIMARY KEY,
            current_no INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS day_closings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            closing_date DATE NOT NULL,
            round_no INT NOT NULL DEFAULT 1,
            start_at DATETIME NULL,
            end_at DATETIME NULL,
            total_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
            bill_count INT NOT NULL DEFAULT 0,
            cash_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            staff VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_day_closing_date_round (closing_date,round_no),
            INDEX idx_day_closing_end_at (end_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS cashier_closings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            closing_no VARCHAR(30) NOT NULL,
            closing_date DATE NOT NULL,
            staff_id INT NULL,
            staff_username VARCHAR(100) DEFAULT NULL,
            staff_name VARCHAR(150) DEFAULT NULL,
            sales_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            vat_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_sales DECIMAL(12,2) NOT NULL DEFAULT 0,
            expected_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_counted DECIMAL(12,2) NOT NULL DEFAULT 0,
            cash_out DECIMAL(12,2) NOT NULL DEFAULT 0,
            variance DECIMAL(12,2) NOT NULL DEFAULT 0,
            denominations_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cashier_closing_date(closing_date),
            INDEX idx_cashier_created_at(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS cashier_cash_outs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cash_out_no VARCHAR(40) NOT NULL,
            staff_id INT NULL,
            staff_username VARCHAR(100) DEFAULT NULL,
            staff_name VARCHAR(150) DEFAULT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            denominations_json LONGTEXT NULL,
            note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cash_out_created_at(created_at),
            INDEX idx_cash_out_staff(staff_username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS restaurant_tables (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_no VARCHAR(50) NOT NULL UNIQUE,
            qr_token VARCHAR(64) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS customer_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_id INT NOT NULL,
            customer_note VARCHAR(255) DEFAULT '',
            status ENUM('pending','accepted','served','payment_pending','cancelled','loaded') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_corder_status(status),
            INDEX idx_corder_table(table_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS customer_order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(200) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            quantity INT NOT NULL,
            note VARCHAR(255) DEFAULT '',
            INDEX idx_citem_order(order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS bill_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_id INT NOT NULL,
            status ENUM('pending','done') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_bill_status(status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($ddl as $idx => $sql) {
        // ทุกคำสั่งในขั้นตอนสร้าง Schema ต้องเป็น SQL ที่ MariaDB execute ได้ตรง ๆ
        try {
            $conn->query($sql);
        } catch (Throwable $e) {
            throw new RuntimeException('Schema step ' . ($idx + 1) . ': ' . $e->getMessage());
        }
    }

    ensureColumn($conn, 'orders', 'receipt_no', "ALTER TABLE orders ADD COLUMN receipt_no VARCHAR(20) NULL AFTER id");
    ensureColumn($conn, 'orders', 'subtotal_amount', "ALTER TABLE orders ADD COLUMN subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount");
    ensureColumn($conn, 'orders', 'discount_amount', "ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal_amount");
    ensureColumn($conn, 'orders', 'vat_amount', "ALTER TABLE orders ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER discount_amount");
    ensureColumn($conn, 'orders', 'payment_method', "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'cash' AFTER change_amount");
    ensureColumn($conn, 'orders', 'created_by', "ALTER TABLE orders ADD COLUMN created_by VARCHAR(150) NOT NULL DEFAULT '' AFTER payment_method");
    ensureColumn($conn, 'orders', 'vat_included', "ALTER TABLE orders ADD COLUMN vat_included TINYINT(1) NOT NULL DEFAULT 0");
    // ตรวจ index ผ่าน information_schema แทน SHOW เพื่อให้เข้ากันได้กับ MariaDB ทุกรุ่น
    $receiptIndexSql = "SELECT COUNT(*) AS c FROM information_schema.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'orders'
                           AND INDEX_NAME = 'uq_orders_receipt_no'";
    $receiptIndex = $conn->query($receiptIndexSql)->fetch_assoc();
    if ((int)($receiptIndex['c'] ?? 0) > 0) {
        $conn->query("ALTER TABLE orders DROP INDEX uq_orders_receipt_no");
    }

    ensureColumn($conn, 'order_items', 'voided_qty', "ALTER TABLE order_items ADD COLUMN voided_qty INT NOT NULL DEFAULT 0 AFTER quantity");
    ensureColumn($conn, 'order_items', 'void_reason', "ALTER TABLE order_items ADD COLUMN void_reason VARCHAR(255) NULL AFTER voided_qty");
    ensureColumn($conn, 'order_items', 'voided_at', "ALTER TABLE order_items ADD COLUMN voided_at DATETIME NULL AFTER void_reason");
    ensureColumn($conn, 'order_items', 'voided_by', "ALTER TABLE order_items ADD COLUMN voided_by VARCHAR(150) NULL AFTER voided_at");

    ensureColumn($conn, 'products', 'sku', "ALTER TABLE products ADD COLUMN sku VARCHAR(50) DEFAULT '' AFTER name");
    ensureColumn($conn, 'products', 'category', "ALTER TABLE products ADD COLUMN category VARCHAR(100) DEFAULT '' AFTER sku");
    ensureColumn($conn, 'products', 'cost_price', "ALTER TABLE products ADD COLUMN cost_price DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER category");
    ensureColumn($conn, 'products', 'image', "ALTER TABLE products ADD COLUMN image MEDIUMTEXT NULL AFTER barcode");
    $imgSql = "SELECT DATA_TYPE FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'image'";
    $img = $conn->query($imgSql)->fetch_assoc();
    if ($img && strtolower((string)$img['DATA_TYPE']) !== 'mediumtext') {
        $conn->query("ALTER TABLE products MODIFY image MEDIUMTEXT NULL");
    }

    ensureColumn($conn, 'day_closings', 'round_no', "ALTER TABLE day_closings ADD COLUMN round_no INT NOT NULL DEFAULT 1 AFTER closing_date");
    ensureColumn($conn, 'day_closings', 'start_at', "ALTER TABLE day_closings ADD COLUMN start_at DATETIME NULL AFTER round_no");
    ensureColumn($conn, 'day_closings', 'end_at', "ALTER TABLE day_closings ADD COLUMN end_at DATETIME NULL AFTER start_at");
    ensureColumn($conn, 'cashier_closings', 'vat_total', "ALTER TABLE cashier_closings ADD COLUMN vat_total DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER sales_total");

    $coSql = "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_orders' AND COLUMN_NAME = 'status'";
    $co = $conn->query($coSql)->fetch_assoc();
    if ($co && strpos((string)$co['COLUMN_TYPE'], "'loaded'") === false) {
        $conn->query("ALTER TABLE customer_orders MODIFY status ENUM('pending','accepted','served','payment_pending','cancelled','loaded') NOT NULL DEFAULT 'pending'");
    }

    $conn->query("INSERT IGNORE INTO receipt_counter (id,current_no) VALUES (1,0)");

    $u = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc();
    if ((int)$u['c'] === 0) {
        // ห้ามใช้ placeholder (?) ในขั้นตอนติดตั้ง เพราะบาง MariaDB configuration
        // อาจส่ง SQL ของ installation ผ่าน query() แทน prepare()
        $adminHash = $conn->real_escape_string(password_hash('admin1234', PASSWORD_DEFAULT));
        $adminUser = $conn->real_escape_string('admin');
        $adminName = $conn->real_escape_string('Initial Admin');
        $conn->query("INSERT INTO users(username,password,fullname,role) VALUES ('{$adminUser}','{$adminHash}','{$adminName}','admin')");
    }

    $_SESSION['pos_schema_ready_v5'] = 1;
    $initialized = true;
}

try {
    initializeDatabase($conn);
} catch (Throwable $e) {
    http_response_code(500);
    die("เตรียมฐานข้อมูลไม่สำเร็จ: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$currentUser = $_SESSION['pos_user'] ?? null;
$isLoggedIn = is_array($currentUser) && !empty($currentUser['id']);

// อ่านสิทธิ์จากฐานข้อมูลทุกครั้ง เพื่อป้องกัน Session เก่าหรือ Role ใน Session ไม่ตรงกับ User จริง
if ($isLoggedIn) {
    $authStmt = $conn->prepare("SELECT id, username, fullname, role FROM users WHERE id=? LIMIT 1");
    if ($authStmt) {
        $authId = (int)$currentUser['id'];
        $authStmt->bind_param('i', $authId);
        $authStmt->execute();
        $authDb = $authStmt->get_result()->fetch_assoc();
        $authStmt->close();
        if ($authDb) {
            $currentUser = [
                'id' => (int)$authDb['id'],
                'username' => (string)$authDb['username'],
                'fullname' => (string)$authDb['fullname'],
                'role' => strtolower(trim((string)$authDb['role']))
            ];
            $_SESSION['pos_user'] = $currentUser;
        } else {
            $currentUser = null;
            $isLoggedIn = false;
        }
    }
}
$isAdmin = $isLoggedIn && strtolower(trim((string)($currentUser['role'] ?? ''))) === 'admin';
$adminActions = ['add_product','update_product','delete_product','sales_history','stock_report','close_day','settings_save','users_list','user_save','user_delete','tables_list','table_save','table_delete','void_item','void_search_today'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'login') {
        $loginUser = trim($_POST['username'] ?? '');
        $loginPass = (string)($_POST['password'] ?? '');
        if ($loginUser === '' || $loginPass === '') jsonResponse(['success'=>false,'message'=>'กรุณากรอก Username และ Password']);
        $stmt = $conn->prepare("SELECT id,username,password,fullname,role FROM users WHERE username=? LIMIT 1");
        $stmt->bind_param('s', $loginUser);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$u || !password_verify($loginPass, $u['password'])) jsonResponse(['success'=>false,'message'=>'Username หรือ Password ไม่ถูกต้อง']);
        session_regenerate_id(true);
        $_SESSION['pos_user'] = ['id'=>(int)$u['id'],'username'=>$u['username'],'fullname'=>$u['fullname'],'role'=>$u['role']];
        jsonResponse(['success'=>true,'user'=>$_SESSION['pos_user']]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        jsonResponse(['success'=>true]);
    }

    if (!$isLoggedIn) jsonResponse(['success'=>false,'message'=>'กรุณาเข้าสู่ระบบก่อนใช้งาน']);
    if (in_array($action, $adminActions, true) && !$isAdmin) jsonResponse(['success'=>false,'message'=>'สิทธิ์ของ Staff ใช้งานได้เฉพาะหน้าขายเท่านั้น']);


    try {
        if ($action === 'users_list') {
            $result = $conn->query("SELECT id,username,fullname,role,created_at FROM users ORDER BY id ASC");
            $rows=[]; while($r=$result->fetch_assoc()) $rows[]=$r;
            jsonResponse(['success'=>true,'data'=>$rows]);
        }

        if ($action === 'user_save') {
            $username=trim($_POST['username'] ?? ''); $fullname=trim($_POST['fullname'] ?? '');
            $password=(string)($_POST['password'] ?? ''); $role=($_POST['role'] ?? 'staff')==='admin'?'admin':'staff';
            if($username==='' || $password==='') jsonResponse(['success'=>false,'message'=>'กรุณากรอก Username และ Password']);
            if(!preg_match('/^[A-Za-z0-9._-]{3,100}$/',$username)) jsonResponse(['success'=>false,'message'=>'Username ใช้ได้เฉพาะ A-Z, a-z, 0-9, . _ - และยาวอย่างน้อย 3 ตัว']);
            if(strlen($password)<4) jsonResponse(['success'=>false,'message'=>'Password ต้องมีอย่างน้อย 4 ตัวอักษร']);
            $stmt=$conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1"); $stmt->bind_param('s',$username); $stmt->execute();
            $exists=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($exists) jsonResponse(['success'=>false,'message'=>'Username นี้มีอยู่แล้ว']);
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $stmt=$conn->prepare("INSERT INTO users(username,password,fullname,role) VALUES(?,?,?,?)");
            $stmt->bind_param('ssss',$username,$hash,$fullname,$role); $stmt->execute(); $newId=$stmt->insert_id; $stmt->close();
            jsonResponse(['success'=>true,'message'=>'เพิ่มผู้ใช้งานเรียบร้อยแล้ว','id'=>$newId]);
        }

        if ($action === 'user_delete') {
            $id=(int)($_POST['id'] ?? 0);
            if($id<=0) jsonResponse(['success'=>false,'message'=>'ไม่พบ User ที่ต้องการลบ']);
            if($id===(int)$currentUser['id']) jsonResponse(['success'=>false,'message'=>'ไม่สามารถลบ User ที่กำลัง Login อยู่ได้']);
            $stmt=$conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1"); $stmt->bind_param('i',$id); $stmt->execute();
            $target=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if(!$target) jsonResponse(['success'=>false,'message'=>'ไม่พบ User']);
            if($target['role']==='admin') {
                $res=$conn->query("SELECT COUNT(*) c FROM users WHERE role='admin'"); $adminCount=(int)$res->fetch_assoc()['c'];
                if($adminCount<=1) jsonResponse(['success'=>false,'message'=>'ไม่สามารถลบ Admin คนสุดท้ายได้']);
            }
            $stmt=$conn->prepare("DELETE FROM users WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
            jsonResponse(['success'=>true,'message'=>'ลบผู้ใช้งานเรียบร้อยแล้ว']);
        }

        if ($action === 'products') {
            $q = trim($_POST['q'] ?? '');
            if ($q !== '') {
                $like = '%' . $q . '%';
                $stmt = $conn->prepare(
                    "SELECT id, name, sku, category, cost_price, price, stock, barcode, image, created_at
                     FROM products
                     WHERE name LIKE ? OR barcode LIKE ? OR sku LIKE ?
                     ORDER BY id DESC"
                );
                $stmt->bind_param("sss", $like, $like, $like);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
            } else {
                $result = $conn->query(
                    "SELECT id, name, sku, category, cost_price, price, stock, barcode, image, created_at
                     FROM products ORDER BY id DESC"
                );
            }
            $rows = [];
            while ($r = $result->fetch_assoc()) $rows[] = $r;
            jsonResponse(['success'=>true,'data'=>$rows]);
        }

        if ($action === 'add_product' || $action === 'update_product') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $cost_price = (float)($_POST['cost_price'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0);
            $barcode = trim($_POST['barcode'] ?? '');
            $image = trim($_POST['image'] ?? '');

            if ($name === '' || $price < 0) {
                jsonResponse(['success'=>false,'message'=>'กรุณากรอกข้อมูลสินค้าสำคัญให้ครบ']);
            }

            if ($barcode !== '') {
                $checkSql = $id > 0 ? "SELECT id FROM products WHERE barcode=? AND id<>? LIMIT 1" : "SELECT id FROM products WHERE barcode=? LIMIT 1";
                $stmt = $conn->prepare($checkSql);
                if ($id > 0) {
                    $stmt->bind_param("si", $barcode, $id);
                } else {
                    $stmt->bind_param("s", $barcode);
                }
                $stmt->execute();
                $exists = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($exists) jsonResponse(['success'=>false,'message'=>'Barcode นี้มีอยู่ในระบบแล้ว']);
            }

            if ($id > 0) {
                $oldStmt=$conn->prepare("SELECT stock FROM products WHERE id=? LIMIT 1");
                $oldStmt->bind_param('i',$id); $oldStmt->execute(); $oldRow=$oldStmt->get_result()->fetch_assoc(); $oldStmt->close();
                if(!$oldRow) jsonResponse(['success'=>false,'message'=>'ไม่พบสินค้า']);
                $oldStock=(int)$oldRow['stock'];
                $stmt = $conn->prepare(
                    "UPDATE products SET name=?, sku=?, category=?, cost_price=?, price=?, stock=?, barcode=?, image=? WHERE id=?"
                );
                $stmt->bind_param("sssddissi", $name, $sku, $category, $cost_price, $price, $stock, $barcode, $image, $id);
                $stmt->execute();
                $stmt->close();
                $delta=$stock-$oldStock;
                if($delta!==0){
                    $type=$delta>0?'IN':'OUT'; $qty=abs($delta);
                    $reason=$delta>0?'ปรับเพิ่ม Stock':'ปรับลด Stock'; $who=(string)($currentUser['username']??'');
                    $mv=$conn->prepare("INSERT INTO stock_movements(product_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,reason,created_by) VALUES(?,?,?,?,?,'manual',?,?,?)");
                    $nullId=0; $mv->bind_param('isiiisss',$id,$type,$qty,$oldStock,$stock,$nullId,$reason,$who); $mv->execute(); $mv->close();
                }
                jsonResponse(['success'=>true,'message'=>'แก้ไขสินค้าเรียบร้อยแล้ว']);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO products(name, sku, category, cost_price, price, stock, barcode, image) VALUES(?,?,?,?,?,?,?,?)"
                );
                $stmt->bind_param("sssddiss", $name, $sku, $category, $cost_price, $price, $stock, $barcode, $image);
                $stmt->execute();
                $newId = $stmt->insert_id;
                $stmt->close();
                if($stock!==0){
                    $type=$stock>0?'IN':'OUT'; $qty=abs($stock); $reason='Stock ตั้งต้น'; $who=(string)($currentUser['username']??''); $before=0;
                    $mv=$conn->prepare("INSERT INTO stock_movements(product_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,reason,created_by) VALUES(?,?,?,?,?,'manual',?,?,?)");
                    $nullId=0; $mv->bind_param('isiiisss',$newId,$type,$qty,$before,$stock,$nullId,$reason,$who); $mv->execute(); $mv->close();
                }
                jsonResponse(['success'=>true,'message'=>'เพิ่มสินค้าเรียบร้อยแล้ว','id'=>$newId]);
            }
        }

        if ($action === 'delete_product') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM order_items WHERE product_id=?");
            $stmt->bind_param("i",$id);
            $stmt->execute();
            $used = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();

            if ($used > 0) {
                jsonResponse(['success'=>false,'message'=>'ไม่สามารถลบสินค้าได้ เพราะมีประวัติการขายแล้ว']);
            }

            $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
            $stmt->bind_param("i",$id);
            $stmt->execute();
            $stmt->close();

            jsonResponse(['success'=>true,'message'=>'ลบสินค้าเรียบร้อยแล้ว']);
        }

        // ===== จัดการโต๊ะ / QR Code (Admin) =====
        if ($action === 'tables_list') {
            $result = $conn->query("SELECT id, table_no, qr_token, is_active, created_at FROM restaurant_tables ORDER BY id ASC");
            $rows = []; while ($r = $result->fetch_assoc()) $rows[] = $r;
            jsonResponse(['success'=>true,'data'=>$rows]);
        }

        if ($action === 'table_save') {
            $tableNo = trim($_POST['table_no'] ?? '');
            if ($tableNo === '') jsonResponse(['success'=>false,'message'=>'กรุณากรอกหมายเลข/ชื่อโต๊ะ']);
            $stmt = $conn->prepare("SELECT id FROM restaurant_tables WHERE table_no=? LIMIT 1");
            $stmt->bind_param('s', $tableNo);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) jsonResponse(['success'=>false,'message'=>'มีโต๊ะนี้อยู่แล้ว']);
            $token = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("INSERT INTO restaurant_tables(table_no, qr_token) VALUES(?,?)");
            $stmt->bind_param('ss', $tableNo, $token);
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            jsonResponse(['success'=>true,'message'=>'เพิ่มโต๊ะเรียบร้อยแล้ว','id'=>$newId,'qr_token'=>$token]);
        }

        if ($action === 'table_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jsonResponse(['success'=>false,'message'=>'ไม่พบโต๊ะที่ต้องการลบ']);
            $stmt = $conn->prepare("DELETE FROM restaurant_tables WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success'=>true,'message'=>'ลบโต๊ะเรียบร้อยแล้ว']);
        }

        // ===== ออเดอร์ที่ลูกค้าสั่งเองจากการสแกน QR (Admin + Staff ดูได้) =====
        if ($action === 'corders_list') {
            $result = $conn->query(
                "SELECT co.id, co.table_id, co.customer_note, co.status, co.created_at, t.table_no
                 FROM customer_orders co
                 JOIN restaurant_tables t ON t.id = co.table_id
                 WHERE co.status IN ('pending','accepted','served','payment_pending')
                 ORDER BY co.created_at ASC"
            );
            $orders = []; $indexById = [];
            while ($r = $result->fetch_assoc()) { $r['items'] = []; $orders[] = $r; $indexById[$r['id']] = count($orders) - 1; }
            if ($orders) {
                $ids = implode(',', array_map('intval', array_column($orders, 'id')));
                $itemsRes = $conn->query("SELECT order_id, product_id, product_name, price, quantity, note FROM customer_order_items WHERE order_id IN ($ids) ORDER BY id ASC");
                while ($it = $itemsRes->fetch_assoc()) {
                    $orders[$indexById[$it['order_id']]]['items'][] = $it;
                }
            }
            jsonResponse(['success'=>true,'data'=>$orders]);
        }

        if ($action === 'corders_pending_count') {
            $r = $conn->query("SELECT COUNT(*) c FROM customer_orders WHERE status='pending'");
            $c = (int)$r->fetch_assoc()['c'];
            jsonResponse(['success'=>true,'count'=>$c]);
        }

        if ($action === 'corder_update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $allowed = ['pending','accepted','served','payment_pending','cancelled','loaded'];
            if ($id <= 0 || !in_array($status, $allowed, true)) jsonResponse(['success'=>false,'message'=>'ข้อมูลไม่ถูกต้อง']);
            $stmt = $conn->prepare("UPDATE customer_orders SET status=? WHERE id=?");
            $stmt->bind_param('si', $status, $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success'=>true,'message'=>'อัปเดตสถานะเรียบร้อยแล้ว']);
        }

        // ===== คำขอเรียกเก็บเงินจากลูกค้า (Admin + Staff) =====
        if ($action === 'bill_requests_list') {
            $result = $conn->query(
                "SELECT br.id, br.table_id, br.status, br.created_at, t.table_no
                 FROM bill_requests br
                 JOIN restaurant_tables t ON t.id = br.table_id
                 WHERE br.status = 'pending'
                 ORDER BY br.created_at ASC"
            );
            $rows = []; while ($r = $result->fetch_assoc()) $rows[] = $r;
            jsonResponse(['success'=>true,'data'=>$rows]);
        }

        if ($action === 'bill_request_done') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) jsonResponse(['success'=>false,'message'=>'ไม่พบรายการ']);
            $stmt = $conn->prepare("UPDATE bill_requests SET status='done' WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success'=>true,'message'=>'รับทราบเรียบร้อยแล้ว']);
        }

        if ($action === 'checkout') {
            $cart = json_decode($_POST['cart'] ?? '[]', true);
            $cash = (float)($_POST['cash'] ?? 0);
            $discountInput = (float)($_POST['discount'] ?? 0);
            $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
            $allowedMethods = ['cash','qr'];
            if (!in_array($paymentMethod, $allowedMethods, true)) $paymentMethod = 'cash';

            if (!is_array($cart) || count($cart) === 0) {
                jsonResponse(['success'=>false,'message'=>'ไม่มีสินค้าในตะกร้า']);
            }

            $conn->begin_transaction();

            // ล็อกสินค้าเรียงตาม Product ID เดียวกันทุกบิล ลดโอกาสเกิด Deadlock
            usort($cart, static function($a,$b){
                return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
            });

            $validated = [];
            $subtotal = 0;

            $stockByProduct = []; // สะสมจำนวนที่ต้องการต่อ 1 สินค้า เผื่อตรวจสต็อกให้ถูกต้องแม้ตะกร้าอยู่หลายบรรทัดในตะกร้าเดียวกัน
            foreach ($cart as $item) {
                $id = (int)($item['id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                if ($id <= 0 || $qty <= 0) throw new Exception('ข้อมูลสินค้าไม่ถูกต้อง');

                $stmt = $conn->prepare(
                    "SELECT id, name, price, stock, barcode
                     FROM products WHERE id=? FOR UPDATE"
                );
                $stmt->bind_param("i",$id);
                $stmt->execute();
                $p = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$p) throw new Exception('ไม่พบสินค้า ID '.$id);

                // อนุญาตให้ขายได้แม้ STOCK เป็น 0 หรือติดลบ
                // ระบบจะหักจำนวนที่ขายออกจาก STOCK ต่อเนื่อง เช่น 0 -> -1 -> -2 -> -3 ...
                // ไม่บล็อกการขายเพราะ STOCK ไม่เพียงพออีกต่อไป
                $stockByProduct[$id] = ($stockByProduct[$id] ?? 0) + $qty;

                $line = (float)$p['price'] * $qty;
                $subtotal += $line;
                $validated[] = [
                    'id'=>$id,'name'=>$p['name'],'barcode'=>$p['barcode'],
                    'price'=>(float)$p['price'],'qty'=>$qty,'total'=>$line
                ];
            }

            // คำนวณส่วนลด / VAT / ยอดสุทธิ ที่ฝั่งเซิร์ฟเวอร์เสมอ ป้องกันการปลอมค่าจากฝั่ง Client
            $discount = min(max($discountInput, 0), $subtotal);
            $taxable = max(0, $subtotal - $discount);
            // ราคาสินค้ารวม VAT แล้ว: ยอดชำระสุทธิ = ยอดหลังส่วนลด (ไม่บวก VAT เพิ่ม) และแยก VAT 7% ที่รวมอยู่ในยอดไว้สำหรับรายงาน
            $grandTotal = round($taxable, 2);
            $vat = round($taxable * 7 / 107, 2);

            if ($paymentMethod === 'qr') {
                $ppStmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='promptpayId' LIMIT 1");
                $promptpayIdServer = '';
                if ($ppStmt) {
                    $ppStmt->execute();
                    $ppRow = $ppStmt->get_result()->fetch_assoc();
                    $promptpayIdServer = trim((string)($ppRow['setting_value'] ?? ''));
                    $ppStmt->close();
                }
                if ($promptpayIdServer === '') {
                    throw new Exception('ยังไม่ได้ตั้งค่าเลขพร้อมเพย์ กรุณาไปที่ ตั้งค่า > เลขพร้อมเพย์ ก่อนรับชำระ QR');
                }
            }

            if ($paymentMethod !== 'cash') {
                // ชำระผ่านช่องทางที่ไม่ใช่เงินสด ถือว่าชำระพอดี ไม่มีเงินทอน
                $cash = $grandTotal;
            }

            if ($cash < $grandTotal) throw new Exception('เงินสดไม่เพียงพอ');
            $change = round($cash - $grandTotal, 2);

            // ออกเลขที่บิลแบบวิ่ง 00001-99999 ห้ามซ้ำในรอบเดียวกัน เมื่อครบ 99999 ให้วนกลับไปเริ่ม 00001
            $ctrRes = $conn->query("SELECT current_no FROM receipt_counter WHERE id=1 FOR UPDATE");
            $ctrRow = $ctrRes ? $ctrRes->fetch_assoc() : null;
            $currentNo = $ctrRow ? (int)$ctrRow['current_no'] : 0;
            $nextNo = $currentNo + 1;
            if ($nextNo > 99999) $nextNo = 1;
            $conn->query("UPDATE receipt_counter SET current_no=$nextNo WHERE id=1");
            $receiptNo = str_pad((string)$nextNo, 5, '0', STR_PAD_LEFT);

            $sellerUsername=(string)($currentUser['username']??'');
            $stmt = $conn->prepare(
                "INSERT INTO orders(receipt_no,total_amount,subtotal_amount,discount_amount,vat_amount,cash_given,change_amount,payment_method,created_by,vat_included)
                 VALUES(?,?,?,?,?,?,?,?,?,1)"
            );
            $stmt->bind_param("sddddddss",$receiptNo,$grandTotal,$subtotal,$discount,$vat,$cash,$change,$paymentMethod,$sellerUsername);
            $stmt->execute();
            $orderId = $stmt->insert_id;
            $stmt->close();

            $itemStmt = $conn->prepare(
                "INSERT INTO order_items(order_id,product_id,price,quantity,total)
                 VALUES(?,?,?,?,?)"
            );
            // อนุญาตให้ STOCK ติดลบได้ไม่จำกัด
            // ใช้ UPDATE แบบ atomic เพื่อรองรับการขายพร้อมกันหลายบิล
            $stockStmt = $conn->prepare(
                "UPDATE products SET stock=stock-? WHERE id=?"
            );

            foreach ($validated as $p) {
                $itemStmt->bind_param(
                    "iidid",$orderId,$p['id'],$p['price'],$p['qty'],$p['total']
                );
                $itemStmt->execute();

                $stockStmt->bind_param("ii",$p['qty'],$p['id']);
                if (!$stockStmt->execute() || $stockStmt->affected_rows !== 1) {
                    throw new Exception('ตัด STOCK สินค้า "'.$p['name'].'" ไม่สำเร็จ');
                }
                $newStockStmt=$conn->prepare("SELECT stock FROM products WHERE id=?"); $newStockStmt->bind_param('i',$p['id']); $newStockStmt->execute(); $newStock=(int)$newStockStmt->get_result()->fetch_assoc()['stock']; $newStockStmt->close();
                $beforeStock=$newStock+(int)$p['qty']; $who=(string)($currentUser['username']??''); $type='OUT'; $reason='ขายสินค้า'; $refType='order';
                $mv=$conn->prepare("INSERT INTO stock_movements(product_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?)");
                $mv->bind_param('isiiisiss',$p['id'],$type,$p['qty'],$beforeStock,$newStock,$refType,$orderId,$reason,$who); $mv->execute(); $mv->close();
            }

            $itemStmt->close();
            $stockStmt->close();
            $conn->commit();

            jsonResponse([
                'success'=>true,
                'order_id'=>$orderId,
                'receipt_no'=>$receiptNo,
                'subtotal'=>money($subtotal),
                'discount'=>money($discount),
                'vat'=>money($vat),
                'total'=>money($grandTotal),
                'cash'=>money($cash),
                'change'=>money($change),
                'payment_method'=>$paymentMethod,
                'items'=>array_map(static function($p){
                    return [
                        'name'=>(string)$p['name'],
                        'price'=>(float)$p['price'],
                        'qty'=>(int)$p['qty'],
                        'total'=>(float)$p['total']
                    ];
                }, $validated)
            ]);
        }

        if ($action === 'void_item') {
            // Void ได้เฉพาะ Admin และ Void เป็นรายการ+จำนวนที่ระบุ (ไม่บังคับ Void ทั้งบิลอีกต่อไป)
            if (!$isAdmin) {
                jsonResponse(['success'=>false,'message'=>'ไม่มีสิทธิ์ Void: ต้องเข้าสู่ระบบด้วย User ที่มี Role = Admin เท่านั้น']);
            }
            $adminUser = $currentUser;
            $orderItemId = (int)($_POST['order_item_id'] ?? 0);
            $requestedQty = (int)($_POST['void_qty'] ?? 0); // จำนวนที่ต้องการ Void เฉพาะรายการนี้ (0 = ไม่ระบุ จะ Void เท่าที่เหลือทั้งหมดของรายการนี้)
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($orderItemId <= 0) {
                jsonResponse(['success'=>false,'message'=>'ไม่พบรายการสินค้าที่ต้องการ Void']);
            }
            if ($reason === '') $reason = 'Void สินค้า';

            $conn->begin_transaction();
            try {
                // Void ได้เฉพาะบิลของวันนี้เท่านั้น
                $todayStart = date('Y-m-d') . ' 00:00:00';
                $tomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

                // ล็อกเฉพาะแถวรายการสินค้าที่ต้องการ Void (ไม่ใช่ทั้งบิล)
                $item=$conn->prepare("SELECT oi.id,oi.order_id,oi.product_id,oi.quantity,COALESCE(oi.voided_qty,0) voided_qty,oi.price,
                                             COALESCE(p.name,CONCAT('สินค้า #',oi.product_id)) product_name,
                                             o.receipt_no,o.total_amount
                                      FROM order_items oi
                                      JOIN orders o ON o.id=oi.order_id
                                      LEFT JOIN products p ON p.id=oi.product_id
                                      WHERE oi.id=? AND o.created_at>=? AND o.created_at<?
                                      FOR UPDATE");
                $item->bind_param('iss',$orderItemId,$todayStart,$tomorrowStart);
                if(!$item->execute()){ $err=$item->error; $item->close(); throw new Exception('อ่านข้อมูลรายการไม่สำเร็จ: '.$err); }
                $r=$item->get_result()->fetch_assoc();
                $item->close();
                if(!$r) throw new Exception('Void ได้เฉพาะรายการของวันนี้เท่านั้น ไม่สามารถ Void รายการย้อนหลังได้');

                $orderId=(int)$r['order_id'];
                $receiptNo=(string)($r['receipt_no']??'');
                $authorizedBy=(string)($adminUser['username']??$currentUser['username']??'admin');
                $staffName=(string)($currentUser['fullname']??$currentUser['username']??$authorizedBy);
                $pid=(int)$r['product_id'];
                $productName=(string)$r['product_name'];
                $qty=(int)$r['quantity'];
                $already=(int)$r['voided_qty'];
                $remaining=max(0,$qty-$already);

                if($remaining<=0) throw new Exception('รายการนี้ถูก Void ครบแล้ว');

                // ถ้าไม่ระบุจำนวน ให้ Void เท่าที่เหลือทั้งหมดของรายการนี้ (ไม่ใช่ทั้งบิลอีกต่อไป)
                $voidQty = $requestedQty > 0 ? $requestedQty : $remaining;
                if ($voidQty > $remaining) {
                    throw new Exception('จำนวนที่ Void เกินกว่าจำนวนคงเหลือของรายการนี้ (คงเหลือ '.$remaining.' ชิ้น)');
                }

                // คืน Stock เฉพาะจำนวนที่ Void
                $stock=$conn->prepare("UPDATE products SET stock=stock+? WHERE id=?");
                $stock->bind_param('ii',$voidQty,$pid);
                if(!$stock->execute()){ $err=$stock->error; $stock->close(); throw new Exception('คืน Stock ไม่สำเร็จ: '.$err); }
                $stock->close();
                $newStockStmt=$conn->prepare("SELECT stock FROM products WHERE id=?"); $newStockStmt->bind_param('i',$pid); $newStockStmt->execute(); $newStock=(int)$newStockStmt->get_result()->fetch_assoc()['stock']; $newStockStmt->close();
                $beforeStock=$newStock-$voidQty; $who=(string)($authorizedBy); $type='VOID'; $refType='void'; $reasonMv='คืน Stock จาก Void';
                $mv=$conn->prepare("INSERT INTO stock_movements(product_id,movement_type,quantity,stock_before,stock_after,reference_type,reference_id,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?)");
                $mv->bind_param('isiiisiss',$pid,$type,$voidQty,$beforeStock,$newStock,$refType,$orderId,$reasonMv,$who); $mv->execute(); $mv->close();

                // สะสมจำนวนที่ Void ไปแล้วของรายการนี้ (ไม่ใช่ตั้งเป็นเต็มจำนวนเสมอไป เพื่อรองรับการ Void บางส่วนหลายครั้ง)
                $voidedNew = $already + $voidQty;
                $vi=$conn->prepare("UPDATE order_items SET voided_qty=?,void_reason=?,voided_at=NOW(),voided_by=? WHERE id=?");
                $vi->bind_param('issi',$voidedNew,$reason,$staffName,$orderItemId);
                if(!$vi->execute()){ $err=$vi->error; $vi->close(); throw new Exception('บันทึก Void ไม่สำเร็จ: '.$err); }
                $vi->close();

                $refundLine=round((float)$r['price']*$voidQty,2);

                $log=$conn->prepare("INSERT INTO void_logs(order_id,order_item_id,product_id,product_name,void_qty,refund_amount,reason,voided_by) VALUES(?,?,?,?,?,?,?,?)");
                $log->bind_param('iiisidss',$orderId,$orderItemId,$pid,$productName,$voidQty,$refundLine,$reason,$authorizedBy);
                if(!$log->execute()){ $err=$log->error; $log->close(); throw new Exception('บันทึกประวัติ Void ไม่สำเร็จ: '.$err); }
                $log->close();

                // หมายเหตุ: ไม่ต้องปรับยอดในตาราง orders โดยตรง เพราะทุกรายงาน (sales_history, cashier_summary, cashier_close)
                // คำนวณยอดสุทธิจาก total_amount ลบผลรวม void_logs.refund_amount ของบิลนั้นอยู่แล้ว
                // ทำให้ Void บางส่วน/หลายครั้งยังคงถูกต้อง โดยไม่ต้องเขียนทับยอดเดิมของบิล

                $conn->commit();
                $isFullVoid = $voidedNew >= $qty;
                jsonResponse([
                    'success'=>true,
                    'message'=>'Void '.$voidQty.' ชิ้น ('.$productName.') เรียบร้อยแล้ว โดย Admin: '.$authorizedBy,
                    'order_id'=>$orderId,
                    'receipt_no'=>$receiptNo,
                    'refund'=>round($refundLine,2),
                    'void_qty'=>$voidQty,
                    'voided_qty_total'=>$voidedNew,
                    'remaining_qty'=>max(0,$qty-$voidedNew),
                    'full_void'=>$isFullVoid
                ]);
            } catch (Throwable $e) {
                $conn->rollback();
                jsonResponse(['success'=>false,'message'=>'Void ไม่สำเร็จ: '.$e->getMessage()]);
            }
        }

        if ($action === 'stock_report') {
            $from = trim((string)($_POST['from'] ?? ''));
            $to = trim((string)($_POST['to'] ?? ''));
            if ($from === '') $from = date('Y-m-d');
            if ($to === '') $to = $from;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from=date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to=$from;
            if($from>$to){$tmp=$from;$from=$to;$to=$tmp;}

            /*
             * STOCK report must use the real sales/order data for "ขายออก".
             * The old query relied on stock_movements OUT only, which could show 0
             * when historical orders existed but the movement record was missing.
             *
             * - stock_in   : stock movement IN within selected period
             * - stock_out  : quantity originally sold from order_items
             * - stock_void : quantity voided from order_items
             * - net_out    : sold - voided
             * - net_sales  : monetary value of non-voided sold quantity
             * - stock      : current product stock
             */
            $sql="SELECT
                        p.id,p.name,p.sku,p.barcode,p.stock,
                        COALESCE(mv.stock_in,0) stock_in,
                        COALESCE(sa.stock_out,0) stock_out,
                        COALESCE(sa.stock_void,0) stock_void,
                        COALESCE(sa.net_sales,0) net_sales
                  FROM products p
                  LEFT JOIN (
                      SELECT product_id,
                             SUM(CASE WHEN movement_type='IN' THEN quantity ELSE 0 END) stock_in
                      FROM stock_movements
                      WHERE created_at>=CONCAT(?,' 00:00:00')
                        AND created_at<DATE_ADD(CONCAT(?,' 00:00:00'),INTERVAL 1 DAY)
                      GROUP BY product_id
                  ) mv ON mv.product_id=p.id
                  LEFT JOIN (
                      SELECT oi.product_id,
                             SUM(oi.quantity) stock_out,
                             SUM(COALESCE(oi.voided_qty,0)) stock_void,
                             SUM(oi.price*GREATEST(0,oi.quantity-COALESCE(oi.voided_qty,0))*CASE WHEN o.vat_included=1 THEN 100/107 ELSE 1 END) net_sales
                      FROM order_items oi
                      INNER JOIN orders o ON o.id=oi.order_id
                      WHERE o.created_at>=CONCAT(?,' 00:00:00')
                        AND o.created_at<DATE_ADD(CONCAT(?,' 00:00:00'),INTERVAL 1 DAY)
                      GROUP BY oi.product_id
                  ) sa ON sa.product_id=p.id
                  ORDER BY p.name ASC";
            $st=$conn->prepare($sql);
            if(!$st) jsonResponse(['success'=>false,'message'=>'ไม่สามารถเตรียม Query รายงาน STOCK ได้: '.$conn->error]);
            $st->bind_param('ssss',$from,$to,$from,$to);
            $st->execute();
            $rs=$st->get_result();
            $rows=[];
            while($r=$rs->fetch_assoc()){
                $r['stock_in']=(int)$r['stock_in'];
                $r['stock_out']=(int)$r['stock_out'];
                $r['stock_void']=(int)$r['stock_void'];
                $r['stock']=(int)$r['stock'];
                // ขายสุทธิ = ขายออก - Void และอนุญาตให้ติดลบตามข้อมูลจริง
                $r['net_out']=$r['stock_out']-$r['stock_void'];
                $r['net_sales']=(float)$r['net_sales'];
                $rows[]=$r;
            }
            $st->close();
            jsonResponse(['success'=>true,'data'=>$rows,'from'=>$from,'to'=>$to]);
        }

        if ($action === 'sales_history') {
            $from = trim((string)($_POST['from'] ?? ''));
            $to = trim((string)($_POST['to'] ?? ''));
            $receiptSearch = trim((string)($_POST['receipt_no'] ?? ''));

            // รายงานย้อนหลังต้องยึดช่วงวันที่จากหน้าจออย่างเคร่งครัด
            // หากไม่ระบุวันที่ ให้ใช้วันนี้เท่านั้น เพื่อป้องกันข้อมูลวันอื่นหลุดเข้ารายงาน
            if ($from === '') $from = date('Y-m-d');
            if ($to === '') $to = $from;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;
            if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

            $sql = "SELECT
                        o.id order_id,o.receipt_no,o.total_amount,o.subtotal_amount,o.discount_amount,o.vat_amount,
                        o.cash_given,o.change_amount,o.payment_method,o.created_at,
                        COALESCE(vt.void_subtotal,0) void_total,
                        GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) net_order_total,
                        GREATEST(0,o.vat_amount-CASE WHEN o.vat_included=1 THEN COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*7/107,2),0) ELSE COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END) net_vat_amount,
                        GREATEST(0,GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))-GREATEST(0,o.vat_amount-CASE WHEN o.vat_included=1 THEN COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*7/107,2),0) ELSE COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) net_subtotal_amount,
                        oi.id order_item_id,oi.product_id,oi.price,oi.quantity,COALESCE(oi.voided_qty,0) voided_qty,
                        (oi.price*GREATEST(0,oi.quantity-COALESCE(oi.voided_qty,0))*CASE WHEN o.vat_included=1 THEN 100/107 ELSE 1 END) net_line_total,
                        oi.price*CASE WHEN o.vat_included=1 THEN 100/107 ELSE 1 END price_exvat,
                        oi.total,COALESCE(p.name, CONCAT('สินค้า #',oi.product_id)) name,COALESCE(p.barcode,'') barcode,oi.void_reason,oi.voided_at,oi.voided_by
                    FROM orders o
                    JOIN order_items oi ON oi.order_id=o.id
                    LEFT JOIN products p ON p.id=oi.product_id
                    LEFT JOIN (
                        SELECT order_id, SUM(refund_amount) AS void_subtotal
                        FROM void_logs
                        GROUP BY order_id
                    ) vt ON vt.order_id=o.id
                    WHERE ((oi.quantity-COALESCE(oi.voided_qty,0)) > 0 OR COALESCE(oi.voided_qty,0) > 0)";
            $types = '';
            $params = [];

            if ($from !== '') {
                $sql .= " AND o.created_at >= CONCAT(?, ' 00:00:00')";
                $types .= 's'; $params[] = $from;
            }
            if ($to !== '') {
                $sql .= " AND o.created_at < DATE_ADD(CONCAT(?, ' 00:00:00'), INTERVAL 1 DAY)";
                $types .= 's'; $params[] = $to;
            }
            if ($receiptSearch !== '') {
                $sql .= " AND o.receipt_no LIKE ?";
                $types .= 's'; $params[] = '%' . $receiptSearch . '%';
            }
            // ใช้ช่วงเวลาแบบปิดท้ายวัน เพื่อไม่ให้ข้อมูลวันถัดไปปะปน
            $sql .= " ORDER BY o.created_at DESC, o.id DESC, oi.id ASC";

            $stmt = $conn->prepare($sql);
            if ($types !== '') $stmt->bind_param($types,...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = [];
            while ($r=$result->fetch_assoc()) $rows[]=$r;
            $stmt->close();

            jsonResponse(['success'=>true,'data'=>$rows]);
        }

        if ($action === 'void_search_today') {
            // ค้นหา Void ได้เฉพาะรายการของวันนี้เท่านั้น ไม่อนุญาตให้ค้นหาย้อนหลัง
            $receiptSearch = trim((string)($_POST['receipt_no'] ?? ''));
            $sql = "SELECT vl.id,vl.order_id,vl.order_item_id,vl.product_id,vl.product_name,vl.void_qty,
                           vl.refund_amount,vl.reason,vl.voided_by,vl.created_at,
                           o.receipt_no
                    FROM void_logs vl
                    JOIN orders o ON o.id=vl.order_id
                    WHERE DATE(vl.created_at)=CURDATE()";
            $types=''; $params=[];
            if($receiptSearch!==''){
                $sql .= " AND o.receipt_no LIKE ?";
                $types='s'; $params[]='%'.$receiptSearch.'%';
            }
            $sql .= " ORDER BY vl.id DESC";
            $stmt=$conn->prepare($sql);
            if($types!=='') $stmt->bind_param($types,...$params);
            $stmt->execute(); $result=$stmt->get_result(); $rows=[];
            while($r=$result->fetch_assoc()) $rows[]=$r;
            $stmt->close();
            jsonResponse(['success'=>true,'data'=>$rows,'date'=>date('Y-m-d')]);
        }

        if ($action === 'void_sales_today') {
            // หน้า Void แสดงเฉพาะเมนู/บิลที่ขายใน "วันนี้" เท่านั้น
            // ไม่อนุญาตให้ส่งวันที่จาก Client เพื่อป้องกันการค้นหาย้อนหลัง
            $receiptSearch = trim((string)($_POST['receipt_no'] ?? ''));
            $todayStart = date('Y-m-d') . ' 00:00:00';
            $tomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

            $sql = "SELECT o.id AS order_id, o.receipt_no, o.created_at, o.payment_method,
                           o.total_amount AS order_total,
                           oi.id AS order_item_id, oi.product_id, oi.quantity,
                           COALESCE(oi.voided_qty,0) AS voided_qty, oi.price,
                           (oi.price * GREATEST(0, oi.quantity-COALESCE(oi.voided_qty,0))) AS net_line_total,
                           COALESCE(p.name, CONCAT('สินค้า #',oi.product_id)) AS product_name,
                           oi.void_reason, oi.voided_at, oi.voided_by
                    FROM orders o
                    JOIN order_items oi ON oi.order_id=o.id
                    LEFT JOIN products p ON p.id=oi.product_id
                    WHERE o.created_at>=? AND o.created_at<?";
            $types='ss';
            $params=[$todayStart,$tomorrowStart];
            if($receiptSearch!==''){
                $sql .= " AND o.receipt_no LIKE ?";
                $types.='s';
                $params[]='%'.$receiptSearch.'%';
            }
            $sql .= " ORDER BY o.created_at DESC, o.id DESC, oi.id ASC";
            $stmt=$conn->prepare($sql);
            if(!$stmt) jsonResponse(['success'=>false,'message'=>'เตรียมคำสั่งค้นหาบิลวันนี้ไม่สำเร็จ: '.$conn->error]);
            $stmt->bind_param($types,...$params);
            if(!$stmt->execute()){
                $err=$stmt->error; $stmt->close();
                jsonResponse(['success'=>false,'message'=>'ค้นหาบิลวันนี้ไม่สำเร็จ: '.$err]);
            }
            $result=$stmt->get_result();
            $rows=[];
            while($r=$result->fetch_assoc()) $rows[]=$r;
            $stmt->close();
            jsonResponse(['success'=>true,'data'=>$rows,'date'=>date('Y-m-d')]);
        }

        if ($action === 'void_report') {
            $from = trim((string)($_POST['from'] ?? ''));
            $to = trim((string)($_POST['to'] ?? ''));
            if ($from === '') $from = date('Y-m-d');
            if ($to === '') $to = $from;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from=date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to=$from;
            if ($from>$to) { $tmp=$from; $from=$to; $to=$tmp; }
            $sql="SELECT vl.id AS void_id,vl.order_id,vl.order_item_id,vl.product_id,vl.product_name,vl.void_qty,vl.refund_amount,CASE WHEN o.vat_included=1 THEN ROUND(vl.refund_amount*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*7/107,2) ELSE ROUND(vl.refund_amount*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2) END AS void_vat_amount,CASE WHEN o.vat_included=1 THEN ROUND(vl.refund_amount*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END),2) ELSE ROUND(vl.refund_amount+ROUND(vl.refund_amount*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),2) END AS void_gross,vl.reason,vl.voided_by,vl.created_at AS voided_at,o.receipt_no,o.payment_method,o.created_at AS sale_created_at,COALESCE(NULLIF(o.created_by,''),vl.voided_by,'ไม่ระบุ') AS seller_username,COALESCE(NULLIF(u.fullname,''),NULLIF(o.created_by,''),vl.voided_by,'ไม่ระบุ') AS seller_name FROM void_logs vl INNER JOIN orders o ON o.id=vl.order_id LEFT JOIN users u ON u.username COLLATE utf8mb4_general_ci=COALESCE(NULLIF(o.created_by,''),vl.voided_by) COLLATE utf8mb4_general_ci WHERE vl.created_at>=CONCAT(?,' 00:00:00') AND vl.created_at<DATE_ADD(CONCAT(?,' 00:00:00'),INTERVAL 1 DAY) ORDER BY vl.created_at DESC,vl.id DESC";
            $stmt=$conn->prepare($sql);
            if(!$stmt) jsonResponse(['success'=>false,'message'=>'ไม่สามารถเตรียมรายงาน Void ได้: '.$conn->error]);
            $stmt->bind_param('ss',$from,$to);
            if(!$stmt->execute()){ $err=$stmt->error; $stmt->close(); jsonResponse(['success'=>false,'message'=>'โหลดรายงาน Void ไม่สำเร็จ: '.$err]); }
            $rs=$stmt->get_result(); $rows=[]; $voidTotal=0.0; $voidVatTotal=0.0; $voidQty=0; $billSet=[];
            while($r=$rs->fetch_assoc()){
                $r['void_qty']=(int)$r['void_qty'];
                $r['void_vat_amount']=(float)($r['void_vat_amount']??0);
                $r['refund_amount_incl_vat']=(float)($r['void_gross']??0);
                $r['refund_amount']=round($r['refund_amount_incl_vat']-$r['void_vat_amount'],2); // ยอดก่อน VAT
                $rows[]=$r;
                $voidQty+=(int)$r['void_qty'];
                $voidTotal+=(float)$r['refund_amount'];
                $voidVatTotal+=$r['void_vat_amount'];
                $billSet[(string)$r['order_id']]=true;
            }
            $stmt->close();
            jsonResponse(['success'=>true,'data'=>$rows,'from'=>$from,'to'=>$to,'void_qty'=>$voidQty,'void_total'=>round($voidTotal,2),'void_vat_total'=>round($voidVatTotal,2),'void_total_incl_vat'=>round($voidTotal+$voidVatTotal,2),'void_bill_count'=>count($billSet)]);
        }

        if ($action === 'cashier_summary') {
            // ยอดขายสุทธิของ Cashier = ยอดรวมที่ลูกค้าชำระจริง (รวม VAT 7%) หลังหัก Void แบบรวม VAT แล้ว
            // ห้ามหัก VAT 7% ออกจาก sales_total / cash_sales / qr_sales
            // รายงานย้อนหลัง: ดึงยอดตาม User ที่เป็นผู้ขายของแต่ละบิล
            // ถ้าเป็นข้อมูลเก่าไม่มี orders.created_by ให้ fallback ไปที่ stock_movements.created_by
            $from = trim((string)($_POST['from'] ?? ''));
            $to   = trim((string)($_POST['to'] ?? ''));
            $dateMode = ($from !== '' || $to !== '');

            if ($dateMode) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $from;
                if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }
            }

            $last = $conn->query("SELECT created_at FROM cashier_closings ORDER BY id DESC LIMIT 1");
            $lastRow = $last ? $last->fetch_assoc() : null;
            $lastClose = $lastRow['created_at'] ?? null;

            $where = " WHERE 1=1 ";
            $params=[]; $types='';
            if ($dateMode) {
                $where .= " AND o.created_at >= CONCAT(?, ' 00:00:00')
                            AND o.created_at < DATE_ADD(CONCAT(?, ' 00:00:00'), INTERVAL 1 DAY)";
                $types='ss'; $params=[$from,$to];
            } elseif ($lastClose) {
                $where .= " AND o.created_at > ?";
                $types='s'; $params[]=$lastClose;
            }

            // seller_map ป้องกันยอดบิลถูกนับซ้ำจาก stock_movements หลายรายการในบิลเดียว
            $sql = "SELECT
                        COALESCE(NULLIF(o.created_by,''), NULLIF(sm.created_by,''), 'ไม่ระบุ') AS seller_username,
                        COALESCE(NULLIF(u.fullname,''), NULLIF(o.created_by,''), NULLIF(sm.created_by,''), 'ไม่ระบุ') AS seller_name,
                        COUNT(DISTINCT CASE WHEN GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))>0 THEN o.id END) AS bill_count,
                        COALESCE(SUM(GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))),0) AS sales_total,
                        COALESCE(SUM(CASE WHEN o.payment_method='cash' THEN GREATEST(0,(o.cash_given-o.change_amount)-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) ELSE 0 END),0) AS cash_sales,
                        COALESCE(COUNT(DISTINCT CASE WHEN o.payment_method='cash' AND GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))>0 THEN o.id END),0) AS cash_bill_count,
                        COALESCE(SUM(CASE WHEN o.payment_method='qr' THEN GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) ELSE 0 END),0) AS qr_sales,
                        COALESCE(COUNT(DISTINCT CASE WHEN o.payment_method='qr' AND GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))>0 THEN o.id END),0) AS qr_bill_count,
                        COALESCE(SUM(GREATEST(0,o.vat_amount-CASE WHEN o.vat_included=1 THEN COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*7/107,2),0) ELSE COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)),0) AS vat_total
                    FROM orders o
                    LEFT JOIN (
                        SELECT reference_id AS order_id, MAX(NULLIF(created_by,'')) AS created_by
                        FROM stock_movements
                        WHERE reference_type='order' AND reference_id IS NOT NULL
                        GROUP BY reference_id
                    ) sm ON sm.order_id=o.id
                    LEFT JOIN users u ON u.username COLLATE utf8mb4_general_ci = COALESCE(NULLIF(o.created_by,''), NULLIF(sm.created_by,'')) COLLATE utf8mb4_general_ci
                    LEFT JOIN (
                        SELECT order_id, SUM(refund_amount) AS void_subtotal
                        FROM void_logs
                        GROUP BY order_id
                    ) vt ON vt.order_id=o.id
                    $where
                    GROUP BY seller_username,seller_name
                    ORDER BY seller_name ASC, seller_username ASC";

            $stmt=$conn->prepare($sql);
            if(!$stmt) jsonResponse(['success'=>false,'message'=>'ไม่สามารถเตรียม Query Cashier ได้: '.$conn->error]);
            if($types) $stmt->bind_param($types,...$params);
            $stmt->execute(); $result=$stmt->get_result(); $byUser=[];
            while($r=$result->fetch_assoc()){
                $salesTotal=(float)$r['sales_total'];
                $vatTotal=(float)$r['vat_total'];
                $byUser[]=[
                    'username'=>(string)$r['seller_username'],
                    'fullname'=>(string)$r['seller_name'],
                    'bill_count'=>(int)$r['bill_count'],
                    'sales_total'=>$salesTotal,
                    'vat_total'=>$vatTotal,
                    'net_sales_exvat'=>round($salesTotal-$vatTotal,2),
                    'cash_sales'=>(float)$r['cash_sales'],
                    'cash_bill_count'=>(int)$r['cash_bill_count'],
                    'qr_sales'=>(float)$r['qr_sales'],
                    'qr_bill_count'=>(int)$r['qr_bill_count']
                ];
            }
            $stmt->close();

            $tot=['bill_count'=>0,'sales_total'=>0.0,'vat_total'=>0.0,'cash_sales'=>0.0,'cash_bill_count'=>0,'qr_sales'=>0.0,'qr_bill_count'=>0];
            foreach($byUser as $u){ foreach(['bill_count','sales_total','vat_total','cash_sales','cash_bill_count','qr_sales','qr_bill_count'] as $k) $tot[$k]+=$u[$k]; }

            $cashOutTotal=0.0;
            if($lastClose){ $qco=$conn->prepare("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs WHERE created_at > ?"); $qco->bind_param('s',$lastClose); $qco->execute(); $cashOutTotal=(float)($qco->get_result()->fetch_assoc()['cash_out']??0); $qco->close(); }
            else if(!$dateMode){ $qco=$conn->query("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs"); $cashOutTotal=(float)($qco?$qco->fetch_assoc()['cash_out']:0); }

            jsonResponse(['success'=>true,'data'=>[
                'last_close'=>$dateMode ? null : $lastClose,
                'from'=>$dateMode ? $from : null,
                'to'=>$dateMode ? $to : null,
                'bill_count'=>(int)$tot['bill_count'],
                'sales_total'=>(float)$tot['sales_total'],
                'vat_total'=>(float)$tot['vat_total'],
                'net_sales_exvat'=>round($tot['sales_total']-$tot['vat_total'],2),
                'cash_sales'=>(float)$tot['cash_sales'],
                'cash_bill_count'=>(int)$tot['cash_bill_count'],
                'qr_sales'=>(float)$tot['qr_sales'],
                'qr_bill_count'=>(int)$tot['qr_bill_count'],
                'cash_out_total'=>$cashOutTotal,
                'cash_available'=>round(max(0,$tot['cash_sales']-$cashOutTotal),2),
                'by_user'=>$byUser
            ]]);
        }

        if ($action === 'cashier_cash_out') {
            $denoms = json_decode($_POST['denominations'] ?? '{}', true);
            $note = trim((string)($_POST['note'] ?? 'นำเงินออกจากลิ้นชัก'));
            if (!is_array($denoms)) $denoms=[];
            $denomValues=[1000,500,100,50,20,10,5,2,1,0.50,0.25];
            $amount=0.0;
            foreach($denomValues as $v){
                $k=(string)$v; $q=max(0,(int)($denoms[$k] ?? 0));
                $denoms[$k]=$q; $amount += $v*$q;
            }
            $amount=round($amount,2);
            if($amount<=0) jsonResponse(['success'=>false,'message'=>'กรุณาระบุจำนวนเงินที่ต้องการนำออก']);
            $last=$conn->query("SELECT created_at FROM cashier_closings ORDER BY id DESC LIMIT 1");
            $lastRow=$last?$last->fetch_assoc():null; $lastClose=$lastRow['created_at']??null;
            $where=$lastClose ? " AND o.created_at > ?" : '';
            $sql="SELECT COALESCE(SUM(CASE WHEN o.payment_method='cash' THEN GREATEST(0,(o.cash_given-o.change_amount)-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) ELSE 0 END),0) AS cash_sales
                  FROM orders o LEFT JOIN (SELECT order_id,SUM(refund_amount) AS void_subtotal FROM void_logs GROUP BY order_id) vt ON vt.order_id=o.id WHERE 1=1 $where";
            $stmt=$conn->prepare($sql);
            if(!$stmt) jsonResponse(['success'=>false,'message'=>'เตรียมข้อมูลเงินสดไม่สำเร็จ: '.$conn->error]);
            if($lastClose) $stmt->bind_param('s',$lastClose);
            if(!$stmt->execute()){ $e=$stmt->error; $stmt->close(); jsonResponse(['success'=>false,'message'=>'อ่านยอดเงินสดไม่สำเร็จ: '.$e]); }
            $cashSales=(float)($stmt->get_result()->fetch_assoc()['cash_sales']??0); $stmt->close();
            $alreadyOut=0.0;
            if($lastClose){ $q=$conn->prepare("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs WHERE created_at > ?"); $q->bind_param('s',$lastClose); $q->execute(); $alreadyOut=(float)($q->get_result()->fetch_assoc()['cash_out']??0); $q->close(); }
            else { $q=$conn->query("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs"); $alreadyOut=(float)($q?$q->fetch_assoc()['cash_out']:0); }
            $available=round($cashSales-$alreadyOut,2);
            if($amount>$available+0.001) jsonResponse(['success'=>false,'message'=>'เงินสดสำหรับรอบนี้ไม่เพียงพอ\nเงินสดที่มีให้ถอน ฿'.number_format(max(0,$available),2).'\nถอนที่ต้องการ ฿'.number_format($amount,2)]);
            $no='OUT-'.date('Ymd-His').'-'.str_pad((string)mt_rand(0,999),3,'0',STR_PAD_LEFT);
            $uid=(int)($currentUser['id']??0); $un=(string)($currentUser['username']??''); $fn=(string)($currentUser['fullname']??$un);
            $json=json_encode($denoms,JSON_UNESCAPED_UNICODE);
            $st=$conn->prepare("INSERT INTO cashier_cash_outs(cash_out_no,staff_id,staff_username,staff_name,amount,denominations_json,note) VALUES(?,?,?,?,?,?,?)");
            if(!$st) jsonResponse(['success'=>false,'message'=>'บันทึกเงินออกไม่สำเร็จ: '.$conn->error]);
            $st->bind_param('sissdss',$no,$uid,$un,$fn,$amount,$json,$note);
            if(!$st->execute()){ $e=$st->error; $st->close(); jsonResponse(['success'=>false,'message'=>'บันทึกเงินออกไม่สำเร็จ: '.$e]); }
            $st->close();
            jsonResponse(['success'=>true,'message'=>'นำเงินออกเรียบร้อยแล้ว','data'=>['cash_out_no'=>$no,'amount'=>$amount,'available_after'=>round($available-$amount,2),'staff_name'=>$fn,'created_at'=>date('Y-m-d H:i:s'),'denominations'=>$denoms]]);
        }

        if ($action === 'cashier_close') {
            // sales_total เป็นยอดรวมหลังหัก Void แต่ยังรวม VAT 7% อยู่เสมอ
            // ไม่หัก VAT ออกจากยอดขายสุทธิ
            $denoms = json_decode($_POST['denominations'] ?? '{}', true);
            $cashOutDenoms = json_decode($_POST['cash_out_denominations'] ?? '{}', true);
            if (!is_array($denoms)) $denoms=[];
            if (!is_array($cashOutDenoms)) $cashOutDenoms=[];

            $last = $conn->query("SELECT created_at FROM cashier_closings ORDER BY id DESC LIMIT 1");
            $lastRow = $last ? $last->fetch_assoc() : null;
            $lastClose = $lastRow['created_at'] ?? null;

            $sql = "SELECT
                        COUNT(DISTINCT CASE WHEN GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))>0 THEN o.id END) bill_count,
                        COALESCE(SUM(GREATEST(0,o.total_amount-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END))),0) sales_total,
                        COALESCE(SUM(GREATEST(0,o.vat_amount-CASE WHEN o.vat_included=1 THEN COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*7/107,2),0) ELSE COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)),0) vat_total,
                        COALESCE(SUM(CASE WHEN o.payment_method='cash' THEN GREATEST(0,(o.cash_given-o.change_amount)-(CASE WHEN o.vat_included=1 THEN COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END) ELSE COALESCE(vt.void_subtotal,0)+COALESCE(ROUND(COALESCE(vt.void_subtotal,0)*GREATEST(0,1-CASE WHEN o.subtotal_amount>0 THEN o.discount_amount/o.subtotal_amount ELSE 0 END)*0.07,2),0) END)) ELSE 0 END),0) cash_sales
                    FROM orders o
                    LEFT JOIN (
                        SELECT order_id, SUM(refund_amount) AS void_subtotal
                        FROM void_logs
                        GROUP BY order_id
                    ) vt ON vt.order_id=o.id
                    WHERE 1=1";
            $params=[]; $types='';
            if ($lastClose) { $sql .= " AND o.created_at > ?"; $types='s'; $params[]=$lastClose; }
            $stmt=$conn->prepare($sql);
            if($types) $stmt->bind_param($types,...$params);
            $stmt->execute(); $sum=$stmt->get_result()->fetch_assoc(); $stmt->close();

            $denomValues = [1000,500,100,50,20,10,5,2,1,0.50,0.25];
            $cashCounted=0; $cashOut=0;
            foreach($denomValues as $v){
                $k=(string)$v;
                $qty=max(0,(int)($denoms[$k] ?? 0));
                $out=max(0,(int)($cashOutDenoms[$k] ?? 0));
                $cashCounted += $v*$qty;
                $cashOut += $v*$out;
                $denoms[$k]=$qty;
                $cashOutDenoms[$k]=$out;
            }
            $expectedGross=(float)($sum['cash_sales']??0);
            $cashOutStandalone=0.0;
            if($lastClose){ $qOut=$conn->prepare("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs WHERE created_at > ?"); $qOut->bind_param('s',$lastClose); $qOut->execute(); $cashOutStandalone=(float)($qOut->get_result()->fetch_assoc()['cash_out']??0); $qOut->close(); }
            else { $qOut=$conn->query("SELECT COALESCE(SUM(amount),0) cash_out FROM cashier_cash_outs"); $cashOutStandalone=(float)($qOut?$qOut->fetch_assoc()['cash_out']:0); }
            $expected=round(max(0,$expectedGross-$cashOutStandalone),2);
            $variance=round($cashCounted+$cashOut-$expected,2);
            $closingNo='CASH-'.date('Ymd-His');
            $staffId=(int)($currentUser['id']??0);
            $staffUsername=(string)($currentUser['username']??'');
            $staffName=(string)($currentUser['fullname']??$staffUsername);
            $json=json_encode(['counted'=>$denoms,'cash_out'=>$cashOutDenoms],JSON_UNESCAPED_UNICODE);

            $salesTotal=(float)($sum['sales_total']??0); $cashSales=$expected; $closingDate=date('Y-m-d');
            $stmt=$conn->prepare("INSERT INTO cashier_closings
                (closing_no,closing_date,staff_id,staff_username,staff_name,sales_total,vat_total,cash_sales,expected_cash,cash_counted,cash_out,variance,denominations_json)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $bindTypes = 'ssissddddddds'; $vatTotalClose=(float)($sum['vat_total']??0);
            $stmt->bind_param($bindTypes,$closingNo,$closingDate,$staffId,$staffUsername,$staffName,$salesTotal,$vatTotalClose,$cashSales,$expected,$cashCounted,$cashOut,$variance,$json);
            $stmt->execute(); $stmt->close();

            jsonResponse(['success'=>true,'message'=>'ปิดรอบ Cashier เรียบร้อยแล้ว','data'=>[
                'closing_no'=>$closingNo,'closing_date'=>$closingDate,'staff_name'=>$staffName,
                'bill_count'=>(int)($sum['bill_count']??0),'sales_total'=>$salesTotal,'vat_total'=>$vatTotalClose,'net_sales_exvat'=>round($salesTotal-$vatTotalClose,2),'cash_sales'=>$cashSales,
                'expected_cash'=>$expected,'cash_counted'=>$cashCounted,'cash_out'=>$cashOut,'variance'=>$variance,
                'denominations'=>$denoms,'cash_out_denominations'=>$cashOutDenoms
            ]]);
        }

        if ($action === 'cashier_last') {
            $r=$conn->query("SELECT * FROM cashier_closings ORDER BY id DESC LIMIT 1");
            $row=$r?$r->fetch_assoc():null;
            if(!$row) jsonResponse(['success'=>false,'message'=>'ยังไม่มีข้อมูลปิดรอบ Cashier']);
            $j=json_decode($row['denominations_json']??'{}',true);
            jsonResponse(['success'=>true,'data'=>[
                'closing_no'=>$row['closing_no'],'closing_date'=>$row['closing_date'],'staff_name'=>$row['staff_name'],
                'sales_total'=>(float)$row['sales_total'],'vat_total'=>(float)($row['vat_total']??0),'net_sales_exvat'=>round((float)$row['sales_total']-(float)($row['vat_total']??0),2),'cash_sales'=>(float)$row['cash_sales'],
                'expected_cash'=>(float)$row['expected_cash'],'cash_counted'=>(float)$row['cash_counted'],
                'cash_out'=>(float)$row['cash_out'],'variance'=>(float)$row['variance'],
                'denominations'=>$j['counted']??[],'cash_out_denominations'=>$j['cash_out']??[]
            ]]);
        }

        if ($action === 'sales_session_summary') {
            $date = date('Y-m-d');
            $sessionStart = null;

            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='sales_session_start' LIMIT 1");
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($r && !empty($r['setting_value'])) {
                $sessionStart = $r['setting_value'];
            } else {
                $sessionStart = $date . ' 00:00:00';
            }

            // หน้าปิดการขายแสดงเฉพาะยอดของรอบปัจจุบัน และไม่ดึงยอดจากวันก่อนมาแสดง
            $todayStart = $date . ' 00:00:00';
            if (strtotime($sessionStart) < strtotime($todayStart)) {
                $sessionStart = $todayStart;
            }

            $stmt = $conn->prepare(
                "INSERT INTO settings(setting_key,setting_value)
                 VALUES('sales_session_start',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            );
            // SQL มี placeholder (?) เพียง 1 ตัว จึงต้อง bind parameter เพียง 1 ค่า
            $stmt->bind_param("s",$sessionStart);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare(
                "SELECT
                    COALESCE(SUM(GREATEST(0,o.total_amount-COALESCE(v.void_total,0))),0) total_sales,
                    COUNT(CASE WHEN GREATEST(0,o.total_amount-COALESCE(v.void_total,0)) > 0 THEN o.id END) bill_count,
                    COALESCE(SUM(CASE WHEN o.payment_method='cash'
                        THEN GREATEST(0,(o.cash_given-o.change_amount)-COALESCE(v.void_total,0))
                        ELSE 0 END),0) cash_total
                 FROM orders o
                 LEFT JOIN (SELECT order_id,SUM(refund_amount) void_total FROM void_logs GROUP BY order_id) v ON v.order_id=o.id
                 WHERE o.created_at >= ? AND o.created_at < DATE_ADD(?, INTERVAL 1 DAY)"
            );
            $stmt->bind_param("ss",$sessionStart,$date);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            jsonResponse(['success'=>true,'data'=>[
                'total_sales'=>(float)($summary['total_sales']??0),
                'bill_count'=>(int)($summary['bill_count']??0),
                'cash_total'=>(float)($summary['cash_total']??0),
                'session_start'=>$sessionStart
            ]]);
        }

        if ($action === 'close_day') {
            // ปิด "รอบขาย" ปัจจุบัน ไม่ลบประวัติการขาย
            // หลังปิดแล้วรอบใหม่จะเริ่มนับจากเวลานี้ ทำให้ยอดบนหน้าปิดวันกลับเป็น 0
            $date = date('Y-m-d');
            $now = date('Y-m-d H:i:s');

            // อ่านเวลาเริ่มรอบปัจจุบัน ถ้ายังไม่มีให้เริ่มตั้งแต่ต้นวัน
            $sessionStart = null;
            $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key='sales_session_start' LIMIT 1");
            $stmt->execute();
            $r = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($r && !empty($r['setting_value'])) {
                $sessionStart = $r['setting_value'];
            }
            if (!$sessionStart) {
                $sessionStart = $date . ' 00:00:00';
            }

            // ต้องปิดรอบ Cashier ก่อน จึงจะปิดการขายได้
            // และต้องเป็นการปิด Cashier หลังจากเริ่มรอบขายปัจจุบันแล้ว
            $cashierCheck = $conn->query("SELECT created_at FROM cashier_closings ORDER BY id DESC LIMIT 1");
            $cashierCheckRow = $cashierCheck ? $cashierCheck->fetch_assoc() : null;
            $lastCashierClose = $cashierCheckRow['created_at'] ?? null;

            if (!$lastCashierClose || strtotime($lastCashierClose) < strtotime($sessionStart)) {
                jsonResponse([
                    'success'=>false,
                    'code'=>'CASHIER_NOT_CLOSED',
                    'message'=>'ไม่สามารถปิดการขายได้ กรุณาปิดรอบ Cashier ก่อน'
                ]);
            }

            // ถ้ามีการขายหลังจากปิด Cashier ล่าสุด ต้องปิด Cashier อีกครั้งก่อนปิดการขาย
            $stmt = $conn->prepare("SELECT COUNT(id) AS cnt FROM orders WHERE created_at > ?");
            $stmt->bind_param("s",$lastCashierClose);
            $stmt->execute();
            $afterCashier = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ((int)($afterCashier['cnt'] ?? 0) > 0) {
                jsonResponse([
                    'success'=>false,
                    'code'=>'CASHIER_CLOSE_REQUIRED',
                    'message'=>'มีรายการขายหลังจากปิดรอบ Cashier กรุณาปิดรอบ Cashier อีกครั้งก่อนปิดการขาย'
                ]);
            }

            // สรุปเฉพาะยอดของรอบที่กำลังจะปิด
            $stmt = $conn->prepare(
                "SELECT
                    COALESCE(SUM(GREATEST(0,o.total_amount-COALESCE(v.void_total,0))),0) total_sales,
                    COUNT(CASE WHEN GREATEST(0,o.total_amount-COALESCE(v.void_total,0)) > 0 THEN o.id END) bill_count,
                    COALESCE(SUM(CASE WHEN o.payment_method='cash'
                        THEN GREATEST(0,(o.cash_given-o.change_amount)-COALESCE(v.void_total,0))
                        ELSE 0 END),0) cash_total
                 FROM orders o
                 LEFT JOIN (SELECT order_id,SUM(refund_amount) void_total FROM void_logs GROUP BY order_id) v ON v.order_id=o.id
                 WHERE o.created_at >= ? AND o.created_at <= ?"
            );
            $stmt->bind_param("ss",$sessionStart,$now);
            $stmt->execute();
            $summary = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // บันทึกเป็นรอบแยกกัน รองรับการปิดหลายครั้งในวันเดียว
            $roundRes=$conn->query("SELECT COALESCE(MAX(round_no),0)+1 AS next_round FROM day_closings WHERE closing_date='".$conn->real_escape_string($date)."'");
            $roundRow=$roundRes?$roundRes->fetch_assoc():null;
            $roundNo=(int)($roundRow['next_round']??1);
            if($roundNo<1) $roundNo=1;
            $stmt = $conn->prepare("INSERT INTO day_closings (closing_date,round_no,start_at,end_at,total_sales,bill_count,cash_total,staff) VALUES(?,?,?,?,?,?,?,?)");
            $staff = $currentUser['fullname'] ?? $currentUser['username'] ?? 'พนักงาน 1';
            $totalSales=(float)($summary['total_sales']??0); $billCount=(int)($summary['bill_count']??0); $cashTotal=(float)($summary['cash_total']??0);
            $stmt->bind_param("sissdids",$date,$roundNo,$sessionStart,$now,$totalSales,$billCount,$cashTotal,$staff);
            $stmt->execute(); $stmt->close();

            // เริ่มรอบขายใหม่ทันที
            $stmt = $conn->prepare(
                "INSERT INTO settings(setting_key,setting_value)
                 VALUES('sales_session_start',?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            );
            $stmt->bind_param("s",$now);
            $stmt->execute();
            $stmt->close();

            jsonResponse([
                'success'=>true,
                'message'=>'ปิดการขายเรียบร้อยแล้ว ยอดรอบใหม่เป็น 0 พร้อมขายใหม่ หากต้องการดูยอดรอบที่ปิดแล้ว ให้ดูที่รายงานย้อนหลัง',
                'summary'=>$summary,
                'round_no'=>$roundNo,
                'new_session_start'=>$now,
                'new_round'=>[
                    'total_sales'=>0,
                    'bill_count'=>0,
                    'cash_total'=>0
                ]
            ]);
        }

        if ($action === 'day_closing_history') {
            $dateFrom = trim((string)($_POST['from'] ?? date('Y-m-d')));
            $dateTo = trim((string)($_POST['to'] ?? $dateFrom));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)) $dateFrom=date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)) $dateTo=$dateFrom;
            $stmt=$conn->prepare("SELECT id,closing_date,round_no,start_at,end_at,total_sales,bill_count,cash_total,staff,created_at FROM day_closings WHERE closing_date BETWEEN ? AND ? ORDER BY closing_date DESC, round_no DESC, id DESC");
            $stmt->bind_param('ss',$dateFrom,$dateTo); $stmt->execute(); $rs=$stmt->get_result(); $rows=[];
            while($r=$rs->fetch_assoc()) $rows[]=$r; $stmt->close();
            jsonResponse(['success'=>true,'data'=>$rows,'from'=>$dateFrom,'to'=>$dateTo]);
        }

        if ($action === 'settings_get') {
            $result = $conn->query("SELECT setting_key,setting_value FROM settings");
            $settings=[];
            while($r=$result->fetch_assoc()) $settings[$r['setting_key']]=$r['setting_value'];
            jsonResponse(['success'=>true,'data'=>[
                'shopName'=>$settings['shopName'] ?? '',
                'companyAddress'=>$settings['companyAddress'] ?? '',
                'companyPhone'=>$settings['companyPhone'] ?? '',
                'companyTaxId'=>$settings['companyTaxId'] ?? '',
                'promptpayId'=>$settings['promptpayId'] ?? '',
                'autoPrint'=>$settings['autoPrint'] ?? '1',
                'kitchenPrint'=>$settings['kitchenPrint'] ?? '0'
            ]]);
        }

        if ($action === 'settings_save') {
            // อัปเดตเฉพาะค่าที่ส่งมา เพื่อไม่ให้สวิตช์ Auto Print / Kitchen Print
            // เขียนทับชื่อร้าน ที่อยู่ เบอร์โทร หรือเลขภาษีด้วยค่าว่าง
            $allowedSettings = [
                'shopName','companyAddress','companyPhone','companyTaxId',
                'promptpayId','autoPrint','kitchenPrint'
            ];
            foreach ($allowedSettings as $key) {
                if (!array_key_exists($key, $_POST)) continue;
                $value = trim((string)$_POST[$key]);
                if (in_array($key, ['autoPrint','kitchenPrint'], true)) {
                    $value = $value === '1' ? '1' : '0';
                }
                $stmt = $conn->prepare(
                    "INSERT INTO settings(setting_key,setting_value)
                     VALUES(?,?)
                     ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
                );
                $stmt->bind_param("ss",$key,$value);
                $stmt->execute();
                $stmt->close();
            }
            jsonResponse(['success'=>true,'message'=>'บันทึกการตั้งค่าเรียบร้อยแล้ว']);
        }

        jsonResponse(['success'=>false,'message'=>'ไม่พบ Action']);
    } catch(Throwable $e) {
        try { $conn->rollback(); } catch(Throwable $x) {}
        jsonResponse(['success'=>false,'message'=>$e->getMessage()]);
    }
}

// โหลดสินค้า และตรวจสอบผล Query ก่อน fetch_assoc()
$result = $conn->query(
    "SELECT id, name, sku, category, cost_price, price, stock, barcode, image
     FROM products ORDER BY id ASC"
);

if ($result === false) {
    die("โหลดข้อมูลสินค้าไม่สำเร็จ: " . htmlspecialchars($conn->error));
}

$products = [];
while ($r = $result->fetch_assoc()) {
    $products[] = $r;
}
$result->free();
$productCategories = array_values(array_unique(array_filter(array_map(
    static fn($p) => trim((string)($p['category'] ?? '')),
    $products
))));
sort($productCategories, SORT_NATURAL | SORT_FLAG_CASE);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SUNTORN POS</title>
<link rel="stylesheet" href="tailwind.css">
<!-- Chart.js สำหรับแสดงกราฟ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>.qr-payment-pay{flex:1;min-height:42px;border:0;border-radius:10px;background:#0f766e;color:#fff;font-weight:800;font-size:13px;cursor:pointer;box-shadow:0 4px 12px rgba(15,118,110,.20);transition:.15s}.qr-payment-pay:hover{background:#0d9488;transform:translateY(-1px)}.qr-payment-pay:disabled{opacity:.6;cursor:not-allowed;transform:none}

*{box-sizing:border-box}
html, body {
    height: 100vh;
    overflow: hidden;
    margin: 0;
    padding: 0;
}
body{font-family:'Sarabun',sans-serif;background:#f0f4f8;color:#253047}
button,input{font-family:inherit}
.login-screen{position:fixed;inset:0;background:linear-gradient(135deg,#312e81,#4f46e5);align-items:center;justify-content:center;z-index:100000;padding:20px}
.login-card{width:380px;max-width:94vw;background:#fff;border-radius:18px;padding:30px;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center}
.login-logo{width:58px;height:58px;border-radius:50%;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:25px}
.login-card h1{font-size:22px;font-weight:800;color:#1e293b;margin:0}.login-card>p{font-size:12px;color:#64748b;margin:3px 0 18px}.login-card form{text-align:left}.login-card label{font-size:12px;font-weight:700;color:#334155;display:block;margin-top:8px}.login-card input{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:8px;padding:0 10px;margin-top:5px;outline:none}.login-card input:focus{border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
.login-card button[type=submit]{width:100%;height:42px;border:0;border-radius:8px;background:#4f46e5;color:#fff;font-weight:800;margin-top:15px;cursor:pointer}.login-card button[type=submit]:hover{background:#4338ca}.login-message{min-height:20px;text-align:center;color:#dc2626;font-size:12px;margin-top:8px}

.header{height:54px;background:#4f46e5;color:#fff;display:flex;align-items:center;padding:0 16px;box-shadow:0 2px 4px rgba(0,0,0,0.1);position:relative;z-index:10}
.brand{display:flex;align-items:center;gap:10px;width:260px}
.logo{width:34px;height:34px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;color:#4f46e5;font-size:18px;font-weight:bold}
.brand-name{font-size:16px;font-weight:800;line-height:1.1}
.brand-sub{font-size:9px;color:#c7d2fe}
.topnav{display:flex;gap:4px;align-items:center;flex:1;justify-content:center}
.topnav button{border:0;background:transparent;color:#e0e7ff;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;transition:all 0.2s}
.topnav button.active,.topnav button:hover{background:#4338ca;color:#fff}
.staff{font-size:11px;background:#4338ca;padding:5px 12px;border-radius:20px;display:flex;align-items:center;gap:6px}

.app{height:calc(100vh - 54px);display:flex;gap:10px;padding:10px;width:100vw;overflow:hidden}
.left{width:64%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.02)}
.right{width:36%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.02);min-height:0}.summary{min-height:0;overflow-y:auto;overflow-x:hidden}

.search-wrap{padding:10px 12px 6px}
.search{width:100%;height:34px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px 0 32px;font-size:12px;outline:none;background:#f8fafc}
.search:focus{border-color:#6366f1;background:#fff;box-shadow:0 0 0 3px rgba(99,102,241,0.1)}
.search-box{position:relative}.search-box i{position:absolute;left:10px;top:10px;color:#94a3b8;font-size:12px}
.chips{display:flex;gap:6px;padding:0 12px 8px}
.chip{border:1px solid #e2e8f0;border-radius:16px;padding:3px 10px;font-size:11px;background:#f8fafc;color:#64748b;cursor:pointer;font-weight:500}
.chip.active{background:#4f46e5;color:#fff;border-color:#4f46e5}

.products{padding:4px 12px 12px;overflow-y:auto;display:grid;grid-template-columns:repeat(4,1fr);gap:8px;flex:1;align-content:start}
.product{background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;text-align:left;cursor:pointer;transition:all 0.2s;display:flex;flex-direction:column;height:120px}
.product:hover{border-color:#6366f1;transform:translateY(-1px);box-shadow:0 3px 10px rgba(99,102,241,0.08)}
.product-img{height:45px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:16px}
.product-img img{width:100%;height:100%;object-fit:cover;}
.product-info{padding:5px 7px;display:flex;flex-direction:column;flex:1;justify-content:space-between}
.meta{display:flex;justify-content:space-between;font-size:9px;color:#94a3b8}
.category{background:#e0e7ff;color:#4f46e5;border-radius:3px;padding:0 3px;font-weight:600}
.product-name{font-size:11px;font-weight:700;margin-top:1px;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-bottom{display:flex;justify-content:space-between;align-items:center;margin-top:2px}
.price{color:#4f46e5;font-size:11px;font-weight:800}
.add{width:16px;height:16px;border-radius:50%;background:#e0e7ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:bold}

.cart-head{padding:10px 12px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center}
.cart-title{font-weight:800;font-size:13px;color:#1e293b}
.invoice{font-size:10px;color:#64748b;margin-top:1px}
.clear{color:#ef4444;border:0;background:transparent;font-size:11px;cursor:pointer;font-weight:600}
.cart-table{padding:6px 12px 0;flex:1;overflow-y:auto;max-height:calc(100vh - 350px)}
.cart-row{display:grid;grid-template-columns:1.5fr .7fr .8fr;align-items:center;border-bottom:1px solid #f1f5f9;min-height:36px;font-size:11px}
.cart-row.header{min-height:24px;color:#64748b;font-size:10px;font-weight:600}
.cart-name{font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:6px}
.qty-control{display:flex;align-items:center;justify-content:center;gap:4px}
.qty-control button{width:18px;height:18px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:4px;cursor:pointer;font-weight:bold;font-size:10px}
.cart-total{text-align:right;font-weight:700;color:#1e293b}
.empty{text-align:center;color:#94a3b8;padding-top:40px;font-size:12px}

.summary{border-top:1px solid #e2e8f0;padding:10px 12px;background:#f8fafc}
.toggle-row{height:24px;background:#e0e7ff;border-radius:5px;display:flex;align-items:center;justify-content:space-between;padding:0 8px;color:#4338ca;font-size:10px;margin-bottom:4px;font-weight:600}
.switch{width:22px;height:12px;border-radius:10px;background:#4f46e5;position:relative}
.switch:after{content:'';width:8px;height:8px;background:#fff;border-radius:50%;position:absolute;right:2px;top:2px}
.sumrow{display:flex;justify-content:space-between;font-size:11px;color:#64748b;height:20px;align-items:center}
.discount-input{width:46px;height:18px;border:1px solid #cbd5e1;border-radius:4px;text-align:right;font-size:11px;padding:0 4px}
.net{display:flex;justify-content:space-between;align-items:center;color:#1e293b;font-weight:800;font-size:12px;padding-top:3px;border-top:1px dashed #cbd5e1;margin-top:3px}
.net strong{font-size:16px;color:#4f46e5}

.pay-title{font-size:10px;color:#64748b;margin-top:4px;font-weight:600}
.pay-type-wrap{position:relative;margin-top:4px}
.pay-type-btn{width:100%;height:36px;border:1px solid #cbd5e1;background:#fff;border-radius:8px;font-size:11px;color:#334155;cursor:pointer;font-weight:800;display:flex;align-items:center;justify-content:space-between;padding:0 12px;box-shadow:0 1px 2px rgba(15,23,42,.05)}
.pay-type-btn:hover{border-color:#6366f1;background:#f8faff}
.pay-type-btn .selected{color:#4f46e5}
.pay-methods{display:none;grid-template-columns:1fr 1fr;gap:6px;margin-top:5px;padding:6px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px}
.pay-methods.show{display:grid}
.pay-method{height:34px;border:1px solid #cbd5e1;background:#fff;border-radius:7px;font-size:10px;color:#475569;cursor:pointer;font-weight:800}
.pay-method.active{border-color:#4f46e5;color:#4f46e5;background:#e0e7ff}

.cash-box{margin-top:4px;border:1px solid #fcd34d;background:#fffbeb;border-radius:6px;padding:5px 8px}
.cash-label{display:flex;justify-content:space-between;align-items:center;font-size:10px;font-weight:700;color:#b45309}
.cash-input{width:76px;height:24px;border:1px solid #f59e0b;border-radius:5px;text-align:right;font-size:13px;font-weight:700;color:#1e293b;background:#fff;padding:0 4px}
.quick{display:grid;grid-template-columns:repeat(6,1fr);gap:3px;margin-top:3px}
.quick button{height:20px;border:1px solid #f59e0b;background:#fff;border-radius:3px;color:#b45309;font-size:9px;font-weight:600;cursor:pointer}
.change{display:flex;justify-content:space-between;font-size:10px;font-weight:700;color:#334155;padding-top:3px;margin-top:2px;border-top:1px solid #fde68a}

.checkout{width:100%;height:36px;background:#16a34a;border:0;border-radius:6px;color:#fff;font-weight:800;font-size:11px;margin-top:5px;cursor:pointer;box-shadow:0 2px 4px rgba(22,163,74,0.2);transition:background 0.2s}
.checkout:hover{background:#15803d}

.page{display:none;width:100%;height:100%;overflow:auto;padding:0}
.page.active{display:flex;flex-direction:column}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;width:100%;flex:1;display:flex;flex-direction:column;box-shadow:0 1px 3px rgba(0,0,0,0.02);overflow:hidden}
.table-container{flex:1;overflow:auto;border:1px solid #f1f5f9;border-radius:8px;margin-top:10px}
.table{width:100%;border-collapse:collapse;font-size:12px}
.table th,.table td{padding:10px 12px;border-bottom:1px solid #f1f5f9;text-align:left}
.table th{background:#f8fafc;position:sticky;top:0;z-index:2;color:#475569;font-weight:700}

.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:50;align-items:center;justify-content:center}
.modal.show{display:flex}
.modal-card{background:#fff;border-radius:10px;width:450px;padding:20px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:90vh;overflow-y:auto}
.modal-card input{width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:8px;margin-top:4px;margin-bottom:10px;font-size:12px;outline:none}
.modal-card input:focus{border-color:#6366f1}

/* CSS สำหรับสั่งพิมพ์กระดาษ A4 */
@media print {
  body * {
    visibility: hidden;
  }
  #printable-history, #printable-history * {
    visibility: visible;
  }
  #printable-history {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    background: #fff;
    padding: 20px;
  }
  .no-print {
    display: none !important;
  }
}
/* ===== Cashier Closing ===== */
.cashier-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;overflow:auto;flex:1}
.cashier-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px}
.cashier-denom{display:grid;grid-template-columns:80px 1fr 110px;gap:6px;align-items:center;margin-bottom:6px;font-size:12px}
.cashier-denom input{width:100%;height:30px;border:1px solid #cbd5e1;border-radius:6px;text-align:right;padding:0 8px;font-size:12px}
.cashier-total{font-size:18px;font-weight:800;color:#4f46e5;text-align:right}
.cashier-summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}
@media(max-width:900px){.cashier-grid{grid-template-columns:1fr}.cashier-summary-grid{grid-template-columns:repeat(2,1fr)}}

/* ===== ใบเสร็จ 80mm / Receipt Preview ===== */
.receipt-preview-modal{
  position:fixed;inset:0;background:rgba(15,23,42,.55);
  display:none;align-items:center;justify-content:center;
  z-index:99999;padding:12px;
}
.receipt-preview-modal.show{display:flex}
.receipt-window{
  width:380px;max-width:96vw;max-height:96vh;
  background:#fff;border-radius:10px;overflow:hidden;
  box-shadow:0 20px 50px rgba(0,0,0,.35);
  display:flex;flex-direction:column;
}
.receipt-window-head{
  height:48px;background:#1f2937;color:#fff;
  display:flex;align-items:center;justify-content:space-between;
  padding:0 10px 0 12px;font-size:13px;font-weight:800;
}
.receipt-window-actions{display:flex;gap:6px}
.receipt-window-actions button{
  border:0;border-radius:5px;height:30px;padding:0 12px;
  color:#fff;font-size:11px;font-weight:800;cursor:pointer;
}
.receipt-print-btn{background:#4f46e5}
.receipt-close-btn{background:#475569}
.receipt-paper-wrap{
  overflow:auto;background:#e5e7eb;padding:10px;
}
.receipt-paper{
  width:80mm;max-width:100%;margin:0 auto;background:#fff;color:#111;
  box-sizing:border-box;padding:4mm 3.5mm;
  font-family:"Sarabun","Tahoma",Arial,sans-serif;
  font-size:11px;line-height:1.35;
}
.receipt-paper .r-center{text-align:center}
.receipt-paper .r-shop{font-size:16px;font-weight:800}
.receipt-paper .r-sub{font-size:10px;color:#475569}
.receipt-paper .r-small{font-size:9px}
.receipt-paper .r-line{border-top:1px dashed #94a3b8;margin:7px 0}
.receipt-paper .r-row{display:grid;grid-template-columns:1fr auto;gap:8px}
.receipt-paper .r-row3{display:grid;grid-template-columns:1fr auto auto;gap:7px}
.receipt-paper .r-label{white-space:nowrap}
.receipt-paper .r-value{text-align:right}
.receipt-paper .r-head{font-weight:800}
.receipt-paper .r-total{font-size:13px;font-weight:800}
.receipt-paper .r-grand{font-size:14px;font-weight:900}
.receipt-paper .r-footer{text-align:center;font-size:9px;color:#64748b;margin-top:5px}

@media print {
  @page{size:80mm auto;margin:0}
  html,body{margin:0!important;padding:0!important;background:#fff!important}
  body.receipt-printing > *{display:none!important}
  body.receipt-printing #receiptPrintArea{
    display:block!important;position:absolute!important;
    left:0!important;top:0!important;width:80mm!important;
    margin:0!important;padding:0!important;background:#fff!important;
  }
  body.receipt-printing .receipt-paper{
    display:block!important;width:80mm!important;max-width:none!important;
    margin:0!important;padding:4mm 3.5mm!important;
    box-shadow:none!important;
  }
  body.receipt-printing .receipt-preview-modal{display:none!important}
}
#receiptPrintArea{display:none}
.corder-badge{display:inline-block;min-width:18px;height:18px;line-height:18px;padding:0 5px;border-radius:9px;background:#dc2626;color:#fff;font-size:10px;font-weight:800;text-align:center;margin-left:5px;vertical-align:middle}
.table-card{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);display:flex;flex-direction:column;align-items:center;gap:6px}
.table-card img{width:130px;height:130px;border:1px solid #e2e8f0;border-radius:8px}
.table-card .t-no{font-weight:800;color:#1e293b;font-size:14px}
.table-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.corder-card{border:1px solid #e2e8f0;border-radius:12px;padding:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-bottom:10px}
.corder-card .c-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.corder-card .c-status{font-size:10px;font-weight:800;padding:2px 8px;border-radius:9999px}

/* Customer order status: accepted = green / actively preparing */
.c-status.status-accepted{background:#dcfce7!important;color:#166534!important;border-color:#86efac!important}
.status-pending{background:#fef3c7;color:#92400e}
.status-accepted{background:#dbeafe;color:#1e40af}
.status-served{background:#dcfce7;color:#166534}
.corder-item-row{display:flex;justify-content:space-between;font-size:12px;padding:2px 0;border-bottom:1px dashed #eef2f7}
.bill-banner{background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:10px 12px;margin:10px 10px 0 10px;animation:billPulse 1.6s ease-in-out infinite}
.bill-banner-row{display:flex;justify-content:space-between;align-items:center;padding:4px 0}
.bill-banner-row+.bill-banner-row{border-top:1px dashed #fecaca}
@keyframes billPulse{0%,100%{box-shadow:0 0 0 0 rgba(220,38,38,.25)}50%{box-shadow:0 0 0 6px rgba(220,38,38,0)}}

.kitchen-print-area{display:none}
@media print{
  @page{size:80mm auto;margin:0}
  html,body{margin:0!important;padding:0!important;background:#fff!important}
  /* CSS พิมพ์ A4 เดิมซ่อน body * ด้วย visibility:hidden จึงต้องเปิดให้ใบครัว */
  body.kitchen-printing>*{display:none!important;visibility:hidden!important}
  body.kitchen-printing .kitchen-print-area{
    display:block!important;visibility:visible!important;
    position:absolute!important;left:0!important;top:0!important;
    width:80mm!important;margin:0!important;padding:0!important;
    background:#fff!important;
  }
  body.kitchen-printing .kitchen-print-area *{visibility:visible!important}
  .kitchen-ticket{
    display:block!important;visibility:visible!important;width:80mm!important;
    box-sizing:border-box;margin:0!important;padding:4mm 3mm!important;
    font-family:"Sarabun","Tahoma",Arial,sans-serif;color:#000!important;
    background:#fff!important;font-size:13px;line-height:1.35;
  }
  .kitchen-ticket h2{font-size:20px;text-align:center;margin:0 0 6px;color:#000!important}
  .kitchen-ticket .line{border-top:1px dashed #000;margin:6px 0}
  .kitchen-ticket .row{display:flex;justify-content:space-between;font-size:13px;margin:4px 0;color:#000!important}
  .kitchen-ticket .item{font-size:16px;font-weight:800;margin:6px 0;color:#000!important}
  .kitchen-ticket .note{font-size:12px;margin-left:10px;color:#000!important}
}

/* ===== SUNTORN POS / SIGNATURE UI ===== */
:root{
  --sp-ink:#111827; --sp-muted:#64748b; --sp-line:#e6eaf2;
  --sp-indigo:#5b5ce2; --sp-violet:#7c3aed; --sp-cyan:#06b6d4;
  --sp-bg:#f5f7fb; --sp-card:rgba(255,255,255,.88);
  --sp-shadow:0 14px 38px rgba(28,35,74,.08);
}
body{
  background:
    radial-gradient(circle at 12% 10%,rgba(124,58,237,.10),transparent 25%),
    radial-gradient(circle at 88% 8%,rgba(6,182,212,.09),transparent 24%),
    linear-gradient(135deg,#f8faff 0%,#f4f6fb 52%,#eef2ff 100%);
}
.header{
  height:62px!important;
  padding:0 18px!important;
  background:linear-gradient(105deg,#27256f 0%,#5146d9 46%,#6366f1 72%,#0891b2 125%)!important;
  box-shadow:0 8px 24px rgba(49,46,129,.22)!important;
  border-bottom:1px solid rgba(255,255,255,.16);
}
.header:after{
  content:"";position:absolute;right:250px;top:-50px;width:210px;height:150px;
  background:radial-gradient(circle,rgba(255,255,255,.16),transparent 68%);
  pointer-events:none;
}
.brand{width:275px!important}
.logo{
  width:40px!important;height:40px!important;border-radius:13px!important;
  background:linear-gradient(145deg,#fff,#e8eaff)!important;
  color:#5146d9!important;box-shadow:0 5px 16px rgba(0,0,0,.16);
}
.brand-name{letter-spacing:.3px;font-size:17px!important}
.brand-sub{font-size:9px;color:#c7d2fe;letter-spacing:1px}
.topnav{gap:5px!important}
.topnav button,.nav-btn{
  color:#e9e9ff!important;border:1px solid transparent!important;
  padding:8px 11px!important;border-radius:10px!important;
  font-size:11px!important;font-weight:700!important;
  transition:.2s ease!important;
}
.topnav button.active,.topnav button:hover,.nav-btn:hover{
  background:rgba(255,255,255,.14)!important;
  border-color:rgba(255,255,255,.15)!important;
  color:#fff!important;transform:translateY(-1px);
}
.nav-btn{background:transparent!important;cursor:pointer}
.staff{
  background:rgba(17,24,39,.22)!important;border:1px solid rgba(255,255,255,.12);
  padding:7px 11px!important;backdrop-filter:blur(8px);
}
.header>button[onclick="doLogout()"]{
  background:linear-gradient(135deg,#fb7185,#e11d48)!important;
  border-radius:10px!important;padding:8px 11px!important;
  box-shadow:0 6px 14px rgba(225,29,72,.25);margin-left:5px!important;
}
.app{
  height:calc(100vh - 62px)!important;padding:12px!important;gap:12px!important;
}
.left,.right,.panel{
  border:1px solid rgba(226,232,240,.9)!important;
  border-radius:16px!important;background:rgba(255,255,255,.91)!important;
  box-shadow:var(--sp-shadow)!important;backdrop-filter:blur(12px);
}
.search-wrap{padding:13px 14px 8px!important}
.search{
  height:40px!important;border-radius:12px!important;border-color:#e2e8f0!important;
  background:#f8fafc!important;font-size:12px!important;
  box-shadow:inset 0 1px 2px rgba(15,23,42,.02);
}
.search:focus{background:#fff!important;border-color:#818cf8!important;box-shadow:0 0 0 4px rgba(99,102,241,.11)!important}
.chips{gap:7px!important;padding:2px 14px 10px!important}
.chip{
  padding:6px 13px!important;border-radius:999px!important;
  background:#f8fafc!important;border-color:#e2e8f0!important;
  font-weight:700!important;transition:.18s ease;
}
.chip:hover{border-color:#a5b4fc;transform:translateY(-1px)}
.chip.active{background:linear-gradient(135deg,#5b5ce2,#7c3aed)!important;border-color:transparent!important;box-shadow:0 5px 12px rgba(91,92,226,.22)}
.products{padding:6px 14px 14px!important;grid-template-columns:repeat(4,1fr)!important;gap:10px!important}
.product{
  height:142px!important;border-radius:14px!important;
  border:1px solid #e7eaf2!important;box-shadow:0 3px 12px rgba(15,23,42,.035)!important;
}
.product:hover{
  border-color:#a5b4fc!important;transform:translateY(-3px)!important;
  box-shadow:0 10px 24px rgba(79,70,229,.13)!important;
}
.product-img{height:58px!important;background:linear-gradient(135deg,#f1f5ff,#f8fafc)!important;font-size:20px!important}
.product-info{padding:7px 9px!important}
.category{background:#eef2ff!important;color:#5146d9!important;border-radius:999px!important;padding:2px 6px!important}
.product-name{font-size:11.5px!important}
.price{font-size:12px!important}
.add{
  width:23px!important;height:23px!important;border-radius:8px!important;
  background:linear-gradient(135deg,#5b5ce2,#7c3aed)!important;color:#fff!important;
  box-shadow:0 3px 8px rgba(91,92,226,.2);
}
.cart-head{padding:13px 14px!important;background:linear-gradient(180deg,#fff,#fbfcff)}
.cart-title{font-size:14px!important}
.summary{padding:11px 14px!important;background:linear-gradient(180deg,#fafbff,#f5f7fb)!important}
.toggle-row{height:30px!important;border-radius:9px!important;background:#eef2ff!important;color:#5146d9!important}
.net strong{font-size:19px!important;color:#5146d9!important}
.pay-method{height:30px!important;border-radius:8px!important}
.pay-method.active{background:#eef2ff!important}
.checkout{
  height:40px!important;border-radius:10px!important;
  background:linear-gradient(135deg,#16a34a,#059669)!important;
  box-shadow:0 7px 15px rgba(5,150,105,.2)!important;
}
.checkout:hover{background:linear-gradient(135deg,#15803d,#047857)!important}
.page{padding:0!important}
.panel{padding:18px!important}
.panel h2{letter-spacing:-.15px}
.table-container{border-radius:12px!important}
.table th{background:linear-gradient(180deg,#f8faff,#f1f5f9)!important}
.table tr:hover td{background:#fafbff}
.corder-card,.table-card{
  border-radius:16px!important;box-shadow:0 7px 20px rgba(15,23,42,.05)!important;
  border-color:#e7eaf2!important;
}
.corder-card:hover{box-shadow:0 12px 28px rgba(79,70,229,.10)!important}


/* ===== Historical report page : Coffee POS green responsive theme ===== */
.history-report-layout{display:grid;grid-template-columns:280px minmax(0,1fr);gap:18px;align-items:start;margin-top:10px}
.history-report-sidebar{background:#fff;border:1px solid #d9eee6;border-radius:18px;box-shadow:0 8px 24px rgba(6,78,59,.08);overflow:hidden;position:sticky;top:12px}
.history-report-sidebar-head{display:flex;align-items:center;gap:11px;padding:17px 15px;background:linear-gradient(135deg,#ecfdf5,#f7fffb);border-bottom:1px solid #d9eee6}
.history-report-sidebar-icon{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,#059669,#047857);color:#fff;display:flex;align-items:center;justify-content:center;font-size:17px;box-shadow:0 6px 14px rgba(5,150,105,.25)}
.history-report-sidebar-title{font-size:14px;font-weight:900;color:#064e3b}.history-report-sidebar-subtitle{font-size:11px;color:#64748b;margin-top:2px}
.history-report-menu{padding:10px}
.report-btn{width:100%;display:flex;align-items:center;gap:10px;text-align:left;border:1px solid transparent;background:#fff;color:#334155;border-radius:13px;padding:11px 10px;margin-bottom:6px;cursor:pointer;transition:.18s ease;min-height:62px}
.report-btn:hover{background:#f0fdf4;border-color:#bbf7d0;transform:translateX(1px)}
.report-btn.active{background:linear-gradient(90deg,#ecfdf5,#f6fffb);border-color:#86efac;color:#047857;box-shadow:inset 4px 0 0 #059669,0 4px 12px rgba(5,150,105,.08)}
.report-menu-icon{width:36px;height:36px;min-width:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px}.report-menu-icon.blue{background:#d1fae5;color:#047857}.report-menu-icon.green{background:#dcfce7;color:#16a34a}.report-menu-icon.orange{background:#ffedd5;color:#ea580c}.report-menu-icon.purple{background:#ede9fe;color:#7c3aed}.report-menu-icon.teal{background:#ccfbf1;color:#0f766e}.report-menu-icon.red{background:#fee2e2;color:#dc2626}
.report-menu-text{display:flex;flex-direction:column;min-width:0;flex:1}.report-menu-text b{font-size:12px;line-height:1.3;white-space:normal}.report-menu-text small{font-size:10px;color:#94a3b8;margin-top:3px;white-space:normal;line-height:1.25}.report-btn.active .report-menu-text small{color:#4b7c6b}.report-menu-arrow{font-size:10px;color:#94a3b8}.report-btn.active .report-menu-arrow{color:#059669}
.history-report-content{min-width:0;background:#fff;border:1px solid #d9eee6;border-radius:18px;box-shadow:0 8px 24px rgba(6,78,59,.08);overflow:hidden}
.history-report-content-head{min-height:78px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:15px;background:linear-gradient(135deg,#f7fffb,#ecfdf5);border-bottom:1px solid #d9eee6}
.selected-report-heading{width:100%}.selected-report-heading-top{display:flex;align-items:center;justify-content:space-between;gap:15px}.report-inline-toolbar{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-top:13px;padding:13px;border:1px solid #d9eee6;border-radius:13px;background:linear-gradient(135deg,#f0fdf4,#f8fffc)}.report-date-field{display:flex;flex-direction:column;gap:4px}.report-date-field label{font-size:10px;font-weight:900;color:#365c50}.report-date-field label i{color:#059669;margin-right:4px}.report-date-field input{height:38px;min-width:145px;border:1px solid #b7d8cb;border-radius:9px;background:#fff;padding:0 10px;color:#334155;font-size:11px;font-weight:800;outline:none}.report-date-field input:focus{border-color:#059669;box-shadow:0 0 0 3px rgba(5,150,105,.12)}.report-toolbar-actions{display:flex;gap:7px;margin-left:auto}.report-tool-btn{height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:0 12px;font-size:10px;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;gap:5px}.report-tool-btn.light{background:#fff;color:#475569;border-color:#cbd5e1}.report-tool-btn.excel{background:#059669;color:#fff;border-color:#059669}.report-tool-btn.print{background:#2563eb;color:#fff;border-color:#2563eb}.report-tool-btn:hover{filter:brightness(.97);transform:translateY(-1px)}
.selected-report-code{font-size:11px;font-weight:900;color:#059669;letter-spacing:.6px}.selected-report-title{margin:2px 0 0;font-size:20px;font-weight:900;color:#064e3b}.selected-report-description{font-size:11px;color:#64748b;margin-top:3px}.selected-report-status{font-size:10px;font-weight:800;color:#64748b;white-space:nowrap}.selected-report-status span{display:inline-block;width:7px;height:7px;background:#22c55e;border-radius:50%;margin-right:5px;box-shadow:0 0 0 3px #dcfce7}
.report-content-scroll{margin:0;border:0;border-radius:0;background:#fff;max-height:70vh;padding:16px;overflow:auto}
/* On-screen report cards/tables */
.report-content-scroll .report-a4{font-family:Arial,'Tahoma',sans-serif;background:#fff;color:#0f172a;font-size:12px}
.report-content-scroll .report-a4-head{background:#fff;border-bottom:2px solid #059669;border-radius:10px;padding:12px 14px;margin-bottom:12px}
.report-content-scroll .report-code{color:#059669;font-size:14px}
.report-content-scroll .report-main-title{color:#064e3b;font-size:15px}
.report-content-scroll .report-company{color:#0f172a}
.report-content-scroll .report-a4-table{border:1px solid #d9eee6;border-radius:12px;overflow:hidden;background:#fff}
.report-content-scroll .report-a4-table th{background:#047857;color:#fff;border-color:#047857;padding:9px 7px;font-weight:900}
.report-content-scroll .report-a4-table td{border-color:#e2eee9;padding:8px 7px}
.report-content-scroll .report-a4-table tbody tr:hover td{background:#f0fdf4}
.report-content-scroll .report-a4-table tr.total td{background:#ecfdf5;color:#064e3b;font-weight:900}
.report-content-scroll .report-a4-summary{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0}
.report-content-scroll .report-a4-summary>div{border:1px solid #d9eee6;border-radius:13px;padding:12px 8px;text-align:center;background:linear-gradient(135deg,#f0fdf4,#fff);box-shadow:0 4px 12px rgba(6,78,59,.05)}
.report-content-scroll .report-a4-summary>div:nth-child(3){background:linear-gradient(135deg,#eff6ff,#fff);border-color:#dbeafe}.report-content-scroll .report-a4-summary>div:nth-child(4){background:linear-gradient(135deg,#f5f3ff,#fff);border-color:#e9d5ff}
.report-content-scroll .report-a4-summary b{display:block;font-size:17px;margin-top:4px;color:#064e3b}
.report-content-scroll .report-a4-note{background:#f8fafc;border-left:3px solid #059669;padding:8px 10px;border-radius:6px;color:#64748b}
@media(max-width:900px){.history-report-layout{grid-template-columns:1fr}.history-report-sidebar{position:static}.history-report-menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.report-btn{margin:0}.selected-report-heading-top{align-items:flex-start;flex-direction:column}.selected-report-status{align-self:flex-end}.report-toolbar-actions{margin-left:0}.report-content-scroll{max-height:none}.report-content-scroll .report-a4-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:600px){.history-report-menu{display:flex;overflow-x:auto;gap:7px;padding:9px}.history-report-menu .report-btn{min-width:230px;flex:0 0 230px}.history-report-content-head{padding:12px}.selected-report-title{font-size:17px}.report-inline-toolbar{display:grid;grid-template-columns:1fr 1fr;gap:8px}.report-date-field input{width:100%;min-width:0}.report-toolbar-actions{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr 1fr}.report-tool-btn{justify-content:center;padding:0 7px}.report-content-scroll{padding:9px;max-height:none;overflow-x:auto}.report-content-scroll .report-a4-summary{grid-template-columns:1fr 1fr;gap:7px}.report-content-scroll .report-a4-summary>div{padding:9px 5px}.report-content-scroll .report-a4-summary b{font-size:14px}.report-content-scroll .report-a4-table{min-width:620px}.report-content-scroll .report-a4{min-width:620px}.history-report-content{border-radius:14px}.history-report-sidebar{border-radius:14px}}
@media(max-width:380px){.history-report-menu .report-btn{min-width:205px;flex-basis:205px}.report-inline-toolbar{grid-template-columns:1fr}.report-toolbar-actions{grid-column:auto;grid-template-columns:1fr 1fr 1fr}.report-content-scroll .report-a4-summary{grid-template-columns:1fr}.report-content-scroll .report-a4-table{min-width:560px}.report-content-scroll .report-a4{min-width:560px}}
.report-scroll-panel{max-height:68vh;overflow-y:auto;overflow-x:auto;scroll-behavior:smooth;border:1px solid #dbe3ef;border-radius:12px;padding:12px;background:#f8fafc;scrollbar-width:thin}.report-scroll-panel::-webkit-scrollbar{width:10px;height:10px}.report-scroll-panel::-webkit-scrollbar-thumb{background:#94a3b8;border-radius:10px}.report-scroll-panel::-webkit-scrollbar-track{background:#e2e8f0;border-radius:10px}.report-a4{font-family:Arial,"Tahoma",sans-serif;color:#111;font-size:11px;background:#fff}.report-a4-head{text-align:left;line-height:1.55;border-bottom:1px solid #999;padding-bottom:8px;margin-bottom:10px}.report-code{font-size:13px;font-weight:700}.report-main-title{text-align:left;font-size:13px;font-weight:700;margin:8px 0 6px}.report-company{font-size:14px;font-weight:700}.report-meta{text-align:right;font-size:10px;margin-top:3px}.report-section-title{font-weight:700;font-size:12px;margin:8px 0 4px}.report-a4-table{width:100%;border-collapse:collapse;table-layout:fixed}.report-a4-table th,.report-a4-table td{border:1px solid #555;padding:4px 5px;vertical-align:middle;word-break:break-word}.report-item-table th,.report-item-table td{font-size:10px}.report-item-table th:nth-child(1),.report-item-table td:nth-child(1){width:18%}.report-item-table th:nth-child(2),.report-item-table td:nth-child(2){width:37%}.report-item-table th:nth-child(3),.report-item-table td:nth-child(3){width:15%}.report-item-table th:nth-child(4),.report-item-table td:nth-child(4){width:12%}.report-item-table th:nth-child(5),.report-item-table td:nth-child(5){width:18%}.report-stock-table{font-size:9px}.report-stock-table th,.report-stock-table td{padding:3px 4px}.report-stock-table th:nth-child(1),.report-stock-table td:nth-child(1){width:11%}.report-stock-table th:nth-child(2),.report-stock-table td:nth-child(2){width:8%}.report-stock-table th:nth-child(3),.report-stock-table td:nth-child(3){width:18%}.report-stock-table th:nth-child(4),.report-stock-table td:nth-child(4){width:9%}.report-stock-table th:nth-child(5),.report-stock-table td:nth-child(5){width:9%}.report-stock-table th:nth-child(6),.report-stock-table td:nth-child(6){width:9%}.report-stock-table th:nth-child(7),.report-stock-table td:nth-child(7){width:9%}.report-stock-table th:nth-child(8),.report-stock-table td:nth-child(8){width:12%}.report-stock-table th:nth-child(9),.report-stock-table td:nth-child(9){width:15%}.report-a4-table th{background:#eee;text-align:center;font-weight:700}.report-a4-table td.num{text-align:right;white-space:nowrap}.report-a4-table tr.total td{font-weight:700;background:#f5f5f5}.report-a4-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:8px 0}.report-a4-summary>div{border:1px solid #777;padding:6px;text-align:center}.report-a4-summary b{display:block;font-size:13px}.report-a4-note{font-size:9px;margin-top:8px}@media print{@page{size:A4 portrait;margin:10mm}}
#page-history .bg-slate-50, #page-graph .bg-slate-50{
  background:linear-gradient(135deg,#f8faff,#eef2ff)!important;
  border-color:#e0e7ff!important;
  box-shadow:inset 0 1px 0 #fff,0 8px 24px rgba(79,70,229,.06);
}
.report-toolbar button{transition:.15s ease}
.report-toolbar button:hover{transform:translateY(-1px)}
#historySummary > div{transition:.2s ease}
#historySummary > div:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(79,70,229,.10)}
.cashier-card{border-radius:15px!important;box-shadow:0 7px 20px rgba(15,23,42,.05)!important}
.login-screen{
  background:
    radial-gradient(circle at 15% 20%,rgba(34,211,238,.22),transparent 25%),
    radial-gradient(circle at 85% 15%,rgba(167,139,250,.30),transparent 28%),
    linear-gradient(135deg,#17164a,#4338ca 52%,#0e7490)!important;
}
.login-card{
  border:1px solid rgba(255,255,255,.45)!important;
  border-radius:24px!important;background:rgba(255,255,255,.93)!important;
  box-shadow:0 30px 80px rgba(15,23,42,.30)!important;
  backdrop-filter:blur(18px);
}
.login-logo{border-radius:16px!important;background:linear-gradient(135deg,#5b5ce2,#06b6d4)!important}
@media(max-width:1100px){.brand{width:220px!important}.topnav button{padding:7px 7px!important}.products{grid-template-columns:repeat(3,1fr)!important}}
@media(max-width:800px){.header{padding:0 8px!important}.brand{width:auto!important}.brand-sub{display:none}.topnav{overflow:auto;justify-content:flex-start}.staff{display:none}.products{grid-template-columns:repeat(2,1fr)!important}}


.pos-hero{margin:12px 14px 2px;padding:12px 14px;border-radius:15px;display:flex;align-items:center;justify-content:space-between;color:#fff;background:linear-gradient(120deg,#312e81,#5b5ce2 58%,#0891b2);position:relative;overflow:hidden;box-shadow:0 10px 24px rgba(79,70,229,.18)}
.pos-hero:after{content:"";position:absolute;width:140px;height:140px;right:20px;top:-65px;border-radius:50%;border:22px solid rgba(255,255,255,.08)}
.pos-hero strong{display:block;font-size:15px;line-height:1.15}.pos-hero small{display:block;font-size:10px;opacity:.8;margin-top:3px}.hero-kicker{display:block;font-size:8px;letter-spacing:1.4px;opacity:.72;margin-bottom:2px;font-weight:800}.hero-orb{width:36px;height:36px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:15px;z-index:1;box-shadow:inset 0 1px 0 rgba(255,255,255,.18)}
.status-payment{background:#fef3c7!important;color:#92400e!important}

.utility-card{min-height:96px;}
.utility-card b{line-height:1.2;}
.utility-close{margin-top:0;}
@media(max-width:640px){.utility-card{min-height:88px;padding:.6rem}.utility-card small{font-size:8px}.utility-card .w-9{width:2rem;height:2rem;margin-bottom:.4rem}}

/* =========================================================
   GLOBAL SUNTORN POS THEME - GREEN / WHITE
   ใช้เป็นธีมหลักทุกหน้า โดยคงสีแดงไว้เฉพาะปุ่ม/สถานะที่เป็นการยกเลิกหรืออันตราย
   ========================================================= */
:root{
  --pos-green-950:#064e3b;
  --pos-green-900:#065f46;
  --pos-green-800:#047857;
  --pos-green-700:#059669;
  --pos-green-600:#10b981;
  --pos-green-500:#22c55e;
  --pos-green-100:#d1fae5;
  --pos-green-50:#ecfdf5;
  --pos-bg:#f4fbf7;
  --pos-border:#d7eee2;
  --pos-text:#17352a;
}
html,body{background:var(--pos-bg)!important;color:var(--pos-text)}
body{accent-color:#059669}

/* Header / Navigation */
.header{
  background:linear-gradient(90deg,#064e3b 0%,#047857 48%,#059669 100%)!important;
  box-shadow:0 3px 12px rgba(6,78,59,.18)!important;
}
.logo{color:#047857!important}
.brand-sub{color:#d1fae5!important}
.topnav button{color:#d1fae5!important}
.topnav button.active,.topnav button:hover{background:#065f46!important;color:#fff!important}
.staff{background:#065f46!important}

/* Login */
.login-screen{
  background:
    radial-gradient(circle at 15% 20%,rgba(52,211,153,.20),transparent 25%),
    radial-gradient(circle at 85% 15%,rgba(167,243,208,.18),transparent 28%),
    linear-gradient(135deg,#064e3b,#047857 52%,#10b981)!important;
}
.login-logo{background:linear-gradient(135deg,#047857,#10b981)!important}
.login-card input:focus{border-color:#059669!important;box-shadow:0 0 0 3px rgba(5,150,105,.12)!important}
.login-card button[type=submit]{background:#059669!important}
.login-card button[type=submit]:hover{background:#047857!important}

/* Main POS panels */
.left,.right,.panel,.modal-card{border-color:var(--pos-border)!important}
.search:focus{border-color:#059669!important;box-shadow:0 0 0 3px rgba(5,150,105,.10)!important}
.chip.active{background:#059669!important;border-color:#059669!important}
.category{background:#d1fae5!important;color:#047857!important}
.product:hover{border-color:#10b981!important;box-shadow:0 3px 10px rgba(5,150,105,.10)!important}
.price{color:#047857!important}
.add{background:#d1fae5!important;color:#047857!important}
.toggle-row{background:#d1fae5!important;color:#065f46!important}
.switch{background:#059669!important}
.pay-type-btn:hover{border-color:#10b981!important;background:#f0fdf4!important}
.pay-type-btn .selected{color:#047857!important}
.pay-method.active{border-color:#059669!important;color:#047857!important;background:#d1fae5!important}
.checkout{background:#059669!important}
.checkout:hover{background:#047857!important}

/* Generic Tailwind primary colors -> green theme */
.bg-indigo-50{background:#ecfdf5!important}.bg-indigo-100{background:#d1fae5!important}.bg-indigo-200{background:#a7f3d0!important}
.bg-indigo-500{background:#10b981!important}.bg-indigo-600{background:#059669!important}.bg-indigo-700{background:#047857!important}.bg-indigo-800{background:#065f46!important}.bg-indigo-900{background:#064e3b!important}
.text-indigo-400{color:#34d399!important}.text-indigo-500{color:#10b981!important}.text-indigo-600{color:#059669!important}.text-indigo-700{color:#047857!important}.text-indigo-800{color:#065f46!important}.text-indigo-900{color:#064e3b!important}
.border-indigo-100{border-color:#d1fae5!important}.border-indigo-200{border-color:#a7f3d0!important}.border-indigo-300{border-color:#6ee7b7!important}.border-indigo-400{border-color:#34d399!important}.border-indigo-500{border-color:#10b981!important}.border-indigo-600{border-color:#059669!important}
.bg-violet-50,.bg-purple-50{background:#f0fdf4!important}.bg-violet-100,.bg-purple-100{background:#dcfce7!important}.bg-violet-200,.bg-purple-200{background:#bbf7d0!important}
.bg-violet-500,.bg-purple-500{background:#22c55e!important}.bg-violet-600,.bg-purple-600{background:#16a34a!important}.bg-violet-700,.bg-purple-700{background:#15803d!important}
.text-violet-500,.text-purple-500{color:#22c55e!important}.text-violet-600,.text-purple-600{color:#16a34a!important}.text-violet-700,.text-purple-700{color:#15803d!important}.text-violet-800,.text-purple-800{color:#166534!important}
.border-violet-100,.border-purple-100{border-color:#dcfce7!important}.border-violet-200,.border-purple-200{border-color:#bbf7d0!important}.border-violet-300,.border-purple-300{border-color:#86efac!important}

/* Common blue primary actions -> green */
.bg-blue-50{background:#effdf5!important}.bg-blue-100{background:#d1fae5!important}.bg-blue-200{background:#a7f3d0!important}
.bg-blue-500{background:#10b981!important}.bg-blue-600{background:#059669!important}.bg-blue-700{background:#047857!important}.bg-blue-800{background:#065f46!important}
.text-blue-500{color:#10b981!important}.text-blue-600{color:#059669!important}.text-blue-700{color:#047857!important}.text-blue-800{color:#065f46!important}
.border-blue-100{border-color:#d1fae5!important}.border-blue-200{border-color:#a7f3d0!important}.border-blue-300{border-color:#6ee7b7!important}.border-blue-600{border-color:#059669!important}

/* Existing custom report / cards */
#page-history .bg-slate-50,#page-graph .bg-slate-50{
  background:linear-gradient(135deg,#f7fffb,#ecfdf5)!important;
  border-color:#d7eee2!important;
  box-shadow:inset 0 1px 0 #fff,0 8px 24px rgba(6,78,59,.06)!important;
}
.pos-hero{background:linear-gradient(120deg,#064e3b,#047857 58%,#10b981)!important;box-shadow:0 10px 24px rgba(6,78,59,.18)!important}

/* Report cards / tables */
.history-report-sidebar,.history-report-content{border-color:#d7eee2!important}
.report-menu-icon.blue{background:#d1fae5!important;color:#047857!important}
.report-menu-icon.purple{background:#dcfce7!important;color:#15803d!important}
.report-menu-icon.orange{background:#dcfce7!important;color:#16a34a!important}
.report-menu-icon.teal{background:#ccfbf1!important;color:#0f766e!important}
.report-content-scroll .report-a4-head{border-bottom-color:#059669!important}
.report-content-scroll .report-a4-table th{background:#047857!important;border-color:#047857!important}
.report-content-scroll .report-a4-table tr.total td{background:#ecfdf5!important;color:#064e3b!important}
.report-content-scroll .report-a4-summary>div{border-color:#d7eee2!important;background:linear-gradient(135deg,#f0fdf4,#fff)!important}
.report-content-scroll .report-a4-summary>div:nth-child(3),.report-content-scroll .report-a4-summary>div:nth-child(4){background:linear-gradient(135deg,#f0fdf4,#fff)!important;border-color:#d7eee2!important}

/* Utility / settings / tables */
.table th{background:#ecfdf5!important;color:#065f46!important}
.table-container{border-color:#d7eee2!important}
.utility-card:hover{background:#f0fdf4!important;border-color:#86efac!important}

/* Keep destructive actions visually red */
.text-red-500,.text-red-600,.text-red-700{color:#dc2626!important}
.bg-red-600{background:#dc2626!important}.bg-red-700{background:#b91c1c!important}

/* Responsive global layout */
@media(max-width:800px){
  body{overflow:auto!important}
  .app{height:calc(100vh - 54px);min-height:calc(100vh - 54px);overflow:auto!important}
  .header{overflow-x:auto}
  .topnav{min-width:max-content}
  .page{overflow:auto}
}


/* =========================================================
   COFFEE SHOP POS THEME - WARM CREAM / NAVY / COFFEE BLUE
   ปรับทุกหน้าให้โทนเดียวกับภาพตัวอย่าง Coffee Shop POS
   ========================================================= */
:root{
  --coffee-navy:#243b5a;
  --coffee-blue:#2f5d8a;
  --coffee-blue-dark:#1f466f;
  --coffee-blue-light:#e8eef5;
  --coffee-cream:#f6f0df;
  --coffee-cream-2:#fbf8ef;
  --coffee-beige:#e8dfc9;
  --coffee-gold:#c5a15b;
  --coffee-gold-light:#f3e8c9;
  --coffee-text:#263238;
  --coffee-muted:#68727c;
  --coffee-border:#d8cfbd;
  --coffee-white:#fffdf8;
}
html,body{background:var(--coffee-cream)!important;color:var(--coffee-text)!important}
body{accent-color:var(--coffee-blue)!important}

/* Header / Navigation */
.header{
  background:linear-gradient(90deg,var(--coffee-navy) 0%,var(--coffee-blue-dark) 55%,var(--coffee-blue) 100%)!important;
  box-shadow:0 3px 12px rgba(36,59,90,.22)!important;
}
.logo{color:var(--coffee-navy)!important;background:var(--coffee-white)!important}
.brand-sub{color:#dce5ee!important}
.topnav button{color:#edf3f8!important}
.topnav button.active,.topnav button:hover{background:rgba(255,255,255,.16)!important;color:#fff!important}
.staff{background:rgba(255,255,255,.14)!important;color:#fff!important}

/* Login */
.login-screen{
  background:
    radial-gradient(circle at 15% 20%,rgba(197,161,91,.24),transparent 25%),
    radial-gradient(circle at 85% 15%,rgba(255,255,255,.12),transparent 28%),
    linear-gradient(135deg,#1f344f,#2f5d8a 58%,#4f7398)!important;
}
.login-card{background:var(--coffee-white)!important;border:1px solid var(--coffee-border)!important}
.login-logo{background:linear-gradient(135deg,var(--coffee-navy),var(--coffee-blue))!important;color:#fff!important}
.login-card h1{color:var(--coffee-navy)!important}
.login-card input:focus{border-color:var(--coffee-blue)!important;box-shadow:0 0 0 3px rgba(47,93,138,.12)!important}
.login-card button[type=submit]{background:var(--coffee-blue)!important}
.login-card button[type=submit]:hover{background:var(--coffee-blue-dark)!important}

/* Main cards / panels */
.left,.right,.panel,.modal-card,.cashier-card{background:var(--coffee-white)!important;border-color:var(--coffee-border)!important}
.search{background:#fcfaf4!important;border-color:var(--coffee-border)!important}
.search:focus{border-color:var(--coffee-blue)!important;background:#fff!important;box-shadow:0 0 0 3px rgba(47,93,138,.10)!important}
.chip{background:#f7f3e8!important;border-color:var(--coffee-border)!important;color:var(--coffee-muted)!important}
.chip.active{background:var(--coffee-blue)!important;border-color:var(--coffee-blue)!important;color:#fff!important}
.category{background:var(--coffee-blue-light)!important;color:var(--coffee-blue-dark)!important}
.product{background:var(--coffee-white)!important;border-color:var(--coffee-border)!important}
.product:hover{border-color:var(--coffee-blue)!important;box-shadow:0 4px 12px rgba(47,93,138,.12)!important}
.product-img{background:#eee8d9!important;color:#a99e89!important}
.price{color:var(--coffee-blue-dark)!important}
.add{background:var(--coffee-blue-light)!important;color:var(--coffee-blue)!important}
.cart-title,.product-name{color:var(--coffee-navy)!important}
.summary{background:#f8f4e9!important;border-color:var(--coffee-border)!important}
.toggle-row{background:var(--coffee-blue-light)!important;color:var(--coffee-blue-dark)!important}
.switch{background:var(--coffee-blue)!important}
.net{color:var(--coffee-navy)!important;border-color:var(--coffee-border)!important}
.net strong{color:var(--coffee-blue-dark)!important}
.pay-type-btn:hover{border-color:var(--coffee-blue)!important;background:#f3f6f9!important}
.pay-type-btn .selected{color:var(--coffee-blue-dark)!important}
.pay-method.active{border-color:var(--coffee-blue)!important;color:var(--coffee-blue-dark)!important;background:var(--coffee-blue-light)!important}
.checkout{background:var(--coffee-navy)!important;box-shadow:0 2px 5px rgba(36,59,90,.22)!important}
.checkout:hover{background:var(--coffee-blue-dark)!important}

/* Coffee accent for non-destructive green/emerald actions */
.bg-emerald-50,.bg-green-50{background:#edf3ee!important}
.bg-emerald-100,.bg-green-100{background:#dce8df!important}
.bg-emerald-200,.bg-green-200{background:#c8d9cd!important}
.bg-emerald-500,.bg-green-500{background:#5f7f67!important}
.bg-emerald-600,.bg-green-600{background:#4f7059!important}
.bg-emerald-700,.bg-green-700{background:#3f6049!important}
.text-emerald-500,.text-green-500{color:#5f7f67!important}
.text-emerald-600,.text-green-600{color:#4f7059!important}
.text-emerald-700,.text-green-700{color:#3f6049!important}
.border-emerald-200,.border-green-200{border-color:#c8d9cd!important}

/* Indigo / blue / violet / purple utilities -> coffee navy/blue */
.bg-indigo-50,.bg-blue-50{background:var(--coffee-blue-light)!important}
.bg-indigo-100,.bg-blue-100{background:#dce6f0!important}
.bg-indigo-200,.bg-blue-200{background:#c3d3e3!important}
.bg-indigo-500,.bg-blue-500{background:var(--coffee-blue)!important}
.bg-indigo-600,.bg-blue-600{background:var(--coffee-navy)!important}
.bg-indigo-700,.bg-blue-700{background:var(--coffee-blue-dark)!important}
.bg-indigo-800,.bg-blue-800{background:#193957!important}
.bg-indigo-900{background:#142d47!important}
.text-indigo-400,.text-blue-500{color:#5d7da0!important}
.text-indigo-500,.text-blue-600{color:var(--coffee-blue)!important}
.text-indigo-600,.text-blue-700{color:var(--coffee-navy)!important}
.text-indigo-700,.text-blue-800{color:var(--coffee-blue-dark)!important}
.text-indigo-800,.text-indigo-900{color:#193957!important}
.border-indigo-100,.border-blue-100{border-color:#d8e2ec!important}
.border-indigo-200,.border-blue-200{border-color:#c3d3e3!important}
.border-indigo-300,.border-blue-300{border-color:#aebfd1!important}
.border-indigo-500,.border-indigo-600,.border-blue-600{border-color:var(--coffee-blue)!important}
.bg-violet-50,.bg-purple-50{background:#f0edf2!important}
.bg-violet-100,.bg-purple-100{background:#e4dfe8!important}
.bg-violet-500,.bg-purple-500{background:#6b6175!important}
.bg-violet-600,.bg-purple-600{background:#5b5265!important}
.bg-violet-700,.bg-purple-700{background:#4b4354!important}
.text-violet-500,.text-purple-500{color:#6b6175!important}
.text-violet-600,.text-purple-600,.text-violet-700,.text-purple-700{color:#514858!important}

/* Tables */
.table-container{border-color:var(--coffee-border)!important;background:var(--coffee-white)!important}
.table th{background:var(--coffee-blue-light)!important;color:var(--coffee-navy)!important;border-color:var(--coffee-border)!important}
.table td{border-color:#e9e2d4!important}
.table tr:hover td{background:#faf7ef!important}

/* History / report page */
#page-history,.history-report-sidebar,.history-report-content{background:var(--coffee-cream-2)!important}
.history-report-sidebar,.history-report-content{border-color:var(--coffee-border)!important}
.report-menu-icon.blue{background:#dce6f0!important;color:var(--coffee-blue)!important}
.report-menu-icon.purple{background:#e4dfe8!important;color:#5b5265!important}
.report-menu-icon.orange{background:#f1e4cf!important;color:#9b7040!important}
.report-menu-icon.teal{background:#dce9e6!important;color:#4f756d!important}
.report-content-scroll .report-a4-head{border-bottom-color:var(--coffee-navy)!important}
.report-content-scroll .report-a4-table th{background:var(--coffee-navy)!important;border-color:var(--coffee-navy)!important;color:#fff!important}
.report-content-scroll .report-a4-table tr.total td{background:#edf3ee!important;color:var(--coffee-navy)!important}
.report-content-scroll .report-a4-summary>div{border-color:var(--coffee-border)!important;background:linear-gradient(135deg,#fbf8ef,#fffdf8)!important}

/* POS hero */
.pos-hero{background:linear-gradient(120deg,var(--coffee-navy),var(--coffee-blue) 60%,#557a9e)!important;box-shadow:0 10px 24px rgba(36,59,90,.18)!important}

/* Buttons commonly used across all pages */
button.bg-indigo-600,button.bg-blue-600{background:var(--coffee-navy)!important}
button.bg-indigo-600:hover,button.bg-blue-600:hover{background:var(--coffee-blue-dark)!important}
button.bg-emerald-600,button.bg-green-600{background:#4f7059!important}

/* Settings / Utility */
.utility-card:hover{background:#f5f1e6!important;border-color:#cfc3aa!important}

/* Gold accent for secondary coffee-style controls */
.cash-box{border-color:#e1c98e!important;background:#fbf5e4!important}
.cash-label{color:#8b6a2f!important}
.cash-input{border-color:#d4b56e!important}
.quick button{border-color:#d4b56e!important;color:#8b6a2f!important}

/* Keep destructive actions red */
.text-red-500,.text-red-600,.text-red-700{color:#c62828!important}
.bg-red-600{background:#c62828!important}.bg-red-700{background:#a91f1f!important}

/* Mobile */
@media(max-width:800px){
  html,body{background:var(--coffee-cream)!important}
  .header{background:var(--coffee-navy)!important}
  .topnav button.active{background:rgba(255,255,255,.18)!important}
}


/* ===== Sales Graph / PromptPay QR modern dashboard ===== */
.sales-graph-dashboard{position:relative;overflow:hidden;border-radius:20px;padding:18px;background:radial-gradient(circle at 78% 12%,rgba(14,165,233,.16),transparent 28%),radial-gradient(circle at 15% 85%,rgba(16,185,129,.13),transparent 30%),linear-gradient(135deg,#06111f,#0b1d32 48%,#06111f);border:1px solid rgba(125,211,252,.22);box-shadow:0 18px 50px rgba(2,12,27,.18)}
.sales-graph-topline{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;color:#e2e8f0}.sales-graph-kicker{font-size:10px;font-weight:800;letter-spacing:.14em;color:#67e8f9}.sales-graph-title{font-size:20px;font-weight:800;color:#f8fafc;margin:3px 0}.sales-graph-range{font-size:11px;color:#94a3b8}.sales-graph-live{font-size:10px;font-weight:800;color:#86efac;background:rgba(16,185,129,.12);border:1px solid rgba(134,239,172,.25);padding:7px 10px;border-radius:999px;white-space:nowrap}.sales-graph-live span{display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 12px #22c55e;margin-right:5px}
.graph-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px}.graph-summary-grid>div{background:rgba(15,30,49,.72)!important;border:1px solid rgba(148,163,184,.15)!important;border-radius:14px!important;padding:11px!important;color:#e2e8f0}.graph-summary-grid .text-xs{color:#94a3b8!important}.graph-summary-grid .text-xl,.graph-summary-grid .text-2xl{color:#f8fafc!important}
.sales-chart-frame{position:relative;overflow:hidden;border-radius:16px;border:1px solid rgba(125,211,252,.16);background:linear-gradient(180deg,rgba(5,18,34,.98),rgba(7,24,43,.98));padding:12px 12px 8px;box-shadow:inset 0 1px 0 rgba(255,255,255,.03)}.sales-chart-scroll{overflow-x:auto;width:100%;-webkit-overflow-scrolling:touch;scrollbar-color:#334155 #071525;scrollbar-width:thin}.sales-chart-canvas-wrap{height:430px;min-width:1180px;position:relative;z-index:2}.sales-chart-axis-note{display:flex;justify-content:space-between;gap:8px;padding:8px 4px 2px;color:#64748b;font-size:9px}.sales-chart-axis-note b{color:#7dd3fc;font-weight:700}.sales-chart-glow{position:absolute;border-radius:50%;filter:blur(55px);opacity:.18;pointer-events:none}.glow-one{width:230px;height:180px;right:5%;top:8%;background:#0ea5e9}.glow-two{width:220px;height:160px;left:10%;bottom:4%;background:#10b981}
.promptpay-qr-box{margin-top:9px;border:1px solid #86efac;border-radius:14px;background:linear-gradient(135deg,#f0fdf4,#ecfeff);padding:10px;box-shadow:0 8px 24px rgba(16,185,129,.10)}.promptpay-qr-head{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:800;color:#166534;margin-bottom:8px}.promptpay-qr-head span{font-size:10px;color:#059669}.promptpay-qr-content{display:flex;align-items:center;justify-content:center;gap:14px;flex-wrap:wrap}.promptpay-qr-canvas{width:200px;min-height:200px;background:#fff;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:5px;border:1px solid #d1fae5}.promptpay-qr-canvas img{max-width:190px!important;height:auto!important}.promptpay-qr-info{min-width:180px;max-width:280px;text-align:center}.promptpay-label{font-size:11px;color:#64748b}.promptpay-qr-info strong{display:block;font-size:23px;color:#065f46;margin:2px 0 5px}.promptpay-number{font-size:11px;color:#334155}.promptpay-number span{font-weight:800;color:#047857}.promptpay-note{font-size:9px;color:#64748b;margin-top:8px;line-height:1.5}.promptpay-qr-error{font-size:11px;color:#b91c1c;text-align:center;line-height:1.6;padding:18px}.qr-payment-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}.qr-payment-cancel{border-radius:10px;padding:11px 10px;font-size:12px;font-weight:900;cursor:pointer;transition:.15s;background:#fff;color:#dc2626;border:1px solid #fecaca}.qr-payment-cancel{background:#fff;color:#dc2626;border:1px solid #fecaca}.qr-payment-cancel:hover{background:#fef2f2}.qr-payment-actions-3{grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch}.qr-payment-actions-3 .qr-payment-pay,.qr-payment-actions-3 .qr-payment-cancel,.qr-payment-actions-3 .qr-payment-print{min-height:46px;display:flex;align-items:center;justify-content:center;white-space:nowrap}.qr-payment-pay{background:#2563eb!important}.qr-payment-pay:hover{background:#1d4ed8!important}@media(max-width:700px){.qr-payment-actions-3{grid-template-columns:1fr 1fr 1fr;gap:6px}.qr-payment-actions-3 .qr-payment-pay,.qr-payment-actions-3 .qr-payment-cancel,.qr-payment-actions-3 .qr-payment-print{font-size:11px;padding:9px 5px;min-height:44px}}@media(max-width:420px){.qr-payment-actions-3{grid-template-columns:1fr 1fr}.qr-payment-actions-3 .qr-payment-pay{grid-column:1 / -1}.qr-payment-actions-3 .qr-payment-pay,.qr-payment-actions-3 .qr-payment-cancel,.qr-payment-actions-3 .qr-payment-print{font-size:12px}}
@media(max-width:700px){.graph-summary-grid{grid-template-columns:1fr}.sales-graph-dashboard{padding:12px;border-radius:15px}.sales-graph-title{font-size:17px}.sales-chart-canvas-wrap{height:340px;min-width:1050px}.sales-chart-axis-note{font-size:8px}.promptpay-qr-content{flex-direction:column}.promptpay-qr-info{max-width:100%}}
</style>

<style>
/* QR payment actions: keep confirmation buttons visible inside the POS payment panel */
.promptpay-qr-box{overflow:visible!important}
.promptpay-qr-content{gap:8px!important}
.promptpay-qr-canvas{width:170px!important;min-height:170px!important;padding:4px!important}
.promptpay-qr-canvas img{max-width:160px!important}
.promptpay-qr-info{min-width:150px!important}
.promptpay-qr-info strong{font-size:21px!important}
.qr-payment-actions{display:grid!important;visibility:visible!important;opacity:1!important;position:relative!important;z-index:20!important;margin-top:10px!important;padding:0!important}
.qr-payment-actions-3{grid-template-columns:repeat(3,minmax(0,1fr))!important}
.qr-payment-actions-3 button{display:flex!important;visibility:visible!important;opacity:1!important;align-items:center!important;justify-content:center!important;min-height:48px!important;height:48px!important;position:relative!important;z-index:21!important}
.qr-payment-pay{background:#2563eb!important;color:#fff!important}
.qr-payment-cancel{background:#fff!important;color:#dc2626!important;border:2px solid #dc2626!important}
.qr-payment-print{background:#334155!important;color:#fff!important;border:0!important;border-radius:10px!important;font-weight:900!important;cursor:pointer!important}
.qr-payment-print:hover{background:#1e293b!important}
.qr-payment-print:disabled{opacity:.5!important;cursor:not-allowed!important}
@media(max-width:700px){
  .right{min-height:0}
  .summary{overflow-y:auto!important;max-height:calc(100vh - 180px)!important}
  .promptpay-qr-canvas{width:160px!important;min-height:160px!important}
  .promptpay-qr-canvas img{max-width:150px!important}
  .qr-payment-actions-3{grid-template-columns:1fr 1fr 1fr!important}
  .qr-payment-actions-3 button{font-size:11px!important;padding:8px 4px!important}
}
@media(max-width:420px){
  .qr-payment-actions-3{grid-template-columns:1fr 1fr!important}
  .qr-payment-actions-3 .qr-payment-pay{grid-column:1 / -1!important}
}
</style>
</head>
<body>

<div id="loginScreen" class="login-screen" style="<?= $isLoggedIn ? 'display:none' : 'display:flex' ?>">
  <div class="login-card">
    <div class="login-logo"><i class="fa-solid fa-store"></i></div>
    <h1>SUNTORN POS</h1><p>เข้าสู่ระบบเพื่อใช้งาน POS</p>
    <form onsubmit="doLogin(event)">
      <label>Username</label><input id="loginUsername" autocomplete="username" required>
      <label>Password</label><input id="loginPassword" type="password" autocomplete="current-password" required>
      <button type="submit"><i class="fa-solid fa-right-to-bracket"></i>&nbsp; เข้าสู่ระบบ</button>
      <div id="loginMessage" class="login-message"></div>
    </form>
  </div>
</div>

<div id="mainApp" style="<?= $isLoggedIn ? '' : 'display:none' ?>">
<header class="header">
  <div class="brand">
    <div class="logo"><i class="fa-solid fa-store"></i></div>
    <div><div class="brand-name">SUNTORN POS</div><div class="brand-sub">POINT OF SALE SYSTEM</div></div>
  </div>
  <div class="topnav">
    <button class="active" data-page="sale" onclick="showPage('sale')"><i class="fa-solid fa-cash-register"></i>&nbsp; หน้าขาย (POS)</button>
    <button data-page="cashier" onclick="showPage('cashier')"><i class="fa-solid fa-vault"></i>&nbsp; ปิดรอบ Cashier</button>
    <button data-admin-only="1" data-page="history" onclick="showPage('history')"><i class="fa-solid fa-file-invoice"></i>&nbsp; รายงานย้อนหลัง</button>
    <button data-admin-only="1" data-page="graph" onclick="showPage('graph')"><i class="fa-solid fa-chart-line"></i>&nbsp; กราฟยอดขาย</button>
    <button data-admin-only="1" data-page="tables" onclick="showPage('tables')"><i class="fa-solid fa-qrcode"></i>&nbsp; โต๊ะ/QR</button>
    <button data-page="corders" onclick="showPage('corders')"><i class="fa-solid fa-bell-concierge"></i>&nbsp; ออเดอร์จากลูกค้า<span id="corderBadge" class="corder-badge" style="display:none">0</span></button>
  </div>
  <div class="staff"><i class="fa-solid fa-circle-user text-green-300"></i>&nbsp; <span id="currentUserName">ผู้ใช้งาน</span> <small id="currentUserRole"></small></div>
  <button type="button" data-admin-only="1" data-page="utility" onclick="showPage('utility')" class="nav-btn">
  <i class="fa-solid fa-toolbox"></i>&nbsp; Utility
</button>
  <button type="button" data-page="settings" onclick="showPage('settings')" class="nav-btn">
  <i class="fa-solid fa-gear"></i>&nbsp; ตั้งค่า
</button>
    <button onclick="doLogout()" title="ออกจากระบบ" style="border:0;background:#dc2626;color:#fff;border-radius:6px;padding:6px 9px;margin-left:8px;cursor:pointer"><i class="fa-solid fa-right-from-bracket"></i></button>
</header>

<div class="app">
  <section id="page-sale" class="page active left" style="display:flex;">
    <div id="billRequestBanner"></div>
    <div class="pos-hero">
      <div><span class="hero-kicker">SUNTORN • LIVE POS</span><strong>พร้อมรับรายการขาย</strong><small>เลือกสินค้า หรือสแกน Barcode เพื่อเริ่มบิล</small></div>
      <div class="hero-orb"><i class="fa-solid fa-bolt"></i></div>
    </div>
    <div class="search-wrap">
      <div class="search-box">
        <i class="fa-solid fa-barcode"></i>
        <input id="searchSale" class="search" oninput="filterSale()" placeholder="ค้นหา Barcode / SKU / ชื่อสินค้า...">
      </div>
    </div>
    <div class="chips" id="saleCategoryChips">
      <button type="button" class="chip active" data-category="all" onclick="filterCategory('all',this)">ทั้งหมด</button>
      <?php foreach($productCategories as $category): ?>
        <button type="button" class="chip" data-category="<?=htmlspecialchars($category,ENT_QUOTES)?>" onclick='filterCategory(<?=json_encode($category,JSON_UNESCAPED_UNICODE)?>,this)'><?=htmlspecialchars($category)?></button>
      <?php endforeach; ?>
    </div>
    <div id="productGrid" class="products">
      <?php foreach($products as $p): ?>
      <button type="button" class="product"
        data-name="<?=htmlspecialchars(strtolower($p['name']),ENT_QUOTES)?>"
        data-barcode="<?=htmlspecialchars(strtolower($p['barcode']??''),ENT_QUOTES)?>"
        data-sku="<?=htmlspecialchars(strtolower($p['sku']??''),ENT_QUOTES)?>"
        data-category="<?=htmlspecialchars($p['category']??'',ENT_QUOTES)?>"
        onclick='addToCart(<?=intval($p['id'])?>, <?=json_encode($p['name'], JSON_UNESCAPED_UNICODE)?>, <?=floatval($p['price'])?>, <?=intval($p['stock'])?>)'>
        <div class="product-img">
          <?php if(!empty($p['image'])): ?>
            <img src="<?=htmlspecialchars($p['image'])?>" alt="img">
          <?php else: ?>
            <i class="fa-solid fa-box-open"></i>
          <?php endif; ?>
        </div>
        <div class="product-info">
          <div class="meta"><span class="category"><?=htmlspecialchars($p['category'] ?: 'สินค้า')?></span><span>สต็อก: <?=intval($p['stock'])?></span></div>
          <div class="product-name"><?=htmlspecialchars($p['name'])?></div>
          <div class="product-bottom"><span class="price">฿<?=money($p['price'])?></span><span class="add">+</span></div>
        </div>
      </button>
      <?php endforeach; ?>
    </div>
  </section>

  <section id="page-cart" class="right">
    <div class="cart-head">
      <div>
        <div class="cart-title"><i class="fa-solid fa-receipt text-indigo-600"></i>&nbsp; รายการสั่งซื้อ</div>
        <div class="invoice">เลขที่บิล: <span id="currentReceiptNo">-</span></div>
      </div>
      <button class="clear" onclick="clearCart()">ล้างรายการ</button>
    </div>
    <div class="cart-table">
      <div class="cart-row header"><div>สินค้า</div><div>จำนวน</div><div class="text-right">ยอดรวม</div></div>
      <div id="cartItems"><div class="empty">ยังไม่มีสินค้าในรายการ</div></div>
    </div>
    <div class="summary">
      <div id="autoPrintRow" class="toggle-row" onclick="toggleAutoPrint()" style="cursor:pointer;">
        <span><i class="fa-solid fa-print"></i>&nbsp; พิมพ์ใบเสร็จอัตโนมัติ</span>
        <span id="autoPrintSwitch" class="switch"></span>
      </div>
      <div class="sumrow"><span>รวมก่อนส่วนลด</span><span id="beforeDiscount">฿0.00</span></div>
      <div class="sumrow"><span>ส่วนลด</span><input id="discount" class="discount-input" value="0" oninput="renderCart()"></div>
      <div class="sumrow"><span>VAT 7% (รวมในยอดแล้ว)</span><span id="vat">฿0.00</span></div>
      <div class="net"><span>ยอดชำระสุทธิ</span><strong id="grandTotal">฿0.00</strong></div>
      <div class="pay-title">ประเภทการชำระ</div>
      <div class="pay-type-wrap">
        <button type="button" id="payTypeBtn" class="pay-type-btn" onclick="togglePayMethods()">
          <span><i class="fa-solid fa-wallet"></i>&nbsp; ประเภทการชำระ: <span id="payTypeSelected" class="selected">เงินสด</span></span>
          <i id="payTypeChevron" class="fa-solid fa-chevron-down"></i>
        </button>
        <div id="payMethodsMenu" class="pay-methods">
          <button type="button" class="pay-method active" onclick="selectPay('cash',this)"><i class="fa-solid fa-money-bill-wave"></i>&nbsp; เงินสด</button>
          <button type="button" class="pay-method" onclick="selectPay('qr',this)"><i class="fa-solid fa-qrcode"></i>&nbsp; ชำระ QR โอน</button>
        </div>
      </div>
      <div class="cash-box" id="cashBox">
        <div class="cash-label"><span>รับเงินสด (฿):</span><input id="cash" class="cash-input" type="number" min="0" step=".01" value="0" oninput="calcChange()"></div>
        <div class="quick">
          <button onclick="setCash('exact')">พอดี</button><button onclick="setCash(20)">20</button><button onclick="setCash(50)">50</button><button onclick="setCash(100)">100</button><button onclick="setCash(500)">500</button><button onclick="setCash(1000)">1000</button>
        </div>
        <div class="change"><span>เงินทอน:</span><span id="change">฿0.00</span></div>
      </div>
      <div id="promptpayQrBox" class="promptpay-qr-box" style="display:none;">
        <div class="promptpay-qr-head">
          <div><i class="fa-solid fa-qrcode"></i> QR พร้อมเพย์</div>
          <span id="promptpayQrStatus">กำลังเตรียม QR</span>
        </div>
        <div class="promptpay-qr-content">
          <div id="promptpayQrCanvas" class="promptpay-qr-canvas"></div>
          <div class="promptpay-qr-info">
            <div class="promptpay-label">สแกนเพื่อชำระเงิน</div>
            <strong id="promptpayQrAmount">฿0.00</strong>
            <div class="promptpay-number">พร้อมเพย์: <span id="promptpayQrId">-</span></div>
            <div class="promptpay-note">QR จะสร้างจากเลขพร้อมเพย์ที่บันทึกไว้ในหน้าตั้งค่า และยอดชำระปัจจุบัน</div>
          </div>
        </div>
        <div id="qrPaymentActions" class="qr-payment-actions qr-payment-actions-3" style="display:grid!important;visibility:visible!important;opacity:1!important;">
          <button type="button" id="qrPaymentPayBtn" class="qr-payment-pay" onclick="confirmQRPayment()" style="display:flex!important;visibility:visible!important;">
            <i class="fa-solid fa-money-check-dollar"></i>&nbsp; ชำระ
          </button>
          <button type="button" id="qrPaymentCancelBtn" class="qr-payment-cancel" onclick="cancelQRPayment()" style="display:flex!important;visibility:visible!important;">
            <i class="fa-solid fa-circle-xmark"></i>&nbsp; ยกเลิกชำระ
          </button>
          <button type="button" id="qrPaymentPrintBtn" class="qr-payment-print" onclick="printLastReceipt()" disabled style="display:flex!important;visibility:visible!important;">
            <i class="fa-solid fa-print"></i>&nbsp; พิมพ์
          </button>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 120px;gap:5px;margin-top:5px;">
        <button id="checkoutBtn" class="checkout" onclick="checkout()" style="margin-top:0;"><i class="fa-solid fa-circle-check"></i>&nbsp; ชำระเงิน</button>
        <button id="printReceiptBtn" onclick="printLastReceipt()" disabled style="height:36px;background:#334155;border:0;border-radius:6px;color:#fff;font-weight:800;font-size:11px;cursor:pointer;opacity:.5;">
          <i class="fa-solid fa-print"></i>&nbsp; พิมพ์ใบเสร็จ
        </button>
      </div>
    </div>
  </section>

  <!-- หน้าจัดการรายการสินค้า (ตามภาพที่แนบมา) -->
  <section id="page-products" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-1">
        <div>
          <h2 class="text-base font-bold text-slate-800">จัดการสินค้าและสต็อก</h2>
          <p class="text-[11px] text-slate-500">ซิงค์ข้อมูลตรงกับ Google Sheets</p>
        </div>
        <button onclick="openProductModal()" class="bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow hover:bg-indigo-700">+ เพิ่มสินค้าใหม่</button>
      </div>
      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>รูปภาพ</th>
              <th>BARCODE</th>
              <th>SKU</th>
              <th>ชื่อสินค้า</th>
              <th>หมวดหมู่</th>
              <th>ราคาขาย</th>
              <th>STOCK</th>
              <th>จัดการ</th>
            </tr>
          </thead>
          <tbody id="productTable"></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- หน้ากราฟยอดขาย แยกออกจากหน้ารายงานย้อนหลัง -->
  <section id="page-graph" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-3 gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-bold text-slate-800"><i class="fa-solid fa-chart-line text-indigo-600"></i> กราฟยอดขาย</h2>
          <p class="text-xs text-slate-500">แสดงยอดขายวันปัจจุบันเป็นกราฟแท่งรายชั่วโมง แยกตามประเภทการชำระเงิน (เงินสด / QR โอน)</p>
        </div>
        <div id="graphReportHeader" class="text-xs"></div>
      </div>

      <div class="flex flex-wrap items-center gap-2 mb-4 no-print">
        <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-2 rounded-lg text-xs font-bold">วันที่ปัจจุบัน: <span id="graphTodayLabel">-</span></span>
        <button type="button" onclick="loadSalesGraph()" class="bg-emerald-600 text-white px-3 py-2 rounded-lg text-xs font-bold">รีเฟรช</button>
        <button type="button" onclick="printSalesGraph()" class="bg-slate-800 text-white px-3 py-2 rounded-lg text-xs font-bold">พิมพ์ A4</button>
      </div>

      <div id="salesGraphBlock" class="sales-graph-dashboard">
        <div class="sales-graph-topline">
          <div>
            <div class="sales-graph-kicker"><i class="fa-solid fa-chart-column"></i> LIVE SALES ANALYTICS</div>
            <h3 id="graphChartTitle" class="sales-graph-title">กราฟยอดขายรายชั่วโมง</h3>
            <div id="graphRange" class="sales-graph-range"></div>
          </div>
          <div class="sales-graph-live"><span></span> LIVE</div>
        </div>
        <div id="graphPaymentSummary" class="graph-summary-grid"></div>
        <div class="sales-chart-frame">
          <div class="sales-chart-glow glow-one"></div><div class="sales-chart-glow glow-two"></div>
          <div class="sales-chart-scroll">
            <div class="sales-chart-canvas-wrap">
              <canvas id="salesGraphChart"></canvas>
            </div>
          </div>
          <div class="sales-chart-axis-note"><span>ยอดขาย (บาท)</span><b>เวลา (ชั่วโมง)</b><span>00:00 — 23:00</span></div>
        </div>
        <div id="graphEmpty" class="hidden text-center text-sm text-slate-300 py-8">ไม่พบข้อมูลการขายของวันนี้</div>
      </div>
    </div>
  </section>

  <!-- หน้ารายงานย้อนหลัง -->
  <section id="page-history" class="page">
    <div class="panel">
      <!-- ส่วนหัวเดิม/กรอบข้อมูลบริษัทด้านบนถูกซ่อนตามรูปแบบใหม่
           ให้ผู้ใช้เลือกช่วงวันที่ภายในรายงานที่กำลังเปิดอยู่ -->
      <div id="reportRange" class="hidden"></div>
      <div id="reportHeader" class="hidden"></div>

      <div class="history-report-layout no-print" id="historyReportLayout">
        <aside class="history-report-sidebar">
          <div class="history-report-sidebar-head">
            <div class="history-report-sidebar-icon"><i class="fa-solid fa-file-lines"></i></div>
            <div>
              <div class="history-report-sidebar-title">รายงานย้อนหลัง</div>
              <div class="history-report-sidebar-subtitle">เลือกประเภทรายงาน</div>
            </div>
          </div>

          <div class="history-report-menu" id="reportSelector">
            <button type="button" onclick="switchReport('cashier')" data-report="cashier" class="report-btn active">
              <span class="report-menu-icon blue"><i class="fa-solid fa-cash-register"></i></span>
              <span class="report-menu-text"><b>1. สรุปยอด Cashier</b><small>รายงานสรุปยอดขายแคชเชียร์</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
            <button type="button" onclick="switchReport('store')" data-report="store" class="report-btn">
              <span class="report-menu-icon green"><i class="fa-solid fa-store"></i></span>
              <span class="report-menu-text"><b>2. สรุปยอดร้านค้า</b><small>สรุปยอดขายแยกร้านค้า</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
            <button type="button" onclick="switchReport('item')" data-report="item" class="report-btn">
              <span class="report-menu-icon orange"><i class="fa-solid fa-cart-shopping"></i></span>
              <span class="report-menu-text"><b>3. รายงาน Item</b><small>ยอดขายแยกตามสินค้า</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
            <button type="button" onclick="switchReport('tax')" data-report="tax" class="report-btn">
              <span class="report-menu-icon purple"><i class="fa-solid fa-file-invoice-dollar"></i></span>
              <span class="report-menu-text"><b>4. รายงานภาษีขาย</b><small>ยอดขายและ VAT</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
            <button type="button" onclick="switchReport('stock')" data-report="stock" class="report-btn">
              <span class="report-menu-icon teal"><i class="fa-solid fa-boxes-stacked"></i></span>
              <span class="report-menu-text"><b>5. รายงาน STOCK</b><small>สินค้าคงเหลือและยอดขาย</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
            <button type="button" onclick="switchReport('void')" data-report="void" class="report-btn">
              <span class="report-menu-icon red"><i class="fa-solid fa-ban"></i></span>
              <span class="report-menu-text"><b>6. รายงานยกเลิกสินค้า</b><small>รายการสินค้า Void</small></span>
              <i class="fa-solid fa-chevron-right report-menu-arrow"></i>
            </button>
          </div>
        </aside>

        <main class="history-report-content">
          <div class="history-report-content-head">
            <div class="selected-report-heading">
              <div class="selected-report-heading-top">
                <div>
                  <div id="selectedReportCode" class="selected-report-code">FIN001</div>
                  <h3 id="selectedReportTitle" class="selected-report-title">รายงานสรุปยอดขาย Cashier</h3>
                  <div id="selectedReportDescription" class="selected-report-description">รายงานสรุปยอดขายแยกตาม Cashier</div>
                </div>
                <div class="selected-report-status"><span></span>พร้อมแสดงรายงาน</div>
              </div>
              <div class="report-inline-toolbar no-print">
                <div class="report-date-field">
                  <label for="fromDate"><i class="fa-regular fa-calendar"></i> จากวันที่</label>
                  <input id="fromDate" type="date" onchange="selectReportDate(this.value,'from')">
                </div>
                <div class="report-date-field">
                  <label for="toDate"><i class="fa-regular fa-calendar"></i> ถึงวันที่</label>
                  <input id="toDate" type="date" onchange="selectReportDate(this.value,'to')">
                </div>
                <div class="report-toolbar-actions">
                  <button type="button" onclick="clearReportPanel()" class="report-tool-btn light"><i class="fa-solid fa-broom"></i>ล้าง</button>
                  <button type="button" onclick="exportSelectedReport()" class="report-tool-btn excel"><i class="fa-solid fa-file-excel"></i>Excel</button>
                  <button type="button" onclick="printSelectedReportA4()" class="report-tool-btn print"><i class="fa-solid fa-print"></i>พิมพ์ A4</button>
                </div>
              </div>
            </div>
          </div>
          <div id="customReportPanel" class="report-scroll-panel report-content-scroll"></div>
        </main>
      </div>

      <!-- ส่วนเก่าของหน้ารายงาน ใช้เป็นข้อมูลประกอบ/รองรับ JavaScript เดิม และซ่อนไว้ -->
      <div id="itemReportBlock" class="hidden">
        <table><tbody id="historyTable"></tbody></table>
      </div>
      <div id="historySummary" class="hidden"></div>
      <div id="chartTitle" class="hidden"></div>
      <div id="reportPreviewModalPlaceholder" class="hidden"></div>
    </div>
  </section>

  <!-- หน้าค้นหา Void: แสดงเฉพาะเมนู/บิลที่ขายของวันนี้ และ Void ได้จากหน้านี้ -->
  <section id="page-void" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-3">
        <div>
          <h2 class="text-lg font-bold text-slate-800"><i class="fa-solid fa-ban text-red-600"></i> ค้นหาข้อมูล Void</h2>
          <p class="text-xs text-slate-500">แสดงเฉพาะเมนูที่ขายในวันปัจจุบัน <b id="voidTodayLabel"></b> และสามารถ Void แยกเป็นรายชิ้นได้จากหน้านี้ (เช่น ซื้อ 3 ชิ้น Void แค่ 1 ชิ้นก็ได้)</p>
        </div>
        <span class="bg-red-50 text-red-700 border border-red-200 rounded-lg px-3 py-1.5 text-xs font-bold"><i class="fa-solid fa-calendar-day"></i> วันนี้</span>
      </div>

      <div class="flex flex-wrap gap-2 mb-4 items-center">
        <div class="relative flex-1 min-w-[240px]">
          <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
          <input id="voidReceiptSearch" type="text" class="w-full border border-slate-300 rounded-lg pl-9 pr-3 py-2 text-sm outline-none focus:ring-2 focus:ring-red-200 focus:border-red-400" placeholder="ค้นหาเลขที่ใบเสร็จของวันนี้..." oninput="loadVoidToday()">
        </div>
        <button type="button" onclick="loadVoidToday()" class="bg-red-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-red-700">
          <i class="fa-solid fa-magnifying-glass mr-1"></i>ค้นหา
        </button>
        <button type="button" onclick="clearVoidTodaySearch()" class="bg-slate-100 border border-slate-300 text-slate-700 px-4 py-2 rounded-lg text-xs font-bold">
          <i class="fa-solid fa-eraser mr-1"></i>ล้าง
        </button>
      </div>

      <div id="voidTodaySummary" class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4"></div>

      <div id="voidTodayEmpty" class="hidden p-8 text-center text-slate-400 border border-dashed border-slate-300 rounded-xl mb-3">
        <i class="fa-solid fa-receipt text-2xl mb-2"></i>
        <div class="font-semibold">ไม่พบรายการขายของวันนี้</div>
      </div>

      <div class="table-container">
        <table class="table">
          <thead>
            <tr>
              <th>วันเวลา</th>
              <th>เลขที่ใบเสร็จ</th>
              <th>เมนู / สินค้า</th>
              <th>จำนวน</th>
              <th>ราคา</th>
              <th>ยอดสุทธิ</th>
              <th>ชำระ</th>
              <th class="no-print">ดำเนินการ</th>
            </tr>
          </thead>
          <tbody id="voidTodayTable"></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ตัวอย่างรายงาน A4 ตามรูปแบบรายงานอ้างอิง -->
  <div id="reportPreviewModal" class="fixed inset-0 z-[9999] hidden bg-black/50 p-3 md:p-6 overflow-auto no-print">
    <div class="mx-auto max-w-[1100px] bg-white rounded-xl shadow-2xl min-h-full md:min-h-0">
      <div class="sticky top-0 z-10 flex items-center justify-between gap-2 px-4 py-3 border-b bg-white rounded-t-xl no-print">
        <div id="reportPreviewTitle" class="font-bold text-sm text-slate-800">ตัวอย่างรายงาน</div>
        <div class="flex gap-2">
          <button type="button" onclick="printReportPreview()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-print mr-1"></i>พิมพ์ A4</button>
          <button type="button" onclick="closeReportPreview()" class="bg-slate-100 text-slate-700 border border-slate-300 px-4 py-2 rounded-lg text-xs font-bold">ปิด</button>
        </div>
      </div>
      <div id="reportPreviewContent" class="p-5"></div>
    </div>
  </div>

  <!-- หน้าปิดวันขาย (รีเซ็ตยอดเป็น 0 หลังปิดระบบ) -->
  <section id="page-close" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-2">
        <div><h2 class="text-lg font-bold text-slate-800">ปิดวันขาย / ปิดรอบการขาย</h2><p class="text-xs text-slate-500 mb-1">สามารถปิดได้หลายครั้งต่อวัน แต่ละรอบจะแยกยอดและเก็บประวัติแยกกัน</p></div>
        <button onclick="loadCloseSummary();loadDayClosingHistory()" class="bg-slate-100 border border-slate-300 text-slate-700 px-3 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-rotate"></i> รีเฟรช</button>
      </div>
      <div id="closeSummary" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4"></div>
      <div class="flex gap-2 mb-4"><button onclick="closeDay()" class="bg-red-600 text-white px-5 py-2.5 rounded-lg text-xs font-bold shadow hover:bg-red-700"><i class="fa-solid fa-lock"></i> ปิดรอบการขาย</button></div>
      <div class="border-t border-slate-200 pt-4">
        <div class="flex justify-between items-center mb-2"><div><h3 class="font-bold text-sm text-slate-800">ประวัติการปิดการขาย</h3><p class="text-[11px] text-slate-500">แต่ละแถวคือ 1 รอบที่ปิด ยอดไม่รวมกันข้ามรอบ</p></div><span id="dayClosingHistoryInfo" class="text-[11px] text-slate-500"></span></div>
        <div class="table-container"><table class="table"><thead><tr><th>ครั้งที่</th><th>วันที่</th><th>เริ่มรอบ</th><th>ปิดรอบ</th><th>จำนวนบิล</th><th>ยอดขาย</th><th>เงินสด</th><th>ผู้ปิด</th></tr></thead><tbody id="dayClosingHistoryTable"></tbody></table></div>
      </div>
    </div>
  </section>

  <!-- หน้าปิดรอบ Cashier: Staff และ Admin ใช้งานได้ -->
  <section id="page-cashier" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-2">
        <div><h2 class="text-lg font-bold text-slate-800"><i class="fa-solid fa-vault text-indigo-600"></i> ปิดรอบ Cashier</h2><p class="text-xs text-slate-500">นับเงินตามธนบัตร/เหรียญ นำเงินออกระหว่างรอบได้ และพิมพ์สรุปยอดขาย</p></div>
        <div class="flex gap-2">
          <button onclick="loadCashierRound()" class="bg-slate-100 border border-slate-300 text-slate-700 px-3 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-rotate"></i> รีเฟรช</button>
          <button onclick="confirmCashOut()" id="cashOutActionBtn" class="bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-money-bill-transfer"></i> นำเงินออก</button>
          <button onclick="printCashierSummary()" class="bg-slate-700 text-white px-3 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-print"></i> พิมพ์</button>
          <button onclick="saveCashierClose()" class="bg-red-600 text-white px-3 py-2 rounded-lg text-xs font-bold"><i class="fa-solid fa-lock"></i> ปิดรอบ Cashier</button>
        </div>
      </div>
      <div id="cashierSummaryTop" class="cashier-summary-grid mb-3"></div>
      <div class="cashier-grid">
        <div class="cashier-card">
          <h3 class="font-bold text-sm text-slate-800 mb-2">💵 เงินสดที่นับได้</h3>
          <div id="cashDenomList"></div>
          <div class="cashier-total border-t pt-2 mt-2">รวมเงินที่นับได้: ฿<span id="cashCountedTotal">0.00</span></div>
        </div>
        <div class="cashier-card">
          <h3 class="font-bold text-sm text-slate-800 mb-2">💸 เงินออกจากลิ้นชัก</h3>
          <p class="text-[11px] text-slate-500 mb-2">ระบุเงินที่ต้องการนำออก แล้วกด “นำเงินออก” ได้ทันที โดยไม่ต้องปิดรอบ</p>
          <div id="cashOutDenomList"></div>
          <div class="cashier-total border-t pt-2 mt-2">รวมเงินออก: ฿<span id="cashOutTotal">0.00</span></div>
        </div>
      </div>
      <div class="mt-3 p-3 rounded-xl bg-indigo-50 border border-indigo-200">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-xs">
          <div><span class="text-slate-500">เงินสดที่ควรมี</span><br><b id="cashExpected" class="text-lg text-indigo-700">฿0.00</b></div>
          <div><span class="text-slate-500">เงินนับได้ + เงินออก</span><br><b id="cashCheckTotal" class="text-lg text-slate-800">฿0.00</b></div>
          <div><span class="text-slate-500">ส่วนต่าง</span><br><b id="cashVariance" class="text-lg text-green-600">฿0.00</b></div>
          <div><span class="text-slate-500">รอบล่าสุด</span><br><b id="cashLastClose" class="text-xs text-slate-700">ยังไม่มี</b></div>
        </div>
      </div>
    </div>
  </section>

  <!-- หน้า Utility: เมนูจัดการระบบ -->
  <section id="page-utility" class="page">
    <div class="panel max-w-5xl mx-auto w-full">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="text-lg font-extrabold text-slate-800"><i class="fa-solid fa-toolbox text-indigo-600"></i>&nbsp; Utility</h2>
          <p class="text-xs text-slate-500 mt-1">รวมเครื่องมือจัดการระบบสำหรับ Administrator</p>
        </div>
        <span class="px-3 py-1.5 rounded-full bg-indigo-50 text-indigo-700 text-[11px] font-bold border border-indigo-100"><i class="fa-solid fa-shield-halved"></i>&nbsp; Admin Only</span>
      </div>

      <div class="grid grid-cols-2 md:grid-cols-3 gap-2.5 max-w-3xl">
        <button type="button" onclick="showPage('products')" class="utility-card text-left border border-slate-200 rounded-xl p-2.5 bg-white hover:bg-indigo-50 hover:border-indigo-300 transition shadow-sm group">
          <div class="w-9 h-9 rounded-lg bg-indigo-100 text-indigo-600 flex items-center justify-center mb-2 group-hover:scale-105 transition"><i class="fa-solid fa-boxes-stacked text-sm"></i></div>
          <b class="block text-xs text-slate-800">รายการสินค้า</b>
          <small class="text-[9px] text-slate-500">สินค้า ราคา และ Stock</small>
        </button>

        <button type="button" onclick="showPage('users')" class="utility-card text-left border border-slate-200 rounded-xl p-2.5 bg-white hover:bg-emerald-50 hover:border-emerald-300 transition shadow-sm group">
          <div class="w-9 h-9 rounded-lg bg-emerald-100 text-emerald-600 flex items-center justify-center mb-2 group-hover:scale-105 transition"><i class="fa-solid fa-users text-sm"></i></div>
          <b class="block text-xs text-slate-800">User</b>
          <small class="text-[9px] text-slate-500">จัดการ User และสิทธิ์</small>
        </button>

        <button type="button" onclick="showPage('void')" class="utility-card text-left border border-red-200 rounded-xl p-2.5 bg-white hover:bg-red-50 hover:border-red-400 transition shadow-sm group">
          <div class="w-9 h-9 rounded-lg bg-red-100 text-red-600 flex items-center justify-center mb-2 group-hover:scale-105 transition"><i class="fa-solid fa-ban text-sm"></i></div>
          <b class="block text-xs text-slate-800">ค้นหา Void</b>
          <small class="text-[9px] text-slate-500">ค้นหา Void ของวันนี้</small>
        </button>

        <button type="button" onclick="showPage('close')" class="utility-card utility-close text-left border border-amber-200 rounded-xl p-2.5 bg-white hover:bg-amber-50 hover:border-amber-400 transition shadow-sm group md:col-start-2">
          <div class="w-9 h-9 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center mb-2 group-hover:scale-105 transition"><i class="fa-solid fa-chart-line text-sm"></i></div>
          <b class="block text-xs text-slate-800">ปิดวันขาย</b>
          <small class="text-[9px] text-slate-500">ปิดรอบและดูประวัติ</small>
        </button>
      </div>
    </div>
  </section>

  <!-- หน้าตั้งค่า -->
  <section id="page-settings" class="page">
    <div class="panel max-w-xl mx-auto w-full">
      <h2 class="text-lg font-bold text-slate-800 mb-3">ตั้งค่าระบบ</h2>

      <div class="mb-4 p-3 rounded-xl bg-indigo-50 border border-indigo-200">
        <div class="flex items-center justify-between gap-3">
          <div>
            <div class="text-sm font-bold text-indigo-800">ข้อมูลร้าน / บริษัท</div>
            <p class="text-[11px] text-indigo-600 mt-1">กรอกข้อมูลร้าน/บริษัทสำหรับแสดงบนใบเสร็จและรายงาน</p>
          </div>
          <button type="button" onclick="toggleShopSettings()" class="shrink-0 bg-indigo-600 text-white px-3 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700">
            <i class="fa-solid fa-pen-to-square"></i> แก้ไขข้อมูล
          </button>
        </div>
      </div>
      <div id="shopSettingsForm" class="mt-4 border border-slate-200 rounded-xl p-4 bg-white">
        <div class="flex justify-between items-center mb-3">
          <div>
            <h3 class="text-sm font-bold text-slate-800">ข้อมูลร้าน / บริษัท</h3>
            <p class="text-[10px] text-slate-500">แก้ไขข้อมูลที่ใช้แสดงบนใบเสร็จและรายงาน</p>
          </div>
          <button type="button" onclick="toggleShopSettings(false)" class="text-xs text-slate-500 hover:text-slate-800">
            <i class="fa-solid fa-xmark"></i> ปิด
          </button>
        </div>
        <label class="text-xs font-semibold text-slate-700">ชื่อร้าน / บริษัท</label>
        <input id="shopName" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-2 text-xs focus:outline-none focus:border-indigo-500">
        <label class="text-xs font-semibold text-slate-700">ที่อยู่บริษัท</label>
        <input id="companyAddress" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-2 text-xs focus:outline-none focus:border-indigo-500">
        <label class="text-xs font-semibold text-slate-700">เบอร์โทรศัพท์</label>
        <input id="companyPhone" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-2 text-xs focus:outline-none focus:border-indigo-500">
        <label class="text-xs font-semibold text-slate-700">เลขประจำตัวผู้เสียภาษี (TAX ID)</label>
        <input id="companyTaxId" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-2 text-xs focus:outline-none focus:border-indigo-500">
        <label class="text-xs font-semibold text-slate-700">เลขพร้อมเพย์</label>
        <input id="promptpayId" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-4 text-xs focus:outline-none focus:border-indigo-500">
        <div>
          <button type="button" onclick="saveSettings()" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-xs font-bold shadow hover:bg-indigo-700">
            บันทึกการตั้งค่า
          </button>
        </div>
      </div>
    </div>
  </section>

  <!-- หน้าจัดการ User: Admin เท่านั้น -->
  <section id="page-users" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-3">
        <div><h2 class="text-lg font-bold text-slate-800">จัดการผู้ใช้งาน</h2><p class="text-xs text-slate-500">Admin เพิ่ม User / กำหนดสิทธิ์ / ลบ User ได้</p></div>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-4 gap-2 bg-slate-50 border border-slate-200 rounded-xl p-3 mb-3">
        <div><label class="text-xs font-semibold">Username</label><input id="newUsername" class="w-full border border-slate-300 rounded-lg p-2 mt-1 text-xs" placeholder="เช่น cashier01"></div>
        <div><label class="text-xs font-semibold">ชื่อผู้ใช้งาน</label><input id="newFullname" class="w-full border border-slate-300 rounded-lg p-2 mt-1 text-xs" placeholder="ชื่อพนักงาน"></div>
        <div><label class="text-xs font-semibold">Password</label><input id="newPassword" type="password" class="w-full border border-slate-300 rounded-lg p-2 mt-1 text-xs" placeholder="อย่างน้อย 4 ตัว"></div>
        <div><label class="text-xs font-semibold">สิทธิ์</label><select id="newRole" class="w-full border border-slate-300 rounded-lg p-2 mt-1 text-xs"><option value="staff">Staff - หน้าขายเท่านั้น</option><option value="admin">Admin - ทุกหน้า</option></select></div>
        <div class="md:col-span-4"><button onclick="saveUser()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700"><i class="fa-solid fa-user-plus"></i>&nbsp; เพิ่ม User</button></div>
      </div>
      <div class="table-container"><table class="table"><thead><tr><th>ID</th><th>Username</th><th>ชื่อผู้ใช้งาน</th><th>สิทธิ์</th><th>สร้างเมื่อ</th><th>จัดการ</th></tr></thead><tbody id="userTable"></tbody></table></div>
    </div>
  </section>

  <!-- หน้าจัดการโต๊ะ + QR Code ให้ลูกค้าสแกนสั่งอาหารเอง (Admin เท่านั้น) -->
  <section id="page-tables" class="page">
    <div class="panel">
      <div class="flex justify-between items-center mb-3">
        <div><h2 class="text-lg font-bold text-slate-800">โต๊ะ &amp; QR Code สั่งอาหาร</h2><p class="text-xs text-slate-500">สร้าง QR ต่อโต๊ะ ให้ลูกค้าสแกนแล้วสั่งอาหารได้เอง เมนูจะขึ้นให้ลูกค้าเลือกทันที</p></div>
      </div>
      <div class="flex gap-2 mb-3">
        <input id="newTableNo" placeholder="เช่น T1, โต๊ะ 5" class="border border-slate-300 rounded-lg p-2 text-xs flex-1 max-w-xs">
        <button onclick="addTable()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-xs font-bold hover:bg-indigo-700"><i class="fa-solid fa-plus"></i>&nbsp; เพิ่มโต๊ะ</button>
      </div>
      <div id="tableGrid" class="table-grid"></div>
    </div>
  </section>

  <!-- หน้าออเดอร์ที่ลูกค้าสั่งเองจากการสแกน QR (Admin + Staff) -->
  <section id="page-corders" class="page">
    <div class="panel max-w-2xl mx-auto w-full">
      <div class="flex justify-between items-center mb-3 gap-3 flex-wrap">
        <div><h2 class="text-lg font-bold text-slate-800">ออเดอร์จากลูกค้า (สแกน QR)</h2><p class="text-xs text-slate-500">รายการที่ลูกค้าสั่งเองจากโต๊ะ กดรับออเดอร์ แล้วโหลดเข้าตะกร้าเพื่อชำระเงินที่หน้าขายได้</p></div>
        <div class="flex items-center gap-2">
          <button id="kitchenPrintRow" type="button" onclick="toggleKitchenPrint()" class="px-3 py-2 rounded-lg text-xs font-bold border shadow-sm flex items-center gap-2" style="background:#fff7ed;border-color:#fdba74;color:#9a3412;cursor:pointer;">
            <i class="fa-solid fa-fire-burner"></i>
            <span>พิมพ์ออเดอร์ไปครัว</span>
            <span id="kitchenPrintSwitch" class="switch"></span>
            <strong id="kitchenPrintStatus">ปิด</strong>
          </button>
          <button onclick="loadCorders()" class="bg-slate-100 text-slate-700 px-3 py-2 rounded-lg text-xs font-bold hover:bg-slate-200"><i class="fa-solid fa-rotate"></i>&nbsp; รีเฟรช</button>
        </div>
      </div>
      <div id="corderList"></div>
    </div>
  </section>
</div>

<div id="receiptPrintArea"></div>
<div id="kitchenPrintArea" class="kitchen-print-area"></div>

<div id="receiptPreviewModal" class="receipt-preview-modal" onclick="if(event.target===this)closeReceiptPreview()">
  <div class="receipt-window">
    <div class="receipt-window-head">
      <span><i class="fa-solid fa-receipt"></i>&nbsp; ใบเสร็จรับเงิน (80mm)</span>
      <div class="receipt-window-actions">
        <button class="receipt-print-btn" onclick="printReceiptNow()"><i class="fa-solid fa-print"></i>&nbsp; พิมพ์</button>
        <button class="receipt-close-btn" onclick="closeReceiptPreview()">ปิด</button>
      </div>
    </div>
    <div class="receipt-paper-wrap">
      <div id="receiptPreviewPaper" class="receipt-paper"></div>
    </div>
  </div>
</div>


<!-- ป๊อปอัปเพิ่ม/แก้ไขสินค้า (ตามภาพที่แนบมา) -->
<div id="productModal" class="modal">
 <div class="modal-card">
  <div class="flex justify-between items-center mb-2">
    <h3 id="productModalTitle" class="font-bold text-base text-slate-800">เพิ่มสินค้าใหม่</h3>
    <button onclick="closeProductModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <input type="hidden" id="editId">
  
  <label class="text-xs font-semibold text-slate-600">รูปภาพสินค้า (เลือกจากเครื่อง หรือ URL)</label>
  <div class="flex gap-2 items-center mb-2">
    <input type="file" id="imageFile" accept="image/*" onchange="previewImageFile(this)" class="text-xs border border-slate-300 rounded-lg p-1 w-full m-0">
  </div>
  <input id="pImage" placeholder="หรือวางลิงก์รูปภาพ (Image URL)">

  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="text-xs font-semibold text-slate-600">Barcode *</label>
      <input id="pBarcode" placeholder="Barcode">
    </div>
    <div>
      <label class="text-xs font-semibold text-slate-600">SKU *</label>
      <input id="pSKU" placeholder="SKU">
    </div>
  </div>

  <label class="text-xs font-semibold text-slate-600">ชื่อสินค้า *</label>
  <input id="pName" placeholder="ชื่อสินค้า">

  <label class="text-xs font-semibold text-slate-600">หมวดหมู่ *</label>
  <input id="pCategory" placeholder="เช่น เครื่องดื่ม, อาหาร">

  <div class="grid grid-cols-3 gap-2">
    <div>
      <label class="text-xs font-semibold text-slate-600">ราคาทุน</label>
      <input id="pCostPrice" type="number" step=".01" placeholder="0">
    </div>
    <div>
      <label class="text-xs font-semibold text-slate-600">ราคาขาย *</label>
      <input id="pPrice" type="number" step=".01" placeholder="0">
    </div>
    <div>
      <label class="text-xs font-semibold text-slate-600">คลังสต็อก</label>
      <input id="pStock" type="number" placeholder="0">
    </div>
  </div>

  <div class="flex gap-2 mt-3">
    <button onclick="saveProduct()" class="flex-1 bg-indigo-600 text-white py-2 rounded-lg font-bold text-xs shadow hover:bg-indigo-700">บันทึกลง Sheets</button>
    <button onclick="closeProductModal()" class="flex-1 bg-slate-200 text-slate-700 py-2 rounded-lg font-semibold text-xs">ยกเลิก</button>
  </div>
 </div>
</div>
</div>

<!-- Modal ยืนยัน Void ด้วย Admin -->
<div id="voidAdminModal" class="hidden fixed inset-0 z-[9999] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4"><div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden"><div class="bg-gradient-to-r from-red-600 to-rose-500 text-white px-5 py-4 flex items-center justify-between"><div><div class="font-extrabold text-lg"><i class="fa-solid fa-shield-halved"></i> ยืนยันการ Void</div><div class="text-xs text-red-100 mt-1">เฉพาะ User ที่มีสิทธิ์ Admin เท่านั้น</div></div><button type="button" onclick="closeVoidAdminModal()" class="w-9 h-9 rounded-full bg-white/15">&times;</button></div><div class="p-5"><div id="voidAdminInfo" class="bg-red-50 border border-red-100 text-red-700 rounded-xl p-3 text-sm font-semibold mb-4"></div><div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3 mb-4 text-xs text-emerald-800"><b>สิทธิ์การ Void:</b> ระบบจะตรวจสอบจาก User ที่ Login อยู่และต้องมี Role = Admin</div><div class="mt-4 flex gap-2 justify-end"><button type="button" onclick="closeVoidAdminModal()" class="px-4 py-2.5 rounded-xl bg-slate-100 font-bold text-sm">ยกเลิก</button><button type="button" id="btnConfirmVoidAdmin" onclick="confirmVoidWithAdmin()" class="px-4 py-2.5 rounded-xl bg-red-600 text-white font-bold text-sm"><i class="fa-solid fa-ban"></i> ยืนยัน Void</button></div></div></div></div>

<!-- Modal กรอกจำนวน/เหตุผล Void (แทน prompt() ซึ่งบางเบราว์เซอร์/เว็บวิวบล็อกจนปุ่ม Void ไม่ตอบสนอง) -->
<div id="voidQtyModal" class="hidden fixed inset-0 z-[9999] bg-slate-950/70 backdrop-blur-sm flex items-center justify-center p-4">
  <div class="w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
    <div class="bg-gradient-to-r from-red-600 to-rose-500 text-white px-5 py-4 flex items-center justify-between">
      <div>
        <div class="font-extrabold text-lg"><i class="fa-solid fa-ban"></i> Void สินค้า</div>
        <div class="text-xs text-red-100 mt-1" id="voidQtyProductLabel"></div>
      </div>
      <button type="button" onclick="closeVoidQtyModal()" class="w-9 h-9 rounded-full bg-white/15">&times;</button>
    </div>
    <div class="p-5">
      <label class="text-xs font-semibold text-slate-600">จำนวนที่ต้องการ Void</label>
      <input id="voidQtyInput" type="number" min="1" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-3 text-sm">
      <label class="text-xs font-semibold text-slate-600">เหตุผลการ Void</label>
      <input id="voidQtyReason" type="text" class="w-full border border-slate-300 rounded-lg p-2 mt-1 mb-4 text-sm" value="ยกเลิกรายการ / รายการผิด">
      <div class="flex gap-2 justify-end">
        <button type="button" onclick="closeVoidQtyModal()" class="px-4 py-2.5 rounded-xl bg-slate-100 font-bold text-sm">ยกเลิก</button>
        <button type="button" onclick="confirmVoidQty()" class="px-4 py-2.5 rounded-xl bg-red-600 text-white font-bold text-sm"><i class="fa-solid fa-arrow-right"></i> ถัดไป</button>
      </div>
    </div>
  </div>
</div>

<script>
let cart=[],payMethod='cash';
let historyChart = null;
// ตัวนับลำดับคำขอของหน้ารายงานย้อนหลัง ป้องกัน "เผลอโชว์ข้อมูลผิดวัน" ที่เกิดจาก
// การยิง request ซ้อนกัน (เช่น เปลี่ยนวันที่เร็ว ๆ) แล้ว response เก่ามาถึงทีหลัง
// มาทับ response ใหม่ที่ตรงกับวันที่กำลังเลือกอยู่จริง
let historyRequestSeq = 0;
let autoPrint = true;
let lastReceipt = null;

let currentUser = <?= json_encode($currentUser ?: ['id'=>0,'username'=>'','fullname'=>'','role'=>''], JSON_UNESCAPED_UNICODE) ?>;
function hasVoidPermission(){ return String(currentUser?.role||'').toLowerCase()==='admin'; }
function canVoidNow(){ return hasVoidPermission(); }
function setupUserUI(){
 const logged=Number(currentUser.id||0)>0;
 document.getElementById('mainApp').style.display=logged?'':'none';
 document.getElementById('loginScreen').style.display=logged?'none':'flex';
 const n=document.getElementById('currentUserName'), r=document.getElementById('currentUserRole'), ub=document.getElementById('userNavBtn');
 if(n)n.textContent=currentUser.fullname||currentUser.username||'ผู้ใช้งาน';
 if(r)r.textContent=currentUser.role==='admin'?'(Admin)':'(Staff)';
 if(ub)ub.style.display=currentUser.role==='admin'?'':'none';
 document.querySelectorAll('[data-admin-only]').forEach(x=>x.style.display=currentUser.role==='admin'?'':'none');
}
function doLogin(e){
 e.preventDefault(); const msg=document.getElementById('loginMessage'); msg.textContent='กำลังตรวจสอบ...';
 api('login',{username:document.getElementById('loginUsername').value.trim(),password:document.getElementById('loginPassword').value}).then(d=>{
   if(!d.success){msg.textContent=d.message||'เข้าสู่ระบบไม่สำเร็จ';return;}
   currentUser=d.user; setupUserUI(); document.getElementById('loginPassword').value=''; loadSettings();
 }).catch(()=>msg.textContent='ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้');
}
function doLogout(){if(!confirm('ต้องการออกจากระบบหรือไม่?'))return;api('logout').then(()=>location.reload());}
function loadUsers(){
 if(currentUser.role!=='admin')return;
 api('users_list').then(d=>{if(!d.success)return alert(d.message);document.getElementById('userTable').innerHTML=(d.data||[]).map(u=>`<tr><td>${u.id}</td><td><b>${esc(u.username)}</b></td><td>${esc(u.fullname||'-')}</td><td>${u.role==='admin'?'<span class="px-2 py-1 rounded bg-indigo-100 text-indigo-700 text-xs font-bold">Admin</span>':'<span class="px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs font-bold">Staff</span>'}</td><td>${esc(u.created_at||'')}</td><td><button onclick="deleteUser(${u.id},'${esc(u.username)}')" class="text-red-600 font-bold text-xs"><i class="fa-solid fa-trash"></i> ลบ</button></td></tr>`).join('');});
}
function saveUser(){
 const username=document.getElementById('newUsername').value.trim(),fullname=document.getElementById('newFullname').value.trim(),password=document.getElementById('newPassword').value,role=document.getElementById('newRole').value;
 if(!username||!password)return alert('กรุณากรอก Username และ Password');
 api('user_save',{username,fullname,password,role}).then(d=>{alert(d.message);if(d.success){document.getElementById('newUsername').value='';document.getElementById('newFullname').value='';document.getElementById('newPassword').value='';document.getElementById('newRole').value='staff';loadUsers();}});
}
function deleteUser(id,username){if(!confirm('ต้องการลบ User '+username+' หรือไม่?'))return;api('user_delete',{id}).then(d=>{alert(d.message);if(d.success)loadUsers();});}

// ===== โต๊ะ / QR Code สั่งอาหาร =====
function orderUrlForToken(token){
  return location.href.replace(/[^/]*$/, '') + 'order.php?t=' + token;
}
function loadTables(){
 if(currentUser.role!=='admin')return;
 api('tables_list').then(d=>{
  if(!d.success)return alert(d.message);
  const el=document.getElementById('tableGrid');
  if(!d.data.length){el.innerHTML='<div class="empty">ยังไม่มีโต๊ะ กรุณาเพิ่มโต๊ะด้านบน</div>';return;}
  el.innerHTML=d.data.map(t=>{
    const url=orderUrlForToken(t.qr_token);
    const qrImg='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='+encodeURIComponent(url);
    return `<div class="table-card">
      <div class="t-no"><i class="fa-solid fa-utensils"></i>&nbsp; ${esc(t.table_no)}</div>
      <img src="${qrImg}" alt="QR ${esc(t.table_no)}">
      <button onclick="window.open('${qrImg}','_blank')" class="bg-slate-100 text-slate-700 px-2 py-1 rounded text-[11px] font-bold w-full">พิมพ์ / ดูขนาดเต็ม</button>
      <button onclick="copyTableLink('${url}')" class="bg-indigo-50 text-indigo-700 px-2 py-1 rounded text-[11px] font-bold w-full"><i class="fa-solid fa-link"></i>&nbsp; คัดลอกลิงก์</button>
      <button onclick='deleteTable(${Number(t.id)},${JSON.stringify(String(t.table_no||''))})' class="text-red-600 text-[11px] font-bold w-full"><i class="fa-solid fa-trash"></i>&nbsp; ลบโต๊ะ</button>
    </div>`;
  }).join('');
 });
}
function addTable(){
 const v=document.getElementById('newTableNo').value.trim();
 if(!v)return alert('กรุณากรอกหมายเลข/ชื่อโต๊ะ');
 api('table_save',{table_no:v}).then(d=>{alert(d.message);if(d.success){document.getElementById('newTableNo').value='';loadTables();}});
}
function deleteTable(id,name){
 if(!confirm('ต้องการลบโต๊ะ '+name+' หรือไม่? QR เดิมจะใช้งานไม่ได้อีก'))return;
 api('table_delete',{id}).then(d=>{alert(d.message);if(d.success)loadTables();});
}
function copyTableLink(url){
 navigator.clipboard?.writeText(url).then(()=>alert('คัดลอกลิงก์แล้ว:\n'+url)).catch(()=>prompt('คัดลอกลิงก์นี้:',url));
}

// ===== ออเดอร์จากลูกค้า (สแกน QR) =====
let corderPoll=null;
function loadCorders(){
 api('corders_list').then(d=>{
  if(!d.success)return alert(d.message);
  const el=document.getElementById('corderList');
  if(!el)return;
  if(!d.data.length){el.innerHTML='<div class="empty">ยังไม่มีออเดอร์จากลูกค้าในขณะนี้</div>';return;}
  el.innerHTML=d.data.map(o=>{
    const items=o.items.map(it=>`<div class="corder-item-row"><span>${esc(it.product_name)} x${it.quantity}${it.note?' <i class="text-slate-400">('+esc(it.note)+')</i>':''}</span><span>฿${money(it.price*it.quantity)}</span></div>`).join('');
    const statusCls=o.status==='pending'?'status-pending':(o.status==='served'?'status-served':(o.status==='payment_pending'?'status-payment':'status-accepted'));
    const statusTxt=o.status==='pending'?'รอออเดอร์':(o.status==='served'?'เสร็จแล้ว':(o.status==='payment_pending'?'รอชำระ':'กำลังเตรียมอาหาร'));
    const total=o.items.reduce((s,it)=>s+Number(it.price)*Number(it.quantity),0);
    return `<div class="corder-card">
      <div class="c-head">
        <div><b>โต๊ะ ${esc(o.table_no)}</b> <small class="text-slate-400">${esc(o.created_at)}</small></div>
        <span class="c-status ${statusCls}">${statusTxt}</span>
      </div>
      ${items}
      ${o.customer_note?`<div class="text-[11px] text-amber-700 mt-1"><i class="fa-solid fa-note-sticky"></i> หมายเหตุ: ${esc(o.customer_note)}</div>`:''}
      <div class="flex justify-between items-center mt-2">
        <b class="text-indigo-700">รวม ฿${money(total)}</b>
        <div class="flex gap-1">
          ${o.status==='pending'?`<button onclick="updateCorderStatus(${o.id},'accepted')" class="bg-green-600 text-white px-2 py-1 rounded text-[11px] font-bold"><i class="fa-solid fa-fire-burner"></i> กำลังเตรียมอาหาร</button>`:''}
          <button onclick="printKitchenOrder(${o.id})" class="bg-amber-500 text-white px-2 py-1 rounded text-[11px] font-bold"><i class="fa-solid fa-print"></i> พิมพ์ครัว</button>
          <button onclick="updateCorderStatus(${o.id},'served')" class="bg-slate-200 text-slate-700 px-2 py-1 rounded text-[11px] font-bold"><i class="fa-solid fa-check"></i> เสิร์ฟแล้ว</button>
          ${o.status==='served'?`<button onclick="updateCorderStatus(${o.id},'payment_pending')" class="bg-amber-500 text-white px-2 py-1 rounded text-[11px] font-bold"><i class="fa-solid fa-credit-card"></i> รอชำระ</button>`:''}
          <button onclick="loadCorderToCart(${o.id})" class="bg-green-600 text-white px-2 py-1 rounded text-[11px] font-bold">โหลดเข้าตะกร้า POS</button>
          <button onclick="updateCorderStatus(${o.id},'cancelled')" class="text-red-600 px-2 py-1 text-[11px] font-bold">ยกเลิก</button>
        </div>
      </div>
    </div>`;
  }).join('');

  // การพิมพ์ครัวจะเกิดตอนกด "รับออเดอร์" เพื่อป้องกันพิมพ์ซ้ำก่อนพนักงานรับรายการ
 });
}
function updateCorderStatus(id,status){
  // เมื่อกดรับออเดอร์ ให้เปลี่ยนสถานะ + ส่งพิมพ์ครัว + แจ้งรายละเอียดออเดอร์ทันที
  if(status==='accepted') {
    api('corders_list').then(list=>{
      if(!list.success) return alert(list.message || 'ไม่สามารถโหลดข้อมูลออเดอร์ได้');
      const o=list.data.find(x=>Number(x.id)===Number(id));
      if(!o) return alert('ไม่พบออเดอร์ #'+id);

      api('corder_update_status',{id,status}).then(d=>{
        if(!d.success) return alert(d.message || 'ไม่สามารถรับออเดอร์ได้');

        // ส่งใบออเดอร์ไปพิมพ์ที่จุดครัว
        if(kitchenPrint){
          printKitchenOrderData(o);
        }

        const itemText=(o.items||[]).map((it,i)=>
          (i+1)+'. '+it.product_name+' x '+Number(it.quantity)+(it.note?' | หมายเหตุ: '+it.note:'')
        ).join('\n');
        const printText=kitchenPrint
          ? '✓ ส่งพิมพ์ใบออเดอร์ไปที่: เครื่องพิมพ์ครัว'
          : '⚠️ ยังไม่ได้เปิดการพิมพ์ออเดอร์ครัว';

        alert(
          'รับออเดอร์แล้ว\n\n'+
          'โต๊ะ: '+o.table_no+'\n'+
          'เลขออเดอร์: #'+o.id+'\n'+
          'เวลา: '+o.created_at+'\n\n'+
          printText+'\n\n'+
          'รายการที่ลูกค้าสั่ง:\n'+itemText+
          (o.customer_note?'\n\nหมายเหตุลูกค้า: '+o.customer_note:'')
        );
        loadCorders();
      });
    }).catch(err=>{
      console.error(err);
      alert('ไม่สามารถเชื่อมต่อเพื่อรับออเดอร์ได้');
    });
    return;
  }

  api('corder_update_status',{id,status}).then(d=>{
    if(!d.success)return alert(d.message);
    loadCorders();
  });
}
function loadCorderToCart(id){
  api('corders_list').then(d=>{
    if(!d.success){
      return alert(d.message || 'ไม่สามารถโหลดออเดอร์ได้');
    }

    const o=d.data.find(x=>Number(x.id)===Number(id));
    if(!o){
      return alert('ไม่พบออเดอร์ #' + id);
    }

    if(!Array.isArray(o.items) || !o.items.length){
      return alert('ออเดอร์นี้ไม่มีรายการสินค้า');
    }

    let loaded=0;

    o.items.forEach(it=>{
      const productId=Number(it.product_id);
      const qty=Number(it.quantity);
      const price=Number(it.price);

      // ต้องมี product_id จริง จึงจะส่งเข้า checkout ได้
      if(productId<=0 || qty<=0){
        return;
      }

      const x=cart.find(i=>Number(i.id)===productId);

      if(x){
        x.qty += qty;
      }else{
        cart.push({
          id:productId,
          name:it.product_name || 'สินค้า',
          price:price,
          stock:99999,
          qty:qty
        });
      }

      loaded++;
    });

    if(loaded===0){
      return alert('ไม่สามารถโหลดสินค้าเข้า POS ได้ เนื่องจากไม่พบ Product ID ของรายการ');
    }

    renderCart();
    showPage('sale');
    document.querySelectorAll('.topnav button').forEach(x=>{
      x.classList.toggle('active',x.dataset.page==='sale');
    });

    // สำคัญ: กด "เสิร์ฟแล้ว" จะยังแสดงออเดอร์อยู่
    // ออเดอร์จะหายจากหน้านี้ก็ต่อเมื่อโหลดเข้าตะกร้า POS สำเร็จเท่านั้น
    api('corder_update_status',{id:id,status:'loaded'}).then(statusRes=>{
      if(!statusRes.success){
        console.warn('ปิดออเดอร์หลังโหลดเข้าตะกร้าไม่สำเร็จ:',statusRes.message);
        alert(statusRes.message || 'ไม่สามารถปิดออเดอร์ได้');
        return;
      }
      loadCorders();
    }).catch(err=>{
      console.error('Update order status error:',err);
      alert('โหลดเข้าตะกร้าแล้ว แต่ไม่สามารถปิดออเดอร์ออกจากรายการได้');
    });

    alert('โหลดออเดอร์โต๊ะ '+o.table_no+' เข้าตะกร้าแล้ว ตรวจสอบก่อนกดชำระเงิน');
  }).catch(err=>{
    console.error('Load customer order error:',err);
    alert('โหลดออเดอร์ไม่สำเร็จ กรุณาตรวจสอบการเชื่อมต่อ');
  });
}
function printKitchenOrder(id){
  api('corders_list').then(d=>{
    if(!d.success)return alert(d.message||'โหลดออเดอร์ไม่สำเร็จ');
    const o=d.data.find(x=>Number(x.id)===Number(id));
    if(!o)return alert('ไม่พบออเดอร์ #'+id);
    printKitchenOrderData(o);
  }).catch(()=>alert('ไม่สามารถเชื่อมต่อเพื่อพิมพ์ออเดอร์ได้'));
}

function printKitchenOrderData(o){
  const area=document.getElementById('kitchenPrintArea');
  if(!area)return;
  const items=(o.items||[]).map(it=>`<div class="item">${esc(it.product_name)} x ${Number(it.quantity)}<div class="note">${it.note?'หมายเหตุ: '+esc(it.note):''}</div></div>`).join('');
  area.innerHTML=`<div class="kitchen-ticket">
    <h2>ออเดอร์ครัว</h2>
    <div class="row"><b>โต๊ะ</b><b>${esc(o.table_no)}</b></div>
    <div class="row"><span>ออเดอร์ #${Number(o.id)}</span><span>${esc(o.created_at)}</span></div>
    <div class="line"></div>${items}
    ${o.customer_note?`<div class="line"></div><div><b>หมายเหตุลูกค้า:</b><br>${esc(o.customer_note)}</div>`:''}
    <div class="line"></div><div style="text-align:center;font-size:12px">กรุณาจัดเตรียมอาหาร</div>
  </div>`;
  document.body.classList.add('kitchen-printing');
  const cleanup=()=>{
    document.body.classList.remove('kitchen-printing');
    window.removeEventListener('afterprint',cleanup);
    area.innerHTML='';
  };
  window.addEventListener('afterprint',cleanup);
  // รอให้ DOM/CSS โหมดพิมพ์ถูกวาดก่อนเปิด Print Preview เพื่อไม่ให้หน้าขาว
  requestAnimationFrame(()=>{
    requestAnimationFrame(()=>{
      setTimeout(()=>window.print(),150);
    });
  });
}

function pollCorderBadge(){
 if(Number(currentUser.id||0)<=0)return;
 api('corders_pending_count').then(d=>{
  if(!d.success)return;
  const b=document.getElementById('corderBadge');
  if(!b)return;
  if(d.count>0){b.style.display='inline-block';b.textContent=d.count;}else{b.style.display='none';}
 }).catch(()=>{});
}

// ===== แจ้งเตือนคำขอเรียกเก็บเงินจากลูกค้า (โชว์ที่หน้าขาย/หน้าแรก) =====
function pollBillRequests(){
 if(Number(currentUser.id||0)<=0)return;
 api('bill_requests_list').then(d=>{
  if(!d.success)return;
  const el=document.getElementById('billRequestBanner');
  if(!el)return;
  if(!d.data.length){el.innerHTML='';el.className='';return;}
  el.className='bill-banner';
  el.innerHTML=d.data.map(b=>`<div class="bill-banner-row">
      <span><i class="fa-solid fa-bell text-red-600"></i>&nbsp; <b>โต๊ะ ${esc(b.table_no)}</b> ขอเรียกเก็บเงิน <small class="text-slate-400">${esc(b.created_at)}</small></span>
      <button onclick="ackBillRequest(${b.id})" class="bg-red-600 text-white px-3 py-1 rounded text-[11px] font-bold">รับทราบ</button>
    </div>`).join('');
 }).catch(()=>{});
}
function ackBillRequest(id){
 api('bill_request_done',{id}).then(d=>{if(!d.success)return alert(d.message);pollBillRequests();});
}
function money(v){return Number(v||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}
const cashierDenoms=[1000,500,100,50,20,10,5,2,1,0.50,0.25];
let cashierRound=null;
let cashOutConfirmed=false;
function buildCashierDenoms(){
 const make=(id,arr)=>{document.getElementById(id).innerHTML=arr.map(v=>`<div class="cashier-denom"><b>฿${money(v)}</b><input type="number" min="0" step="1" value="0" data-denom="${v}" oninput="calcCashierTotals()"><span class="text-right font-bold text-slate-600">฿<span data-total="${v}">0.00</span></span></div>`).join('');};
 make('cashDenomList',cashierDenoms); make('cashOutDenomList',cashierDenoms);
}
function calcDenomBox(id){let total=0;document.querySelectorAll(`#${id} input[data-denom]`).forEach(i=>{let q=Math.max(0,Number(i.value||0)),v=Number(i.dataset.denom);total+=q*v;const out=document.querySelector(`#${id} [data-total="${i.dataset.denom}"]`);if(out)out.textContent=money(q*v);});return total;}
function calcCashierTotals(){
 const counted=calcDenomBox('cashDenomList'), out=calcDenomBox('cashOutDenomList');
 document.getElementById('cashCountedTotal').textContent=money(counted); document.getElementById('cashOutTotal').textContent=money(out);
 const expected=Number(cashierRound?.cash_available!=null?cashierRound.cash_available:cashierRound?.cash_sales||0), check=counted+out, variance=check-expected;
 document.getElementById('cashExpected').textContent='฿'+money(expected); document.getElementById('cashCheckTotal').textContent='฿'+money(check);
 const ve=document.getElementById('cashVariance');ve.textContent=(variance>=0?'+':'')+'฿'+money(variance);ve.className='cashier-total '+(variance===0?'text-green-600':variance>0?'text-blue-600':'text-red-600');
}
function confirmCashOut(){
 const out=calcDenomBox('cashOutDenomList');
 if(out<=0){ const first=document.querySelector('#cashOutDenomList input[data-denom]'); first?.focus(); alert('กรุณาระบุจำนวนเงินที่ต้องการนำออกก่อน'); return false; }
 if(!confirm(`ยืนยันนำเงินออก ฿${money(out)} ?\nการนำเงินออกนี้จะบันทึกทันที โดยไม่ต้องปิดรอบ Cashier`)) return false;
 const btn=document.getElementById('cashOutActionBtn'); if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> กำลังบันทึก...';}
 api('cashier_cash_out',{denominations:JSON.stringify(getDenomValues('cashOutDenomList')),note:'นำเงินออกระหว่างรอบ Cashier'}).then(d=>{
   if(!d.success){alert(d.message||'นำเงินออกไม่สำเร็จ');return;}
   alert(`นำเงินออกเรียบร้อยแล้ว\nเลขที่: ${d.data.cash_out_no}\nจำนวนเงิน: ฿${money(d.data.amount)}\nคงเหลือที่ถอนได้: ฿${money(d.data.available_after)}`);
   document.querySelectorAll('#cashOutDenomList input[data-denom]').forEach(i=>i.value=0); loadCashierRound();
 }).catch(err=>alert('ไม่สามารถบันทึกเงินออกได้\n'+(err?.message||'Unknown error'))).finally(()=>{ if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-money-bill-transfer"></i> นำเงินออก';} });
 return true;
}

function loadCashierRound(){
 cashOutConfirmed=false;
 const btn=document.getElementById('cashOutActionBtn');
 if(btn){btn.innerHTML='<i class="fa-solid fa-money-bill-transfer"></i> นำเงินออก';btn.className='bg-amber-500 hover:bg-amber-600 text-white px-3 py-2 rounded-lg text-xs font-bold';}
 buildCashierDenoms();
 api('cashier_summary').then(d=>{if(!d.success)return alert(d.message);cashierRound=d.data||{};
   const vatT=Number(cashierRound.vat_total||0);
   const netExVat=Number(cashierRound.net_sales_exvat!=null?cashierRound.net_sales_exvat:Math.max(0,Number(cashierRound.sales_total||0)-vatT));
   document.getElementById('cashierSummaryTop').innerHTML=`<div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3"><div class="text-xs text-slate-500">ยอดขายสุทธิ (ไม่รวม VAT)</div><b class="text-lg text-indigo-700">฿${money(netExVat)}</b></div><div class="bg-slate-50 border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">VAT 7%</div><b class="text-lg text-slate-800">฿${money(vatT)}</b></div><div class="bg-slate-50 border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">ยอดรับชำระรวม (รวม VAT)</div><b class="text-lg text-slate-800">฿${money(cashierRound.sales_total)}</b></div><div class="bg-slate-50 border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">จำนวนบิล</div><b class="text-lg text-slate-800">${cashierRound.bill_count}</b></div><div class="bg-green-50 border border-green-200 rounded-xl p-3"><div class="text-xs text-slate-500">เงินสดที่รับ (รวม VAT)</div><b class="text-lg text-green-700">฿${money(cashierRound.cash_sales)}</b></div><div class="bg-slate-50 border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">บิลเงินสด</div><b class="text-lg text-slate-800">${cashierRound.cash_bill_count}</b></div><div class="bg-amber-50 border border-amber-200 rounded-xl p-3"><div class="text-xs text-slate-500">นำเงินออกแล้ว</div><b class="text-lg text-amber-700">฿${money(cashierRound.cash_out_total||0)}</b></div>`;
   document.getElementById('cashLastClose').textContent=cashierRound.last_close||'ยังไม่มี'; calcCashierTotals();
 }).catch(()=>alert('ไม่สามารถโหลดข้อมูล Cashier ได้'));
}
function getDenomValues(id){let o={};document.querySelectorAll(`#${id} input[data-denom]`).forEach(i=>o[i.dataset.denom]=Math.max(0,parseInt(i.value||0,10)||0));return o;}
function saveCashierClose(){
 calcCashierTotals(); const expected=Number(cashierRound?.cash_available!=null?cashierRound.cash_available:cashierRound?.cash_sales||0), counted=calcDenomBox('cashDenomList'), out=calcDenomBox('cashOutDenomList');
 if(out>0 && !cashOutConfirmed){
   if(!confirmCashOut()) return;
 }
 if(!confirm(`ยืนยันปิดรอบ Cashier?\nยอดเงินสดที่ควรมี ฿${money(expected)}\nนับได้ ฿${money(counted)}\nเงินออก ฿${money(out)}\nส่วนต่าง ฿${money(counted+out-expected)}`))return;
 api('cashier_close',{denominations:JSON.stringify(getDenomValues('cashDenomList')),cash_out_denominations:JSON.stringify(getDenomValues('cashOutDenomList'))}).then(d=>{
   if(!d.success)return alert(d.message); cashierRound=d.data; alert('ปิดรอบ Cashier เรียบร้อยแล้ว\nเลขที่รอบ: '+d.data.closing_no); printCashierSummary(d.data); loadCashierRound();
 }).catch(err=>{console.error('cashier_close:',err);alert('ไม่สามารถปิดรอบ Cashier ได้\n'+(err?.message||'ไม่ทราบสาเหตุ'));});
}
function printCashierSummary(data){
 if(data && data.preventDefault)data=null;
 const render=(d)=>{const rows=cashierDenoms.map(v=>`<tr><td style="text-align:right">฿${money(v)}</td><td style="text-align:right">${d?.denominations?.[String(v)]||0}</td><td style="text-align:right">฿${money(v*(d?.denominations?.[String(v)]||0))}</td><td style="text-align:right">${d?.cash_out_denominations?.[String(v)]||0}</td><td style="text-align:right">฿${money(v*(d?.cash_out_denominations?.[String(v)]||0))}</td></tr>`).join('');return `<html><head><title>Cashier Closing</title><meta charset="utf-8"><style>body{font-family:Arial,'Tahoma',sans-serif;padding:20px;color:#111}h2{text-align:center}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #bbb;padding:6px}th{background:#eee}.r{text-align:right}.box{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0}.card{border:1px solid #bbb;padding:8px}.total{font-size:16px;font-weight:bold}</style></head><h2>สรุปปิดรอบ Cashier</h2><div>เลขที่รอบ: <b>${esc(d?.closing_no||'-')}</b> | วันที่: ${esc(d?.closing_date||new Date().toLocaleString('th-TH'))} | ผู้ปฏิบัติงาน: ${esc(d?.staff_name||currentUser.fullname||currentUser.username)}</div><div class="box"><div class="card">ยอดขายสุทธิ (ไม่รวม VAT)<br><b>฿${money(d?.net_sales_exvat!=null?d.net_sales_exvat:Math.max(0,Number(d?.sales_total||0)-Number(d?.vat_total||0)))}</b></div><div class="card">VAT 7%<br><b>฿${money(d?.vat_total||0)}</b></div><div class="card">ยอดรับชำระรวม (รวม VAT)<br><b>฿${money(d?.sales_total||0)}</b></div><div class="card">เงินสดสุทธิ<br><b>฿${money(d?.cash_sales||0)}</b></div><div class="card">นับได้<br><b>฿${money(d?.cash_counted||0)}</b></div><div class="card">เงินออก<br><b>฿${money(d?.cash_out||0)}</b></div></div><table><thead><tr><th>ชนิดเงิน</th><th>จำนวนเงินนับ</th><th>ยอดนับ</th><th>จำนวนเงินออก</th><th>ยอดเงินออก</th></tr></thead><tbody>${rows}</tbody></table><p class="total r">เงินสดที่ควรมี: ฿${money(d?.expected_cash||0)}<br>เงินนับได้ + เงินออก: ฿${money(Number(d?.cash_counted||0)+Number(d?.cash_out||0))}<br>ส่วนต่าง: ฿${money(d?.variance||0)}</p><hr><div style="text-align:center">ลงชื่อ Cashier ____________________</div></body></html>`;};
 if(data){const w=window.open('','_blank','width=900,height=700');if(!w)return alert('Browser บล็อกหน้าต่างพิมพ์ กรุณาอนุญาต Pop-up');w.document.write(render(data));w.document.close();w.onload=()=>w.print();return;}
 api('cashier_last').then(d=>{if(!d.success)return alert(d.message);const w=window.open('','_blank','width=900,height=700');if(!w)return alert('Browser บล็อกหน้าต่างพิมพ์ กรุณาอนุญาต Pop-up');w.document.write(render(d.data));w.document.close();w.onload=()=>w.print();});
}
function api(action,data={}){
 const f=new URLSearchParams();
 f.set('action',String(action||''));
 Object.entries(data||{}).forEach(([k,v])=>f.set(k,v==null?'':String(v)));

 // สร้าง URL จากตำแหน่งหน้าปัจจุบันอย่างถูกต้อง เพื่อรองรับชื่อไฟล์ PHP เช่น Index(9)_STOCK_REPORT.php
 // และป้องกัน ERR_INVALID_URL จากการต่อ URL เองผิดรูปแบบ
 let endpoint;
 try{
   if(window.location.protocol!=='http:' && window.location.protocol!=='https:'){
     throw new Error('กรุณาเปิดระบบผ่าน Apache เช่น http://localhost/pos_system/Index(9)_STOCK_REPORT.php');
   }
   const u=new URL(window.location.href);
   u.hash='';
   endpoint=u.toString();
 }catch(err){
   throw new Error('ERR_INVALID_URL: '+(err.message||'กรุณาเปิดระบบผ่าน http://localhost'));
 }

 return fetch(endpoint,{
   method:'POST',
   headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
   body:f.toString(),
   credentials:'include',
   cache:'no-store'
 }).then(async r=>{
   const text=await r.text();
   if(!r.ok) throw new Error('HTTP '+r.status+'\n'+text.slice(0,500));
   try{return JSON.parse(text);}
   catch(e){throw new Error('Invalid JSON response\n'+text.slice(0,500));}
 });
}function showPage(page){
 if(currentUser.role!=='admin' && !['sale','cashier','corders'].includes(page)){alert('Staff เข้าได้เฉพาะหน้าขาย, ปิดรอบ Cashier และออเดอร์จากลูกค้า');return;}
 document.querySelectorAll('.page').forEach(x=>{
   x.classList.remove('active');
   x.style.display='none';
 });
 let target=document.getElementById('page-'+page);
 if(page==='sale'){
   target.style.display='flex';
   target.classList.add('active');
   document.getElementById('page-cart').style.display='flex';
 }else{
   document.getElementById('page-cart').style.display='none';
   target.style.display='flex';
   target.classList.add('active');
 }
 document.querySelectorAll('.topnav button').forEach(x=>x.classList.toggle('active',x.dataset.page===page));
 if(page==='products')loadProducts();
 if(page==='graph'){
   renderReportHeader('graphReportHeader');
   const gf=document.getElementById('graphFromDate'), gt=document.getElementById('graphToDate');
   if(gf && gt){
     const today=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Bangkok'});
     gf.value=today; gt.value=today;
   }
   loadSalesGraph();
 }
 if(page==='history'){
   // เปิดรายงานทุกครั้งต้องมีช่วงวันที่ที่ถูกต้อง หากผู้ใช้เคยกด "ดูทั้งหมด"
   // แล้วกลับเข้าหน้ารายงาน ให้เริ่มที่วันนี้อีกครั้ง
   const fd=document.getElementById('fromDate');
   const td=document.getElementById('toDate');
   if(fd && td && !fd.value && !td.value){
     const now=new Date();
     const today=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
     fd.value=today;
     td.value=today;
   }else if(fd && td && !fd.value){
     td.value=td.value || '';
     fd.value=td.value;
   }else if(fd && td && !td.value){
     td.value=fd.value;
   }
   renderReportHeader();
   switchReport('cashier');
 }
 if(page==='void'){loadVoidToday();}
 if(page==='close'){loadCloseSummary();loadDayClosingHistory();}
 if(page==='settings')loadSettings();
 if(page==='users')loadUsers();
 if(page==='cashier')loadCashierRound();
 if(page==='tables')loadTables();
 if(page==='corders')loadCorders();
}
function addToCart(id,name,price,stock){
 let x=cart.find(i=>i.id===id);
 if(x){x.qty++;}
 else cart.push({id,name,price:Number(price),stock:Number(stock),qty:1});
 renderCart();
}
function updateQty(id,d){
 let x=cart.find(i=>i.id===id);if(!x)return;
 x.qty+=d;if(x.qty<=0)cart=cart.filter(i=>i.id!==id);renderCart();
}
function resetSaleDefaults(){
  // ทุกครั้งที่เริ่มรายการใหม่/Reset/ชำระเงินสำเร็จ ให้กลับค่าเริ่มต้นเป็นเงินสด
  cart=[];
  payMethod='cash';
  const cashEl=document.getElementById('cash');
  const discountEl=document.getElementById('discount');
  if(cashEl) cashEl.value=0;
  if(discountEl) discountEl.value=0;

  const cashBox=document.getElementById('cashBox');
  if(cashBox) cashBox.style.display='block';
  const qrBox=document.getElementById('promptpayQrBox');
  if(qrBox) qrBox.style.display='none';
  const qrActions=document.getElementById('qrPaymentActions');
  if(qrActions){qrActions.style.display='none';qrActions.style.visibility='visible';qrActions.style.opacity='1';}
  const checkoutBtn=document.getElementById('checkoutBtn');
  if(checkoutBtn) checkoutBtn.style.display='block';
  const qrPay=document.getElementById('qrPaymentPayBtn');
  if(qrPay){qrPay.disabled=false;qrPay.innerHTML='<i class="fa-solid fa-money-check-dollar"></i>&nbsp; ชำระ';}
  const qrPrint=document.getElementById('qrPaymentPrintBtn');
  if(qrPrint){qrPrint.disabled=!lastReceipt;qrPrint.style.opacity=lastReceipt?'1':'.5';}

  const selected=document.getElementById('payTypeSelected');
  if(selected) selected.textContent='เงินสด';
  const menu=document.getElementById('payMethodsMenu');
  if(menu) menu.classList.remove('show');
  const chev=document.getElementById('payTypeChevron');
  if(chev) chev.className='fa-solid fa-chevron-down';

  document.querySelectorAll('.pay-method').forEach(btn=>{
    btn.classList.toggle('active', btn.getAttribute('onclick')?.includes("selectPay('cash'"));
  });

  const change=document.getElementById('change');
  if(change) change.textContent='฿0.00';
  if(typeof renderCart==='function') renderCart();
}
function clearCart(){
  if(!cart.length){
    resetSaleDefaults();
    return;
  }
  if(confirm('ต้องการล้างรายการและกลับค่าเริ่มต้นเป็นเงินสดหรือไม่?')) resetSaleDefaults();
}
function renderCart(){
 let el=document.getElementById('cartItems');
 if(!cart.length){el.innerHTML='<div class="empty">ยังไม่มีสินค้าในรายการ</div>'}
 else{
  let h='';
  cart.forEach(x=>{h+=`<div class="cart-row"><div class="cart-name">${esc(x.name)}</div><div class="qty-control"><button onclick="updateQty(${x.id},-1)">−</button><b>${x.qty}</b><button onclick="updateQty(${x.id},1)">+</button></div><div class="cart-total">฿${money(x.price*x.qty)}</div></div>`});
  el.innerHTML=h;
 }
 let before=cart.reduce((s,x)=>s+x.price*x.qty,0);
 let disc=Math.max(0,Number(document.getElementById('discount').value||0));
 let taxable=Math.max(0,before-disc),vat=taxable*7/107,total=taxable;
 document.getElementById('beforeDiscount').textContent='฿'+money(before);
 document.getElementById('vat').textContent='฿'+money(vat);
 document.getElementById('grandTotal').textContent='฿'+money(total);
 calcChange();
 if(typeof updatePromptPayQR==='function') updatePromptPayQR();
}
function calcChange(){
 let before=cart.reduce((s,x)=>s+x.price*x.qty,0),disc=Math.max(0,Number(document.getElementById('discount').value||0)),total=Math.max(0,before-disc),cash=Number(document.getElementById('cash').value||0);
 document.getElementById('change').textContent='฿'+money(Math.max(0,cash-total));
}
function setCash(v){
 let before=cart.reduce((s,x)=>s+x.price*x.qty,0),disc=Math.max(0,Number(document.getElementById('discount').value||0)),total=Math.max(0,before-disc);
 document.getElementById('cash').value=v==='exact'?total:v;calcChange();
}
function togglePayMethods(){
 const menu=document.getElementById('payMethodsMenu');
 const chev=document.getElementById('payTypeChevron');
 if(!menu)return;
 const open=menu.classList.toggle('show');
 if(chev)chev.className=open?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down';
}
function normalizePromptPayId(value){
  return String(value||'').replace(/[^0-9]/g,'');
}
function tlv(tag,value){
  const v=String(value??'');
  return String(tag).padStart(2,'0')+String(v.length).padStart(2,'0')+v;
}
function crc16ccitt(input){
  let crc=0xFFFF;
  for(let i=0;i<input.length;i++){
    crc ^= input.charCodeAt(i) << 8;
    for(let j=0;j<8;j++) crc=(crc&0x8000)?((crc<<1)^0x1021)&0xFFFF:(crc<<1)&0xFFFF;
  }
  return crc.toString(16).toUpperCase().padStart(4,'0');
}
function buildPromptPayPayload(promptpayId,amount){
  const id=normalizePromptPayId(promptpayId);
  if(!(id.length===10 || id.length===13)) throw new Error('เลขพร้อมเพย์ต้องเป็นเบอร์มือถือ 10 หลัก หรือเลขบัตรประชาชน/Tax ID 13 หลัก');
  let target;
  if(id.length===10){
    const mobile=id.startsWith('0')?'0066'+id.slice(1):'0066'+id;
    target=tlv('01',mobile);
  }else{
    target=tlv('02',id);
  }
  const merchantAccount=tlv('00','A000000677010111')+target;
  let payload=tlv('00','01')+tlv('01','12')+tlv('29',merchantAccount)+tlv('52','0000')+tlv('53','764');
  const numericAmount=Number(amount||0);
  if(numericAmount>0) payload+=tlv('54',numericAmount.toFixed(2));
  payload+=tlv('58','TH')+tlv('59','SUNTORN POS')+tlv('60','THAILAND')+'6304';
  return payload+crc16ccitt(payload);
}
function updatePromptPayQR(){
  const box=document.getElementById('promptpayQrBox');
  if(!box)return;
  if(payMethod!=='qr'){box.style.display='none';return;}
  box.style.display='block';
  const id=normalizePromptPayId(companyInfo.promptpayId||document.getElementById('promptpayId')?.value||'');
  const amount=Number(document.getElementById('grandTotal')?.textContent?.replace(/[^0-9.]/g,'')||0);
  const idEl=document.getElementById('promptpayQrId'); if(idEl)idEl.textContent=id||'ยังไม่ได้ตั้งค่า';
  const amountEl=document.getElementById('promptpayQrAmount'); if(amountEl)amountEl.textContent='฿'+money(amount);
  const status=document.getElementById('promptpayQrStatus');
  const canvas=document.getElementById('promptpayQrCanvas');
  if(!canvas)return;
  canvas.innerHTML='';
  if(!id){
    if(status)status.textContent='กรุณาตั้งค่าเลขพร้อมเพย์';
    canvas.innerHTML='<div class="promptpay-qr-error"><i class="fa-solid fa-triangle-exclamation"></i><br>ยังไม่มีเลขพร้อมเพย์<br>ไปที่ ตั้งค่า เพื่อบันทึก</div>';
    return;
  }
  try{
    const payload=buildPromptPayPayload(id,amount);
    if(typeof QRCode==='undefined') throw new Error('ไม่สามารถโหลด QR Generator ได้');
    new QRCode(canvas,{text:payload,width:190,height:190,colorDark:'#0b1f3a',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
    if(status)status.textContent='พร้อมสแกนชำระ';
  }catch(e){
    canvas.innerHTML='<div class="promptpay-qr-error">'+esc(e.message||'สร้าง QR ไม่สำเร็จ')+'</div>';
    if(status)status.textContent='ตรวจสอบเลขพร้อมเพย์';
  }
}
function selectPay(method,el){
 payMethod=(method==='qr')?'qr':'cash';
 document.querySelectorAll('.pay-method').forEach(x=>x.classList.remove('active'));
 if(el)el.classList.add('active');
 const selected=document.getElementById('payTypeSelected');
 if(selected)selected.textContent=payMethod==='cash'?'เงินสด':'ชำระ QR โอน';
 const cashBox=document.getElementById('cashBox');
 if(cashBox)cashBox.style.display=payMethod==='cash'?'block':'none';
 const qrBox=document.getElementById('promptpayQrBox');
 if(qrBox)qrBox.style.display=payMethod==='qr'?'block':'none';
 const qrActions=document.getElementById('qrPaymentActions');
 if(qrActions){ qrActions.style.display=payMethod==='qr'?'grid':'none'; qrActions.style.visibility='visible'; qrActions.style.opacity='1'; }
 const checkoutBtn=document.getElementById('checkoutBtn');
 // QR ให้ผู้ใช้กด “ชำระ” เพื่อยืนยันหลังจากสแกนจ่ายแล้ว
 if(checkoutBtn){
   checkoutBtn.style.display=payMethod==='qr'?'none':'block';
 }
 const menu=document.getElementById('payMethodsMenu');
 const chev=document.getElementById('payTypeChevron');
 if(menu)menu.classList.remove('show');
 if(chev)chev.className='fa-solid fa-chevron-down';
 if(payMethod==='qr'){
   const cash=document.getElementById('cash');
   if(cash)cash.value=0;
 }
 calcChange();
 updatePromptPayQR();
 // เลือก QR แล้วเลื่อนพื้นที่ชำระเงินลงสุดทันที เพื่อให้ปุ่ม ชำระ / ยกเลิกชำระ / พิมพ์ มองเห็น
 if(payMethod==='qr'){
   const summary=document.querySelector('#page-cart .summary');
   if(summary){
     requestAnimationFrame(()=>{
       summary.scrollTop=summary.scrollHeight;
       setTimeout(()=>{summary.scrollTop=summary.scrollHeight;},120);
     });
   }
 }
}
function restoreQRPaymentButtons(){
  const payBtn=document.getElementById('qrPaymentPayBtn');
  const cancelBtn=document.getElementById('qrPaymentCancelBtn');
  const printBtn=document.getElementById('qrPaymentPrintBtn');
  if(payBtn){payBtn.disabled=false;payBtn.innerHTML='<i class="fa-solid fa-money-check-dollar"></i>&nbsp; ชำระ';}
  if(cancelBtn)cancelBtn.disabled=false;
  if(printBtn)printBtn.disabled=!lastReceipt;
}
function confirmQRPayment(){
  if(payMethod!=='qr') return;
  const id=normalizePromptPayId(companyInfo.promptpayId||document.getElementById('promptpayId')?.value||'');
  if(!id){
    alert('ยังไม่ได้ตั้งค่าเลขพร้อมเพย์ กรุณาไปที่ ตั้งค่า > เลขพร้อมเพย์ ก่อนรับชำระ QR');
    return;
  }
  const total=Number(document.getElementById('grandTotal')?.textContent?.replace(/[^0-9.]/g,'')||0);
  if(total<=0){
    alert('ยอดชำระต้องมากกว่า 0 บาท');
    return;
  }
  const payBtn=document.getElementById('qrPaymentPayBtn');
  if(payBtn && payBtn.disabled)return;
  const cancelBtn=document.getElementById('qrPaymentCancelBtn');
  const printBtn=document.getElementById('qrPaymentPrintBtn');
  if(payBtn){payBtn.disabled=true;payBtn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i>&nbsp; กำลังบันทึก...';}
  if(cancelBtn)cancelBtn.disabled=true;
  if(printBtn)printBtn.disabled=true;
  checkout(true);
}
function cancelQRPayment(){
  // ยกเลิกการชำระ QR แต่ยังคงรายการสินค้าในตะกร้าไว้ และกลับไปเลือกเงินสด
  const cashBtn=document.querySelector('.pay-method[onclick*="selectPay(\'cash\'"]');
  selectPay('cash',cashBtn);
  const qrStatus=document.getElementById('promptpayQrStatus');
  if(qrStatus)qrStatus.textContent='ยกเลิกการชำระ QR';
}
let checkoutBusy=false;
function checkout(fromQR=false){
  if(checkoutBusy)return;
  if(!cart.length)return alert('กรุณาเลือกสินค้า');
  if(payMethod==='qr' && !fromQR){
    return alert('กรุณากดปุ่ม “ชำระ” เพื่อยืนยันการชำระ QR');
  }

  let before=cart.reduce((s,x)=>s+x.price*x.qty,0),
      disc=Math.max(0,Number(document.getElementById('discount').value||0)),
      total=Math.max(0,before-disc),
      cash=payMethod==='cash'?Number(document.getElementById('cash').value||0):total;

  if(cash<total)return alert('เงินสดไม่เพียงพอ');
  checkoutBusy=true;

  api('checkout',{cart:JSON.stringify(cart),cash,discount:disc,payment_method:payMethod}).then(d=>{
    if(!d.success)return alert(d.message);

    lastReceipt={
      receipt_no:d.receipt_no,
      order_id:d.order_id,
      items:cart.map(x=>({name:x.name,price:Number(x.price),qty:Number(x.qty),total:Number(x.price)*Number(x.qty)})),
      payment_method:d.payment_method||payMethod,
      subtotal:Number(String(d.subtotal).replace(/,/g,'')),
      discount:Number(String(d.discount).replace(/,/g,'')),
      vat:Number(String(d.vat).replace(/,/g,'')),
      total:Number(String(d.total).replace(/,/g,'')),
      cash:Number(String(d.cash).replace(/,/g,'')),
      change:Number(String(d.change).replace(/,/g,'')),
      datetime:new Date().toLocaleString('th-TH')
    };

    document.getElementById('currentReceiptNo').textContent=d.receipt_no;
    const pb=document.getElementById('printReceiptBtn');
    pb.disabled=false; pb.style.opacity='1';
    const qpb=document.getElementById('qrPaymentPrintBtn');
    if(qpb){qpb.disabled=false;qpb.style.opacity='1';}

    alert(`ชำระเงินสำเร็จ\nเลขที่ใบเสร็จ ${d.receipt_no}\nยอดชำระ ฿${d.total}\nเงินทอน ฿${d.change}`);

    // ชำระเงินสำเร็จแล้ว เริ่มบิลใหม่ด้วยค่าเริ่มต้น = เงินสดทุกครั้ง
    resetSaleDefaults();

    if(autoPrint)setTimeout(()=>openReceiptPreview(true),150);
  });
}

function paymentLabel(v){
  return {cash:'เงินสด',transfer:'โอนเงิน',qr:'QR',card:'บัตรเครดิต'}[v]||v||'เงินสด';
}

function buildReceiptHtml(r){
  const shop = companyInfo.shopName || 'ร้าน POS';
  const address = companyInfo.companyAddress || '';
  const phone = companyInfo.companyPhone || '-';
  const taxId = companyInfo.companyTaxId || '-';
  const cashier = currentUser.fullname || currentUser.username || 'พนักงาน';
  const method = paymentLabel(r.payment_method || payMethod || 'cash');

  const items = (r.items||[]).map(x=>`
    <div class="r-row3">
      <span>${esc(x.name)}</span>
      <span>${Number(x.qty||0)}x${money(x.price||((x.total||0)/(x.qty||1)))}</span>
      <span class="r-value">฿${money(x.total||0)}</span>
    </div>
  `).join('');

  return `
    <div class="r-center">
      <div class="r-shop">${esc(shop)}</div>
      ${address?`<div class="r-sub">${esc(address)}</div>`:''}
      <div class="r-sub">โทร: ${esc(phone)}</div>
      <div class="r-small">TAX ID: ${esc(taxId)}</div>
    </div>

    <div class="r-line"></div>

    <div class="r-row"><span>เลขที่บิล:</span><b class="r-value">${esc(r.receipt_no||'-')}</b></div>
    <div class="r-row"><span>วันที่/เวลา:</span><span class="r-value">${esc(r.datetime||'-')}</span></div>
    <div class="r-row"><span>พนักงาน:</span><span class="r-value">${esc(cashier)}</span></div>

    <div class="r-line"></div>

    <div class="r-row3 r-head">
      <span>รายการ</span><span>จำนวน</span><span class="r-value">รวม</span>
    </div>
    ${items}

    <div class="r-line"></div>

    <div class="r-row"><span>รวมก่อนส่วนลด:</span><span class="r-value">฿${money(r.subtotal||0)}</span></div>
    <div class="r-row"><span>ส่วนลด:</span><span class="r-value">฿${money(r.discount||0)}</span></div>
    <div class="r-row"><span>VAT 7% (รวมในยอดแล้ว):</span><span class="r-value">฿${money(r.vat||0)}</span></div>
    <div class="r-row r-grand"><span>ยอดชำระสุทธิ:</span><span class="r-value">฿${money(r.total||0)}</span></div>

    <div class="r-line"></div>

    <div class="r-row"><span>วิธีชำระเงิน:</span><b class="r-value">${esc(method)}</b></div>
    <div class="r-row"><span>รับเงินสด:</span><span class="r-value">฿${money(r.cash||0)}</span></div>
    <div class="r-row"><span>เงินทอน:</span><span class="r-value">฿${money(r.change||0)}</span></div>

    <div class="r-line"></div>

    <div class="r-footer">*** ขอบคุณที่ใช้บริการ ***</div>
    <div class="r-footer">กรุณาตรวจสอบสินค้าและเงินทอนก่อนออกจากร้าน</div>
  `;
}

function openReceiptPreview(autoPrintNow=false){
  if(!lastReceipt)return alert('ยังไม่มีใบเสร็จสำหรับพิมพ์');

  const html=buildReceiptHtml(lastReceipt);
  document.getElementById('receiptPreviewPaper').innerHTML=html;
  document.getElementById('receiptPrintArea').innerHTML=html;
  document.getElementById('receiptPreviewModal').classList.add('show');

  if(autoPrintNow){
    setTimeout(printReceiptNow,250);
  }
}

function closeReceiptPreview(){
  document.getElementById('receiptPreviewModal').classList.remove('show');
}

function printReceiptNow(){
  if(!lastReceipt)return alert('ยังไม่มีใบเสร็จสำหรับพิมพ์');

  const html=buildReceiptHtml(lastReceipt);
  document.getElementById('receiptPrintArea').innerHTML=html;

  // พิมพ์เฉพาะกระดาษ 80mm ไม่พิมพ์พื้นหลัง/ปุ่ม/หน้าจอ POS
  document.body.classList.add('receipt-printing');

  const cleanup=()=>{
    document.body.classList.remove('receipt-printing');
    window.removeEventListener('afterprint',cleanup);
  };
  window.addEventListener('afterprint',cleanup);

  setTimeout(()=>window.print(),100);
}

function printLastReceipt(){
  // ปุ่ม "พิมพ์ใบเสร็จ" เปิดตัวอย่างใบเสร็จก่อน
  openReceiptPreview(false);
}

function setAutoPrintState(enabled){
  autoPrint=!!enabled;
  const sw=document.getElementById('autoPrintSwitch');
  if(sw){
    sw.style.background=autoPrint?'#4f46e5':'#94a3b8';
    sw.style.opacity=autoPrint?'1':'0.65';
    sw.title=autoPrint?'เปิดอยู่ - คลิกเพื่อปิด':'ปิดอยู่ - คลิกเพื่อเปิด';
  }
}

function toggleAutoPrint(){
  autoPrint=!autoPrint;
  setAutoPrintState(autoPrint);
  api('settings_save',{
    autoPrint:autoPrint?'1':'0'
  });
}

let kitchenPrint=false;
function setKitchenPrintState(enabled){
  kitchenPrint=!!enabled;
  const sw=document.getElementById('kitchenPrintSwitch');
  if(sw){
    sw.style.background=kitchenPrint?'#16a34a':'#94a3b8';
    sw.style.opacity=kitchenPrint?'1':'0.65';
    sw.title=kitchenPrint?'เปิดอยู่ - คลิกเพื่อปิด':'ปิดอยู่ - คลิกเพื่อเปิด';
  }
  const status=document.getElementById('kitchenPrintStatus');
  if(status){ status.textContent=kitchenPrint?'เปิด':'ปิด'; status.style.color=kitchenPrint?'#15803d':'#64748b'; }
  const row=document.getElementById('kitchenPrintRow');
  if(row){ row.style.background=kitchenPrint?'#f0fdf4':'#fff7ed'; row.style.borderColor=kitchenPrint?'#86efac':'#fdba74'; }
}
function toggleKitchenPrint(){
  kitchenPrint=!kitchenPrint;
  setKitchenPrintState(kitchenPrint);
  api('settings_save',{
    kitchenPrint:kitchenPrint?'1':'0'
  });
}

let selectedSaleCategory='all';
function applySaleFilter(){
 const q=(document.getElementById('searchSale')?.value||'').toLowerCase().trim();
 document.querySelectorAll('#productGrid .product').forEach(x=>{
   const textMatch=!q||x.dataset.name.includes(q)||x.dataset.barcode.includes(q)||x.dataset.sku.includes(q);
   const catMatch=selectedSaleCategory==='all'||x.dataset.category===selectedSaleCategory;
   x.style.display=(textMatch&&catMatch)?'':'none';
 });
}
function filterSale(){ applySaleFilter(); }
function filterCategory(cat,btn){
 selectedSaleCategory=cat||'all';
 document.querySelectorAll('#saleCategoryChips .chip').forEach(x=>x.classList.remove('active'));
 if(btn)btn.classList.add('active');
 applySaleFilter();
}

function refreshPosProducts(){
  api('products').then(d=>{
    if(!d.success)return;
    const grid=document.getElementById('productGrid');
    if(!grid)return;
    const rows=Array.isArray(d.data)?d.data:[];
    grid.innerHTML=rows.map(p=>{
      const name=String(p.name||'');
      const stock=Number(p.stock||0);
      const category=String(p.category||'');
      const image=p.image?`<img src="${esc(p.image)}" alt="img">`:`<i class="fa-solid fa-box-open"></i>`;
      return `<button type="button" class="product"
        data-name="${esc(name.toLowerCase())}"
        data-barcode="${esc(String(p.barcode||'').toLowerCase())}"
        data-sku="${esc(String(p.sku||'').toLowerCase())}"
        data-category="${esc(category)}"
        onclick='addToCart(${Number(p.id)},${JSON.stringify(name)},${Number(p.price||0)},${stock})'>
        <div class="product-img">${image}</div>
        <div class="product-info">
          <div class="meta"><span class="category">${esc(category||'สินค้า')}</span><span>สต็อก: ${stock}</span></div>
          <div class="product-name">${esc(name)}</div>
          <div class="product-bottom"><span class="price">฿${money(p.price)}</span><span class="add">+</span></div>
        </div>
      </button>`;
    }).join('') || '<div class="empty">ยังไม่มีเมนูในระบบ</div>';

    const cats=[...new Set(rows.map(p=>String(p.category||'').trim()).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'th'));
    const chips=document.getElementById('saleCategoryChips');
    if(chips){
      chips.innerHTML=`<button type="button" class="chip ${selectedSaleCategory==='all'?'active':''}" data-category="all" onclick="filterCategory('all',this)">ทั้งหมด</button>`
        +cats.map(c=>`<button type="button" class="chip ${selectedSaleCategory===c?'active':''}" data-category="${esc(c)}" onclick='filterCategory(${JSON.stringify(c)},this)'>${esc(c)}</button>`).join('');
      if(!cats.includes(selectedSaleCategory) && selectedSaleCategory!=='all') selectedSaleCategory='all';
    }
    applySaleFilter();
  });
}

function loadProducts(){
 api('products').then(d=>{
  if(!d.success)return alert(d.message);
  document.getElementById('productTable').innerHTML=d.data.map(p=>`
    <tr>
      <td>
        <div class="w-8 h-8 rounded bg-slate-100 flex items-center justify-center overflow-hidden border border-slate-200">
          ${p.image ? `<img src="${esc(p.image)}" class="w-full h-full object-cover">` : `<i class="fa-solid fa-image text-slate-300 text-xs"></i>`}
        </div>
      </td>
      <td>${esc(p.barcode||'-')}</td>
      <td>${esc(p.sku||'-')}</td>
      <td><b>${esc(p.name)}</b></td>
      <td><span class="bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded text-[10px] font-semibold">${esc(p.category||'ทั่วไป')}</span></td>
      <td class="text-indigo-600 font-bold">฿${money(p.price)}</td>
      <td class="font-bold ${Number(p.stock)<0?'text-red-600':(Number(p.stock)===0?'text-amber-600':'text-green-600')}">${p.stock}</td>
      <td>
        <button onclick='editProduct(${JSON.stringify(p)})' class="text-indigo-600 font-bold mr-3 hover:text-indigo-800"><i class="fa-solid fa-pen-to-square"></i></button>
        <button onclick="deleteProduct(${p.id})" class="text-red-600 font-bold hover:text-red-800"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>
  `).join('');
 });
}

function openProductModal(p=null){
 document.getElementById('productModal').classList.add('show');
 document.getElementById('productModalTitle').textContent=p?'แก้ไขสินค้า':'เพิ่มสินค้าใหม่';
 document.getElementById('editId').value=p?p.id:'';
 document.getElementById('pBarcode').value=p?p.barcode||'':'';
 document.getElementById('pSKU').value=p?p.sku||'':'';
 document.getElementById('pName').value=p?p.name:'';
 document.getElementById('pCategory').value=p?p.category||'':'';
 document.getElementById('pCostPrice').value=p?p.cost_price||0:'';
 document.getElementById('pPrice').value=p?p.price:'';
 document.getElementById('pStock').value=p?p.stock:'';
 document.getElementById('pImage').value=p?p.image||'':'';
 document.getElementById('imageFile').value='';
}
function closeProductModal(){document.getElementById('productModal').classList.remove('show')}
function editProduct(p){openProductModal(p)}

function previewImageFile(input){
 if(input.files && input.files[0]){
  let reader = new FileReader();
  reader.onload = function(e){
    document.getElementById('pImage').value = e.target.result;
  }
  reader.readAsDataURL(input.files[0]);
 }
}

function saveProduct(){
 let id=document.getElementById('editId').value,
     data={
       id,
       barcode:document.getElementById('pBarcode').value,
       sku:document.getElementById('pSKU').value,
       name:document.getElementById('pName').value,
       category:document.getElementById('pCategory').value,
       cost_price:document.getElementById('pCostPrice').value,
       price:document.getElementById('pPrice').value,
       stock:document.getElementById('pStock').value,
       image:document.getElementById('pImage').value
     };
 api(id?'update_product':'add_product',data).then(d=>{
  alert(d.message);
  if(d.success){closeProductModal();loadProducts();refreshPosProducts()}
 });
}
function deleteProduct(id){if(confirm('ต้องการลบสินค้านี้หรือไม่?'))api('delete_product',{id}).then(d=>{alert(d.message);if(d.success){loadProducts();refreshPosProducts()}})}

const PAY_METHOD_COLORS = {cash:'#4f46e5',transfer:'#0ea5e9',qr:'#10b981',card:'#f59e0b'};

function setHistoryRange(range){
  if(range==='today'){
    const today=new Date().toLocaleDateString('en-CA');
    selectReportDate(today);
    return;
  }
  if(range==='all'){
    // ดูทั้งหมดใช้เฉพาะเมื่อผู้ใช้กดปุ่มนี้โดยตั้งใจ
    document.getElementById('fromDate').value='';
    document.getElementById('toDate').value='';
  }
  loadHistory();
}

function renderReportHeader(){
  const el=document.getElementById('reportHeader');
  if(!el)return;
  el.innerHTML=`
    <div class="text-base font-extrabold text-slate-800">${esc(companyInfo.shopName)}</div>
    ${companyInfo.companyAddress?`<div class="text-xs text-slate-500"><b>ที่อยู่:</b> ${esc(companyInfo.companyAddress)}</div>`:''}
    <div class="text-xs text-slate-500">โทร: ${esc(companyInfo.companyPhone)} &nbsp;|&nbsp; TAX ID: ${esc(companyInfo.companyTaxId)}</div>
    <div class="text-xs font-semibold text-indigo-600 mt-1">รายงานสรุปยอดขาย</div>
  `;
}

// ========================= VOID UI - FIXED =========================
// ใช้ onclick โดยตรงกับปุ่ม Void เพื่อไม่พึ่ง event delegation และป้องกันปัญหา
// ปุ่มที่สร้างแบบ dynamic แล้วกดไม่ทำงาน
let pendingVoidQtyCtx = null;
let pendingVoidAuth = null;

function safeVoidText(v){
  return String(v == null ? '' : v);
}

function openVoidItem(orderItemId, maxQty, productName, orderId = 0){
  try {
    if(!hasVoidPermission()){
      alert('ไม่มีสิทธิ์ Void\nกรุณาเข้าสู่ระบบด้วย User ที่มี Role = Admin');
      return false;
    }

    orderItemId = Number(orderItemId || 0);
    maxQty = Number(maxQty || 0);
    orderId = Number(orderId || 0);
    productName = safeVoidText(productName);

    if(orderItemId <= 0){
      alert('ไม่พบรหัสรายการสินค้า (Order Item ID)');
      return false;
    }
    if(maxQty <= 0){
      alert('รายการนี้ไม่มีจำนวนคงเหลือสำหรับ Void');
      return false;
    }

    const modal = document.getElementById('voidQtyModal');
    const label = document.getElementById('voidQtyProductLabel');
    const qtyEl = document.getElementById('voidQtyInput');
    const reasonEl = document.getElementById('voidQtyReason');

    if(!modal || !label || !qtyEl || !reasonEl){
      alert('ไม่พบหน้าต่าง Void ในหน้าเว็บ กรุณารีเฟรชหน้าแล้วลองใหม่');
      return false;
    }

    pendingVoidQtyCtx = {
      orderItemId,
      maxQty,
      productName,
      orderId
    };

    label.textContent = productName + ' (คงเหลือ ' + maxQty + ' ชิ้น)';
    qtyEl.value = maxQty;
    qtyEl.max = maxQty;
    qtyEl.min = 1;
    reasonEl.value = 'ยกเลิกรายการ / รายการผิด';

    modal.classList.remove('hidden');
    setTimeout(function(){ qtyEl.focus(); qtyEl.select(); }, 50);
    return false;
  } catch(e){
    console.error('openVoidItem error:', e);
    alert('เปิดหน้าต่าง Void ไม่สำเร็จ\n' + (e.message || String(e)));
    return false;
  }
}

function closeVoidQtyModal(){
  pendingVoidQtyCtx = null;
  const modal = document.getElementById('voidQtyModal');
  if(modal) modal.classList.add('hidden');
}

function confirmVoidQty(){
  try {
    if(!pendingVoidQtyCtx){
      alert('ไม่พบรายการที่ต้องการ Void');
      return false;
    }

    const qtyEl = document.getElementById('voidQtyInput');
    const reasonEl = document.getElementById('voidQtyReason');
    const voidQty = parseInt(qtyEl ? qtyEl.value : '0', 10);
    const reason = ((reasonEl ? reasonEl.value : '') || '').trim() || 'Void สินค้า';

    if(!Number.isFinite(voidQty) || voidQty <= 0){
      alert('กรุณากรอกจำนวนที่ต้องการ Void ให้ถูกต้อง');
      if(qtyEl) qtyEl.focus();
      return false;
    }
    if(voidQty > pendingVoidQtyCtx.maxQty){
      alert('จำนวนที่กรอกเกินกว่าจำนวนคงเหลือ (' + pendingVoidQtyCtx.maxQty + ' ชิ้น)');
      if(qtyEl) qtyEl.focus();
      return false;
    }

    const ctx = Object.assign({}, pendingVoidQtyCtx);
    closeVoidQtyModal();
    return openVoidAdminModal({
      orderItemId: ctx.orderItemId,
      orderId: ctx.orderId,
      reason: reason,
      productName: ctx.productName,
      voidQty: voidQty,
      maxQty: ctx.maxQty
    });
  } catch(e){
    console.error('confirmVoidQty error:', e);
    alert('เตรียม Void ไม่สำเร็จ\n' + (e.message || String(e)));
    return false;
  }
}

function openVoidAdminModal(data){
  if(!hasVoidPermission()){
    alert('ไม่มีสิทธิ์ Void\nUser ปัจจุบัน: ' + (currentUser.username || '-') + ' | Role: ' + (currentUser.role || '-'));
    return false;
  }

  data = data || {};
  pendingVoidAuth = data;

  const modal = document.getElementById('voidAdminModal');
  const info = document.getElementById('voidAdminInfo');
  if(!modal || !info){
    pendingVoidAuth = null;
    alert('ไม่พบหน้าต่างยืนยัน Void กรุณารีเฟรชหน้า');
    return false;
  }

  const qty = Number(data.voidQty || 0);
  const max = Number(data.maxQty || 0);
  const qtyLabel = qty >= max ? 'Void ทั้งหมด ' + qty + ' ชิ้น' : 'Void ' + qty + ' จาก ' + max + ' ชิ้น';
  info.textContent = qtyLabel + ' | สินค้า: ' + safeVoidText(data.productName) + ' | Admin: ' + (currentUser.username || '');
  modal.classList.remove('hidden');
  return false;
}

function closeVoidAdminModal(){
  pendingVoidAuth = null;
  const modal = document.getElementById('voidAdminModal');
  if(modal) modal.classList.add('hidden');
}

function confirmVoidWithAdmin(){
  if(!pendingVoidAuth){
    alert('ไม่พบรายการที่ต้องการ Void');
    return;
  }

  if(!hasVoidPermission()){
    alert('ไม่มีสิทธิ์ Void\nต้อง Login ด้วย User ที่มี Role = Admin');
    closeVoidAdminModal();
    return;
  }

  const orderItemId = Number(pendingVoidAuth.orderItemId || 0);
  const voidQty = Number(pendingVoidAuth.voidQty || 0);
  const reason = safeVoidText(pendingVoidAuth.reason || 'Void สินค้า').trim() || 'Void สินค้า';
  const btn = document.getElementById('btnConfirmVoidAdmin');

  if(orderItemId <= 0){ alert('ไม่พบ Order Item ID'); return; }
  if(voidQty <= 0){ alert('จำนวน Void ไม่ถูกต้อง'); return; }

  if(btn){
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> กำลัง Void...';
  }

  api('void_item', {
    order_item_id: orderItemId,
    void_qty: voidQty,
    reason: reason
  })
  .then(function(d){
    console.log('VOID RESPONSE:', d);
    if(!d || !d.success){
      throw new Error((d && d.message) ? d.message : 'Void ไม่สำเร็จ');
    }

    closeVoidAdminModal();
    alert(d.message || 'Void เรียบร้อยแล้ว');

    // โหลดข้อมูลใหม่ทั้งหน้า Void และประวัติ เพื่อให้จำนวนคงเหลือเปลี่ยนทันที
    if(typeof loadVoidToday === 'function') loadVoidToday();
    if(typeof loadHistory === 'function') loadHistory();
    if(typeof loadStock === 'function') loadStock();
  })
  .catch(function(e){
    console.error('VOID API ERROR:', e);
    alert('Void ไม่สำเร็จ\n\n' + (e && e.message ? e.message : String(e)));
  })
  .finally(function(){
    if(btn){
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-ban"></i> ยืนยัน Void';
    }
  });
}

// ดัก error ของปุ่ม Void เฉพาะกรณีที่ browser แจ้ง error ระหว่างการทำงาน
// ไม่ใช้ alert ทุก JavaScript error ของทั้งระบบ เพราะจะทำให้ผู้ใช้รู้สึกว่าปุ่มอื่นไม่ตอบสนอง


function loadVoidToday(){
  const q=(document.getElementById('voidReceiptSearch')?.value||'').trim();
  api('void_sales_today',{receipt_no:q}).then(d=>{
    const tbody=document.getElementById('voidTodayTable'), empty=document.getElementById('voidTodayEmpty'), summary=document.getElementById('voidTodaySummary');
    if(!d.success){alert(d.message||'ไม่สามารถค้นหารายการขายวันนี้ได้');return;}
    const label=document.getElementById('voidTodayLabel');
    if(label) label.textContent='('+d.date+')';
    const rows=Array.isArray(d.data)?d.data:[];
    if(!rows.length){
      if(tbody) tbody.innerHTML='';
      empty?.classList.remove('hidden');
      if(summary) summary.innerHTML='';
      return;
    }
    empty?.classList.add('hidden');

    let billSet=new Set(), itemQty=0, saleTotal=0;
    rows.forEach(r=>{
      billSet.add(String(r.order_id||''));
      itemQty += Number(r.quantity||0);
      saleTotal += Number(r.net_line_total||0);
    });
    if(summary) summary.innerHTML=`
      <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3"><div class="text-[11px] text-indigo-600">จำนวนบิลวันนี้</div><div class="text-lg font-bold text-indigo-700">${billSet.size.toLocaleString()} บิล</div></div>
      <div class="bg-slate-50 border border-slate-200 rounded-xl p-3"><div class="text-[11px] text-slate-500">จำนวนสินค้า</div><div class="text-lg font-bold text-slate-800">${itemQty.toLocaleString()} รายการ</div></div>
      <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-3"><div class="text-[11px] text-emerald-600">ยอดขายสุทธิ</div><div class="text-lg font-bold text-emerald-700">฿${money(saleTotal)}</div></div>`;

    tbody.innerHTML=rows.map(r=>{
      const qty=Number(r.quantity||0);
      const voided=Number(r.voided_qty||0);
      const remain=Math.max(0,qty-voided);
      const status=voided>0
        ? `<div class="text-[10px] text-red-600 font-bold mt-1">Void แล้ว ${voided}/${qty}</div>`
        : '';
      let action='';
      if(remain<=0){
        action=`<span class="text-[10px] font-bold text-red-600">Void แล้ว</span>`;
      }else if(hasVoidPermission()){
        const voidProductName = JSON.stringify(String(r.product_name || ''));
        action=`<button type="button" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded-lg text-[10px] font-bold no-print" onclick='openVoidItem(${Number(r.order_item_id)},${Number(remain)},${voidProductName},${Number(r.order_id||0)}); return false;'><i class="fa-solid fa-ban"></i> Void</button>`;
      }else{
        action=`<span class="text-[10px] text-slate-400">เฉพาะ Admin</span>`;
      }
      return `<tr>
        <td>${esc(r.created_at||'')}</td>
        <td class="font-bold text-indigo-600">${esc(r.receipt_no||('#'+r.order_id))}</td>
        <td><b>${esc(r.product_name||'')}</b>${status}</td>
        <td class="font-bold">${remain}/${qty}</td>
        <td>฿${money(r.price||0)}</td>
        <td class="font-bold">฿${money(r.net_line_total||0)}</td>
        <td>${esc(paymentLabel(r.payment_method))}</td>
        <td class="no-print">${action}</td>
      </tr>`;
    }).join('');
  }).catch(e=>alert(e.message||'ไม่สามารถเชื่อมต่อระบบได้'));
}
function clearVoidTodaySearch(){
  const el=document.getElementById('voidReceiptSearch'); if(el) el.value='';
  loadVoidToday();
}

function clearReceiptSearch(){
  const el=document.getElementById('receiptSearch');
  if(el) el.value='';
  loadHistory();
}

function selectReportDate(date, changed=''){
  // รายงานย้อนหลังรองรับช่วงหลายวัน: เปลี่ยนวันที่แล้วโหลดเฉพาะรายงานที่กำลังเลือก
  if(!date) return;
  const fromEl=document.getElementById('fromDate');
  const toEl=document.getElementById('toDate');
  const from=fromEl?.value||'';
  const to=toEl?.value||'';
  if(from && to && from>to){
    if(changed==='from' && toEl) toEl.value=from;
    else if(changed==='to' && fromEl) fromEl.value=to;
  }
  renderReportHeader();
  const active=document.querySelector('.report-btn.active')?.dataset.report || 'cashier';
  switchReport(active);
}

function reloadSelectedReport(){
  const active=document.querySelector('.report-btn.active')?.dataset.report || 'cashier';
  switchReport(active);
}

function loadStockReport(){
 const d=reportDates();
 Promise.all([ api('stock_report',d), api('sales_history',d) ]).then(([r,h])=>{
   const panel=document.getElementById('customReportPanel');
   if(!panel)return;
   if(!r.success){panel.innerHTML='<div class="p-6 text-center text-red-500 font-semibold">'+esc(r.message||'โหลดรายงาน Stock ไม่สำเร็จ')+'</div>';return;}
   const rows=Array.isArray(r.data)?r.data:[];
   let vatTotal=0;
   if(h && h.success){
     const orderMap=new Map();
     (h.data||[]).forEach(x=>{if(!orderMap.has(x.order_id))orderMap.set(x.order_id,x);});
     vatTotal=[...orderMap.values()].reduce((a,x)=>a+Math.max(0,Number(x.net_vat_amount??x.vat_amount??0)),0);
   }
   panel.innerHTML=stockPreviewHTML(rows,vatTotal)+`<div class="flex justify-end gap-2 mt-3 no-print"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold"><i class="fa-solid fa-print mr-1"></i>พิมพ์ A4</button></div>`;
 }).catch(err=>{
   const panel=document.getElementById('customReportPanel');
   if(panel)panel.innerHTML='<div class="p-6 text-center text-red-500 font-semibold">โหลดรายงาน Stock ไม่สำเร็จ: '+esc(err.message||'Unknown error')+'</div>';
 });
}

function reportDates(){
  return {from:document.getElementById('fromDate')?.value||'',to:document.getElementById('toDate')?.value||''};
}
function reportHeaderHTML(code,title){
  const d=reportDates();
  const from=d.from||'-', to=d.to||d.from||'-';
  const batch='-';
  return `<div class="report-a4-head">
    <div class="report-company">${esc(companyInfo.shopName||'ร้าน POS')}</div>
    ${companyInfo.companyAddress?`<div><b>ที่อยู่:</b> ${esc(companyInfo.companyAddress)}</div>`:''}
    <div>โทร: ${esc(companyInfo.companyPhone||'-')} | หมายเลขประจำตัวผู้เสียภาษี ${esc(companyInfo.companyTaxId||'-')}</div>
    <div class="report-meta">Batch No.${batch}</div>
  </div><div class="report-main-title">${esc(title)}</div>`;
}
function reportTableHTML(headers,rows,foot=''){
  return `<table class="report-a4-table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody>${foot?`<tfoot>${foot}</tfoot>`:''}</table>`;
}
function openReportPreview(type, html, title){
  const m=document.getElementById('reportPreviewModal'), c=document.getElementById('reportPreviewContent'), t=document.getElementById('reportPreviewTitle');
  if(!m||!c)return;
  t.textContent=title||'ตัวอย่างรายงาน'; c.innerHTML=html; m.classList.remove('hidden'); document.body.classList.add('overflow-hidden');
}
function closeReportPreview(){document.getElementById('reportPreviewModal')?.classList.add('hidden');document.body.classList.remove('overflow-hidden');}
function printReportPreview(){
  const c=document.getElementById('reportPreviewContent'); if(!c)return;
  const w=window.open('','_blank','width=1200,height=850'); if(!w)return;
  w.document.write(`<!doctype html><html lang="th"><head><meta charset="utf-8"><title>รายงาน</title><style>${reportPrintCSS()}</style></head><body>${c.innerHTML}</body></html>`); w.document.close(); w.focus(); setTimeout(()=>w.print(),300);
}
function reportPrintCSS(){return `.report-scroll-panel{max-height:none!important;overflow:visible!important;border:0!important;padding:0!important;background:#fff!important}.report-a4{font-family:Arial,'Tahoma',sans-serif;color:#111;font-size:11px;background:#fff}.report-a4-head{text-align:left;line-height:1.55;border-bottom:1px solid #999;padding-bottom:8px;margin-bottom:10px}.report-code{font-size:13px;font-weight:700}.report-main-title{text-align:left;font-size:13px;font-weight:700;margin:8px 0 6px}.report-company{font-size:14px;font-weight:700}.report-meta{text-align:right;font-size:10px;margin-top:3px}.report-section-title{font-weight:700;font-size:12px;margin:8px 0 4px}.report-a4-table{width:100%;border-collapse:collapse;table-layout:fixed}.report-a4-table th,.report-a4-table td{border:1px solid #555;padding:4px 5px;vertical-align:middle;word-break:break-word}.report-item-table th,.report-item-table td{font-size:10px}.report-item-table th:nth-child(1),.report-item-table td:nth-child(1){width:18%}.report-item-table th:nth-child(2),.report-item-table td:nth-child(2){width:37%}.report-item-table th:nth-child(3),.report-item-table td:nth-child(3){width:15%}.report-item-table th:nth-child(4),.report-item-table td:nth-child(4){width:12%}.report-item-table th:nth-child(5),.report-item-table td:nth-child(5){width:18%}.report-stock-table{font-size:9px}.report-stock-table th,.report-stock-table td{padding:3px 4px}.report-stock-table th:nth-child(1),.report-stock-table td:nth-child(1){width:11%}.report-stock-table th:nth-child(2),.report-stock-table td:nth-child(2){width:8%}.report-stock-table th:nth-child(3),.report-stock-table td:nth-child(3){width:18%}.report-stock-table th:nth-child(4),.report-stock-table td:nth-child(4){width:9%}.report-stock-table th:nth-child(5),.report-stock-table td:nth-child(5){width:9%}.report-stock-table th:nth-child(6),.report-stock-table td:nth-child(6){width:9%}.report-stock-table th:nth-child(7),.report-stock-table td:nth-child(7){width:9%}.report-stock-table th:nth-child(8),.report-stock-table td:nth-child(8){width:12%}.report-stock-table th:nth-child(9),.report-stock-table td:nth-child(9){width:15%}.report-a4-table th{background:#eee;text-align:center;font-weight:700}.report-a4-table td.num{text-align:right;white-space:nowrap}.report-a4-table td.center{text-align:center}.report-a4-table tr.total td{font-weight:700;background:#f5f5f5}.report-a4-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:8px 0}.report-a4-summary>div{border:1px solid #777;padding:6px;text-align:center}.report-a4-summary b{display:block;font-size:13px}.report-a4-note{font-size:9px;margin-top:8px}@media print{@page{size:A4 portrait;margin:10mm}.report-a4{font-size:10px}.no-print{display:none!important}}`}
function stockPreviewHTML(rows,vatTotal){
  vatTotal=Number(vatTotal||0);
  const body=rows.map(r=>`<tr>
    <td>${esc(r.barcode||'-')}</td>
    <td>${esc(r.sku||'-')}</td>
    <td>${esc(r.name||'')}</td>
    <td class="num">${Number(r.stock_in||0).toLocaleString('th-TH')}</td>
    <td class="num">${Number(r.stock_out||0).toLocaleString('th-TH')}</td>
    <td class="num">${Number(r.stock_void||0).toLocaleString('th-TH')}</td>
    <td class="num">${Number(r.net_out||0).toLocaleString('th-TH')}</td>
    <td class="num">${money(r.net_sales||0)}</td>
    <td class="num">${Number(r.stock||0).toLocaleString('th-TH')}</td>
  </tr>`);
  const sums=rows.reduce((a,r)=>{
    a.i+=Number(r.stock_in||0);
    a.o+=Number(r.stock_out||0);
    a.v+=Number(r.stock_void||0);
    a.n+=Number(r.net_out||0);
    a.s+=Number(r.net_sales||0);
    return a;
  },{i:0,o:0,v:0,n:0,s:0});
  return `<div class="report-a4">${reportHeaderHTML('STOCK001','รายงาน STOCK สินค้า')}
    <div class="report-section-title">สรุป Stock และยอดขายตามช่วงวันที่ (ยอดขายสุทธิ = ก่อน VAT)</div>
    <table class="report-a4-table report-stock-table">
      <thead><tr>
        <th>BARCODE</th><th>SKU</th><th>สินค้า</th><th>Stock เข้า</th><th>ขายออก</th><th>คืน Void</th><th>ขายสุทธิ</th><th>ยอดขายสุทธิ</th><th>Stock คงเหลือ</th>
      </tr></thead>
      <tbody>${body.join('')}</tbody>
      <tfoot><tr class="total">
        <td colspan="3">ยอดรวม</td><td class="num">${sums.i.toLocaleString('th-TH')}</td><td class="num">${sums.o.toLocaleString('th-TH')}</td><td class="num">${sums.v.toLocaleString('th-TH')}</td><td class="num">${sums.n.toLocaleString('th-TH')}</td><td class="num">${money(sums.s)}</td><td></td>
      </tr></tfoot>
    </table>
    <div class="report-a4-summary"><div>ยอดขายสุทธิ (ก่อน VAT)<b>${money(sums.s)}</b></div><div>VAT รวมช่วงนี้<b>${money(vatTotal)}</b></div><div>ยอดรวมทั้งสิ้น (รวม VAT)<b>${money(sums.s+vatTotal)}</b></div><div></div></div>
    <div class="report-a4-note">ยอดขายสุทธิคำนวณจากรายการขายจริงใน orders / order_items (ก่อน VAT) และหักจำนวนที่ Void แล้วตามช่วงวันที่เลือก</div>
  </div>`;
}

function itemPreviewHTML(rows){
  // รายงาน Item: ถ้ารายการถูก Void ครบจำนวนแล้ว ไม่ต้องแสดงรายการและยอดของสินค้านั้นเลย
  const src=Array.isArray(rows)?rows:[];
  const activeRows=src.filter(r=>Math.max(0,Number(r.quantity||0)-Number(r.voided_qty||0))>0 && Number(r.net_line_total||0)>0);
  const map=new Map(); activeRows.forEach(r=>{const k=r.product_id;const q=Math.max(0,Number(r.quantity||0)-Number(r.voided_qty||0));const old=map.get(k)||{barcode:r.barcode,name:r.name,price:Number(r.price_exvat!=null?r.price_exvat:(r.price||0)),qty:0,total:0};old.qty+=q;old.total+=Number(r.net_line_total||0);map.set(k,old);});
  const arr=[...map.values()]; const body=arr.map(x=>`<tr><td>${esc(x.barcode||'-')}</td><td>${esc(x.name||'')}</td><td class="num">${money(x.price)}</td><td class="num">${x.qty}</td><td class="num">${money(x.total)}</td></tr>`); const qty=arr.reduce((a,x)=>a+x.qty,0),total=arr.reduce((a,x)=>a+x.total,0);
  const orderMap=new Map(); src.forEach(r=>{if(!orderMap.has(r.order_id))orderMap.set(r.order_id,r);});
  const vatTotal=[...orderMap.values()].reduce((a,x)=>a+Math.max(0,Number(x.net_vat_amount??x.vat_amount??0)),0);
  return `<div class="report-a4">${reportHeaderHTML('VEN028','รายงานยอดขาย Item')}<div class="report-section-title">Item สินค้า (ยอดรวม = ยอดสุทธิก่อน VAT)</div><table class="report-a4-table report-item-table"><thead><tr><th>รหัส Item</th><th>ชื่อ Item สินค้า</th><th>ราคาต่อหน่วย</th><th>จำนวน</th><th>ยอดรวม</th></tr></thead><tbody>${body.join('')}</tbody><tfoot><tr class="total"><td colspan="3">ยอดรวม</td><td class="num">${qty}</td><td class="num">${money(total)}</td></tr></tfoot></table><div class="report-a4-summary"><div>ยอดสุทธิ (ก่อน VAT)<b>${money(total)}</b></div><div>VAT รวมช่วงนี้<b>${money(vatTotal)}</b></div><div>ยอดรวมทั้งสิ้น (รวม VAT)<b>${money(total+vatTotal)}</b></div><div>จำนวนสินค้า<b>${qty}</b></div></div><div class="report-a4-note">ราคาต่อหน่วย/ยอดรวมของแต่ละ Item เป็นราคาก่อน VAT เสมอ ส่วน VAT รวมคำนวณระดับบิลตามส่วนลดจริงของแต่ละบิล</div></div>`;
}
function taxPreviewHTML(rows){
  const m=new Map(); rows.forEach(r=>{const g=Number(r.net_order_total??r.total_amount??0);if(g<=0)return;if(!m.has(r.order_id))m.set(r.order_id,r);}); const arr=[...m.values()]; let total=0,vat=0,net=0;
  const body=arr.map(x=>{const g=Number(x.net_order_total??x.total_amount??0),v=Math.max(0,Number(x.net_vat_amount ?? x.vat_amount ?? 0)),n=g-v;total+=g;vat+=v;net+=n;return `<tr><td>${esc(String(x.created_at||'').slice(0,10))}</td><td>${esc(x.receipt_no||('#'+x.order_id))}</td><td class="num">${money(n)}</td><td class="num">${money(v)}</td><td class="num">${money(g)}</td></tr>`});
  return `<div class="report-a4">${reportHeaderHTML('VEN014','รายงานภาษีขายแบบสรุป (ใบกำกับภาษีอย่างย่อ)')}${reportTableHTML(['วันที่','เลขที่ใบกำกับภาษี / ใบเสร็จ','ยอดสุทธิ (ก่อน VAT)','VAT','ยอดรวมทั้งสิ้น (รวม VAT)'],body,`<tr class="total"><td colspan="2">ยอดรวม</td><td class="num">${money(net)}</td><td class="num">${money(vat)}</td><td class="num">${money(total)}</td></tr>`)}<div class="report-a4-note">ยอดสุทธิ = ยอดขายก่อนหัก VAT | ยอดรวมทั้งสิ้น = ยอดสุทธิ + VAT</div></div>`;
}
function voidPreviewHTML(data){
  const rows=Array.isArray(data?.data)?data.data:[];
  const body=rows.map(r=>{
    const net=Number(r.refund_amount||0);
    const vat=Number(r.void_vat_amount||0);
    const gross=Number(r.refund_amount_incl_vat!=null?r.refund_amount_incl_vat:(net+vat));
    return `<tr><td>${esc(r.voided_at||'')}</td><td>${esc(r.receipt_no||('#'+r.order_id))}</td><td>${esc(r.product_name||'')}</td><td class="center">${Number(r.void_qty||0).toLocaleString('th-TH')}</td><td class="num">${money(net)}</td><td class="num">${money(vat)}</td><td class="num">${money(gross)}</td><td>${esc(r.payment_method==='cash'?'เงินสด':'QR โอน')}</td><td>${esc(r.seller_name||r.seller_username||'ไม่ระบุ')}</td><td>${esc(r.reason||'Void สินค้า')}</td></tr>`;
  });
  const total=Number(data?.void_total||0),qty=Number(data?.void_qty||0),bills=Number(data?.void_bill_count||0);
  const vatTotal=Number(data?.void_vat_total!=null?data.void_vat_total:0);
  const grossTotal=Number(data?.void_total_incl_vat!=null?data.void_total_incl_vat:(total+vatTotal));
  return `<div class="report-a4">${reportHeaderHTML('VOID001','รายงานยกเลิกสินค้า (Void)')}<div class="report-section-title">รายการยกเลิกสินค้า วันที่ ${esc(data?.from||'')} ถึง ${esc(data?.to||'')} (ยอดที่หัก = ก่อน VAT)</div>${reportTableHTML(['วันที่/เวลา Void','เลขที่บิล','สินค้า','จำนวน','ยอดที่หัก (ก่อน VAT)','VAT ที่หัก','ยอดรวมที่หัก','ชำระเงิน','ผู้ทำรายการ','เหตุผล'],body,`<tr class="total"><td colspan="3">ยอดรวม</td><td class="center">${qty.toLocaleString('th-TH')}</td><td class="num">${money(total)}</td><td class="num">${money(vatTotal)}</td><td class="num">${money(grossTotal)}</td><td colspan="3"></td></tr>`)}<div class="report-a4-summary"><div>จำนวนบิลที่มี Void<b>${bills.toLocaleString('th-TH')}</b></div><div>ยอดที่หัก (ก่อน VAT)<b>฿${money(total)}</b></div><div>VAT ที่หัก<b>฿${money(vatTotal)}</b></div><div>ยอดรวมที่หักทั้งสิ้น<b>฿${money(grossTotal)}</b></div></div><div class="report-a4-note">ยอดที่หัก (ก่อน VAT) และ VAT ที่หัก ถูกนำไปหักออกจากรายงานสรุปยอดร้านค้าและรายงาน Cashier แล้วทั้งสองส่วน</div></div>`;
}
function cashierPreviewHTML(data){
  const x=data||{}, users=Array.isArray(x.by_user)?x.by_user:[];
  if(!users.length){
    return `<div class="report-a4"><div class="report-section-title">สรุปยอด Cashier</div><div class="p-6 text-center text-slate-500">ไม่พบรายการขายในช่วงวันที่เลือก</div></div>`;
  }
  return users.map((u,i)=>{
    const title=`${esc(u.fullname||u.username||'ไม่ระบุ')} (${esc(u.username||'-')})`;
    const vatTotal=Number(u.vat_total!=null?u.vat_total:0);
    const netExVat=Number(u.net_sales_exvat!=null?u.net_sales_exvat:Math.max(0,Number(u.sales_total||0)-vatTotal));
    return `<div class="report-a4 cashier-report-page" style="${i>0?'page-break-before:always;margin-top:24px;':''}">
      ${reportHeaderHTML('FIN001','รายงานสรุปยอดขาย Cashier')}
      <div class="report-section-title">Cashier: ${title}</div>
      <div class="report-a4-summary">
        <div>จำนวนบิล<b>${Number(u.bill_count||0).toLocaleString('th-TH')}</b></div>
        <div>ยอดสุทธิ (ก่อน VAT)<b>฿${money(netExVat)}</b></div>
        <div>VAT รวม<b>฿${money(vatTotal)}</b></div>
        <div>ยอดรวมทั้งสิ้น (รวม VAT)<b>฿${money(u.sales_total||0)}</b></div>
      </div>
      ${reportTableHTML(['รายการ','จำนวน / ยอดเงิน'],[
        `<tr><td>จำนวนบิลทั้งหมด</td><td class="num">${Number(u.bill_count||0).toLocaleString('th-TH')}</td></tr>`,
        `<tr><td>ยอดสุทธิ (ก่อน VAT)</td><td class="num">${money(netExVat)}</td></tr>`,
        `<tr><td>VAT รวม (7%)</td><td class="num">${money(vatTotal)}</td></tr>`,
        `<tr><td>ยอดรวมทั้งสิ้น (รวม VAT)</td><td class="num">${money(u.sales_total||0)}</td></tr>`,
        `<tr><td>ยอดขายเงินสด (รวม VAT)</td><td class="num">${money(u.cash_sales||0)}</td></tr>`,
        `<tr><td>จำนวนบิลเงินสด</td><td class="num">${Number(u.cash_bill_count||0).toLocaleString('th-TH')}</td></tr>`,
        `<tr><td>ยอดขาย QR โอน (รวม VAT)</td><td class="num">${money(u.qr_sales||0)}</td></tr>`,
        `<tr><td>จำนวนบิล QR โอน</td><td class="num">${Number(u.qr_bill_count||0).toLocaleString('th-TH')}</td></tr>`
      ])}
      <div class="report-a4-note">ยอดสุทธิ = ยอดขายก่อนหัก VAT | ยอดรวมทั้งสิ้น = ยอดสุทธิ + VAT | ยอดเงินสด/QR ยังคงเป็นยอดรวม VAT เพื่อใช้กระทบยอดเงินจริงในลิ้นชัก</div>
    </div>`;
  }).join('');
}
function storePreviewHTML(rows){
  const src=Array.isArray(rows)?rows:[];
  const m=new Map();
  src.forEach(r=>{
    const v=Number(r.net_order_total??r.total_amount??0);
    if(v<=0)return;
    if(!m.has(r.order_id))m.set(r.order_id,r);
  });
  const arr=[...m.values()]; let total=0,vatSum=0,netSum=0,cash=0,qr=0;
  const body=arr.map(x=>{
    const v=Math.max(0,Number(x.net_order_total??x.total_amount??0));
    const vat=Math.max(0,Number(x.net_vat_amount??x.vat_amount??0));
    const net=Math.max(0,v-vat);
    total+=v; vatSum+=vat; netSum+=net;
    if(x.payment_method==='cash')cash+=v;else qr+=v;
    return `<tr><td>${esc(String(x.created_at||'').slice(0,10))}</td><td>${esc(x.receipt_no||('#'+x.order_id))}</td><td>${x.payment_method==='cash'?'เงินสด':'QR โอน'}</td><td class="num">${money(net)}</td><td class="num">${money(vat)}</td><td class="num">${money(v)}</td></tr>`;
  });
  return `<div class="report-a4">${reportHeaderHTML('VEN001','รายงานสรุปยอดขายร้านค้าประจำวัน')}<div class="report-section-title">ยอดขายสุทธิ (ก่อน VAT) หลังหัก Void</div>${reportTableHTML(['วันที่','เลขที่บิล','ประเภทการชำระเงิน','ยอดสุทธิ (ก่อน VAT)','VAT','ยอดรวม (รวม VAT)'],body,`<tr class="total"><td colspan="3">ยอดรวม</td><td class="num">${money(netSum)}</td><td class="num">${money(vatSum)}</td><td class="num">${money(total)}</td></tr>`)}<div class="report-a4-summary"><div>ยอดสุทธิ (ก่อน VAT)<b>${money(netSum)}</b></div><div>VAT รวม<b>${money(vatSum)}</b></div><div>จำนวนบิล<b>${arr.length}</b></div><div>ยอดรวมทั้งสิ้น (รวม VAT)<b>${money(total)}</b></div></div><div class="report-a4-summary"><div>เงินสด (รวม VAT)<b>${money(cash)}</b></div><div>QR PromptPay (รวม VAT)<b>${money(qr)}</b></div><div></div><div></div></div><div class="report-a4-note">ยอดสุทธิ = ยอดขายก่อนหัก VAT (หลังหักส่วนลดและยอด Void) | VAT = ภาษีมูลค่าเพิ่ม 7% | ยอดรวม = ยอดสุทธิ + VAT</div></div>`;
}
function downloadCSVFile(filename, rows){
  if(!rows || !rows.length){ alert('ไม่มีข้อมูลสำหรับ Export'); return; }
  const headers=Object.keys(rows[0]);
  const escCsv=v=>`"${String(v??'').replace(/"/g,'""')}"`;
  const csv='\uFEFF'+[headers.map(escCsv).join(','), ...rows.map(r=>headers.map(h=>escCsv(r[h])).join(','))].join('\n');
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
  const url=URL.createObjectURL(blob);
  const a=document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove();
  setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function exportSelectedReport(){
  const active=document.querySelector('.report-btn.active')?.dataset.report||'cashier';
  const d=reportDates();
  const stamp=(d.from||'')+(d.to&&d.to!==d.from?'_'+d.to:'');
  if(active==='void'){ api('void_report',d).then(r=>{ if(!r.success)return alert(r.message||'Export รายงาน Void ไม่สำเร็จ'); const rows=(r.data||[]).map(x=>({วันที่เวลาVoid:x.voided_at||'',เลขที่บิล:x.receipt_no||'',สินค้า:x.product_name||'',จำนวนVoid:Number(x.void_qty||0),ยอดที่หักก่อนVAT:Number(x.refund_amount||0),VATที่หัก:Number(x.void_vat_amount||0),ยอดรวมที่หัก:Number(x.refund_amount_incl_vat!=null?x.refund_amount_incl_vat:(Number(x.refund_amount||0)+Number(x.void_vat_amount||0))),การชำระ:x.payment_method==='cash'?'เงินสด':'QR โอน',ผู้ทำรายการ:x.seller_name||x.voided_by||'',เหตุผล:x.reason||'Void สินค้า'})); downloadCSVFile(`report_void_${stamp||'report'}.csv`,rows); });
  }else if(active==='stock'){
    api('stock_report',d).then(r=>{
      if(!r.success)return alert(r.message||'Export Stock ไม่สำเร็จ');
      const rows=(r.data||[]).map(x=>({BARCODE:x.barcode||'',SKU:x.sku||'',สินค้า:x.name||'',Stockเข้า:Number(x.stock_in||0),ขายออก:Number(x.stock_out||0),คืนVoid:Number(x.stock_void||0),ขายสุทธิ:Number(x.net_out||0),ยอดขายสุทธิ:Number(x.net_sales||0),Stockคงเหลือ:Number(x.stock||0)}));
      downloadCSVFile(`report_stock_${stamp||'report'}.csv`,rows);
    });
  }else if(active==='cashier'){
    api('cashier_summary',d).then(r=>{
      if(!r.success)return alert(r.message||'Export Cashier ไม่สำเร็จ');
      const rows=(r.data?.by_user||[]).map(x=>{const vat=Number(x.vat_total||0);const net=Number(x.net_sales_exvat!=null?x.net_sales_exvat:Math.max(0,Number(x.sales_total||0)-vat));return {User:x.username||'',ชื่อพนักงาน:x.fullname||'',จำนวนบิล:Number(x.bill_count||0),ยอดสุทธิก่อนVAT:net,VATรวม:vat,ยอดรวมทั้งสิ้น:Number(x.sales_total||0),เงินสด:Number(x.cash_sales||0),จำนวนบิลเงินสด:Number(x.cash_bill_count||0),QRโอน:Number(x.qr_sales||0),จำนวนบิลQR:Number(x.qr_bill_count||0)};});
      downloadCSVFile(`report_cashier_${stamp||'report'}.csv`,rows);
    });
  }else{
    api('sales_history',d).then(r=>{
      if(!r.success)return alert(r.message||'Export รายงานไม่สำเร็จ');
      const src=r.data||[];
      if(active==='item'){
        // Export Item ต้องไม่แสดงสินค้าที่ถูก Void ครบจำนวนแล้ว
        const m=new Map(); src.filter(x=>Math.max(0,Number(x.quantity||0)-Number(x.voided_qty||0))>0 && Number(x.net_line_total||0)>0).forEach(x=>{const k=x.product_id;const q=Math.max(0,Number(x.quantity||0)-Number(x.voided_qty||0));const o=m.get(k)||{Item:x.barcode||'',ชื่อสินค้า:x.name||'',ราคาต่อหน่วย:Number(x.price||0),จำนวน:0,ยอดรวม:0};o.จำนวน+=q;o.ยอดรวม+=Number(x.net_line_total||0);m.set(k,o);});
        downloadCSVFile(`report_item_${stamp||'report'}.csv`,[...m.values()]);
      }else if(active==='tax'){
        const m=new Map(); src.forEach(x=>{const g=Number(x.net_order_total??x.total_amount??0);if(g<=0)return;if(!m.has(x.order_id))m.set(x.order_id,x);});
        const rows=[...m.values()].map(x=>{const g=Number(x.net_order_total??x.total_amount??0),v=Math.max(0,Number(x.net_vat_amount ?? x.vat_amount ?? 0));return {วันที่:String(x.created_at||'').slice(0,10),เลขที่ใบเสร็จ:x.receipt_no||('#'+x.order_id),ก่อนVAT:g-v,VAT:v,รวม:g};});
        downloadCSVFile(`report_tax_${stamp||'report'}.csv`,rows);
      }else{
        const m=new Map(); src.forEach(x=>{const g=Number(x.net_order_total??x.total_amount??0);if(g<=0)return;if(!m.has(x.order_id))m.set(x.order_id,x);});
        const rows=[...m.values()].map(x=>{const g=Number(x.net_order_total??x.total_amount??0);const vat=Math.max(0,Number(x.net_vat_amount??x.vat_amount??0));return {วันที่:String(x.created_at||'').slice(0,10),เลขที่บิล:x.receipt_no||('#'+x.order_id),การชำระ:x.payment_method==='cash'?'เงินสด':'QR โอน',ยอดสุทธิก่อนVAT:g-vat,VAT:vat,ยอดรวมทั้งสิ้น:g};});
        downloadCSVFile(`report_store_${stamp||'report'}.csv`,rows);
      }
    });
  }
}
function printSelectedReportA4(){
  const active=document.querySelector('.report-btn.active')?.dataset.report||'cashier';
  const panel=document.getElementById('customReportPanel');
  if(!panel){alert('ไม่พบพื้นที่รายงาน');return;}

  // พิมพ์จากรายงานที่แสดงอยู่จริง เพื่อให้ทุกประเภทใช้ข้อมูล/ช่วงวันที่เดียวกับหน้าจอ
  const clone=panel.cloneNode(true);
  clone.querySelectorAll('.no-print,button').forEach(el=>el.remove());
  const html=clone.innerHTML.trim();
  if(!html){
    alert('กรุณาเลือกประเภทรายงานก่อนพิมพ์ A4');
    return;
  }

  const titles={
    cashier:'รายงานสรุปยอด Cashier',
    store:'รายงานสรุปยอดร้านค้า',
    item:'รายงานยอดขาย Item',
    tax:'รายงานภาษีขาย',
    stock:'รายงาน STOCK สินค้า',
    void:'รายงานยกเลิกสินค้า (Void)'
  };
  const title=titles[active]||'รายงานย้อนหลัง';
  const w=window.open('','_blank','width=1200,height=850');
  if(!w){
    alert('Browser บล็อกหน้าต่างพิมพ์ กรุณาอนุญาต Pop-up สำหรับเว็บไซต์นี้');
    return;
  }

  const css=reportPrintCSS()+`\n
    body{margin:0;padding:0;background:#fff;color:#111;font-family:Arial,'Tahoma',sans-serif}
    .report-a4{width:100%;box-sizing:border-box}
    .cashier-report-page{page-break-after:always;break-after:page}
    .cashier-report-page:last-child{page-break-after:auto;break-after:auto}
    table{page-break-inside:auto}
    tr{page-break-inside:avoid;page-break-after:auto}
    thead{display:table-header-group}
    tfoot{display:table-row-group}
  `;

  w.document.open();
  w.document.write(`<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(title)}</title><style>${css}</style></head><body>${html}</body></html>`);
  w.document.close();

  // รอให้เนื้อหา/ฟอนต์ในหน้าต่างพิมพ์โหลดเสร็จจริงก่อนสั่งพิมพ์ แทนการหน่วงเวลาคงที่ (บักเดิม: บางเครื่อง/รายงานยาวโหลดไม่ทันใน 700ms ทำให้พิมพ์ออกมาว่าง/หน้าไม่ครบ)
  let printed=false;
  const doPrint=()=>{
    if(printed)return; printed=true;
    w.focus();
    w.print();
  };
  w.onload=doPrint;
  // กันเหตุการณ์ onload ไม่ทำงานในบางเบราว์เซอร์ (เช่นถูกบล็อกบางส่วน) ให้ยังพิมพ์ได้อยู่ดี
  setTimeout(doPrint, 1200);
}
function openSelectedReportPreview(){
  const active=document.querySelector('.report-btn.active')?.dataset.report||'cashier';
  if(active==='void') api('void_report',reportDates()).then(d=>{if(d.success)openReportPreview('void',voidPreviewHTML(d),'ตัวอย่าง VOID001 | รายงานยกเลิกสินค้า (Void)');});
  else if(active==='stock') api('stock_report',reportDates()).then(d=>{if(d.success)openReportPreview('stock',stockPreviewHTML(d.data||[]),'ตัวอย่าง STOCK001 | รายงาน STOCK สินค้า');});
  else if(active==='item') api('sales_history',reportDates()).then(d=>{if(d.success)openReportPreview('item',itemPreviewHTML(d.data||[]),'ตัวอย่าง VEN028 | รายงานยอดขาย Item');});
  else if(active==='tax') api('sales_history',reportDates()).then(d=>{if(d.success)openReportPreview('tax',taxPreviewHTML(d.data||[]),'ตัวอย่าง VEN014 | รายงานภาษีขาย');});
  else if(active==='cashier') api('cashier_summary',reportDates()).then(d=>{if(d.success)openReportPreview('cashier',cashierPreviewHTML(d.data||{}),'ตัวอย่าง FIN001 | รายงานสรุปยอดขาย Cashier');});
  else api('sales_history',reportDates()).then(d=>{if(d.success)openReportPreview('store',storePreviewHTML(d.data||[]),'ตัวอย่าง VEN001 | รายงานสรุปยอดขายร้านค้า');});
}
const reportMenuMeta={
  cashier:{code:'FIN001',title:'รายงานสรุปยอดขาย Cashier',desc:'รายงานสรุปยอดขายแยกตาม Cashier'},
  store:{code:'VEN001',title:'รายงานสรุปยอดร้านค้า',desc:'สรุปยอดขายสุทธิแยกตามร้านค้า'},
  item:{code:'VEN028',title:'รายงานยอดขาย Item',desc:'สรุปจำนวนและยอดขายสุทธิแยกตามสินค้า'},
  tax:{code:'VEN014',title:'รายงานภาษีขาย',desc:'สรุปยอดขายและภาษีมูลค่าเพิ่มหลังหัก Void'},
  stock:{code:'STOCK001',title:'รายงาน STOCK สินค้า',desc:'สรุปสินค้า จำนวนขาย Void และยอดสุทธิ'},
  void:{code:'VOID001',title:'รายงานยกเลิกสินค้า (Void)',desc:'รายละเอียดรายการสินค้าและยอดเงินที่ถูกยกเลิก'}
};
function updateReportMenuHeader(type){
  const m=reportMenuMeta[type]||reportMenuMeta.cashier;
  const code=document.getElementById('selectedReportCode');
  const title=document.getElementById('selectedReportTitle');
  const desc=document.getElementById('selectedReportDescription');
  if(code)code.textContent=m.code;
  if(title)title.textContent=m.title;
  if(desc)desc.textContent=m.desc;
}
function clearReportPanel(){
  const panel=document.getElementById('customReportPanel');
  if(panel){panel.innerHTML='';panel.style.display='block';panel.classList.remove('hidden');}
  document.querySelectorAll('.report-btn').forEach(b=>b.classList.remove('active'));
  updateReportMenuHeader('cashier');
}
function switchReport(type){
  const ids=['itemReportBlock','historySummary','stockReportBlock'];
  ids.forEach(id=>{const el=document.getElementById(id);if(el){el.style.display='none';el.classList.add('hidden');}});
  document.querySelectorAll('.report-btn').forEach(b=>{
    b.classList.toggle('active',b.dataset.report===type);
  });
  updateReportMenuHeader(type);

  const panel=document.getElementById('customReportPanel');
  if(!panel)return;
  panel.style.display='block';
  panel.classList.remove('hidden');
  panel.innerHTML='<div class="p-10 text-center text-slate-400"><i class="fa-solid fa-spinner fa-spin mr-2"></i>กำลังโหลด '+esc((reportMenuMeta[type]||{}).title||'รายงาน')+'...</div>';

  if(type==='item') loadItemReport();
  else if(type==='void') loadVoidReport();
  else if(type==='stock') loadStockReport();
  else if(type==='tax') loadTaxReport();
  else if(type==='cashier') loadCashierReport();
  else if(type==='store') loadStoreReport();
}
function loadItemReport(){
  const d=reportDates();
  api('sales_history',d).then(r=>{
    const panel=document.getElementById('customReportPanel');
    if(!panel)return;
    if(!r.success){
      panel.innerHTML='<div class="p-6 text-center text-red-500 font-semibold">'+esc(r.message||'ไม่สามารถโหลดรายงาน Item ได้')+'</div>';
      return;
    }
    const rows=Array.isArray(r.data)?r.data:[];
    panel.innerHTML=itemPreviewHTML(rows)+`<div class="flex justify-end gap-2 mt-3 no-print"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold"><i class="fa-solid fa-print mr-1"></i>พิมพ์ A4</button></div>`;
  }).catch(err=>{
    const panel=document.getElementById('customReportPanel');
    if(panel)panel.innerHTML='<div class="p-6 text-center text-red-500 font-semibold">โหลดรายงาน Item ไม่สำเร็จ: '+esc(err.message||'Unknown error')+'</div>';
  });
}
function loadTaxReport(){
 const d=reportDates(); api('sales_history',d).then(r=>{if(!r.success)return alert(r.message||'ไม่สามารถโหลดรายงานภาษีขายได้');const rows=r.data||[],m=new Map();rows.forEach(x=>{if(!m.has(x.order_id))m.set(x.order_id,x);});const arr=[...m.values()];let total=0,vat=0,net=0;arr.forEach(x=>{const g=Number(x.net_order_total??x.total_amount??0),v=Math.max(0,Number(x.net_vat_amount ?? x.vat_amount ?? 0));total+=g;vat+=v;net+=g-v;});document.getElementById('customReportPanel').innerHTML=taxPreviewHTML(arr)+`<div class="flex justify-end mt-2"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">พิมพ์ A4</button></div>`;});
}
function loadVoidReport(){ const d=reportDates(); api('void_report',d).then(r=>{ if(!r.success)return alert(r.message||'ไม่สามารถโหลดรายงาน Void ได้'); const panel=document.getElementById('customReportPanel'); if(panel)panel.innerHTML=voidPreviewHTML(r)+`<div class="flex justify-end mt-3 no-print"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">พิมพ์ A4</button></div>`; }).catch(err=>alert('โหลดรายงาน Void ไม่สำเร็จ: '+(err.message||'Unknown error'))); }
function loadCashierReport(){const d=reportDates();api('cashier_summary',d).then(r=>{if(!r.success)return alert(r.message||'ไม่สามารถโหลดรายงาน Cashier ได้');document.getElementById('customReportPanel').innerHTML=cashierPreviewHTML(r.data||{})+`<div class="flex justify-end mt-3 no-print"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">พิมพ์ A4</button></div>`;}).catch(err=>alert('โหลดรายงาน Cashier ไม่สำเร็จ: '+(err.message||'Unknown error')));}
function loadStoreReport(){const d=reportDates();api('sales_history',d).then(r=>{if(!r.success)return alert(r.message||'ไม่สามารถโหลดรายงานร้านค้าได้');document.getElementById('customReportPanel').innerHTML=storePreviewHTML(r.data||[])+`<div class="flex justify-end mt-2"><button onclick="printSelectedReportA4()" class="bg-slate-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold">พิมพ์ A4</button></div>`;});}

function loadHistory(){
 const fromEl=document.getElementById('fromDate');
 const toEl=document.getElementById('toDate');
 const from=fromEl?.value||'';
 const to=toEl?.value||'';
 const receiptNo='';
 if(from && to && from>to){
   alert('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');
   return;
 }
 // จองลำดับคำขอปัจจุบันไว้ ถ้ามีคำขอใหม่กว่าถูกยิงออกไปก่อน response นี้จะถูกทิ้ง
 // ไม่ให้เข้ามาทับหน้าจอ ป้องกันปัญหาข้อมูลโชว์ผิดวันเวลาเปลี่ยนวันที่เร็ว ๆ
 const reqId = ++historyRequestSeq;
 api('sales_history',{from:from,to:to,receipt_no:receiptNo}).then(d=>{
  if(reqId !== historyRequestSeq) return; // มีคำขอใหม่กว่านี้แล้ว ทิ้ง response เก่านี้
  if(!d.success)return alert(d.message);
  let rows = Array.isArray(d.data) ? d.data : [];
  const reportRange=document.getElementById('reportRange');
  if(reportRange){
    reportRange.textContent = `ข้อมูลวันที่ ${from || to || '-'}${(from && to && from!==to) ? ' ถึง '+to : ''}` + (receiptNo ? ` | ใบเสร็จ ${receiptNo}` : '');
  }
  if(!rows.length){
    document.getElementById('historyTable').innerHTML='<tr><td colspan="8" class="text-center py-8 text-slate-400">ไม่พบข้อมูลการขายตามเงื่อนไขที่ค้นหา</td></tr>';
    document.getElementById('historySummary').innerHTML='';
    const chartTitleEl0=document.getElementById('chartTitle'); if(chartTitleEl0) chartTitleEl0.textContent='';
    if(historyChart){historyChart.destroy();historyChart=null;}
    return;
  }

  // ตารางแสดงรายการต่อบิล (ไม่โชว์รับเงินสด/เงินทอน แสดงช่องทางชำระ + VAT แทน)
document.getElementById('historyTable').innerHTML=rows.map(r=>{
    const qty=Number(r.quantity||0);
    const remain=Math.max(0,qty-Number(r.voided_qty||0));
    const voided=Number(r.voided_qty||0);
    const orderDate=String(r.created_at||'').slice(0,10);
    const todayDate=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Bangkok'});
    const canVoidToday=orderDate===todayDate;
    const action=remain>0
      ? (!canVoidToday
          ? `<span class="text-[10px] text-slate-400 no-print">หมดเวลา Void</span>`
          : (hasVoidPermission()
              ? (()=>{ const voidProductName = JSON.stringify(String(r.name || '')); return `<button type="button" class="bg-red-600 text-white px-2 py-1 rounded-lg text-[10px] font-bold no-print" onclick='openVoidItem(${Number(r.order_item_id)},${Number(remain)},${voidProductName},${Number(r.order_id||0)}); return false;'><i class="fa-solid fa-ban"></i> Void</button>`; })()
              : `<span class="text-[10px] text-slate-400 no-print">เฉพาะ Admin</span>`))
      : `<span class="text-[10px] font-bold text-red-600 no-print">Void แล้ว</span>`;
    const status=voided>0 ? `<div class="text-[10px] text-red-600 font-bold">Void แล้ว ${voided}/${Number(r.quantity||0)} ${r.void_reason?'- '+esc(r.void_reason):''}</div>` : '';
    return `<tr><td>${r.created_at}</td><td>${esc(r.name)}${status}</td><td>${remain}/${qty}</td><td>${esc(r.receipt_no||('#'+r.order_id))}</td><td>${esc(paymentLabel(r.payment_method))}</td><td>฿${money(Number(r.vat_amount||0))}</td><td>฿${money(Number(r.net_line_total||0))}</td><td class="no-print">${action}</td></tr>`;
  }).join('');

  // รวมข้อมูลระดับ "บิล" ให้เหลือ 1 แถวต่อ order_id (กันนับซ้ำจากการ JOIN กับ order_items)
  // เก็บทั้งวันที่และชั่วโมงไว้ เพื่อให้กราฟแสดงข้อมูลได้ตรงตามวันที่เลือกจริง ๆ
  let orderMap = new Map();
  rows.forEach(r => {
    if(!orderMap.has(r.order_id)){
      const dt = String(r.created_at||'').split(' ');
      orderMap.set(r.order_id, {
        date: dt[0] || '',
        hour: parseInt((dt[1]||'0:0').split(':')[0], 10) || 0,
        method: r.payment_method || 'cash',
        total: Number(r.net_order_total!=null ? r.net_order_total : r.total_amount||0),
        vat: Math.max(0, Number(r.vat_amount||0) - (Number(r.void_total||0) * 7 / 107))
      });
    }
  });
  let orders = Array.from(orderMap.values());

  // สรุปยอดขาย/VAT เฉพาะข้อมูลที่ backend กรองมาให้ตามช่วงวันที่เลือกเท่านั้น (ไม่ปนวันอื่น)
  let sumTotal = orders.reduce((a,x)=>a+x.total,0);
  let sumVat = orders.reduce((a,x)=>a+x.vat,0);
  let sumExVat = sumTotal - sumVat;
  document.getElementById('historySummary').innerHTML = `
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm"><div class="text-xs text-slate-500 font-medium">ยอดขายรวม (รวม VAT)</div><b class="text-lg text-indigo-700">฿${money(sumTotal)}</b></div>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm"><div class="text-xs text-slate-500 font-medium">ยอดขายก่อน VAT (หัก VAT ออกแล้ว)</div><b class="text-lg text-slate-800">฿${money(sumExVat)}</b></div>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm"><div class="text-xs text-slate-500 font-medium">VAT รวมทั้งหมด (7%)</div><b class="text-lg text-amber-600">฿${money(sumVat)}</b></div>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm"><div class="text-xs text-slate-500 font-medium">จำนวนบิล</div><b class="text-lg text-slate-800">${orders.length}</b></div>
  `;
 });
}

function setGraphRange(type){
  // กราฟยอดขายแสดงเฉพาะวันปัจจุบันเท่านั้น
  const f=document.getElementById('graphFromDate'), t=document.getElementById('graphToDate');
  if(f&&t){
    const today=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Bangkok'});
    f.value=today; t.value=today;
  }
  loadSalesGraph();
}
function printSalesGraph(){
  const block=document.getElementById('salesGraphBlock');
  if(!block)return;
  const w=window.open('','_blank','width=1100,height=800');
  if(!w)return alert('Browser บล็อกหน้าต่างพิมพ์ กรุณาอนุญาต Pop-up');
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>กราฟยอดขายวันนี้</title><style>body{font-family:Arial,Tahoma,sans-serif;padding:20px;color:#111}.wrap{width:100%}h2{text-align:center}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:15px}.card{border:1px solid #ddd;border-radius:8px;padding:10px}.num{font-size:20px;font-weight:bold}</style></head><body><h2>กราฟยอดขาย — วันปัจจุบัน</h2><div>${block.outerHTML.replace(/class="no-print"/g,'')}</div></body></html>`);
  w.document.close(); w.onload=()=>w.print();
}
function loadSalesGraph(){
  // บังคับวันปัจจุบันเสมอ ไม่ว่าผู้ใช้เคยเลือกค่าใดไว้ก่อนหน้า
  const today=new Date().toLocaleDateString('en-CA',{timeZone:'Asia/Bangkok'});
  const fEl=document.getElementById('graphFromDate'), tEl=document.getElementById('graphToDate');
  if(fEl) fEl.value=today;
  if(tEl) tEl.value=today;
  const label=document.getElementById('graphTodayLabel');
  if(label) label.textContent=today;

  api('sales_history',{from:today,to:today,receipt_no:''}).then(d=>{
    if(!d.success)return alert(d.message||'โหลดกราฟไม่สำเร็จ');
    const rows=Array.isArray(d.data)?d.data:[];
    const orderMap=new Map();
    rows.forEach(r=>{
      if(!orderMap.has(r.order_id)){
        const dt=String(r.created_at||'').split(' ');
        orderMap.set(r.order_id,{date:dt[0]||today,hour:parseInt((dt[1]||'0:0').split(':')[0],10)||0,method:r.payment_method||'cash',total:Number(r.net_order_total!=null?r.net_order_total:r.total_amount||0)});
      }
    });
    const orders=Array.from(orderMap.values());
    const emptyEl=document.getElementById('graphEmpty');
    if(emptyEl) emptyEl.classList.toggle('hidden',orders.length>0);
    if(historyChart){historyChart.destroy();historyChart=null;}

    const methods=['cash','qr'];
    const summary={cash:0,qr:0};
    orders.forEach(o=>{if(o.method==='cash')summary.cash+=o.total;else if(o.method==='qr')summary.qr+=o.total;});
    const sumEl=document.getElementById('graphPaymentSummary');
    if(sumEl){
      sumEl.innerHTML=`<div class="bg-white border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">เงินสด</div><div class="text-xl font-bold text-emerald-600">฿${money(summary.cash)}</div></div><div class="bg-white border border-slate-200 rounded-xl p-3"><div class="text-xs text-slate-500">QR โอน</div><div class="text-xl font-bold text-indigo-600">฿${money(summary.qr)}</div></div><div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 sm:col-span-2"><div class="text-xs text-indigo-600">ยอดขายรวมวันนี้</div><div class="text-2xl font-bold text-indigo-700">฿${money(summary.cash+summary.qr)}</div></div>`;
    }

    // แสดงครบทุกชั่วโมง 00:00 - 23:00 เพื่อให้เห็นช่วงเวลาขายชัดเจน แม้บางชั่วโมงไม่มียอดขาย
    const hours=Array.from({length:24},(_,i)=>i);
    const categories=hours.map(h=>String(h).padStart(2,'0')+':00');
    // แยกประเภทการชำระเป็นแท่งคนละแท่ง (ไม่ซ้อนกัน) เพื่อเปรียบเทียบ Cash / QR ได้ง่าย
    const datasets=methods.map(m=>({
      label:paymentLabel(m),
      data:hours.map(h=>orders.filter(o=>o.hour===h&&o.method===m).reduce((a,x)=>a+x.total,0)),
      backgroundColor:(context)=>{
        const chart=context.chart, area=chart.chartArea;
        if(!area) return PAY_METHOD_COLORS[m]||'#94a3b8';
        const g=chart.ctx.createLinearGradient(0,area.bottom,0,area.top);
        if(m==='cash'){g.addColorStop(0,'#22c55e');g.addColorStop(.55,'#86efac');g.addColorStop(1,'#ecfdf5');}
        else {g.addColorStop(0,'#38bdf8');g.addColorStop(.55,'#7dd3fc');g.addColorStop(1,'#e0f2fe');}
        return g;
      },
      borderColor:m==='cash'?'#86efac':'#7dd3fc',
      borderWidth:1,
      borderRadius:8,
      borderSkipped:false,
      maxBarThickness:24

    }));
    const title=document.getElementById('graphChartTitle'); if(title) title.textContent=`กราฟแท่งยอดขายรายชั่วโมง — วันที่ ${today}`;
    const range=document.getElementById('graphRange'); if(range) range.textContent=`ข้อมูลวันที่ ${today} | แกนล่าง = ชั่วโมง | แยกประเภทการชำระ: เงินสด / QR โอน`;
    const canvas=document.getElementById('salesGraphChart'); if(!canvas)return;
    const ctx=canvas.getContext('2d');
    historyChart=new Chart(ctx,{
      type:'bar',
      data:{labels:categories,datasets:datasets},
      options:{
        responsive:true,
        maintainAspectRatio:false,
        interaction:{mode:'index',intersect:false},
        animation:{duration:900,easing:'easeOutQuart'},
        plugins:{
          legend:{display:true,position:'top',align:'end',labels:{usePointStyle:true,pointStyle:'circle',boxWidth:8,padding:14,font:{family:'Sarabun',size:11,weight:'700'},color:'#dbeafe'}},
          tooltip:{backgroundColor:'rgba(3,12,28,.96)',titleColor:'#fff',bodyColor:'#e2e8f0',borderColor:'rgba(125,211,252,.45)',borderWidth:1,padding:12,cornerRadius:10,callbacks:{label:function(ctx){return ` ${ctx.dataset.label}: ฿${money(ctx.parsed.y||0)}`;}}}
        },
        scales:{
          x:{
            stacked:false,
            grid:{color:'rgba(148,163,184,.08)',drawBorder:false},
            title:{display:true,text:'เวลา (ชั่วโมง)',font:{family:'Sarabun',size:11,weight:'700'},color:'#bae6fd'},
            ticks:{font:{family:'Sarabun',size:9,weight:'600'},color:'#cbd5e1',autoSkip:false,maxRotation:0,minRotation:0,padding:7}
          },
          y:{
            stacked:false,beginAtZero:true,
            title:{display:true,text:'ยอดขาย (บาท)',font:{family:'Sarabun',size:11,weight:'700'},color:'#bae6fd'},
            grid:{color:'rgba(148,163,184,.10)',drawBorder:false},
            ticks:{font:{family:'Sarabun',size:9},color:'#94a3b8',callback:function(value){return '฿'+Number(value).toLocaleString('th-TH');}}
          }
        }
      }
    });
  }).catch(e=>alert(e.message||'ไม่สามารถเชื่อมต่อระบบได้'));
}
function exportCSV(){
 api('sales_history',{
   from:document.getElementById('fromDate').value,
   to:document.getElementById('toDate').value,
   receipt_no:''
 }).then(d=>{
  if(!d.success)return alert(d.message);
  let rows = d.data;
  if(!rows.length) return alert('ไม่มีข้อมูลสำหรับ Export');
  
  let csvContent = "\uFEFF" + "Order ID,Date,Product Name,Quantity,Total,Payment Method,VAT\n";
  rows.forEach(r => {
    csvContent += `"#${r.order_id}","${r.created_at}","${r.name.replace(/"/g, '""')}","${r.quantity}","${r.net_line_total}","${paymentLabel(r.payment_method)}","${r.vat_amount}"\n`;
  });
  
  let blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  let url = URL.createObjectURL(blob);
  let a = document.createElement('a');
  a.href = url;
  a.setAttribute('download', 'sales_report_' + new Date().toISOString().slice(0,10) + '.csv');
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
 });
}

function loadDayClosingHistory(){
 api('day_closing_history',{from:new Date().toLocaleDateString('en-CA'),to:new Date().toLocaleDateString('en-CA')}).then(d=>{
  const tbody=document.getElementById('dayClosingHistoryTable'); if(!tbody)return;
  if(!d.success){tbody.innerHTML='<tr><td colspan="8" class="text-center text-slate-500">'+esc(d.message||'โหลดประวัติไม่ได้')+'</td></tr>';return;}
  const rows=d.data||[]; document.getElementById('dayClosingHistoryInfo').textContent='วันนี้ '+rows.length+' รอบ';
  tbody.innerHTML=rows.length?rows.map(r=>`<tr><td><span class="inline-flex px-2 py-1 rounded-full bg-indigo-50 text-indigo-700 font-bold">ครั้งที่ ${r.round_no}</span></td><td>${esc(r.closing_date||'')}</td><td>${esc(r.start_at||'-')}</td><td>${esc(r.end_at||r.created_at||'-')}</td><td>${Number(r.bill_count||0)}</td><td>฿${money(r.total_sales)}</td><td>฿${money(r.cash_total)}</td><td>${esc(r.staff||'-')}</td></tr>`).join(''):'<tr><td colspan="8" class="text-center text-slate-500 py-4">วันนี้ยังไม่มีการปิดรอบ</td></tr>';
 }).catch(()=>{const tbody=document.getElementById('dayClosingHistoryTable');if(tbody)tbody.innerHTML='<tr><td colspan="8" class="text-center text-red-500">ไม่สามารถโหลดประวัติการปิดรอบได้</td></tr>';});
}

function loadCloseSummary(){
 // แสดงยอดเฉพาะ "รอบขายปัจจุบัน" ตั้งแต่ครั้งล่าสุดที่ปิดรอบ
 api('sales_session_summary').then(d=>{
  if(!d.success){ alert(d.message||'โหลดสรุปยอดไม่ได้'); return; }
  const s=Number(d.data.total_sales||0);
  const b=Number(d.data.bill_count||0);
  const c=Number(d.data.cash_total||0);
  const sessionStart=d.data.session_start||'';
  document.getElementById('closeSummary').innerHTML=`
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-xs text-slate-500 font-medium">ยอดขายรอบปัจจุบัน</div>
      <b id="lblTotalSales" class="text-xl text-indigo-700">฿${money(s)}</b>
    </div>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-xs text-slate-500 font-medium">จำนวนบิลรอบปัจจุบัน</div>
      <b id="lblBillCount" class="text-xl text-slate-800">${b}</b>
    </div>
    <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
      <div class="text-xs text-slate-500 font-medium">เงินรับรวมรอบปัจจุบัน</div>
      <b id="lblCashTotal" class="text-xl text-green-600">฿${money(c)}</b>
    </div>
    <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 shadow-sm">
      <div class="text-xs text-indigo-600 font-medium">สถานะ</div>
      <b class="text-sm text-indigo-700">${b===0?'พร้อมเริ่มขายใหม่':'กำลังขาย'}</b>
      <div class="text-[10px] text-slate-500 mt-1">เริ่มรอบ: ${sessionStart||'-'}</div>
    </div>`;
 }).catch(()=>alert('ไม่สามารถโหลดสรุปยอดรอบขายได้'));
}

function closeDay(){
 if(confirm('ยืนยันปิดการขายหรือไม่? ระบบจะบันทึกยอดรอบนี้ไว้ในประวัติ และหน้าปิดวันจะเริ่มที่ 0 สำหรับการขายรอบใหม่')) {
  api('close_day').then(d=>{
    if(!d.success && (d.code==='CASHIER_NOT_CLOSED' || d.code==='CASHIER_CLOSE_REQUIRED')){
      alert('⚠️ '+(d.message||'กรุณาปิดรอบ Cashier ก่อนปิดการขาย'));
      showPage('cashier');
      return;
    }
    alert(d.success ? ((d.message||'ปิดการขายเรียบร้อยแล้ว')+'\n\nรอบที่ปิด: '+(d.round_no||'-')) : (d.message||'ไม่สามารถปิดการขายได้'));
    if(d.success) {
      // หลังปิดการขาย ต้องแสดงรอบใหม่เป็น 0 ทันที
      const box = document.getElementById('closeSummary');
      if(box){
        box.innerHTML = `
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">ยอดขายรอบปัจจุบัน</div>
            <b id="lblTotalSales" class="text-xl text-indigo-700">฿0.00</b>
          </div>
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">จำนวนบิลรอบปัจจุบัน</div>
            <b id="lblBillCount" class="text-xl text-slate-800">0</b>
          </div>
          <div class="bg-slate-50 border border-slate-200 rounded-xl p-3 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">เงินรับรวมรอบปัจจุบัน</div>
            <b id="lblCashTotal" class="text-xl text-green-600">฿0.00</b>
          </div>
          <div class="bg-indigo-50 border border-indigo-200 rounded-xl p-3 shadow-sm">
            <div class="text-xs text-indigo-600 font-medium">สถานะ</div>
            <b class="text-sm text-indigo-700">พร้อมเริ่มขายใหม่</b>
          </div>`;
      }
      // เคลียร์ตะกร้าหน้าขายเพื่อเริ่มรอบใหม่อย่างชัดเจน
      cart=[];
      setTimeout(()=>{loadCloseSummary();loadDayClosingHistory()}, 150);
      renderCart();
    }
  });
 }
}

let companyInfo={shopName:'ร้าน POS',companyAddress:'',companyPhone:'',companyTaxId:'',promptpayId:''};

function applySettingsData(data){
  companyInfo={
    shopName:data.shopName||'ร้าน POS',
    companyAddress:data.companyAddress||'',
    companyPhone:data.companyPhone||'',
    companyTaxId:data.companyTaxId||'',
    promptpayId:data.promptpayId||''
  };
  shopName.value=data.shopName||'';
  companyAddress.value=data.companyAddress||'';
  companyPhone.value=data.companyPhone||'';
  companyTaxId.value=data.companyTaxId||'';
  promptpayId.value=data.promptpayId||'';
  companyInfo.promptpayId=data.promptpayId||'';
  if(typeof updatePromptPayQR==='function') updatePromptPayQR();
  setAutoPrintState(data.autoPrint!=='0');
  setKitchenPrintState(data.kitchenPrint==='1');
  renderReportHeader();
}

function loadSettings(){
  api('settings_get').then(d=>{ if(d.success) applySettingsData(d.data); });
}
function toggleShopSettings(force){
  const box = document.getElementById('shopSettingsForm');
  if(!box) return;
  const shouldOpen = (typeof force === 'boolean') ? force : box.classList.contains('hidden');
  box.classList.toggle('hidden', !shouldOpen);
  if(shouldOpen){
    const input = document.getElementById('shopName');
    if(input){
      setTimeout(()=>input.focus(), 80);
    }
  }
}

function saveSettings(){
  api('settings_save',{
    shopName:shopName.value,
    companyAddress:companyAddress.value,
    companyPhone:companyPhone.value,
    companyTaxId:companyTaxId.value,
    promptpayId:promptpayId.value,
    autoPrint:autoPrint?'1':'0',
    kitchenPrint:kitchenPrint?'1':'0'
  }).then(d=>{alert(d.message);if(d.success)loadSettings();});
}
function esc(s){return String(s??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'","&#039;")}
document.getElementById('cash').addEventListener('keydown',e=>{if(e.key==='Enter')checkout()});
setupUserUI();
if(Number(currentUser.id||0)>0){api('settings_get').then(d=>{if(d.success)applySettingsData(d.data);});}
renderCart();
pollCorderBadge();
setInterval(pollCorderBadge, 20000);
pollBillRequests();
setInterval(pollBillRequests, 10000);
setInterval(()=>{if(document.getElementById('page-corders')?.classList.contains('active'))loadCorders();}, 15000);

// เปิดสรุปยอด Cashier เป็นค่าเริ่มต้น
document.addEventListener('DOMContentLoaded',()=>{setTimeout(()=>switchReport('cashier'),150);});
</script>
</body>
</html>

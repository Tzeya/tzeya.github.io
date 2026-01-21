<?php
declare(strict_types=1);
session_start();

// Error handling for production
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    error_log("Error [$severity]: $message in $file on line $line");
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Configuration
define('ADMIN_USER', 'jose');
define('ADMIN_PASS', 'princejose');
define('UPLOAD_DIR', __DIR__ . '/assets/');
define('NITRO_DIR', 'C:/inetpub/wwwroot/public/nitro-assets-new/bundled/furniture/');
define('ICON_DIR', 'C:/inetpub/wwwroot/public/ms-swf/dcr/hof_furni/icons/');
define('TEMP_DIR', __DIR__ . '/temp/');
define('PREVIEW_DIR', __DIR__ . '/preview/');
define('MAX_FILE_SIZE', 100 * 1024 * 1024); // 100MB
define('ALLOWED_EXTENSIONS', ['swf', 'nitro', 'png', 'jpg', 'jpeg', 'gif']);

// Create required directories
foreach ([UPLOAD_DIR, NITRO_DIR, ICON_DIR, TEMP_DIR, PREVIEW_DIR] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Create required directories
foreach ([UPLOAD_DIR, ICON_DIR, TEMP_DIR, PREVIEW_DIR] as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Database connection
try {
    $db = new PDO('sqlite:' . __DIR__ . '/arcturus_furniture.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Database connection failed. Check permissions and try again.');
}

// Database schema
$db->exec("
    CREATE TABLE IF NOT EXISTS furniture (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sprite_id INTEGER NOT NULL UNIQUE,
        item_name VARCHAR(100) NOT NULL,
        public_name VARCHAR(100) NOT NULL,
        description TEXT,
        type VARCHAR(10) DEFAULT 's',
        width INTEGER DEFAULT 1,
        length INTEGER DEFAULT 1,
        stack_height DECIMAL(4,2) DEFAULT 1.00,
        allow_stack BOOLEAN DEFAULT 1,
        allow_sit BOOLEAN DEFAULT 0,
        allow_lay BOOLEAN DEFAULT 0,
        allow_walk BOOLEAN DEFAULT 0,
        allow_gift BOOLEAN DEFAULT 1,
        allow_trade BOOLEAN DEFAULT 1,
        allow_recycle BOOLEAN DEFAULT 1,
        allow_marketplace_sell BOOLEAN DEFAULT 1,
        interaction_type VARCHAR(50) DEFAULT 'default',
        interaction_modes_count INTEGER DEFAULT 1,
        effect_id INTEGER DEFAULT 0,
        is_rare BOOLEAN DEFAULT 0,
        swf_file VARCHAR(255),
        nitro_file VARCHAR(255),
        icon_file VARCHAR(255),
        sql_generated TEXT,
        furnidata_xml TEXT,
        furnidata_json TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        reviewed_by VARCHAR(50),
        reviewed_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by VARCHAR(50)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        username VARCHAR(50),
        action VARCHAR(50),
        details TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS settings (
        key VARCHAR(50) PRIMARY KEY,
        value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// Initialize default settings
$defaultSettings = [
    'server_name' => 'My Habbo Hotel',
    'catalog_page_id' => '1',
    'default_credits' => '0',
    'default_points' => '0',
    'default_points_type' => '0',
];

foreach ($defaultSettings as $key => $value) {
    $stmt = $db->prepare(
        "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)"
    );
    $stmt->execute([$key, $value]);
}

// Furniture Manager Class
class FurnitureManager
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function logActivity(
        string $action,
        string $details = '',
        ?string $username = null
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO activity_log (username, action, details, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $username ?? $_SESSION['admin_user'] ?? 'system',
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
    }

    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf(
            "%.2f %s",
            $bytes / pow(1024, $factor),
            $units[$factor]
        );
    }

    public function generateSQL(array $furni): string
    {
        $settings = $this->getSettings();
        $sql = "-- =====================================================\n";
        $sql .= "-- Arcturus Morningstar Furniture SQL\n";
        $sql .= "-- Item: {$furni['public_name']}\n";
        $sql .= "-- Sprite ID: {$furni['sprite_id']}\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- =====================================================\n\n";

        // Items Base Insert
        $sql .= "-- Step 1: Insert into items_base\n";
        $sql .= "INSERT INTO `items_base` (\n";
        $sql .= "    `sprite_id`, `item_name`, `public_name`, `type`,\n";
        $sql .= "    `width`, `length`, `stack_height`,\n";
        $sql .= "    `allow_stack`, `allow_sit`, `allow_lay`, `allow_walk`,\n";
        $sql .= "    `allow_gift`, `allow_trade`, `allow_recycle`,\n";
        $sql .= "    `allow_marketplace_sell`, `interaction_type`,\n";
        $sql .= "    `interaction_modes_count`, `effect_id`\n";
        $sql .= ") VALUES (\n";
        $sql .= "    {$furni['sprite_id']},\n";
        $sql .= "    '{$this->escapeSql($furni['item_name'])}',\n";
        $sql .= "    '{$this->escapeSql($furni['public_name'])}',\n";
        $sql .= "    '{$furni['type']}',\n";
        $sql .= "    {$furni['width']}, {$furni['length']}, {$furni['stack_height']},\n";
        $sql .= "    {$furni['allow_stack']}, {$furni['allow_sit']}, {$furni['allow_lay']}, {$furni['allow_walk']},\n";
        $sql .= "    {$furni['allow_gift']}, {$furni['allow_trade']}, {$furni['allow_recycle']},\n";
        $sql .= "    {$furni['allow_marketplace_sell']},\n";
        $sql .= "    '{$this->escapeSql($furni['interaction_type'])}',\n";
        $sql .= "    {$furni['interaction_modes_count']}, {$furni['effect_id']}\n";
        $sql .= ");\n\n";

        // Get last inserted ID
        $sql .= "-- Step 2: Get the item ID\n";
        $sql .= "SET @item_id = LAST_INSERT_ID();\n\n";

        // Catalog Items Insert
        $sql .= "-- Step 3: Insert into catalog_items\n";
        $sql .= "INSERT INTO `catalog_items` (\n";
        $sql .= "    `item_ids`, `page_id`, `catalog_name`,\n";
        $sql .= "    `cost_credits`, `cost_points`, `points_type`, `amount`\n";
        $sql .= ") VALUES (\n";
        $sql .= "    @item_id,\n";
        $sql .= "    {$settings['catalog_page_id']},\n";
        $sql .= "    '{$this->escapeSql($furni['public_name'])}',\n";
        $sql .= "    {$settings['default_credits']},\n";
        $sql .= "    {$settings['default_points']},\n";
        $sql .= "    {$settings['default_points_type']},\n";
        $sql .= "    1\n";
        $sql .= ");\n\n";

        // Instructions
        $sql .= "-- =====================================================\n";
        $sql .= "-- POST-INSTALLATION STEPS:\n";
        $sql .= "-- =====================================================\n";
        $sql .= "-- 1. Upload SWF file to: {swf_folder}/{$furni['item_name']}.swf\n";
        if ($furni['nitro_file']) {
            $sql .= "-- 2. Upload Nitro assets to: {nitro_folder}/\n";
        }
        $sql .= "-- 3. Update furnidata.xml (see furnidata_xml output below)\n";
        $sql .= "-- 4. Update furnidata.json (see furnidata_json output below)\n";
        $sql .= "-- 5. Clear emulator cache\n";
        $sql .= "-- 6. Reload emulator or restart\n";
        $sql .= "-- =====================================================\n";

        return $sql;
    }

    public function generateFurnidataXML(array $furni): string
    {
        $xml = "<!-- Furnidata XML Entry -->\n";
        $xml .= "<furnitype id=\"{$furni['sprite_id']}\" classname=\"{$furni['item_name']}\">\n";
        $xml .= "    <revision>" . time() . "</revision>\n";
        $xml .= "    <defaultdir>0</defaultdir>\n";
        $xml .= "    <xdim>{$furni['width']}</xdim>\n";
        $xml .= "    <ydim>{$furni['length']}</ydim>\n";
        $xml .= "    <partcolors />\n";
        $xml .= "    <name>{$this->escapeXml($furni['public_name'])}</name>\n";
        $xml .= "    <description>{$this->escapeXml($furni['description'] ?? '')}</description>\n";
        $xml .= "    <adurl />\n";
        $xml .= "    <offerid>-1</offerid>\n";
        $xml .= "    <buyout>1</buyout>\n";
        $xml .= "    <rentofferid>-1</rentofferid>\n";
        $xml .= "    <rentbuyout>0</rentbuyout>\n";
        $xml .= "    <bc>0</bc>\n";
        $xml .= "    <excludeddynamic>0</excludeddynamic>\n";
        $xml .= "    <customparams />\n";
        $xml .= "    <specialtype>{$furni['interaction_modes_count']}</specialtype>\n";
        $xml .= "    <canstandon>" . ($furni['allow_walk'] ? '1' : '0') . "</canstandon>\n";
        $xml .= "    <cansiton>" . ($furni['allow_sit'] ? '1' : '0') . "</cansiton>\n";
        $xml .= "    <canlayon>" . ($furni['allow_lay'] ? '1' : '0') . "</canlayon>\n";
        $xml .= "    <furniline>{$furni['item_name']}</furniline>\n";
        $xml .= "    <environment />\n";
        $xml .= "    <rare>" . ($furni['is_rare'] ? '1' : '0') . "</rare>\n";
        $xml .= "</furnitype>\n";

        return $xml;
    }

    public function generateFurnidataJSON(array $furni): string
    {
        $data = [
            'id' => $furni['sprite_id'],
            'classname' => $furni['item_name'],
            'revision' => time(),
            'defaultdir' => 0,
            'xdim' => $furni['width'],
            'ydim' => $furni['length'],
            'name' => $furni['public_name'],
            'description' => $furni['description'] ?? '',
            'offerid' => -1,
            'buyout' => true,
            'bc' => false,
            'excludeddynamic' => false,
            'specialtype' => $furni['interaction_modes_count'],
            'canstandon' => (bool) $furni['allow_walk'],
            'cansiton' => (bool) $furni['allow_sit'],
            'canlayon' => (bool) $furni['allow_lay'],
            'furniline' => $furni['item_name'],
            'rare' => (bool) $furni['is_rare'],
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function handleFileUpload(
        array $file,
        string $type,
        string $dir
    ): string {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ALLOWED_EXTENSIONS)) {
            throw new Exception("Invalid file type: .$ext");
        }

        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception(
                "File too large: " . $this->formatFileSize($file['size'])
            );
        }

        // Validate mime type for security
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
// finfo_close($finfo); // Deprecated in PHP 8.5+

        $allowedMimes = [
            'application/x-shockwave-flash',
            'application/octet-stream',
            'application/zip',
            'application/x-coff-executable', 
            'image/png',
            'image/jpeg',
            'image/gif',
        ];

        if (!in_array($mimeType, $allowedMimes)) {
            throw new Exception("Invalid file mime type: $mimeType");
        }

        $newFilename = uniqid($type . '_') . '_' . time() . '.' . $ext;
        $destination = $dir . $newFilename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception("Failed to upload file");
        }

        return $newFilename;
    }

    public function extractIconFromSWF(string $swfPath): ?string
    {
        // ImageMagick method
        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->readImage($swfPath . '[0]');
                $imagick->setImageFormat('png');
                $imagick->resizeImage(64, 64, Imagick::FILTER_LANCZOS, 1);

                $iconPath =
                    ICON_DIR . basename($swfPath, '.swf') . '_icon.png';
                $imagick->writeImage($iconPath);
                $imagick->clear();
                $imagick->destroy();

                return basename($iconPath);
            } catch (Exception $e) {
                error_log("Icon extraction failed: " . $e->getMessage());
            }
        }

        return null;
    }

    public function extractIconFromNitro(string $nitroPath): ?string
    {
        try {
            $zip = new ZipArchive();
            if ($zip->open($nitroPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);
                    if (
                        preg_match('/icon\.png$/i', $filename) ||
                        preg_match('/_icon\.png$/i', $filename) ||
                        preg_match('/_64\.png$/i', $filename)
                    ) {
                        $iconPath =
                            ICON_DIR .
                            basename($nitroPath, '.nitro') .
                            '_icon.png';
                        $content = $zip->getFromIndex($i);
                        if ($content !== false) {
                            file_put_contents($iconPath, $content);
                            $zip->close();
                            return basename($iconPath);
                        }
                    }
                }
                $zip->close();
            }
        } catch (Exception $e) {
            error_log("Nitro icon extraction failed: " . $e->getMessage());
        }
        return null;
    }

    private function escapeSql(string $str): string
    {
        return str_replace("'", "''", $str);
    }

    private function escapeXml(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1, 'UTF-8');
    }

    public function getSettings(): array
    {
        $stmt = $this->db->query("SELECT key, value FROM settings");
        return array_column($stmt->fetchAll(), 'value', 'key');
    }
}

$manager = new FurnitureManager($db);

// Authentication
if (isset($_POST['login'])) {
    if (
        $_POST['username'] === ADMIN_USER &&
        $_POST['password'] === ADMIN_PASS
    ) {
        $_SESSION['authenticated'] = true;
        $_SESSION['admin_user'] = ADMIN_USER;
        $manager->logActivity('LOGIN', 'Admin logged in');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error = 'Invalid credentials';
        $manager->logActivity('FAILED_LOGIN', 'Failed login attempt');
    }
}

if (isset($_GET['logout'])) {
    $manager->logActivity('LOGOUT', 'Admin logged out');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Login page
if (!isset($_SESSION['authenticated'])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arcturus Morningstar - Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
        }
        .logo {
            text-align: center;
            font-size: 64px;
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        h1 {
            color: #667eea;
            text-align: center;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #718096;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 600;
            font-size: 14px;
        }
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s;
        }
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .error {
            background: #fed7d7;
            color: #9b2c2c;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">🌟</div>
        <h1>Arcturus Morningstar</h1>
        <p class="subtitle">Furniture Management System</p>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" name="login">Login to Admin Panel</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit();
}

// Handle Actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_furniture':
                // Validate sprite ID uniqueness
                $spriteId = (int) $_POST['sprite_id'];
                $check = $db->prepare(
                    "SELECT id FROM furniture WHERE sprite_id = ?"
                );
                $check->execute([$spriteId]);
                if ($check->fetch()) {
                    throw new Exception(
                        "Sprite ID $spriteId already exists. Choose a unique ID."
                    );
                }

                // Handle file uploads
                $swfFile = null;
                $nitroFile = null;
                $iconFile = null;

                if (
                    isset($_FILES['swf_file']) &&
                    $_FILES['swf_file']['error'] === UPLOAD_ERR_OK
                ) {
                    $swfFile = $manager->handleFileUpload(
                        $_FILES['swf_file'],
                        'swf',
                        UPLOAD_DIR
                    );

                    // Try auto-extract icon
                    if (!$iconFile) {
                        $extracted = $manager->extractIconFromSWF(
                            UPLOAD_DIR . $swfFile
                        );
                        if ($extracted) {
                            $iconFile = $extracted;
                        }
                    }
                }

            if (
    isset($_FILES['nitro_file']) &&
    $_FILES['nitro_file']['error'] === UPLOAD_ERR_OK
) {
    $nitroFile = $manager->handleFileUpload(
        $_FILES['nitro_file'],
        'nitro',
        NITRO_DIR  // Changed from UPLOAD_DIR
    );

    // Try auto-extract icon
    if (!$iconFile) {
        $extracted = $manager->extractIconFromNitro(
            NITRO_DIR . $nitroFile  // Changed from UPLOAD_DIR
        );
        if ($extracted) {
            $iconFile = $extracted;
        }
    }
}

                if (
                    isset($_FILES['icon_file']) &&
                    $_FILES['icon_file']['error'] === UPLOAD_ERR_OK
                ) {
                    $iconFile = $manager->handleFileUpload(
                        $_FILES['icon_file'],
                        'icon',
                        ICON_DIR
                    );
                }

                // Build furniture data
                $furniData = [
                    'sprite_id' => $spriteId,
                    'item_name' => trim($_POST['item_name']),
                    'public_name' => trim($_POST['public_name']),
                    'description' => trim($_POST['description'] ?? ''),
                    'type' => $_POST['type'] ?? 's',
                    'width' => (int) ($_POST['width'] ?? 1),
                    'length' => (int) ($_POST['length'] ?? 1),
                    'stack_height' =>
                        (float) ($_POST['stack_height'] ?? 1.0),
                    'allow_stack' => isset($_POST['allow_stack']) ? 1 : 0,
                    'allow_sit' => isset($_POST['allow_sit']) ? 1 : 0,
                    'allow_lay' => isset($_POST['allow_lay']) ? 1 : 0,
                    'allow_walk' => isset($_POST['allow_walk']) ? 1 : 0,
                    'allow_gift' => isset($_POST['allow_gift']) ? 1 : 0,
                    'allow_trade' => isset($_POST['allow_trade']) ? 1 : 0,
                    'allow_recycle' => isset($_POST['allow_recycle'])
                        ? 1
                        : 0,
                    'allow_marketplace_sell' => isset(
                        $_POST['allow_marketplace_sell']
                    )
                        ? 1
                        : 0,
                    'interaction_type' =>
                        $_POST['interaction_type'] ?? 'default',
                    'interaction_modes_count' =>
                        (int) ($_POST['interaction_modes_count'] ?? 1),
                    'effect_id' => (int) ($_POST['effect_id'] ?? 0),
                    'is_rare' => isset($_POST['is_rare']) ? 1 : 0,
                    'swf_file' => $swfFile,
                    'nitro_file' => $nitroFile,
                    'icon_file' => $iconFile,
                    'status' => 'pending',
                    'created_by' => $_SESSION['admin_user'],
                ];

                // Generate SQL and Furnidata
                $furniData['sql_generated'] =
                    $manager->generateSQL($furniData);
                $furniData['furnidata_xml'] =
                    $manager->generateFurnidataXML($furniData);
                $furniData['furnidata_json'] =
                    $manager->generateFurnidataJSON($furniData);

                // Insert into database
                $stmt = $db->prepare(
                    "INSERT INTO furniture (
                        sprite_id, item_name, public_name, description, type,
                        width, length, stack_height, allow_stack, allow_sit, allow_lay, allow_walk,
                        allow_gift, allow_trade, allow_recycle, allow_marketplace_sell,
                        interaction_type, interaction_modes_count, effect_id, is_rare,
                        swf_file, nitro_file, icon_file, sql_generated, furnidata_xml, furnidata_json,
                        status, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )"
                );

                $stmt->execute([
                    $furniData['sprite_id'],
                    $furniData['item_name'],
                    $furniData['public_name'],
                    $furniData['description'],
                    $furniData['type'],
                    $furniData['width'],
                    $furniData['length'],
                    $furniData['stack_height'],
                    $furniData['allow_stack'],
                    $furniData['allow_sit'],
                    $furniData['allow_lay'],
                    $furniData['allow_walk'],
                    $furniData['allow_gift'],
                    $furniData['allow_trade'],
                    $furniData['allow_recycle'],
                    $furniData['allow_marketplace_sell'],
                    $furniData['interaction_type'],
                    $furniData['interaction_modes_count'],
                    $furniData['effect_id'],
                    $furniData['is_rare'],
                    $furniData['swf_file'],
                    $furniData['nitro_file'],
                    $furniData['icon_file'],
                    $furniData['sql_generated'],
                    $furniData['furnidata_xml'],
                    $furniData['furnidata_json'],
                    $furniData['status'],
                    $furniData['created_by'],
                ]);

                $manager->logActivity(
                    'CREATE_FURNITURE',
                    "Created furniture: {$furniData['item_name']} (ID: $spriteId) - Pending Review"
                );

                $message =
                    'Furniture created successfully! Status: Pending Admin Review';
                $messageType = 'success';
                break;

           case 'approve_furniture':
    $id = (int) $_POST['id'];
    
    // First get the furniture data
    $stmt = $db->prepare("SELECT * FROM furniture WHERE id = ?");
    $stmt->execute([$id]);
    $furni = $stmt->fetch();
    
    // Then update the status
    $stmt = $db->prepare(
        "UPDATE furniture SET status = 'approved', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?"
    );
    $stmt->execute([$_SESSION['admin_user'], $id]);

    $manager->logActivity(
        'APPROVE_FURNITURE',
        "Approved furniture: {$furni['item_name']}"
    );

    $message = 'Furniture approved! SQL and files are ready for deployment.';
    $messageType = 'success';
    
    // Redirect to prevent resubmission
    header('Location: ' . $_SERVER['PHP_SELF'] . '?approved=1');
    exit();
    break;

            case 'reject_furniture':
                $id = (int) $_POST['id'];
                $reason = $_POST['reason'] ?? 'No reason provided';

                $stmt = $db->prepare(
                    "UPDATE furniture SET status = 'rejected', reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $stmt->execute([$_SESSION['admin_user'], $id]);

                $furni = $db
                    ->prepare("SELECT * FROM furniture WHERE id = ?")
                    ->execute([$id]);
                $furni = $furni->fetch();

                $manager->logActivity(
                    'REJECT_FURNITURE',
                    "Rejected furniture: {$furni['item_name']} - Reason: $reason"
                );

                $message = 'Furniture rejected.';
                $messageType = 'warning';
                break;

            case 'delete_furniture':
                $id = (int) $_POST['id'];
                $stmt = $db->prepare("SELECT * FROM furniture WHERE id = ?");
                $stmt->execute([$id]);
                $furni = $stmt->fetch();

                if ($furni) {
                    // Delete files
                    if ($furni['swf_file'] && file_exists(UPLOAD_DIR . $furni['swf_file'])) {
                        unlink(UPLOAD_DIR . $furni['swf_file']);
                    }
                    if ($furni['nitro_file'] && file_exists(UPLOAD_DIR . $furni['nitro_file'])) {
                        unlink(UPLOAD_DIR . $furni['nitro_file']);
                    }
                    if ($furni['icon_file'] && file_exists(ICON_DIR . $furni['icon_file'])) {
                        unlink(ICON_DIR . $furni['icon_file']);
                    }

                    $db->prepare("DELETE FROM furniture WHERE id = ?")->execute(
                        [$id]
                    );
                    $manager->logActivity(
                        'DELETE_FURNITURE',
                        "Deleted: {$furni['item_name']}"
                    );

                    $message = 'Furniture deleted successfully.';
                    $messageType = 'success';
                }
                break;

            case 'update_settings':
                foreach ($_POST as $key => $value) {
                    if ($key !== 'action') {
                        $stmt = $db->prepare(
                            "INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)"
                        );
                        $stmt->execute([$key, $value]);
                    }
                }
                $manager->logActivity(
                    'UPDATE_SETTINGS',
                    'Updated system settings'
                );
                $message = 'Settings updated successfully.';
                $messageType = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
        $manager->logActivity('ERROR', $e->getMessage());
    }
}
// Check for approval success
if (isset($_GET['approved'])) {
    $message = 'Furniture approved! SQL and files are ready for deployment.';
    $messageType = 'success';
}
// Fetch data
$furniture = $db
    ->query("SELECT * FROM furniture ORDER BY created_at DESC")
    ->fetchAll();
$stats = $db
    ->query(
        "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN swf_file IS NOT NULL THEN 1 ELSE 0 END) as with_swf,
        SUM(CASE WHEN nitro_file IS NOT NULL THEN 1 ELSE 0 END) as with_nitro,
        SUM(CASE WHEN icon_file IS NOT NULL THEN 1 ELSE 0 END) as with_icon
        FROM furniture"
    )
    ->fetch();
$recentLogs = $db
    ->query("SELECT * FROM activity_log ORDER BY timestamp DESC LIMIT 30")
    ->fetchAll();
$settings = array_column(
    $db->query("SELECT key, value FROM settings")->fetchAll(),
    'value',
    'key'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Arcturus Morningstar - Furniture Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #667eea;
            --primary-dark: #5a67d8;
            --secondary: #764ba2;
            --success: #48bb78;
            --warning: #ed8936;
            --danger: #f56565;
            --info: #4299e1;
            --bg-main: #f7fafc;
            --bg-card: #ffffff;
            --text-primary: #2d3748;
            --text-secondary: #718096;
            --border: #e2e8f0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--bg-main);
            color: var(--text-primary);
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            margin-bottom: 30px;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header h1 {
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card.pending { border-left-color: var(--warning); }
        .stat-card.pending .stat-value { color: var(--warning); }
        
        .stat-card.approved { border-left-color: var(--success); }
        .stat-card.approved .stat-value { color: var(--success); }

        /* Card */
        .card {
            background: var(--bg-card);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 30px;
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .card-title {
            font-size: 22px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4); }

        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { background: #38a169; }

        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #dd6b20; }

        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: #e53e3e; }

        .btn-info { background: var(--info); color: white; }
        .btn-info:hover { background: #3182ce; }

        .btn-secondary { background: var(--text-secondary); color: white; }
        .btn-secondary:hover { background: #4a5568; }

        /* Form */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="file"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.3s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            background: var(--bg-main);
            padding: 20px;
            border-radius: 8px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        /* File Upload */
        .file-upload-box {
            border: 3px dashed var(--border);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            transition: all 0.3s;
            background: var(--bg-main);
        }

        .file-upload-box:hover {
            border-color: var(--primary);
            background: #eef2ff;
        }

        .file-upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        /* Furniture Grid */
        .furniture-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .furniture-card {
            background: var(--bg-card);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            position: relative;
        }

        .furniture-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.2);
            transform: translateY(-3px);
        }

        .furniture-header {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .furniture-icon {
            width: 64px;
            height: 64px;
            background: var(--bg-main);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            border: 2px solid var(--border);
        }

        .furniture-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 6px;
        }

        .furniture-info {
            flex: 1;
        }

        .furniture-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .furniture-id {
            color: var(--text-secondary);
            font-size: 13px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-pending {
            background: #fef5e7;
            color: #b7791f;
        }

        .status-approved {
            background: #e6f9f0;
            color: #22543d;
        }

        .status-rejected {
            background: #fed7d7;
            color: #742a2a;
        }

        .furniture-details {
            margin: 15px 0;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            border-bottom: 1px solid var(--bg-main);
        }

        .detail-label {
            color: var(--text-secondary);
        }

        .detail-value {
            font-weight: 600;
        }

        .furniture-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 15px;
        }

        /* Message Alert */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }

        .alert-success {
            background: #e6f9f0;
            color: #22543d;
            border-left: 4px solid var(--success);
        }

        .alert-error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid var(--danger);
        }

        .alert-warning {
            background: #fef5e7;
            color: #b7791f;
            border-left: 4px solid var(--warning);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            padding: 20px;
            overflow-y: auto;
        }

        .modal.active { display: flex; align-items: flex-start; justify-content: center; padding-top: 50px; }

        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 900px;
            width: 100%;
            padding: 30px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .modal-header h2 {
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 32px;
            cursor: pointer;
            color: var(--text-secondary);
            line-height: 1;
            padding: 0;
            width: 32px;
            height: 32px;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        /* Code Preview */
        .code-preview {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            margin: 15px 0;
        }

        .code-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .code-header .btn {
            padding: 8px 16px;
            font-size: 12px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
            flex-wrap: wrap;
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-secondary);
            transition: all 0.3s;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 10px 20px;
            background: var(--bg-main);
            border: 2px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .filter-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Logs */
        .log-entry {
            padding: 15px;
            background: var(--bg-main);
            border-left: 3px solid var(--primary);
            margin-bottom: 10px;
            border-radius: 6px;
            font-size: 14px;
        }

        .log-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .log-details {
            color: var(--text-secondary);
            font-size: 13px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header h1 { font-size: 24px; }
            .header-content { flex-direction: column; }
            .stats-grid { grid-template-columns: 1fr; }
            .furniture-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .furniture-actions { grid-template-columns: 1fr; }
        }

        /* Loading Spinner */
        .spinner {
            border: 3px solid var(--border);
            border-top: 3px solid var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Helper Classes */
        .text-center { text-align: center; }
        .mt-20 { margin-top: 20px; }
        .mb-20 { margin-bottom: 20px; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div>
                    <h1>🌟 Arcturus Morningstar</h1>
                    <p style="opacity: 0.9; margin-top: 5px;">
                        Furniture Management System with Admin Approval
                    </p>
                </div>
                <div class="header-actions">
                    <button class="btn btn-secondary" onclick="openModal('settingsModal')">
                        ⚙️ Settings
                    </button>
                    <button class="btn btn-secondary" onclick="openModal('logsModal')">
                        📋 Activity Log
                    </button>
                    <a href="?logout" class="btn btn-danger">🚪 Logout</a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Furniture</div>
            </div>
            <div class="stat-card pending">
                <div class="stat-value"><?= $stats['pending'] ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card approved">
                <div class="stat-value"><?= $stats['approved'] ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['with_icon'] ?></div>
                <div class="stat-label">With Icons</div>
            </div>
        </div>

        <!-- Message Alert -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <span><?= $messageType === 'success' ? '✅' : ($messageType === 'warning' ? '⚠️' : '❌') ?></span>
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Create Furniture Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">➕ Create New Furniture</h2>
                <button class="btn btn-info" onclick="openModal('helpModal')">
                    ❓ Help Guide
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_furniture">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Sprite ID *</label>
                        <input type="number" name="sprite_id" required 
                               placeholder="e.g., 10001">
                    </div>
                    
                    <div class="form-group">
                        <label>Item Name (classname) *</label>
                        <input type="text" name="item_name" required 
                               placeholder="e.g., my_custom_chair">
                    </div>
                    
                    <div class="form-group">
                        <label>Public Name *</label>
                        <input type="text" name="public_name" required 
                               placeholder="e.g., Custom Chair">
                    </div>
                    
                    <div class="form-group">
                        <label>Type</label>
                        <select name="type">
                            <option value="s">Floor Item (s)</option>
                            <option value="i">Wall Item (i)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" 
                              placeholder="Add a description for this furniture..."></textarea>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label>Width</label>
                        <input type="number" name="width" value="1" min="1" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label>Length</label>
                        <input type="number" name="length" value="1" min="1" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label>Stack Height</label>
                        <input type="number" name="stack_height" value="1.00" 
                               step="0.01" min="0" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label>Interaction Type</label>
                        <select name="interaction_type">
                            <option value="default">Default</option>
                            <option value="gate">Gate</option>
                            <option value="teleport">Teleport</option>
                            <option value="bed">Bed</option>
                            <option value="chair">Chair</option>
                            <option value="dice">Dice</option>
                            <option value="bottle">Bottle</option>
                            <option value="wired">Wired</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Interaction Modes</label>
                        <input type="number" name="interaction_modes_count" 
                               value="1" min="1" max="10">
                    </div>
                    
                    <div class="form-group">
                        <label>Effect ID</label>
                        <input type="number" name="effect_id" value="0" min="0">
                    </div>
                </div>

                <div class="form-group">
                    <label style="margin-bottom: 15px;">Permissions</label>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_stack" 
                                   id="allow_stack" checked>
                            <label for="allow_stack">Allow Stack</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_sit" id="allow_sit">
                            <label for="allow_sit">Allow Sit</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_lay" id="allow_lay">
                            <label for="allow_lay">Allow Lay</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_walk" id="allow_walk">
                            <label for="allow_walk">Allow Walk</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_gift" 
                                   id="allow_gift" checked>
                            <label for="allow_gift">Allow Gift</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_trade" 
                                   id="allow_trade" checked>
                            <label for="allow_trade">Allow Trade</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_recycle" 
                                   id="allow_recycle" checked>
                            <label for="allow_recycle">Allow Recycle</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="allow_marketplace_sell" 
                                   id="allow_marketplace_sell" checked>
                            <label for="allow_marketplace_sell">Marketplace Sell</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="is_rare" id="is_rare">
                            <label for="is_rare">Is Rare</label>
                        </div>
                    </div>
                </div>

                <h3 style="margin: 30px 0 20px;">📁 File Uploads</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>SWF File</label>
                        <div class="file-upload-box">
                            <div class="file-upload-icon">⚡</div>
                            <input type="file" name="swf_file" accept=".swf">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Nitro Bundle</label>
                        <div class="file-upload-box">
                            <div class="file-upload-icon">🔷</div>
                            <input type="file" name="nitro_file" accept=".nitro">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Icon (or auto-extract)</label>
                        <div class="file-upload-box">
                            <div class="file-upload-icon">🖼️</div>
                            <input type="file" name="icon_file" 
                                   accept=".png,.jpg,.jpeg,.gif">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" 
                        style="width: 100%; margin-top: 20px; padding: 16px;">
                    🚀 Create Furniture (Pending Review)
                </button>
            </form>
        </div>

        <!-- Furniture Library -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📚 Furniture Library</h2>
                <div class="filter-bar">
                    <button class="filter-btn active" onclick="filterFurniture('all')">
                        All
                    </button>
                    <button class="filter-btn" onclick="filterFurniture('pending')">
                        Pending
                    </button>
                    <button class="filter-btn" onclick="filterFurniture('approved')">
                        Approved
                    </button>
                    <button class="filter-btn" onclick="filterFurniture('rejected')">
                        Rejected
                    </button>
                </div>
            </div>

            <?php if (empty($furniture)): ?>
                <p class="text-center" style="padding: 40px; color: var(--text-secondary);">
                    No furniture items yet. Create your first one above!
                </p>
            <?php else: ?>
                <div class="furniture-grid">
                    <?php foreach ($furniture as $item): ?>
                        <div class="furniture-card" data-status="<?= $item['status'] ?>">
                            <div class="furniture-header">
                                <div class="furniture-icon">
                                    <?php if ($item['icon_file']): ?>
                                        <img src="icons/<?= htmlspecialchars($item['icon_file']) ?>" 
                                             alt="Icon">
                                    <?php else: ?>
                                        🪑
                                    <?php endif; ?>
                                </div>
                                <div class="furniture-info">
                                    <div class="furniture-name">
                                        <?= htmlspecialchars($item['public_name']) ?>
                                    </div>
                                    <div class="furniture-id">
                                        ID: <?= $item['sprite_id'] ?> • 
                                        <?= htmlspecialchars($item['item_name']) ?>
                                    </div>
                                    <span class="status-badge status-<?= $item['status'] ?>">
                                        <?= $item['status'] ?>
                                    </span>
                                </div>
                            </div>

                            <div class="furniture-details">
                                <div class="detail-item">
                                    <span class="detail-label">Type:</span>
                                    <span class="detail-value">
                                        <?= $item['type'] === 's' ? 'Floor Item' : 'Wall Item' ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Size:</span>
                                    <span class="detail-value">
                                        <?= $item['width'] ?>x<?= $item['length'] ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Files:</span>
                                    <span class="detail-value">
                                        <?= $item['swf_file'] ? '⚡ SWF ' : '' ?>
                                        <?= $item['nitro_file'] ? '🔷 Nitro ' : '' ?>
                                        <?= $item['icon_file'] ? '🖼️ Icon' : '' ?>
                                    </span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Created:</span>
                                    <span class="detail-value">
                                        <?= date('Y-m-d H:i', strtotime($item['created_at'])) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="furniture-actions">
                                <?php if ($item['status'] === 'pending'): ?>
                                    <button class="btn btn-success" 
                                            onclick="approveFurniture(<?= $item['id'] ?>)">
                                        ✅ Approve
                                    </button>
                                    <button class="btn btn-danger" 
                                            onclick="rejectFurniture(<?= $item['id'] ?>)">
                                        ❌ Reject
                                    </button>
                                <?php endif; ?>
                                
                                <button class="btn btn-info" 
                                        onclick="viewFurniture(<?= $item['id'] ?>)">
                                    👁️ Preview
                                </button>
                                
                                <?php if ($item['status'] === 'approved'): ?>
                                    <button class="btn btn-primary" 
                                            onclick="downloadSQL(<?= $item['id'] ?>)">
                                        💾 Download SQL
                                    </button>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: contents;" 
                                      onsubmit="return confirm('Delete this furniture?');">
                                    <input type="hidden" name="action" value="delete_furniture">
                                    <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                    <button type="submit" class="btn btn-danger">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2>⚙️ System Settings</h2>
                <button class="close-modal" onclick="closeModal('settingsModal')">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_settings">
                
                <div class="form-group">
                    <label>Server Name</label>
                    <input type="text" name="server_name" 
                           value="<?= htmlspecialchars($settings['server_name']) ?>">
                </div>
                
                <div class="form-group">
                    <label>Default Catalog Page ID</label>
                    <input type="number" name="catalog_page_id" 
                           value="<?= htmlspecialchars($settings['catalog_page_id']) ?>">
                </div>
                
                <div class="form-group">
                    <label>Default Credits Cost</label>
                    <input type="number" name="default_credits" 
                           value="<?= htmlspecialchars($settings['default_credits']) ?>">
                </div>
                
                <div class="form-group">
                    <label>Default Points Cost</label>
                    <input type="number" name="default_points" 
                           value="<?= htmlspecialchars($settings['default_points']) ?>">
                </div>
                
                <div class="form-group">
                    <label>Default Points Type</label>
                    <input type="number" name="default_points_type" 
                           value="<?= htmlspecialchars($settings['default_points_type']) ?>">
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    💾 Save Settings
                </button>
            </form>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div id="logsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📋 Activity Log</h2>
                <button class="close-modal" onclick="closeModal('logsModal')">×</button>
            </div>
            <div>
                <?php foreach ($recentLogs as $log): ?>
                    <div class="log-entry">
                        <div class="log-header">
                            <span><?= htmlspecialchars($log['action']) ?></span>
                            <span style="color: var(--text-secondary); font-weight: normal;">
                                <?= $log['timestamp'] ?>
                            </span>
                        </div>
                        <div class="log-details">
                            User: <?= htmlspecialchars($log['username']) ?> • 
                            IP: <?= htmlspecialchars($log['ip_address']) ?>
                            <?php if ($log['details']): ?>
                                <br><?= htmlspecialchars($log['details']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Furniture Preview Modal -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>👁️ Furniture Preview</h2>
                <button class="close-modal" onclick="closeModal('previewModal')">×</button>
            </div>
            <div id="previewContent">
                <!-- Dynamic content -->
            </div>
        </div>
    </div>

    <!-- Help Modal -->
    <div id="helpModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>❓ Help Guide</h2>
                <button class="close-modal" onclick="closeModal('helpModal')">×</button>
            </div>
            <div>
                <h3 style="margin: 20px 0 10px;">📝 How to Add Furniture</h3>
                <ol style="line-height: 2; padding-left: 20px;">
                    <li>Fill in the furniture details (Sprite ID must be unique)</li>
                    <li>Upload SWF and/or Nitro files</li>
                    <li>Optionally upload an icon (or it will auto-extract)</li>
                    <li>Click "Create Furniture" - it will be marked as PENDING</li>
                    <li>Review the furniture in the library below</li>
                    <li>Click "Approve" to generate SQL and mark ready for deployment</li>
                    <li>Download the SQL file and run it on your database</li>
                    <li>Upload the SWF/Nitro files to your server</li>
                    <li>Update your furnidata.xml/json files</li>
                    <li>Reload your emulator</li>
                </ol>

                <h3 style="margin: 30px 0 10px;">🔒 Admin Review System</h3>
                <p style="line-height: 1.8;">
                    All furniture items are created with "PENDING" status. This prevents
                    accidental deployment to production. An admin must review and approve
                    each item before SQL and deployment files are available. This ensures
                    quality control and prevents errors in your production environment.
                </p>

                <h3 style="margin: 30px 0 10px;">📁 File Types</h3>
                <ul style="line-height: 2; padding-left: 20px;">
                    <li><strong>SWF:</strong> Flash assets for legacy Habbo clients</li>
                    <li><strong>Nitro:</strong> Modern HTML5/WebGL assets for Nitro client</li>
                    <li><strong>Icon:</strong> 64x64 preview image (auto-extracted if possible)</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Close modal on outside click
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // Filter furniture
        function filterFurniture(status) {
            const cards = document.querySelectorAll('.furniture-card');
            const buttons = document.querySelectorAll('.filter-btn');
            
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            cards.forEach(card => {
                if (status === 'all' || card.dataset.status === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Approve furniture
        function approveFurniture(id) {
            if (confirm('Approve this furniture for deployment?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="approve_furniture">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Reject furniture
        function rejectFurniture(id) {
            const reason = prompt('Reason for rejection (optional):');
            if (reason !== null) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reject_furniture">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="reason" value="${reason}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // View furniture details
        function viewFurniture(id) {
            fetch(`?ajax=get_furniture&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    const content = `
                        <div class="tabs">
                            <button class="tab active" onclick="showTab('details')">
                                📋 Details
                            </button>
                            <button class="tab" onclick="showTab('sql')">💾 SQL</button>
                            <button class="tab" onclick="showTab('xml')">📄 XML</button>
                            <button class="tab" onclick="showTab('json')">📄 JSON</button>
                        </div>
                        
                        <div id="tab-details" class="tab-content active">
                            <h3>Furniture Information</h3>
                            <div class="furniture-details">
                                <div class="detail-item">
                                    <span class="detail-label">Sprite ID:</span>
                                    <span class="detail-value">${data.sprite_id}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Item Name:</span>
                                    <span class="detail-value">${data.item_name}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Public Name:</span>
                                    <span class="detail-value">${data.public_name}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Status:</span>
                                    <span class="status-badge status-${data.status}">
                                        ${data.status}
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div id="tab-sql" class="tab-content">
                            <div class="code-header">
                                <span>Generated SQL</span>
                                <button class="btn btn-primary" onclick="copyCode('sql')">
                                    📋 Copy
                                </button>
                            </div>
                            <pre class="code-preview" id="code-sql">${escapeHtml(data.sql_generated)}</pre>
                        </div>
                        
                        <div id="tab-xml" class="tab-content">
                            <div class="code-header">
                                <span>Furnidata XML</span>
                                <button class="btn btn-primary" onclick="copyCode('xml')">
                                    📋 Copy
                                </button>
                            </div>
                            <pre class="code-preview" id="code-xml">${escapeHtml(data.furnidata_xml)}</pre>
                        </div>
                        
                        <div id="tab-json" class="tab-content">
                            <div class="code-header">
                                <span>Furnidata JSON</span>
                                <button class="btn btn-primary" onclick="copyCode('json')">
                                    📋 Copy
                                </button>
                            </div>
                            <pre class="code-preview" id="code-json">${escapeHtml(data.furnidata_json)}</pre>
                        </div>
                    `;
                    document.getElementById('previewContent').innerHTML = content;
                    openModal('previewModal');
                })
                .catch(err => alert('Error loading furniture details'));
        }

        // Download SQL
        function downloadSQL(id) {
            window.location.href = `?download_sql=${id}`;
        }

        // Tab switching
        function showTab(name) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            event.target.classList.add('active');
            document.getElementById('tab-' + name).classList.add('active');
        }

        // Copy code
        function copyCode(type) {
            const code = document.getElementById('code-' + type).textContent;
            navigator.clipboard.writeText(code).then(() => {
                alert('Copied to clipboard!');
            });
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

<?php
// AJAX handlers
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    if ($_GET['ajax'] === 'get_furniture' && isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM furniture WHERE id = ?");
        $stmt->execute([(int) $_GET['id']]);
        echo json_encode($stmt->fetch());
    }
    exit();
}

// Download SQL
if (isset($_GET['download_sql'])) {
    $id = (int) $_GET['download_sql'];
    $stmt = $db->prepare("SELECT * FROM furniture WHERE id = ?");
    $stmt->execute([$id]);
    $furni = $stmt->fetch();
    
    if ($furni && $furni['status'] === 'approved') {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . 
               $furni['item_name'] . '_' . $furni['sprite_id'] . '.sql"');
        echo $furni['sql_generated'];
        exit();
    }
}
?>
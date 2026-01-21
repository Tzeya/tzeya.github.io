<?php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Configuration
define('ADMIN_USER', 'jose');
define('ADMIN_PASS', 'princejose');
define('FURNIDATA_XML', 'C:/inetpub/wwwroot/public/nitro-assets-new/gamedata/furnidata.xml');
define('FURNIDATA_JSON', 'C:/inetpub/wwwroot/public/nitro-assets-new/gamedata/furnidata.json');
define('BACKUP_DIR', __DIR__ . '/furnidata_backups/');

// Create backup directory
if (!file_exists(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0755, true);
}

// Authentication
if (isset($_POST['login'])) {
    if ($_POST['username'] === ADMIN_USER && $_POST['password'] === ADMIN_PASS) {
        $_SESSION['authenticated'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $error = 'Invalid credentials';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

if (!isset($_SESSION['authenticated'])) {
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furnidata Editor - Login</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 420px;
        }
        h1 { color: #667eea; text-align: center; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input { width: 100%; padding: 12px; border: 2px solid #e2e8f0; border-radius: 8px; }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .error { background: #fed7d7; color: #9b2c2c; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>📝 Furnidata Editor</h1>
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
            <button type="submit" name="login">Login</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit();
}

$message = '';
$messageType = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'add_furniture_json':
                // Create backup FIRST
                if (file_exists(FURNIDATA_JSON)) {
                    copy(FURNIDATA_JSON, BACKUP_DIR . 'furnidata_' . date('Y-m-d_H-i-s') . '.json');
                }
                
                // Load existing furnidata
                $jsonContent = file_get_contents(FURNIDATA_JSON);
                $data = json_decode($jsonContent, true);
                
                if (!$data || !isset($data['roomitemtypes']['furnitype'])) {
                    throw new Exception('Invalid furnidata.json structure');
                }
                
                // Parse the new furniture from the textarea
                $newFurnitureJson = $_POST['new_furniture'];
                
                // Remove trailing comma if present
                $newFurnitureJson = rtrim(trim($newFurnitureJson), ',');
                
                $newFurniture = json_decode($newFurnitureJson, true);
                
                if (!$newFurniture) {
                    throw new Exception('Invalid furniture JSON: ' . json_last_error_msg());
                }
                
                // Check if ID already exists
                $newId = $newFurniture['id'];
                foreach ($data['roomitemtypes']['furnitype'] as $existing) {
                    if ($existing['id'] == $newId) {
                        throw new Exception("Furniture with ID $newId already exists! Use a different ID.");
                    }
                }
                
                // Add new furniture to the array
                $data['roomitemtypes']['furnitype'][] = $newFurniture;
                
                // Save back to file
                file_put_contents(FURNIDATA_JSON, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                
                $message = "✅ Furniture added successfully! ID: $newId | Total furniture: " . count($data['roomitemtypes']['furnitype']);
                $messageType = 'success';
                break;

            case 'add_furniture_xml':
                // Create backup FIRST
                if (file_exists(FURNIDATA_XML)) {
                    copy(FURNIDATA_XML, BACKUP_DIR . 'furnidata_' . date('Y-m-d_H-i-s') . '.xml');
                }
                
                // Load existing XML
                $xml = new DOMDocument();
                $xml->preserveWhiteSpace = false;
                $xml->formatOutput = true;
                $xml->load(FURNIDATA_XML);
                
                // Find roomitemtypes
                $roomitemtypes = $xml->getElementsByTagName('roomitemtypes')->item(0);
                if (!$roomitemtypes) {
                    throw new Exception('Invalid furnidata.xml structure');
                }
                
                // Parse new furniture XML
                $newFurnitureXml = trim($_POST['new_furniture_xml']);
                
                // Create temporary document to parse the new furniture
                $tempDoc = new DOMDocument();
                $tempDoc->loadXML($newFurnitureXml);
                $newFurnitype = $tempDoc->documentElement;
                
                // Check if ID already exists
                $newId = $newFurnitype->getAttribute('id');
                $existingFurnitypes = $xml->getElementsByTagName('furnitype');
                foreach ($existingFurnitypes as $existing) {
                    if ($existing->getAttribute('id') == $newId) {
                        throw new Exception("Furniture with ID $newId already exists! Use a different ID.");
                    }
                }
                
                // Import and append the new furniture
                $importedNode = $xml->importNode($newFurnitype, true);
                $roomitemtypes->appendChild($importedNode);
                
                // Save
                $xml->save(FURNIDATA_XML);
                
                $message = "✅ Furniture added to XML successfully! ID: $newId";
                $messageType = 'success';
                break;

            case 'restore_backup':
                $backupFile = $_POST['backup_file'];
                $backupPath = BACKUP_DIR . basename($backupFile);
                
                if (file_exists($backupPath)) {
                    if (strpos($backupFile, '.xml') !== false) {
                        copy($backupPath, FURNIDATA_XML);
                        $message = 'XML restored from backup!';
                    } else {
                        copy($backupPath, FURNIDATA_JSON);
                        $message = 'JSON restored from backup!';
                    }
                    $messageType = 'success';
                }
                break;
        }
    } catch (Exception $e) {
        $message = '❌ Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Load current file stats
$jsonCount = 0;
$xmlCount = 0;

if (file_exists(FURNIDATA_JSON)) {
    $jsonData = json_decode(file_get_contents(FURNIDATA_JSON), true);
    $jsonCount = count($jsonData['roomitemtypes']['furnitype'] ?? []);
}

if (file_exists(FURNIDATA_XML)) {
    $xmlDoc = new DOMDocument();
    $xmlDoc->load(FURNIDATA_XML);
    $xmlCount = $xmlDoc->getElementsByTagName('furnitype')->length;
}

// Get backups
$backups = array_diff(scandir(BACKUP_DIR), ['.', '..']);
rsort($backups);

// Default template
$defaultTemplate = '{
  "id": ITEM_ID,
  "classname": "ITEM_NAME",
  "revision": 45508,
  "category": "",
  "defaultdir": 0,
  "xdim": 1,
  "ydim": 1,
  "partcolors": {"color": []},
  "name": "NAME",
  "description": "DESCRIPTION",
  "adurl": "",
  "offerid": 12,
  "buyout": true,
  "rentofferid": -1,
  "rentbuyout": false,
  "bc": true,
  "excludeddynamic": false,
  "customparams": "",
  "specialtype": 1,
  "canstandon": false,
  "cansiton": false,
  "canlayon": false,
  "furniline": "rare",
  "environment": "",
  "rare": false
},';

$defaultXmlTemplate = '<furnitype id="ITEM_ID" classname="ITEM_NAME">
  <revision>45508</revision>
  <defaultdir>0</defaultdir>
  <xdim>1</xdim>
  <ydim>1</ydim>
  <partcolors />
  <n>NAME</n>
  <description>DESCRIPTION</description>
  <adurl />
  <offerid>12</offerid>
  <buyout>1</buyout>
  <rentofferid>-1</rentofferid>
  <rentbuyout>0</rentbuyout>
  <bc>1</bc>
  <excludeddynamic>0</excludeddynamic>
  <customparams />
  <specialtype>1</specialtype>
  <canstandon>0</canstandon>
  <cansiton>0</cansiton>
  <canlayon>0</canlayon>
  <furniline>rare</furniline>
  <environment />
  <rare>0</rare>
</furnitype>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Furnidata Editor - ADD ONLY</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #667eea;
            --secondary: #764ba2;
            --success: #48bb78;
            --danger: #f56565;
            --warning: #ed8936;
            --bg: #f7fafc;
            --card: #ffffff;
            --text: #2d3748;
            --border: #e2e8f0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }

        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header h1 { font-size: 32px; display: flex; align-items: center; gap: 12px; }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }

        .stat-value { font-size: 36px; font-weight: bold; color: var(--primary); }
        .stat-label { color: #718096; font-size: 14px; text-transform: uppercase; }

        .card {
            background: var(--card);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 30px;
            margin-bottom: 30px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border);
        }

        .card-title { font-size: 22px; font-weight: 600; }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 16px;
        }

        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-warning { background: var(--warning); color: white; }
        .btn:hover { transform: translateY(-2px); opacity: 0.9; }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 16px;
            font-weight: 500;
        }
        .alert-success { background: #e6f9f0; color: #22543d; border-left: 4px solid var(--success); }
        .alert-error { background: #fed7d7; color: #742a2a; border-left: 4px solid var(--danger); }

        textarea {
            width: 100%;
            min-height: 400px;
            padding: 15px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
        }

        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            transition: all 0.3s;
            font-size: 16px;
        }

        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .backup-list {
            max-height: 300px;
            overflow-y: auto;
        }

        .backup-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: var(--bg);
            margin-bottom: 10px;
            border-radius: 8px;
        }

        .warning-box {
            background: #fef5e7;
            border-left: 4px solid var(--warning);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 16px;
        }

        .info-box {
            background: #eef2ff;
            border-left: 4px solid var(--primary);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .header { flex-direction: column; }
            .stats { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>➕ Furnidata Editor (ADD ONLY - SAFE)</h1>
            <a href="?logout" class="btn btn-danger">🚪 Logout</a>
        </div>

        <div class="warning-box">
            <strong>⚠️ SAFE MODE:</strong> This tool ONLY ADDS furniture to your existing files. 
            It will NEVER delete or modify your existing furniture. A backup is created before every change!
        </div>

        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?= $jsonCount ?></div>
                <div class="stat-label">JSON Furniture Count</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $xmlCount ?></div>
                <div class="stat-label">XML Furniture Count</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($backups) ?></div>
                <div class="stat-label">Backups Available</div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <!-- Add Furniture -->
        <div class="card">
            <div class="info-box">
                <strong>📝 Instructions:</strong><br>
                1. Fill in the template below (replace ITEM_ID, ITEM_NAME, NAME, DESCRIPTION)<br>
                2. Click "Add to JSON" or "Add to XML"<br>
                3. Your new furniture will be ADDED to the existing file (nothing gets deleted)<br>
                4. A backup is automatically created before each change
            </div>

            <div class="tabs">
                <button class="tab active" onclick="showTab('json')">➕ Add to JSON</button>
                <button class="tab" onclick="showTab('xml')">➕ Add to XML</button>
                <button class="tab" onclick="showTab('backups')">💾 Backups</button>
            </div>

            <div id="tab-json" class="tab-content active">
                <form method="POST">
                    <input type="hidden" name="action" value="add_furniture_json">
                    <textarea name="new_furniture"><?= htmlspecialchars($defaultTemplate) ?></textarea>
                    <button type="submit" class="btn btn-success" style="margin-top: 15px; width: 100%;">
                        ➕ Add This Furniture to JSON
                    </button>
                </form>
            </div>

            <div id="tab-xml" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_furniture_xml">
                    <textarea name="new_furniture_xml"><?= htmlspecialchars($defaultXmlTemplate) ?></textarea>
                    <button type="submit" class="btn btn-primary" style="margin-top: 15px; width: 100%;">
                        ➕ Add This Furniture to XML
                    </button>
                </form>
            </div>

            <div id="tab-backups" class="tab-content">
                <h3 style="margin-bottom: 15px;">Backup History</h3>
                <p style="margin-bottom: 15px; color: #718096;">
                    Backups are created automatically before each change. You can restore any previous version here.
                </p>
                <div class="backup-list">
                    <?php foreach ($backups as $backup): ?>
                        <div class="backup-item">
                            <span><?= htmlspecialchars($backup) ?></span>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="restore_backup">
                                <input type="hidden" name="backup_file" value="<?= htmlspecialchars($backup) ?>">
                                <button type="submit" class="btn btn-warning" 
                                        onclick="return confirm('⚠️ Restore this backup? This will replace your current file!')">
                                    🔄 Restore
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($backups)): ?>
                        <p style="text-align: center; padding: 20px; color: #718096;">
                            No backups yet. Backups are created automatically when you add furniture.
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📁 File Locations</h2>
            </div>
            <p><strong>JSON File:</strong> <?= FURNIDATA_JSON ?></p>
            <p style="margin-top: 10px;"><strong>XML File:</strong> <?= FURNIDATA_XML ?></p>
            <p style="margin-top: 10px;"><strong>Backups:</strong> <?= BACKUP_DIR ?></p>
        </div>
    </div>

    <script>
        function showTab(name) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById('tab-' + name).classList.add('active');
        }
    </script>
</body>
</html>
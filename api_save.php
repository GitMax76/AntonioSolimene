<?php
// Imposta gli header per la risposta JSON e CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Gestione preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Supporto lettura diretta catalogo via GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $paths = [
        __DIR__ . '/public/catalog.json',
        __DIR__ . '/catalog.json'
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) {
            $content = file_get_contents($p);
            if ($content !== false && !empty(trim($content))) {
                echo $content;
                exit;
            }
        }
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Catalogo non trovato']);
    exit;
}

// Verifica che il metodo HTTP sia POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito. Utilizzare POST.'
    ]);
    exit;
}

// Configurazione Sicurezza & Secret Key
$secretSalt = 'SolimeneVietri1876SaltKey_Secure!';
$pinFile = __DIR__ . '/public/.auth_pin.json';
$rateLimitFile = __DIR__ . '/public/.rate_limit.json';

// Funzione recupero PIN corrente
function getCurrentPin($pinFile) {
    if (file_exists($pinFile)) {
        $content = @file_get_contents($pinFile);
        if ($content) {
            $data = json_decode($content, true);
            if (!empty($data['pin'])) {
                return (string)$data['pin'];
            }
        }
    }
    return 'vietri1876'; // PIN predefinito
}

// Funzione verifica Rate Limit (Protezione anti Brute-Force)
function checkRateLimit($rateLimitFile) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $window = 300; // 5 minuti
    $maxAttempts = 5;

    $attempts = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content) {
            $attempts = json_decode($content, true) ?: [];
        }
    }

    // Pulizia vecchi tentativi
    foreach ($attempts as $clientIp => $info) {
        if ($now - ($info['first_time'] ?? 0) > $window) {
            unset($attempts[$clientIp]);
        }
    }

    if (isset($attempts[$ip])) {
        if ($attempts[$ip]['count'] >= $maxAttempts && ($now - $attempts[$ip]['last_time']) < $window) {
            $remaining = $window - ($now - $attempts[$ip]['last_time']);
            return [
                'blocked' => true,
                'message' => "Troppi tentativi errati. Accesso temporaneamente bloccato per sicurezza. Riprova tra {$remaining} secondi."
            ];
        }
    }

    return ['blocked' => false, 'attempts' => $attempts, 'ip' => $ip];
}

function recordFailedAttempt($rateLimitFile, $ip) {
    $now = time();
    $attempts = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content) {
            $attempts = json_decode($content, true) ?: [];
        }
    }
    if (!isset($attempts[$ip])) {
        $attempts[$ip] = ['count' => 1, 'first_time' => $now, 'last_time' => $now];
    } else {
        $attempts[$ip]['count'] += 1;
        $attempts[$ip]['last_time'] = $now;
    }
    @file_put_contents($rateLimitFile, json_encode($attempts), LOCK_EX);
}

function clearRateLimit($rateLimitFile, $ip) {
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content) {
            $attempts = json_decode($content, true) ?: [];
            unset($attempts[$ip]);
            @file_put_contents($rateLimitFile, json_encode($attempts), LOCK_EX);
        }
    }
}

function generateToken($secretSalt) {
    $dateKey = date('Y-m-d');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    return hash_hmac('sha256', "solimene_auth_{$dateKey}_{$ip}", $secretSalt);
}

function validateToken($token, $secretSalt) {
    $todayToken = generateToken($secretSalt);
    // Tolleranza per cambio data mezzanotte (giorno precedente)
    $yesterdayKey = date('Y-m-d', strtotime('-1 day'));
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $yesterdayToken = hash_hmac('sha256', "solimene_auth_{$yesterdayKey}_{$ip}", $secretSalt);

    return ($token === $todayToken || $token === $yesterdayToken);
}

// -------------------------------------------------------------
// FUNZIONI LOGGING, BACKUP & NOTIFICHE EMAIL
// -------------------------------------------------------------
function logActivity($action, $details = '') {
    $logDir = __DIR__ . '/public';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/activity_log.txt';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $logLine = "[{$time}] [IP: {$ip}] [{$action}] {$details}\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

function createRollingBackup($jsonContent) {
    $backupDir = __DIR__ . '/public/backups';
    if (!is_dir($backupDir)) {
        @mkdir($backupDir, 0777, true);
    }
    
    // Salva file timestampato
    $filename = 'catalog_backup_' . date('Y-m-d_His') . '.json';
    $target = $backupDir . '/' . $filename;
    @file_put_contents($target, $jsonContent, LOCK_EX);

    // Mantiene solo gli ultimi 30 backup per ottimizzare lo spazio
    $files = glob($backupDir . '/catalog_backup_*.json');
    if ($files && count($files) > 30) {
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        while (count($files) > 30) {
            $oldest = array_shift($files);
            @unlink($oldest);
        }
    }
    return $filename;
}

function sendBackupEmailNotification($catalog, $toEmail = 'Info@solimene.net') {
    $count = count($catalog);
    $subject = "=?UTF-8?B?" . base64_encode("🏺 [solimene.net] Backup Automatico Catalogo ({$count} opere)") . "?=";
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('d/m/Y H:i:s');

    $listHtml = "";
    foreach (array_slice($catalog, 0, 15) as $item) {
        $title = htmlspecialchars($item['title'] ?? 'Senza titolo');
        $price = htmlspecialchars($item['price'] ?? '-');
        $col = htmlspecialchars($item['collection'] ?? '-');
        $status = ($item['status'] ?? '') === 'venduto' ? '🔴 Venduto' : '🟢 Disponibile';
        $listHtml .= "<tr><td style='padding:6px 10px;border-bottom:1px solid #eee;'><strong>{$title}</strong> ({$col})</td><td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$price}</td><td style='padding:6px 10px;border-bottom:1px solid #eee;'>{$status}</td></tr>";
    }

    $message = "
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family:Arial,sans-serif;color:#333;line-height:1.6;'>
      <div style='max-width:600px;margin:0 auto;border:1px solid #e0e0e0;border-radius:12px;overflow:hidden;'>
        <div style='background:#0A3764;color:#fff;padding:20px;text-align:center;'>
          <h2 style='margin:0;font-size:22px;'>Bottega Antonio Solimene</h2>
          <p style='margin:5px 0 0;font-size:12px;opacity:0.8;'>Aggiornamento & Backup Catalogo solimene.net</p>
        </div>
        <div style='padding:20px;'>
          <p>Ciao Antonio,</p>
          <p>È stato eseguito un salvataggio delle creazioni sul sito <strong>solimene.net</strong> in data <strong>{$time}</strong> (IP: {$ip}).</p>
          <h3 style='color:#0A3764;font-size:16px;border-bottom:2px solid #0A3764;padding-bottom:5px;'>Riepilogo Opere Online ({$count} totali):</h3>
          <table style='width:100%;border-collapse:collapse;font-size:13px;'>
            <thead>
              <tr style='background:#f8f9fa;text-align:left;'>
                <th style='padding:8px 10px;'>Opera</th>
                <th style='padding:8px 10px;'>Prezzo</th>
                <th style='padding:8px 10px;'>Stato</th>
              </tr>
            </thead>
            <tbody>
              {$listHtml}
            </tbody>
          </table>
          <p style='margin-top:20px;font-size:12px;color:#777;'>Questo messaggio è generato automaticamente dal sistema di gestione bottega di solimene.net.</p>
        </div>
      </div>
    </body>
    </html>";

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Bottega Solimene <Info@solimene.net>',
        'Reply-To: Info@solimene.net',
        'X-Mailer: PHP/' . phpversion()
    ];

    @mail($toEmail, $subject, $message, implode("\r\n", $headers));
}

// Lettura del payload JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Payload JSON non valido o mancante.'
    ]);
    exit;
}

$currentPin = getCurrentPin($pinFile);
$rateCheck = checkRateLimit($rateLimitFile);
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($rateCheck['blocked']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => $rateCheck['message']
    ]);
    exit;
}

$action = $data['action'] ?? (isset($data['catalog']) ? 'save' : 'login');

// 1. AZIONE LOGIN SERVER-SIDE
if ($action === 'login') {
    $inputPin = isset($data['pin']) ? trim((string)$data['pin']) : '';
    
    if ($inputPin === $currentPin) {
        clearRateLimit($rateLimitFile, $clientIp);
        $token = generateToken($secretSalt);
        logActivity('LOGIN_OK', 'Accesso autorizzato alla gestione bottega');
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Autenticazione riuscita.',
            'token' => $token
        ]);
        exit;
    } else {
        recordFailedAttempt($rateLimitFile, $clientIp);
        logActivity('LOGIN_FAILED', 'Tentativo di accesso con PIN non valido: ' . substr($inputPin, 0, 2) . '***');
        usleep(300000); // Ritardo di 300ms anti brute-force
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Codice di accesso non corretto. Riprova.'
        ]);
        exit;
    }
}

// 2. AZIONE CAMBIO PIN BOTTEGA
if ($action === 'change_pin') {
    $token = $data['token'] ?? '';
    $oldPin = isset($data['old_pin']) ? trim((string)$data['old_pin']) : '';
    $newPin = isset($data['new_pin']) ? trim((string)$data['new_pin']) : '';

    $isAuthorized = validateToken($token, $secretSalt) || ($oldPin === $currentPin);

    if (!$isAuthorized || $oldPin !== $currentPin) {
        logActivity('PIN_CHANGE_FAIL', 'Tentativo cambio PIN non autorizzato');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Vecchio PIN non valido o sessione scaduta.'
        ]);
        exit;
    }

    if (strlen($newPin) < 4) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Il nuovo codice deve contenere almeno 4 caratteri.'
        ]);
        exit;
    }

    $saved = @file_put_contents($pinFile, json_encode(['pin' => $newPin, 'updated_at' => date('c')]), LOCK_EX);
    if ($saved !== false) {
        $newToken = generateToken($secretSalt);
        logActivity('PIN_CHANGED', 'Codice segreto di accesso aggiornato con successo');
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Codice segreto di bottega aggiornato con successo!',
            'token' => $newToken
        ]);
        exit;
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Impossibile salvare il nuovo PIN. Verificare permessi cartella public.'
        ]);
        exit;
    }
}

// 3. AZIONE SALVATAGGIO CATALOGO
if ($action === 'save') {
    $providedPin = isset($data['pin']) ? (string)$data['pin'] : '';
    $providedToken = isset($data['token']) ? (string)$data['token'] : '';

    $isAuth = validateToken($providedToken, $secretSalt) || ($providedPin === $currentPin);

    if (!$isAuth) {
        logActivity('SAVE_UNAUTHORIZED', 'Tentativo di salvataggio senza autorizzazione valida');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Sessione scaduta o PIN non valido. Effettua nuovamente il login.'
        ]);
        exit;
    }

    if (!isset($data['catalog']) || !is_array($data['catalog'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dati catalogo mancanti o formato non valido.'
        ]);
        exit;
    }

    $jsonContent = json_encode($data['catalog'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($jsonContent === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Errore durante la codifica JSON del catalogo.'
        ]);
        exit;
    }

    $candidatePaths = [
        __DIR__ . '/public/catalog.json',
        __DIR__ . '/catalog.json'
    ];

    $saved = false;
    $savedPath = '';

    foreach ($candidatePaths as $targetPath) {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        
        $res = @file_put_contents($targetPath, $jsonContent, LOCK_EX);
        if ($res !== false) {
            $saved = true;
            $savedPath = basename($dir) === 'public' ? 'public/catalog.json' : 'catalog.json';
        }
    }

    if (!$saved) {
        logActivity('SAVE_ERROR', 'Impossibile scrivere il file catalog.json sul server');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Impossibile salvare il file catalog.json. Verificare i permessi di scrittura.'
        ]);
        exit;
    }

    // 1. Crea Backup Storico Timestampato (Rotazione 30 copie)
    $backupFile = createRollingBackup($jsonContent);

    // 2. Registra Log di Attività
    $itemsCount = count($data['catalog']);
    logActivity('CATALOG_SAVED', "Catalogo aggiornato con {$itemsCount} opere. Backup creato: {$backupFile}");

    // 3. Invia Notifica Email di Backup ad Antonio (Info@solimene.net)
    @sendBackupEmailNotification($data['catalog'], 'Info@solimene.net');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Catalogo aggiornato con successo!',
        'saved_to' => $savedPath,
        'backup_file' => $backupFile,
        'items_count' => $itemsCount,
        'updated_at' => date('c')
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Azione non riconosciuta.']);


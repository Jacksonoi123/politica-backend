<?php
/***********************
 * Política Game – API (PHP)
 * Compatível com rotas com ou sem "/server.php" no caminho.
 ***********************/

header("Content-Type: application/json");

// CORS — permita somente seu frontend no Netlify
$allowed_origin = "https://politicagame.netlify.app";
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
} else {
    // Opcional: em dev você pode liberar tudo, mas em produção mantenha restrito
    // header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ===== Config ===== */
$DB_DIR    = __DIR__;
$USERS_FILE = $DB_DIR . "/usuarios.db";
$NEWS_FILE  = $DB_DIR . "/news.db";

$NATIONS = ['Brasil', 'EUA', 'China', 'Rússia', 'França', 'Alemanha', 'Japão', 'Índia', 'Reino Unido', 'Canadá'];

$CARGO_LIMITS = [
    'Ditador Supremo' => 1,
    'Ditador' => 1,
    'Monarca' => 1,
    'Presidente' => 1,
    'Vice-Presidente' => 3,
    'Senador Vitalício' => 5,
    'Senador' => 25,
    'Deputado Federal' => 50,
    'Deputado Estadual' => 75,
    'Prefeito' => 125
];

$powerLadder = [
    "Imigrante Ilegal", "Imigrante", "Cidadão Sem Direitos", "Cidadão", "Eleitor Manipulado",
    "Militante de WhatsApp", "Influenciador", "Influenciador Político", "Candidato de Baixo Orçamento",
    "Prefeito de Bairro Corrupto", "Prefeito de Bairro", "Tesoureiro de Campanha Corrupto",
    "Tesoureiro de Campanha", "Diretor Executivo de Gabinete", "Diretor Executivo Corrupto",
    "Diretor Executivo", "Vereador Fantasma", "Vereador Popular", "Vice Prefeito Corrupto",
    "Vice Prefeito", "Prefeito Corrupto", "Prefeito", "Deputado Estadual Corrupto",
    "Deputado Estadual", "Deputado Federal Corrupto", "Deputado Federal", "Senador Corrupto",
    "Senador", "Senador Vitalício Corrupto", "Senador Vitalício", "Ministro Questionável",
    "Ministro Populista", "Presidente Interino Corrupto", "Presidente Interino",
    "Vice-Presidente Corrupto", "Vice-Presidente", "Presidente Corrupto", "Presidente",
    "Monarca", "Ditador", "Ditador Supremo"
];

// Data do jogo (em memória)
$gameDate = ['year' => 1989, 'month' => 1, 'day' => 1, 'lastUpdate' => round(microtime(true) * 1000)];

/* ===== Helpers ===== */
function sendJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function encryptBase64($str) { return base64_encode($str); }

function getRequestData() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function readFileJson($file) {
    if (!file_exists($file)) return [];
    $txt = file_get_contents($file);
    $json = json_decode($txt, true);
    return is_array($json) ? $json : [];
}

function writeFileJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getUserIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function findUserByEmail(&$users, $emailBase64) {
    foreach ($users as $k => $u) {
        if (isset($u['email']) && $u['email'] === $emailBase64) return $k;
    }
    return null;
}

function updateTop30(&$users, $nation, $powerLadder, $CARGO_LIMITS) {
    $nationUsers = array_filter($users, fn($u) => ($u['nation'] ?? '') === $nation);
    usort($nationUsers, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $nationUsers = array_values($nationUsers);

    for ($i = 0; $i < count($nationUsers); $i++) {
        if ($i === 0 && isset($CARGO_LIMITS['Ditador Supremo'])) {
            $nationUsers[$i]['role'] = $powerLadder[40];
        } elseif ($i === 1 && isset($CARGO_LIMITS['Ditador'])) {
            $nationUsers[$i]['role'] = $powerLadder[39];
        } elseif ($i === 2 && isset($CARGO_LIMITS['Monarca'])) {
            $nationUsers[$i]['role'] = $powerLadder[38];
        } else {
            $level = max(1, $nationUsers[$i]['level'] ?? 1);
            $nationUsers[$i]['role'] = $powerLadder[min($level - 1, 37)];
        }
    }

    // Propaga papéis para o array original
    foreach ($nationUsers as $nu) {
        foreach ($users as &$u) {
            if (($u['id'] ?? null) === ($nu['id'] ?? null)) {
                $u['role'] = $nu['role'];
            }
        }
    }
    return array_slice($nationUsers, 0, 30);
}

function updateEconomy(&$users) {
    $nationEconomies = [];
    foreach ($users as $user) {
        $n = $user['nation'] ?? '';
        if (!isset($nationEconomies[$n])) $nationEconomies[$n] = ['totalCredits' => 0, 'crisisCount' => 0];
        $nationEconomies[$n]['totalCredits'] += $user['credits'] ?? 0;
    }
    foreach ($users as &$user) {
        $n = $user['nation'] ?? '';
        $economy = $nationEconomies[$n];
        // A condição original no Node era meio sem sentido (comparava total com metade de si mesmo).
        // Mantemos a lógica mas protegemos contra undefined.
        if ($economy['crisisCount'] < 3) {
            // Trigger fictício para "crise" — você pode ajustar depois
            if (($economy['totalCredits'] ?? 0) <= 0) {
                $economy['crisisCount']++;
                $user['score'] = ($user['score'] ?? 0) - 200;
                if ($economy['crisisCount'] === 3) {
                    $user['score'] = 0;
                    if (($user['level'] ?? 0) >= 20) $user['role'] = "Ditador";
                }
            }
        }
        $cr = $economy['crisisCount'] ?? 0;
        $user['credits'] = max(0, ($user['credits'] ?? 0) * (1 - $cr * 0.05));
    }
    unset($user);
    return $users;
}

function checkElections(&$users, $nation, $gameDate, $CARGO_LIMITS) {
    $years = [1993, 1997, 2001, 2005, 2009, 2013, 2017, 2021, 2025];
    if (($gameDate['month'] ?? 0) === 11 && in_array(($gameDate['year'] ?? 0), $years)) {
        $eligible = array_filter($users, fn($u) =>
            ($u['nation'] ?? '') === $nation && !in_array(($u['role'] ?? ''), ['Ditador Supremo', 'Ditador', 'Monarca'])
        );
        usort($eligible, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        foreach ($CARGO_LIMITS as $role => $limit) {
            $current = array_filter($eligible, fn($u) => ($u['role'] ?? '') === $role);
            $currentCount = count($current);
            if ($currentCount < $limit) {
                $needed = $limit - $currentCount;
                $candidates = array_slice($eligible, 0, $needed);
                foreach ($candidates as $cand) {
                    foreach ($users as &$u) {
                        if (($u['id'] ?? null) === ($cand['id'] ?? null)) {
                            $u['role'] = $role;
                        }
                    }
                }
                unset($u);
            }
        }
    }
}

/* ===== Normalização do PATH =====
 * Garante que /server.php/rota e /rota sejam tratados igual.
 */
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

// Remove o diretório do script do início do path, se houver
$path = $rawPath;
if ($scriptDir && $scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
if ($path === '') $path = '/';

// Remove o próprio /server.php do início do path, se houver
if ($scriptName && strpos($path, $scriptName) === 0) {
    $path = substr($path, strlen($scriptName));
}
if (strpos($path, '/server.php') === 0) {
    $path = substr($path, strlen('/server.php'));
}
$path = urldecode($path);
if ($path === '') $path = '/';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Carrega dados
$users = readFileJson($USERS_FILE);
$news  = readFileJson($NEWS_FILE);

// Função utilitária para gravar ambos
function saveAll() {
    global $users, $news, $USERS_FILE, $NEWS_FILE;
    writeFileJson($USERS_FILE, $users);
    writeFileJson($NEWS_FILE, $news);
}

/* ===== Rotas ===== */

/** POST /register */
if ($path === '/register' && $method === 'POST') {
    $data = getRequestData();
    $name = $data['name'] ?? null;
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;
    $nation = $data['nation'] ?? null;
    if (!$name || !$email || !$password || !$nation || !in_array($nation, $NATIONS)) {
        sendJson(['message' => 'Dados inválidos.'], 400);
    }
    $encEmail = encryptBase64($email);
    if (findUserByEmail($users, $encEmail) !== null) {
        sendJson(['message' => 'Email já cadastrado.'], 400);
    }
    $new = [
        'id' => count($users) + 1,
        'name' => $name,
        'email' => $encEmail,
        'password' => encryptBase64($password),
        'nation' => $nation,
        'role' => $powerLadder[0],
        'level' => 1,
        'tutorialCompleted' => false,
        'score' => 0,
        'credits' => 10,
        'skills' => new stdClass(),
        'lastIP' => null
    ];
    $users[] = $new;
    writeFileJson($USERS_FILE, $users);
    updateTop30($users, $nation, $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    writeFileJson($USERS_FILE, $users);
    sendJson(['message' => 'Usuário cadastrado!'], 201);
}

/** POST /login */
if ($path === '/login' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;
    if (!$email) sendJson(['message' => 'Email é obrigatório.'], 400);

    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 401);

    $userIP = getUserIP();
    $user = $users[$idx];

    if (($user['lastIP'] ?? null) === $userIP) {
        $users[$idx]['lastIP'] = $userIP;
        writeFileJson($USERS_FILE, $users);
        sendJson(['user' => $users[$idx]]);
    }

    if (!$password) sendJson(['message' => 'Senha é obrigatória.'], 400);
    if (($user['password'] ?? '') !== encryptBase64($password)) {
        sendJson(['message' => 'Senha incorreta.'], 401);
    }

    $users[$idx]['lastIP'] = $userIP;
    updateTop30($users, $user['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    checkElections($users, $user['nation'], $gameDate, $CARGO_LIMITS);
    writeFileJson($USERS_FILE, $users);
    sendJson(['user' => $users[$idx]]);
}

/** POST /update-score */
if ($path === '/update-score' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $score = $data['score'] ?? null;
    if (!$email || $score === null) sendJson(['message' => 'Dados inválidos.'], 400);

    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $users[$idx]['score'] = ($users[$idx]['score'] ?? 0) + (int)$score;
    $users[$idx]['level'] = min(floor(($users[$idx]['score'] ?? 0) / 100) + 1, 38);

    updateTop30($users, $users[$idx]['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    checkElections($users, $users[$idx]['nation'], $gameDate, $CARGO_LIMITS);
    writeFileJson($USERS_FILE, $users);
    sendJson(['user' => $users[$idx]]);
}

/** POST /global-top */
if ($path === '/global-top' && $method === 'POST') {
    $data = getRequestData();
    $nation = $data['nation'] ?? null;
    if (!$nation || !in_array($nation, $NATIONS)) sendJson(['message' => 'Nação inválida.'], 400);

    updateTop30($users, $nation, $powerLadder, $CARGO_LIMITS);
    writeFileJson($USERS_FILE, $users);

    $topUsers = array_filter($users, fn($u) => ($u['nation'] ?? '') === $nation);
    usort($topUsers, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
    $top = array_slice($topUsers, 0, 30);
    sendJson(['top' => array_values($top)]);
}

/** POST /change-nation */
if ($path === '/change-nation' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $newNation = $data['newNation'] ?? null;
    if (!$email || !$newNation || !in_array($newNation, $NATIONS)) {
        sendJson(['message' => 'Dados inválidos.'], 400);
    }
    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $users[$idx]['nation'] = $newNation;
    $users[$idx]['score'] = 0;
    $users[$idx]['level'] = 1;
    $users[$idx]['role'] = $powerLadder[0];
    $users[$idx]['credits'] = 10;
    updateTop30($users, $newNation, $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    writeFileJson($USERS_FILE, $users);
    sendJson(['message' => 'Nação alterada com sucesso!']);
}

/** POST /create-coup */
if ($path === '/create-coup' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $nation = $data['nation'] ?? null;
    if (!$email || !$nation || !in_array($nation, $NATIONS)) sendJson(['message' => 'Dados inválidos.'], 400);

    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    foreach ($users as &$u) { if (!isset($u['coup'])) $u['coup'] = null; }
    unset($u);

    $users[$idx]['coup'] = [
        'leader' => $users[$idx]['id'],
        'members' => [$users[$idx]['id']],
        'nation' => $nation,
        'success' => false,
        'scoreThreshold' => 1000
    ];
    writeFileJson($USERS_FILE, $users);

    $news[] = [
        'title' => "Golpe em $nation!",
        'content' => "{$users[$idx]['name']} iniciou um golpe de estado.",
        'date' => $gameDate['year']
    ];
    writeFileJson($NEWS_FILE, $news);

    sendJson(['message' => 'Golpe criado!']);
}

/** POST /join-coup  (coupId = id do líder) */
if ($path === '/join-coup' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $coupId = $data['coupId'] ?? null;
    if (!$email || !$coupId) sendJson(['message' => 'Dados inválidos.'], 400);

    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    // acha o líder que tem coup com leader = $coupId
    $leaderKey = null;
    foreach ($users as $k => $u) {
        if (isset($u['coup']['leader']) && (string)$u['coup']['leader'] === (string)$coupId) {
            $leaderKey = $k; break;
        }
    }
    if ($leaderKey === null) sendJson(['message' => 'Golpe não encontrado.'], 404);

    // adiciona membro
    if (!in_array($users[$idx]['id'], $users[$leaderKey]['coup']['members'])) {
        $users[$leaderKey]['coup']['members'][] = $users[$idx]['id'];
    }
    $users[$idx]['coup'] = $users[$leaderKey]['coup'];

    // soma scores
    $totalScore = 0;
    foreach ($users[$leaderKey]['coup']['members'] as $mid) {
        foreach ($users as $u) {
            if (($u['id'] ?? null) === $mid) { $totalScore += $u['score'] ?? 0; break; }
        }
    }

    if ($totalScore >= ($users[$leaderKey]['coup']['scoreThreshold'] ?? 1000)) {
        $users[$leaderKey]['coup']['success'] = true;

        $topUsers = array_filter($users, fn($u) => ($u['nation'] ?? '') === ($users[$leaderKey]['nation'] ?? ''));
        usort($topUsers, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $topUsers = array_slice($topUsers, 0, 3);

        foreach ($topUsers as &$tu) {
            if (in_array(($tu['role'] ?? ''), ['Ditador Supremo', 'Ditador', 'Monarca'])) $tu['role'] = $powerLadder[0];
        }
        unset($tu);

        foreach ($users as &$u) {
            if (($u['role'] ?? '') === 'Senador Vitalício' && ($u['nation'] ?? '') === ($users[$leaderKey]['nation'] ?? '')) {
                $u['role'] = $powerLadder[0];
            }
        }
        unset($u);

        $senators = array_filter($users, fn($u) => ($u['role'] ?? '') === 'Senador' && ($u['nation'] ?? '') === ($users[$leaderKey]['nation'] ?? ''));
        usort($senators, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $senators = array_slice($senators, 0, 3);
        foreach ($senators as &$s) { $s['role'] = 'Senador Vitalício'; }
        unset($s);

        // líder = Ditador Supremo; 2º membro = Ditador
        $users[$leaderKey]['role'] = 'Ditador Supremo';
        if (count($users[$leaderKey]['coup']['members']) > 1) {
            $secondId = $users[$leaderKey]['coup']['members'][1];
            foreach ($users as &$u) { if (($u['id'] ?? null) === $secondId) { $u['role'] = 'Ditador'; break; } }
            unset($u);
        }

        saveAll();

        $news[] = [
            'title'   => "Golpe bem-sucedido em " . ($users[$leaderKey]['nation'] ?? '') . "!",
            'content' => ($users[$leaderKey]['name'] ?? 'Líder') . " é o novo Ditador Supremo.",
            'date'    => $gameDate['year']
        ];
        writeFileJson($NEWS_FILE, $news);
    }

    saveAll();
    sendJson(['message' => 'Juntou-se ao golpe!']);
}

/** GET /news/:nation  (aceita com ou sem /server.php no caminho) */
if ($method === 'GET') {
    // casa /news/<nation> exatamente
    if (preg_match('#^/news/([^/]+)$#i', $path, $m)) {
        $nation = urldecode($m[1]);
        if (!in_array($nation, $NATIONS)) sendJson(['message' => 'Nação inválida.'], 400);

        // Mantém a lógica original: retorna notícias do último ano de jogo
        $filtered = array_filter($news, fn($n) => isset($n['date']) && $n['date'] >= ($gameDate['year'] - 1));
        sendJson(['news' => array_values($filtered)]);
    }
}

/** POST /post-news */
if ($path === '/post-news' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $title = $data['title'] ?? null;
    $content = $data['content'] ?? null;
    $isInternational = (bool)($data['isInternational'] ?? false);
    if (!$email || !$title || !$content) sendJson(['message' => 'Dados inválidos.'], 400);

    $encEmail = encryptBase64($email);
    $idx = findUserByEmail($users, $encEmail);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $user = $users[$idx];
    $roleIndex = array_search(($user['role'] ?? ''), $powerLadder);
    $minRoleIndex = array_search('Prefeito Corrupto', $powerLadder);
    if ($roleIndex === false || $roleIndex < $minRoleIndex) {
        sendJson(['message' => 'Apenas cargos de Prefeito Corrupto ou superior podem postar notícias.'], 403);
    }

    $news[] = [
        'title' => $title,
        'content' => $content,
        'date' => $gameDate['year'],
        'nation' => $user['nation'] ?? null,
        'isInternational' => $isInternational
    ];
    writeFileJson($NEWS_FILE, $news);
    sendJson(['message' => 'Notícia postada!']);
}

/** GET /game-date */
if ($path === '/game-date' && $method === 'GET') {
    $now = round(microtime(true) * 1000);
    $elapsedMs = $now - ($gameDate['lastUpdate'] ?? $now);

    $msPerDay = 2500;
    $totalDays = floor($elapsedMs / $msPerDay);
    $yearsPassed = floor($totalDays / 360);
    $remainingDays = $totalDays % 360;
    $monthsPassed = floor($remainingDays / 30);
    $daysPassed = $remainingDays % 30;

    $gameDate['year'] = 1989 + $yearsPassed;
    $gameDate['month'] = 1 + $monthsPassed;
    $gameDate['day'] = 1 + $daysPassed;
    $gameDate['lastUpdate'] = $now;

    if ($gameDate['day'] > 30) { $gameDate['day'] = 1; $gameDate['month']++; }
    if ($gameDate['month'] > 12) { $gameDate['month'] = 1; $gameDate['year']++; }

    sendJson($gameDate);
}

/* ===== 404 padrão ===== */
sendJson(['message' => 'Rota não encontrada.'], 404);

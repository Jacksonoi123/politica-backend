<?php
/***********************
 * Política Game – API (PHP)
 * Email e senha agora salvos em texto puro
 ***********************/

header("Content-Type: application/json");

// CORS — permita apenas seu frontend
$allowed_origin = "https://politicagame.netlify.app";
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
}
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/* ===== Config ===== */
$DB_DIR     = __DIR__;
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

function findUserByEmail(&$users, $email) {
    foreach ($users as $k => $u) {
        if (isset($u['email']) && $u['email'] === $email) return $k;
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
        if ($economy['crisisCount'] < 3 && ($economy['totalCredits'] ?? 0) <= 0) {
            $economy['crisisCount']++;
            $user['score'] = ($user['score'] ?? 0) - 200;
            if ($economy['crisisCount'] === 3) {
                $user['score'] = 0;
                if (($user['level'] ?? 0) >= 20) $user['role'] = "Ditador";
            }
        }
        $cr = $economy['crisisCount'] ?? 0;
        $user['credits'] = max(0, ($user['credits'] ?? 0) * (1 - $cr * 0.05));
    }
    unset($user);
    return $users;
}

/* ===== Normalização de PATH ===== */
$rawPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

$path = $rawPath;
if ($scriptDir && $scriptDir !== '/' && strpos($path, $scriptDir) === 0) {
    $path = substr($path, strlen($scriptDir));
}
if ($path === '') $path = '/';
if ($scriptName && strpos($path, $scriptName) === 0) {
    $path = substr($path, strlen($scriptName));
}
if (strpos($path, '/server.php') === 0) {
    $path = substr($path, strlen('/server.php'));
}
$path = urldecode($path);
if ($path === '') $path = '/';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$users = readFileJson($USERS_FILE);
$news  = readFileJson($NEWS_FILE);

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
    if (findUserByEmail($users, $email) !== null) {
        sendJson(['message' => 'Email já cadastrado.'], 400);
    }
    $new = [
        'id' => count($users) + 1,
        'name' => $name,
        'email' => $email,
        'password' => $password,
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

    $idx = findUserByEmail($users, $email);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 401);

    $userIP = getUserIP();
    $user = $users[$idx];

    if (($user['lastIP'] ?? null) === $userIP) {
        $users[$idx]['lastIP'] = $userIP;
        writeFileJson($USERS_FILE, $users);
        sendJson(['user' => $users[$idx]]);
    }

    if (!$password) sendJson(['message' => 'Senha é obrigatória.'], 400);
    if (($user['password'] ?? '') !== $password) {
        sendJson(['message' => 'Senha incorreta.'], 401);
    }

    $users[$idx]['lastIP'] = $userIP;
    updateTop30($users, $user['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    writeFileJson($USERS_FILE, $users);
    sendJson(['user' => $users[$idx]]);
}

/** POST /update-score */
if ($path === '/update-score' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $score = $data['score'] ?? null;
    if (!$email || $score === null) sendJson(['message' => 'Dados inválidos.'], 400);

    $idx = findUserByEmail($users, $email);
    if ($idx === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $users[$idx]['score'] = ($users[$idx]['score'] ?? 0) + (int)$score;
    $users[$idx]['level'] = min(floor(($users[$idx]['score'] ?? 0) / 100) + 1, 38);

    updateTop30($users, $users[$idx]['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
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
    sendJson(['top' => array_values(array_slice($topUsers, 0, 30))]);
}

/** GET /news/:nation */
if ($method === 'GET') {
    if (preg_match('#^/news/([^/]+)$#i', $path, $m)) {
        $nation = urldecode($m[1]);
        if (!in_array($nation, $NATIONS)) sendJson(['message' => 'Nação inválida.'], 400);
        $filtered = array_filter($news, fn($n) => isset($n['date']) && $n['date'] >= ($gameDate['year'] - 1));
        sendJson(['news' => array_values($filtered)]);
    }
}

/* ===== 404 padrão ===== */
sendJson(['message' => 'Rota não encontrada.'], 404);

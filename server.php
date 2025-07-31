<?php
header("Content-Type: application/json");

// Permitir CORS só para seu frontend
$allowed_origin = "https://politicagame.netlify.app";
if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
}
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0); // Preflight CORS
}

// Configurações
$DB_DIR = __DIR__;
$USERS_FILE = $DB_DIR . "/usuarios.db";
$NEWS_FILE = $DB_DIR . "/news.db";

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

// Data inicial do jogo
$gameDate = ['year' => 1989, 'month' => 1, 'day' => 1, 'lastUpdate' => round(microtime(true) * 1000)];

// Funções auxiliares

function encryptBase64($str) {
    return base64_encode($str);
}

function getRequestData() {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?: [];
}

function readFileJson($filename) {
    if (!file_exists($filename)) return [];
    $data = file_get_contents($filename);
    return json_decode($data, true) ?: [];
}

function writeFileJson($filename, $data) {
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function getUserIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function findUserByEmail(&$users, $emailBase64) {
    foreach ($users as $k => $user) {
        if ($user['email'] === $emailBase64) {
            return $k;
        }
    }
    return null;
}

function updateTop30(&$users, $nation, $powerLadder, $CARGO_LIMITS) {
    $nationUsers = array_filter($users, fn($u) => $u['nation'] === $nation);
    usort($nationUsers, fn($a, $b) => $b['score'] <=> $a['score']);
    $nationUsers = array_values($nationUsers);

    for ($i = 0; $i < count($nationUsers); $i++) {
        if ($i == 0 && isset($CARGO_LIMITS['Ditador Supremo'])) {
            $nationUsers[$i]['role'] = $powerLadder[40];
        } elseif ($i == 1 && isset($CARGO_LIMITS['Ditador'])) {
            $nationUsers[$i]['role'] = $powerLadder[39];
        } elseif ($i == 2 && isset($CARGO_LIMITS['Monarca'])) {
            $nationUsers[$i]['role'] = $powerLadder[38];
        } else {
            $level = max(1, $nationUsers[$i]['level'] ?? 1);
            $nationUsers[$i]['role'] = $powerLadder[min($level - 1, 37)];
        }
    }

    foreach ($nationUsers as $nu) {
        foreach ($users as &$u) {
            if ($u['id'] == $nu['id']) {
                $u['role'] = $nu['role'];
            }
        }
    }
    return array_slice($nationUsers, 0, 30);
}

function updateEconomy(&$users) {
    $nationEconomies = [];
    foreach ($users as $user) {
        $nation = $user['nation'];
        if (!isset($nationEconomies[$nation])) {
            $nationEconomies[$nation] = ['totalCredits' => 0, 'crisisCount' => 0];
        }
        $nationEconomies[$nation]['totalCredits'] += $user['credits'] ?? 0;
    }

    foreach ($users as &$user) {
        $nation = $user['nation'];
        $economy = &$nationEconomies[$nation];

        if ($economy['totalCredits'] < $economy['totalCredits'] * 0.5 && $economy['crisisCount'] < 3) {
            $economy['crisisCount']++;
            $user['score'] -= 200;
            if ($economy['crisisCount'] === 3) {
                $user['score'] = 0;
                if (($user['level'] ?? 0) >= 20) $user['role'] = "Ditador";
            }
        }
        $user['credits'] = max(0, ($user['credits'] ?? 0) * (1 - $economy['crisisCount'] * 0.05));
    }
    unset($user);
    return $users;
}
$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Função para enviar resposta JSON e finalizar
function sendJson($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Carregar usuários e notícias
$users = readFileJson($USERS_FILE);
$news = readFileJson($NEWS_FILE);

// --- ENDPOINT /register ---
if ($path === '/register' && $method === 'POST') {
    $data = getRequestData();
    $name = $data['name'] ?? null;
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;
    $nation = $data['nation'] ?? null;

    if (!$name || !$email || !$password || !$nation || !in_array($nation, $NATIONS)) {
        sendJson(['message' => 'Dados inválidos.'], 400);
    }

    $encryptedEmail = encryptBase64($email);
    if (findUserByEmail($users, $encryptedEmail) !== null) {
        sendJson(['message' => 'Email já cadastrado.'], 400);
    }

    $encryptedPassword = encryptBase64($password);

    $newUser = [
        'id' => count($users) + 1,
        'name' => $name,
        'email' => $encryptedEmail,
        'password' => $encryptedPassword,
        'nation' => $nation,
        'role' => $powerLadder[0],
        'level' => 1,
        'tutorialCompleted' => false,
        'score' => 0,
        'credits' => 10,
        'skills' => new stdClass(),
        'lastIP' => null
    ];

    $users[] = $newUser;
    writeFileJson($USERS_FILE, $users);
    updateTop30($users, $nation, $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    writeFileJson($USERS_FILE, $users);

    sendJson(['message' => 'Usuário cadastrado!'], 201);
}

// --- ENDPOINT /login ---
if ($path === '/login' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $password = $data['password'] ?? null;
    $userIP = getUserIP();

    if (!$email) sendJson(['message' => 'Email é obrigatório.'], 400);

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);

    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 401);

    $user = $users[$userKey];

    // Login sem senha se IP é o mesmo
    if ($user['lastIP'] === $userIP) {
        $users[$userKey]['lastIP'] = $userIP;
        writeFileJson($USERS_FILE, $users);
        sendJson(['user' => $user]);
    }

    if (!$password) sendJson(['message' => 'Senha é obrigatória.'], 400);
    if ($user['password'] !== encryptBase64($password)) sendJson(['message' => 'Senha incorreta.'], 401);

    $users[$userKey]['lastIP'] = $userIP;
    writeFileJson($USERS_FILE, $users);

    updateTop30($users, $user['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    // checkElections não implementado nesta parte, adicionar depois se quiser

    writeFileJson($USERS_FILE, $users);

    sendJson(['user' => $users[$userKey]]);
}

// --- ENDPOINT /update-score ---
if ($path === '/update-score' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $score = $data['score'] ?? null;

    if (!$email || $score === null) sendJson(['message' => 'Dados inválidos.'], 400);

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);

    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $users[$userKey]['score'] += $score;
    $users[$userKey]['level'] = min(floor($users[$userKey]['score'] / 100) + 1, 38);

    writeFileJson($USERS_FILE, $users);

    updateTop30($users, $users[$userKey]['nation'], $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    // checkElections não implementado nesta parte

    writeFileJson($USERS_FILE, $users);

    sendJson(['user' => $users[$userKey]]);
}

// --- ENDPOINT /global-top ---
if ($path === '/global-top' && $method === 'POST') {
    $data = getRequestData();
    $nation = $data['nation'] ?? null;
    if (!$nation || !in_array($nation, $NATIONS)) sendJson(['message' => 'Nação inválida.'], 400);

    updateTop30($users, $nation, $powerLadder, $CARGO_LIMITS);
    writeFileJson($USERS_FILE, $users);

    $topUsers = array_filter($users, fn($u) => $u['nation'] === $nation);
    usort($topUsers, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = array_slice($topUsers, 0, 30);

    sendJson(['top' => $top]);
}

// --- ENDPOINT /change-nation ---
if ($path === '/change-nation' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $newNation = $data['newNation'] ?? null;

    if (!$email || !$newNation || !in_array($newNation, $NATIONS)) {
        sendJson(['message' => 'Dados inválidos.'], 400);
    }

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);
    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $users[$userKey]['nation'] = $newNation;
    $users[$userKey]['score'] = 0;
    $users[$userKey]['level'] = 1;
    $users[$userKey]['role'] = $powerLadder[0];
    $users[$userKey]['credits'] = 10;

    writeFileJson($USERS_FILE, $users);
    updateTop30($users, $newNation, $powerLadder, $CARGO_LIMITS);
    updateEconomy($users);
    writeFileJson($USERS_FILE, $users);

    sendJson(['message' => 'Nação alterada com sucesso!']);
}

// Para qualquer outra rota:
sendJson(['message' => 'Rota não encontrada.'], 404);
$users = readFileJson($USERS_FILE);
$news = readFileJson($NEWS_FILE);

// Função auxiliar para salvar usuários e notícias atualizados
function saveAll() {
    global $users, $news, $USERS_FILE, $NEWS_FILE;
    writeFileJson($USERS_FILE, $users);
    writeFileJson($NEWS_FILE, $news);
}

// --- ENDPOINT /create-coup ---
if ($path === '/create-coup' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $nation = $data['nation'] ?? null;

    global $users, $powerLadder, $gameDate;

    if (!$email || !$nation || !in_array($nation, $NATIONS)) {
        sendJson(['message' => 'Dados inválidos.'], 400);
    }

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);
    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $user = &$users[$userKey];

    // Cria golpe
    $coup = [
        'leader' => $user['id'],
        'members' => [$user['id']],
        'nation' => $nation,
        'success' => false,
        'scoreThreshold' => 1000
    ];

    // Inicializar coup para todos usuários (se necessário)
    foreach ($users as &$u) {
        if (!isset($u['coup'])) $u['coup'] = null;
    }
    unset($u);

    $user['coup'] = $coup;

    saveAll();

    $news[] = [
        'title' => "Golpe em $nation!",
        'content' => "{$user['name']} iniciou um golpe de estado.",
        'date' => $gameDate['year']
    ];
    writeFileJson($NEWS_FILE, $news);

    sendJson(['message' => 'Golpe criado!']);
}

// --- ENDPOINT /join-coup ---
if ($path === '/join-coup' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $coupId = $data['coupId'] ?? null; // aqui coupId é o ID do líder do golpe

    global $users, $powerLadder, $CARGO_LIMITS, $gameDate;

    if (!$email || !$coupId) sendJson(['message' => 'Dados inválidos.'], 400);

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);
    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $user = &$users[$userKey];

    // Encontrar líder do golpe pelo ID
    $coupLeaderKey = null;
    foreach ($users as $k => $u) {
        if (isset($u['coup']) && $u['coup'] !== null && $u['coup']['leader'] == $coupId) {
            $coupLeaderKey = $k;
            break;
        }
    }

    if ($coupLeaderKey === null) sendJson(['message' => 'Golpe não encontrado.'], 404);

    $coupLeader = &$users[$coupLeaderKey];

    // Adicionar usuário ao golpe se ainda não está
    if (!in_array($user['id'], $coupLeader['coup']['members'])) {
        $coupLeader['coup']['members'][] = $user['id'];
    }
    $user['coup'] = $coupLeader['coup'];

    // Calcular pontuação total do golpe
    $totalScore = 0;
    foreach ($coupLeader['coup']['members'] as $memberId) {
        foreach ($users as $u) {
            if ($u['id'] === $memberId) {
                $totalScore += $u['score'] ?? 0;
                break;
            }
        }
    }

    // Verificar sucesso do golpe
    if ($totalScore >= $coupLeader['coup']['scoreThreshold']) {
        $coupLeader['coup']['success'] = true;

        // Resetar cargos dos top 3
        $topUsers = array_filter($users, fn($u) => $u['nation'] === $coupLeader['nation']);
        usort($topUsers, fn($a, $b) => $b['score'] <=> $a['score']);
        $topUsers = array_slice($topUsers, 0, 3);

        foreach ($topUsers as &$tu) {
            if (in_array($tu['role'], ['Ditador Supremo', 'Ditador', 'Monarca'])) {
                $tu['role'] = $powerLadder[0];
            }
        }
        unset($tu);

        // Vitalícios e Senadores
        foreach ($users as &$u) {
            if ($u['role'] === 'Senador Vitalício' && $u['nation'] === $coupLeader['nation']) {
                $u['role'] = $powerLadder[0];
            }
        }
        unset($u);

        $senators = array_filter($users, fn($u) => $u['role'] === 'Senador' && $u['nation'] === $coupLeader['nation']);
        usort($senators, fn($a, $b) => $b['score'] <=> $a['score']);
        $senators = array_slice($senators, 0, 3);
        foreach ($senators as &$sen) {
            $sen['role'] = 'Senador Vitalício';
        }
        unset($sen);

        // Atualizar líder e segundo colocado
        $coupLeader['role'] = 'Ditador Supremo';
        if (count($coupLeader['coup']['members']) > 1) {
            $secondId = $coupLeader['coup']['members'][1];
            foreach ($users as &$u) {
                if ($u['id'] === $secondId) {
                    $u['role'] = 'Ditador';
                    break;
                }
            }
            unset($u);
        }

        saveAll();

        $news[] = [
            'title' => "Golpe bem-sucedido em {$coupLeader['nation']}!",
            'content' => "{$coupLeader['name']} é o novo Ditador Supremo.",
            'date' => $gameDate['year']
        ];
        writeFileJson($NEWS_FILE, $news);
    }

    saveAll();

    sendJson(['message' => 'Juntou-se ao golpe!']);
}

// --- ENDPOINT GET /news/:nation ---
if (preg_match('#^/news/([^/]+)$#', $path, $matches) && $method === 'GET') {
    $nation = urldecode($matches[1]);
    global $news, $gameDate, $NATIONS;

    if (!in_array($nation, $NATIONS)) sendJson(['message' => 'Nação inválida.'], 400);

    $filteredNews = array_filter($news, fn($n) => isset($n['date']) && $n['date'] >= $gameDate['year'] - 1);
    sendJson(['news' => array_values($filteredNews)]);
}

// --- ENDPOINT /post-news ---
if ($path === '/post-news' && $method === 'POST') {
    $data = getRequestData();
    $email = $data['email'] ?? null;
    $title = $data['title'] ?? null;
    $content = $data['content'] ?? null;
    $isInternational = $data['isInternational'] ?? false;

    global $users, $powerLadder, $NEWS_FILE, $news, $gameDate;

    if (!$email || !$title || !$content) sendJson(['message' => 'Dados inválidos.'], 400);

    $encryptedEmail = encryptBase64($email);
    $userKey = findUserByEmail($users, $encryptedEmail);
    if ($userKey === null) sendJson(['message' => 'Usuário não encontrado.'], 404);

    $user = $users[$userKey];

    $roleIndex = array_search($user['role'], $powerLadder);
    $minRoleIndex = array_search('Prefeito Corrupto', $powerLadder);
    if ($roleIndex === false || $roleIndex < $minRoleIndex) {
        sendJson(['message' => 'Apenas cargos de Prefeito Corrupto ou superior podem postar notícias.'], 403);
    }

    $news[] = [
        'title' => $title,
        'content' => $content,
        'date' => $gameDate['year'],
        'nation' => $user['nation'],
        'isInternational' => $isInternational
    ];
    writeFileJson($NEWS_FILE, $news);

    sendJson(['message' => 'Notícia postada!']);
}

// --- ENDPOINT /game-date ---
if ($path === '/game-date' && $method === 'GET') {
    global $gameDate;

    $now = round(microtime(true) * 1000);
    $elapsedMs = $now - $gameDate['lastUpdate'];

    $msPerDay = 2500; // 2.5 segundos por dia
    $msPerMonth = $msPerDay * 30;
    $msPerYear = $msPerMonth * 12;

    $totalDays = floor($elapsedMs / $msPerDay);
    $yearsPassed = floor($totalDays / 360);
    $remainingDays = $totalDays % 360;
    $monthsPassed = floor($remainingDays / 30);
    $daysPassed = $remainingDays % 30;

    $gameDate['year'] = 1989 + $yearsPassed;
    $gameDate['month'] = 1 + $monthsPassed;
    $gameDate['day'] = 1 + $daysPassed;
    $gameDate['lastUpdate'] = $now;

    if ($gameDate['day'] > 30) {
        $gameDate['day'] = 1;
        $gameDate['month']++;
    }
    if ($gameDate['month'] > 12) {
        $gameDate['month'] = 1;
        $gameDate['year']++;
    }

    sendJson($gameDate);
}
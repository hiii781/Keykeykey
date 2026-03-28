<?php
session_start();

define('DATA_DIR', __DIR__ . '/data/');
define('USERS_FILE', DATA_DIR . 'users.json');
define('KEYS_FILE', DATA_DIR . 'keys.json');
define('INVITES_FILE', DATA_DIR . 'invites.json');
define('AD_CONFIG_FILE', DATA_DIR . 'ad_config.json');
define('SCRIPTS_FILE', DATA_DIR . 'scripts.json');
define('STORE_FILE', DATA_DIR . 'store.json');

define('RECOVERY_USERS', DATA_DIR . 'users_recovery.json');
define('RECOVERY_KEYS', DATA_DIR . 'keys_recovery.json');
define('RECOVERY_INVITES', DATA_DIR . 'invites_recovery.json');
define('RECOVERY_AD', DATA_DIR . 'ad_config_recovery.json');
define('RECOVERY_SCRIPTS', DATA_DIR . 'scripts_recovery.json');
define('RECOVERY_STORE', DATA_DIR . 'store_recovery.json');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

function init_json_files() {
    $default = [];
    $files = [
        USERS_FILE => RECOVERY_USERS,
        KEYS_FILE => RECOVERY_KEYS,
        INVITES_FILE => RECOVERY_INVITES,
        AD_CONFIG_FILE => RECOVERY_AD,
        SCRIPTS_FILE => RECOVERY_SCRIPTS,
        STORE_FILE => RECOVERY_STORE
    ];
    foreach ($files as $file => $recovery) {
        if (!file_exists($file)) {
            file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            file_put_contents($recovery, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
init_json_files();

function load_json($file, $recovery) {
    if (file_exists($file) && filesize($file) > 5) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) return $data;
    }
    if (file_exists($recovery)) {
        return json_decode(file_get_contents($recovery), true) ?: [];
    }
    return [];
}

function save_json($file, $data, $recovery) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents($recovery, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function generate_invite_code() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function generate_key() {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789あいうえおかきくけこさしすせそたちつてとなにぬねのはひふへほまみむめもやゆよらりるれろわをんアイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン';
    $key = '';
    for ($i = 0; $i < 16; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $key;
}

function validate_key($key) {
    $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
    $now = time();
    foreach ($keys as $k) {
        if ($k['key'] === $key && ($k['expiration'] === 0 || $k['expiration'] > $now)) {
            return true;
        }
    }
    return false;
}

$ad_config = load_json(AD_CONFIG_FILE, RECOVERY_AD);
if (empty($ad_config['linkvertise_url'])) {
    $ad_config['linkvertise_url'] = 'https://linkvertise.com/1262024/duckhub?iwantreferrer=aHR0cHM6Ly9hZHMubHVhcm1vci5uZXQvdi9hZHIvZWpZaXpuUUxuVUFQSFhLa1lwRkV2d1lmYkdHQlppTHFHaWI%3D&redirect=https:%2F%2Fads.luarmor.net%2Fv%2Fadr%2FejYiznQLnUAPHXKkYpFEvwYfbGGBZiLqGib';
    save_json(AD_CONFIG_FILE, $ad_config, RECOVERY_AD);
}

$msg = '';

if (isset($_GET['ad_complete']) && is_logged_in()) {
    if (!isset($_SESSION['ad_start_time']) || (time() - $_SESSION['ad_start_time']) < 5) {
        $msg = "❌ 5초 이상 기다린 후 완료 버튼을 눌러주세요.";
    } else {
        $newkey = generate_key();
        $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
        $keys[] = ['key' => $newkey, 'expiration' => time() + 86400, 'created' => time(), 'owner' => $_SESSION['user_id']];
        save_json(KEYS_FILE, $keys, RECOVERY_KEYS);
        $msg = "✅ 광고 시청 완료!<br><b>키: " . htmlspecialchars($newkey) . "</b>";
        unset($_SESSION['ad_step'], $_SESSION['ad_start_time']);
    }
}

if (isset($_POST['start_ad']) && is_logged_in()) {
    $_SESSION['ad_start_time'] = time();
    $link = $ad_config['linkvertise_url'];
    echo "<script>window.location.href = '" . htmlspecialchars($link, ENT_QUOTES) . "';</script>";
    exit;
}

if (isset($_POST['save_ad_link']) && is_logged_in()) {
    $new_link = trim($_POST['linkvertise_url']);
    if (strpos($new_link, 'linkvertise.com') !== false || strpos($new_link, 'luarmor.net') !== false) {
        $ad_config['linkvertise_url'] = $new_link;
        save_json(AD_CONFIG_FILE, $ad_config, RECOVERY_AD);
        $msg = "✅ 광고 링크가 업데이트되었습니다.";
    } else {
        $msg = "❌ 올바른 Linkvertise 링크를 입력해주세요.";
    }
}

if (isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $invite_code = trim($_POST['invite_code'] ?? 'invite1');

    $invites = load_json(INVITES_FILE, RECOVERY_INVITES);
    $valid = false;
    foreach ($invites as &$inv) {
        if ($inv['code'] === $invite_code && !$inv['used']) {
            $valid = true;
            $inv['used'] = true;
            save_json(INVITES_FILE, $invites, RECOVERY_INVITES);
            break;
        }
    }
    if (!$valid) {
        $msg = "❌ Invite Code가 유효하지 않습니다.";
        goto end;
    }

    $users = load_json(USERS_FILE, RECOVERY_USERS);
    foreach ($users as $u) {
        if ($u['username'] === $username) {
            $msg = "❌ 이미 존재하는 아이디입니다.";
            goto end;
        }
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $users[] = ['id' => count($users)+1, 'username' => $username, 'password' => $hash, 'created' => time()];
    save_json(USERS_FILE, $users, RECOVERY_USERS);
    $msg = "✅ 회원가입 완료! 로그인해주세요.";
}

if (isset($_POST['login'])) {
    $users = load_json(USERS_FILE, RECOVERY_USERS);
    foreach ($users as $user) {
        if ($user['username'] === $_POST['username'] && password_verify($_POST['password'], $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: index.php");
            exit;
        }
    }
    $msg = "❌ 아이디 또는 비밀번호가 틀립니다.";
}

if (isset($_POST['generate_invite']) && is_logged_in()) {
    $code = generate_invite_code();
    $invites = load_json(INVITES_FILE, RECOVERY_INVITES);
    $invites[] = ['code' => $code, 'used' => false, 'created' => time(), 'generated_by' => $_SESSION['user_id']];
    save_json(INVITES_FILE, $invites, RECOVERY_INVITES);
    $msg = "✅ Invite Code 생성 완료: <b>$code</b>";
}

if (isset($_POST['save_script']) && is_logged_in()) {
    $name = trim($_POST['script_name'] ?? 'Untitled');
    $content = $_POST['main_script'] ?? '';
    $scripts = load_json(SCRIPTS_FILE, RECOVERY_SCRIPTS);
    $scripts[] = ['id' => uniqid('scr_'), 'user_id' => $_SESSION['user_id'], 'name' => $name, 'content' => $content, 'created' => time()];
    save_json(SCRIPTS_FILE, $scripts, RECOVERY_SCRIPTS);
    $msg = "✅ 스크립트 저장 완료: " . htmlspecialchars($name);
}

if (isset($_POST['create_store']) && is_logged_in()) {
    $name = trim($_POST['store_name'] ?? '');
    $desc = trim($_POST['store_desc'] ?? '');
    if ($name) {
        $newkey = generate_key();
        $store = load_json(STORE_FILE, RECOVERY_STORE);
        $store[] = ['id' => uniqid('store_'), 'name' => $name, 'description' => $desc, 'key' => $newkey, 'claimed' => false, 'created_by' => $_SESSION['user_id'], 'created' => time()];
        save_json(STORE_FILE, $store, RECOVERY_STORE);
        $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
        $keys[] = ['key' => $newkey, 'expiration' => 0, 'created' => time(), 'owner' => $_SESSION['user_id']];
        save_json(KEYS_FILE, $keys, RECOVERY_KEYS);
        $msg = "✅ 영구키가 Store에 등록되었습니다.";
    }
}

if (isset($_POST['generate']) && is_logged_in()) {
    $hours = (int)($_POST['hours'] ?? 24);
    $newkey = generate_key();
    $expiration = $hours > 0 ? time() + ($hours * 3600) : 0;
    $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
    $keys[] = ['key' => $newkey, 'expiration' => $expiration, 'created' => time(), 'owner' => $_SESSION['user_id']];
    save_json(KEYS_FILE, $keys, RECOVERY_KEYS);
    $msg = "✅ 키 생성 완료: <b>$newkey</b>";
}

if (isset($_POST['adjust']) && is_logged_in()) {
    $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
    foreach ($keys as &$k) {
        if ($k['key'] === $_POST['key']) {
            $hours = (int)$_POST['hours'];
            $k['expiration'] = $hours > 0 ? time() + ($hours * 3600) : 0;
        }
    }
    save_json(KEYS_FILE, $keys, RECOVERY_KEYS);
    $msg = "✅ 키 만료 시간이 조정되었습니다.";
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

end:
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Script Key System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background:#0f0f0f; color:#ddd; }
        .card { background:#1a1a1a; border:none; }
        .ad-btn { font-size:1.3rem; padding:18px; }
        .preview { background:#222; padding:12px; border-radius:8px; word-break:break-all; }
    </style>
</head>
<body>
<div class="container mt-4">
    <h1 class="text-center mb-4">🔑 Script Key System</h1>
    
    <?php if ($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <?php if (!is_logged_in()): ?>
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card p-4 mb-4">
                    <h4>로그인</h4>
                    <form method="post">
                        <input type="text" name="username" class="form-control mb-3" placeholder="아이디" required>
                        <input type="password" name="password" class="form-control mb-3" placeholder="비밀번호" required>
                        <button type="submit" name="login" class="btn btn-primary w-100">로그인</button>
                    </form>
                </div>
                
                <div class="card p-4">
                    <h4>회원가입</h4>
                    <form method="post">
                        <input type="text" name="username" class="form-control mb-2" placeholder="아이디" required>
                        <input type="password" name="password" class="form-control mb-2" placeholder="비밀번호" required>
                        <input type="text" name="invite_code" class="form-control mb-3" value="invite1" readonly style="text-transform:lowercase;">
                        <button type="submit" name="register" class="btn btn-success w-100">회원가입</button>
                    </form>
                    <small class="text-muted">Invite Code는 자동으로 입력되어 있습니다. (invite1)</small>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-success d-flex justify-content-between align-items-center">
            <span>환영합니다, <b><?= htmlspecialchars($_SESSION['username']) ?></b>님</span>
            <a href="?logout=1" class="btn btn-danger btn-sm">로그아웃</a>
        </div>

        <ul class="nav nav-tabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#getkey">Get Key</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#adsetting">광고 설정</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#keys">Key 관리</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#script">Script</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#store">Store</a></li>
        </ul>

        <div class="tab-content mt-4">
            <div class="tab-pane fade show active" id="getkey">
                <div class="card p-5 text-center">
                    <h3>광고 1회 시청 후 키 받기</h3>
                    <p class="text-warning">광고를 보고 5초 기다린 후 아래 완료 버튼을 눌러주세요.</p>
                    <form method="post">
                        <button type="submit" name="start_ad" class="btn btn-danger ad-btn w-100 mb-3">🚀 광고 보기</button>
                    </form>
                    <a href="?ad_complete=1" class="btn btn-success btn-lg w-100">✅ 광고 완료하고 키 생성</a>
                </div>
            </div>

            <div class="tab-pane fade" id="adsetting">
                <div class="card p-4">
                    <h4>Linkvertise 광고 링크 설정</h4>
                    <form method="post">
                        <textarea name="linkvertise_url" class="form-control mb-3" rows="5" placeholder="Linkvertise 전체 링크를 붙여넣으세요"><?= htmlspecialchars($ad_config['linkvertise_url']) ?></textarea>
                        <button type="submit" name="save_ad_link" class="btn btn-primary w-100">저장 및 실시간 적용</button>
                    </form>
                    <div class="preview mt-3">
                        <strong>현재 링크:</strong><br>
                        <a href="<?= htmlspecialchars($ad_config['linkvertise_url']) ?>" target="_blank"><?= htmlspecialchars($ad_config['linkvertise_url']) ?></a>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="keys">
                <h4>Key 관리 (만료 시간 조절 가능)</h4>
                <form method="post" class="mb-4">
                    <div class="input-group">
                        <input type="number" name="hours" value="24" class="form-control" style="max-width:180px;">
                        <button type="submit" name="generate" class="btn btn-primary">새 키 생성</button>
                    </div>
                </form>

                <table class="table table-dark">
                    <thead>
                        <tr>
                            <th>키</th>
                            <th>만료 시간</th>
                            <th>시간 조절</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $keys = load_json(KEYS_FILE, RECOVERY_KEYS);
                    if (empty($keys)) {
                        echo "<tr><td colspan='3' class='text-center'>생성된 키가 없습니다.</td></tr>";
                    }
                    foreach ($keys as $k):
                        $exp = $k['expiration'] ? date('Y-m-d H:i', $k['expiration']) : '무제한';
                    ?>
                    <tr>
                        <td><code><?= htmlspecialchars($k['key']) ?></code></td>
                        <td><?= $exp ?></td>
                        <td>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="key" value="<?= htmlspecialchars($k['key']) ?>">
                                <input type="number" name="hours" value="24" style="width:90px;" class="form-control d-inline">
                                <button type="submit" name="adjust" class="btn btn-warning btn-sm">조절</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="tab-pane fade" id="script">
                <h4>개인 스크립트 저장</h4>
                <form method="post">
                    <input type="text" name="script_name" class="form-control mb-2" placeholder="스크립트 이름" required>
                    <textarea name="main_script" class="form-control mb-3" rows="12" placeholder="Lua 스크립트 전체를 붙여넣으세요"></textarea>
                    <button type="submit" name="save_script" class="btn btn-success w-100">저장하기</button>
                </form>
            </div>

            <div class="tab-pane fade" id="store">
                <h4>영구키 Store 등록</h4>
                <form method="post">
                    <input type="text" name="store_name" class="form-control mb-2" placeholder="상품명" required>
                    <textarea name="store_desc" class="form-control mb-3" rows="2" placeholder="설명"></textarea>
                    <button type="submit" name="create_store" class="btn btn-primary w-100">Store에 등록</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

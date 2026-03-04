<?php
/**
 * STORMY MARIE - Admin API
 * Handles uploads, auth, user management, password reset, email verification
 */

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://stormymarie.com', 'https://www.stormymarie.com', 'http://localhost:8000'];
if (in_array($origin, $allowed)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://stormymarie.com');
}
header('Access-Control-Allow-Methods: POST, GET, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration
$docRoot = dirname(__DIR__);
define('UPLOAD_DIR', $docRoot . '/images/');
define('GALLERY_DIR', $docRoot . '/images/gallery/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('USERS_FILE', __DIR__ . '/.users.json');
define('TOKENS_FILE', __DIR__ . '/.tokens.json');
define('RESET_TOKENS_FILE', __DIR__ . '/.reset_tokens.json');
define('VERIFY_TOKENS_FILE', __DIR__ . '/.verify_tokens.json');
define('SITE_URL', 'https://stormymarie.com');
define('FROM_EMAIL', 'wyatt@wyattxxxcole.com');
define('FROM_NAME', 'Stormy Marie Admin');

// Role permissions
define('ROLE_PERMISSIONS', [
    'owner'   => ['all'],
    'manager' => ['upload-logo', 'upload-hero', 'upload-gallery', 'delete-gallery', 'list-gallery', 'get-settings', 'save-settings', 'debug'],
    'editor'  => ['upload-logo', 'upload-hero', 'upload-gallery', 'delete-gallery', 'list-gallery', 'get-settings'],
    'viewer'  => ['list-gallery', 'get-settings']
]);

// ── User Storage ──────────────────────────────────────────────

function getUsers() {
    if (!file_exists(USERS_FILE)) {
        // Bootstrap with default admin
        $defaultUser = [
            'id' => 'owner_' . bin2hex(random_bytes(4)),
            'username' => 'admin',
            'email' => 'wyatt@wyattxxxcole.com',
            'password_hash' => password_hash('WyattAdmin2025!', PASSWORD_BCRYPT),
            'role' => 'owner',
            'email_verified' => true,
            'created' => date('Y-m-d H:i:s'),
            'last_login' => null
        ];
        saveUsers([$defaultUser]);
        return [$defaultUser];
    }
    $data = json_decode(file_get_contents(USERS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
    @chmod(USERS_FILE, 0600);
}

function findUserByUsername($username) {
    foreach (getUsers() as $user) {
        if (strtolower($user['username']) === strtolower($username)) return $user;
    }
    return null;
}

function findUserByEmail($email) {
    foreach (getUsers() as $user) {
        if (strtolower($user['email']) === strtolower($email)) return $user;
    }
    return null;
}

function findUserById($id) {
    foreach (getUsers() as $user) {
        if ($user['id'] === $id) return $user;
    }
    return null;
}

function updateUser($id, $updates) {
    $users = getUsers();
    foreach ($users as &$user) {
        if ($user['id'] === $id) {
            foreach ($updates as $k => $v) {
                $user[$k] = $v;
            }
            saveUsers($users);
            return $user;
        }
    }
    return null;
}

function deleteUser($id) {
    $users = getUsers();
    $users = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
    saveUsers($users);
}

// ── Token Storage ─────────────────────────────────────────────

function getTokens() {
    if (!file_exists(TOKENS_FILE)) return [];
    $data = json_decode(file_get_contents(TOKENS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveTokens($tokens) {
    file_put_contents(TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    @chmod(TOKENS_FILE, 0600);
}

function storeAuthToken($userId, $token) {
    $tokens = getTokens();
    // Remove expired tokens
    $tokens = array_values(array_filter($tokens, fn($t) => $t['expires'] > time()));
    $tokens[] = [
        'token' => $token,
        'user_id' => $userId,
        'expires' => time() + 86400 // 24 hours
    ];
    saveTokens($tokens);
}

function findAuthToken($token) {
    foreach (getTokens() as $t) {
        if ($t['token'] === $token && $t['expires'] > time()) {
            return $t;
        }
    }
    return null;
}

// ── Reset Token Storage ───────────────────────────────────────

function getResetTokens() {
    if (!file_exists(RESET_TOKENS_FILE)) return [];
    $data = json_decode(file_get_contents(RESET_TOKENS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveResetTokens($tokens) {
    file_put_contents(RESET_TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    @chmod(RESET_TOKENS_FILE, 0600);
}

// ── Verify Token Storage ──────────────────────────────────────

function getVerifyTokens() {
    if (!file_exists(VERIFY_TOKENS_FILE)) return [];
    $data = json_decode(file_get_contents(VERIFY_TOKENS_FILE), true);
    return is_array($data) ? $data : [];
}

function saveVerifyTokens($tokens) {
    file_put_contents(VERIFY_TOKENS_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    @chmod(VERIFY_TOKENS_FILE, 0600);
}

// ── Auth ──────────────────────────────────────────────────────

function checkAuth() {
    $headers = getallheaders();
    $token = $headers['Authorization'] ?? $_GET['token'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    if (!$token) return false;

    $tokenData = findAuthToken($token);
    if (!$tokenData) return false;

    $user = findUserById($tokenData['user_id']);
    if (!$user) return false;

    return $user;
}

function requireAuth() {
    $user = checkAuth();
    if (!$user) handleError('Unauthorized', 401);
    return $user;
}

function requireRole($user, $action) {
    $role = $user['role'] ?? 'viewer';
    $perms = ROLE_PERMISSIONS[$role] ?? [];
    if (in_array('all', $perms)) return true;
    if (in_array($action, $perms)) return true;
    handleError('Forbidden: insufficient permissions', 403);
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function handleError($message, $code = 400) {
    respond(['success' => false, 'error' => $message], $code);
}

// ── Email ─────────────────────────────────────────────────────

function sendEmail($to, $subject, $htmlBody) {
    $headers = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . FROM_EMAIL . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: StormyMarie/1.0\r\n";

    return @mail($to, $subject, $htmlBody, $headers);
}

function emailTemplate($title, $bodyContent) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#111;font-family:Arial,sans-serif;">
    <div style="max-width:500px;margin:40px auto;background:#1a0d1a;border:1px solid rgba(255,16,240,0.2);border-radius:12px;padding:40px;text-align:center;">
        <h1 style="color:#ff10f0;font-size:24px;margin:0 0 8px;">' . htmlspecialchars($title) . '</h1>
        <p style="color:#666;font-size:12px;letter-spacing:2px;text-transform:uppercase;margin:0 0 30px;">STORMY MARIE</p>
        <div style="color:#ccc;font-size:14px;line-height:1.6;text-align:left;">' . $bodyContent . '</div>
        <hr style="border:none;border-top:1px solid rgba(255,16,240,0.15);margin:30px 0;">
        <p style="color:#555;font-size:11px;">This is an automated email from stormymarie.com</p>
    </div></body></html>';
}

// ── Ensure directories exist ──────────────────────────────────
if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!file_exists(GALLERY_DIR)) mkdir(GALLERY_DIR, 0755, true);

// ── Route handling ────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // Public routes
    case 'login':
        handleLogin();
        break;
    case 'forgot-password':
        handleForgotPassword();
        break;
    case 'reset-password':
        handleResetPassword();
        break;
    case 'verify-email':
        handleVerifyEmail();
        break;
    case 'list-gallery':
        handleGalleryList();
        break;
    case 'get-settings':
        handleGetSettings();
        break;

    // Auth required routes
    case 'upload-logo':
        $user = requireAuth(); requireRole($user, 'upload-logo');
        handleLogoUpload();
        break;
    case 'upload-hero':
        $user = requireAuth(); requireRole($user, 'upload-hero');
        handleHeroUpload();
        break;
    case 'upload-gallery':
        $user = requireAuth(); requireRole($user, 'upload-gallery');
        handleGalleryUpload();
        break;
    case 'delete-gallery':
        $user = requireAuth(); requireRole($user, 'delete-gallery');
        handleGalleryDelete();
        break;
    case 'save-settings':
        $user = requireAuth(); requireRole($user, 'save-settings');
        handleSaveSettings();
        break;
    case 'change-password':
        $user = requireAuth();
        handleChangePassword($user);
        break;
    case 'me':
        $user = requireAuth();
        respond(['success' => true, 'user' => sanitizeUser($user)]);
        break;

    // Owner-only: delegate management
    case 'list-delegates':
        $user = requireAuth();
        if ($user['role'] !== 'owner') handleError('Forbidden', 403);
        handleListDelegates();
        break;
    case 'add-delegate':
        $user = requireAuth();
        if ($user['role'] !== 'owner') handleError('Forbidden', 403);
        handleAddDelegate();
        break;
    case 'update-delegate':
        $user = requireAuth();
        if ($user['role'] !== 'owner') handleError('Forbidden', 403);
        handleUpdateDelegate();
        break;
    case 'remove-delegate':
        $user = requireAuth();
        if ($user['role'] !== 'owner') handleError('Forbidden', 403);
        handleRemoveDelegate();
        break;
    case 'resend-invite':
        $user = requireAuth();
        if ($user['role'] !== 'owner') handleError('Forbidden', 403);
        handleResendInvite();
        break;

    case 'debug':
        $user = requireAuth();
        respond([
            'success' => true,
            'uploadDir' => UPLOAD_DIR,
            'galleryDir' => GALLERY_DIR,
            'uploadDirExists' => file_exists(UPLOAD_DIR),
            'galleryDirExists' => file_exists(GALLERY_DIR),
            'uploadDirWritable' => is_writable(UPLOAD_DIR),
            'galleryDirWritable' => is_writable(GALLERY_DIR)
        ]);
        break;
    default:
        handleError('Invalid action');
}

// ── Auth Handlers ─────────────────────────────────────────────

function sanitizeUser($user) {
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'email_verified' => $user['email_verified'] ?? false,
        'created' => $user['created'] ?? null,
        'last_login' => $user['last_login'] ?? null
    ];
}

function handleLogin() {
    $rawInput = file_get_contents('php://input');
    $cleanedInput = str_replace('\\!', '!', $rawInput);
    $input = json_decode($cleanedInput, true);

    $username = $input['username'] ?? $_POST['username'] ?? '';
    $password = $input['password'] ?? $_POST['password'] ?? '';

    if (!$username || !$password) {
        handleError('Username and password required', 400);
    }

    $user = findUserByUsername($username);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        handleError('Invalid credentials', 401);
    }

    $token = generateToken();
    storeAuthToken($user['id'], $token);

    // Update last login
    updateUser($user['id'], ['last_login' => date('Y-m-d H:i:s')]);

    respond([
        'success' => true,
        'token' => $token,
        'user' => sanitizeUser($user)
    ]);
}

function handleChangePassword($user) {
    $input = json_decode(file_get_contents('php://input'), true);
    $current = $input['currentPassword'] ?? '';
    $new = $input['newPassword'] ?? '';

    if (!$current || !$new) handleError('Current and new password required');
    if (strlen($new) < 8) handleError('New password must be at least 8 characters');

    if (!password_verify($current, $user['password_hash'])) {
        handleError('Current password is incorrect', 401);
    }

    updateUser($user['id'], ['password_hash' => password_hash($new, PASSWORD_BCRYPT)]);
    respond(['success' => true, 'message' => 'Password updated']);
}

// ── Forgot Password ───────────────────────────────────────────

function handleForgotPassword() {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        handleError('Valid email address required');
    }

    // Always respond success to prevent email enumeration
    $user = findUserByEmail($email);
    if ($user) {
        $token = bin2hex(random_bytes(32));

        // Store reset token with 1-hour expiry
        $tokens = getResetTokens();
        // Remove any existing tokens for this user
        $tokens = array_values(array_filter($tokens, fn($t) => $t['user_id'] !== $user['id']));
        $tokens[] = [
            'token' => $token,
            'user_id' => $user['id'],
            'expires' => time() + 3600
        ];
        saveResetTokens($tokens);

        // Send email
        $resetUrl = SITE_URL . '/api/reset-password.html?token=' . $token;
        $body = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
              . '<p>You requested a password reset for your Stormy Marie admin account.</p>'
              . '<p><a href="' . $resetUrl . '" style="display:inline-block;padding:12px 30px;background:#ff10f0;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Reset Password</a></p>'
              . '<p style="color:#888;font-size:12px;">This link expires in 1 hour. If you didn\'t request this, ignore this email.</p>';

        sendEmail($email, 'Password Reset - Stormy Marie', emailTemplate('Password Reset', $body));
    }

    respond(['success' => true, 'message' => 'If an account with that email exists, a reset link has been sent.']);
}

function handleResetPassword() {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['token'] ?? '';
    $newPassword = $input['password'] ?? '';

    if (!$token) handleError('Reset token required');
    if (!$newPassword || strlen($newPassword) < 8) handleError('Password must be at least 8 characters');

    $tokens = getResetTokens();
    $found = null;
    foreach ($tokens as $t) {
        if ($t['token'] === $token && $t['expires'] > time()) {
            $found = $t;
            break;
        }
    }

    if (!$found) handleError('Invalid or expired reset token', 400);

    $user = findUserById($found['user_id']);
    if (!$user) handleError('User not found', 400);

    // Update password
    updateUser($user['id'], ['password_hash' => password_hash($newPassword, PASSWORD_BCRYPT)]);

    // Remove used token
    $tokens = array_values(array_filter($tokens, fn($t) => $t['token'] !== $token));
    saveResetTokens($tokens);

    respond(['success' => true, 'message' => 'Password has been reset. You can now log in.']);
}

// ── Email Verification ────────────────────────────────────────

function sendVerificationEmail($user) {
    $token = bin2hex(random_bytes(32));

    $tokens = getVerifyTokens();
    $tokens = array_values(array_filter($tokens, fn($t) => $t['user_id'] !== $user['id']));
    $tokens[] = [
        'token' => $token,
        'user_id' => $user['id'],
        'expires' => time() + 86400 // 24 hours
    ];
    saveVerifyTokens($tokens);

    $verifyUrl = SITE_URL . '/api/upload.php?action=verify-email&token=' . $token;
    $body = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
          . '<p>Please verify your email address for the Stormy Marie admin panel.</p>'
          . '<p><a href="' . $verifyUrl . '" style="display:inline-block;padding:12px 30px;background:#ff10f0;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Verify Email</a></p>'
          . '<p style="color:#888;font-size:12px;">This link expires in 24 hours.</p>';

    return sendEmail($user['email'], 'Verify Your Email - Stormy Marie', emailTemplate('Email Verification', $body));
}

function handleVerifyEmail() {
    $token = $_GET['token'] ?? '';
    if (!$token) {
        header('Content-Type: text/html');
        echo '<html><body style="background:#111;color:#ccc;font-family:Arial;text-align:center;padding:60px;"><h2 style="color:#ff10f0;">Invalid Link</h2><p>This verification link is invalid.</p></body></html>';
        exit();
    }

    $tokens = getVerifyTokens();
    $found = null;
    foreach ($tokens as $t) {
        if ($t['token'] === $token && $t['expires'] > time()) {
            $found = $t;
            break;
        }
    }

    header('Content-Type: text/html');

    if (!$found) {
        echo '<html><body style="background:#111;color:#ccc;font-family:Arial;text-align:center;padding:60px;"><h2 style="color:#ff10f0;">Link Expired</h2><p>This verification link has expired. Please request a new one from the admin panel.</p></body></html>';
        exit();
    }

    $user = findUserById($found['user_id']);
    if ($user) {
        updateUser($user['id'], ['email_verified' => true]);
    }

    // Remove token
    $tokens = array_values(array_filter($tokens, fn($t) => $t['token'] !== $token));
    saveVerifyTokens($tokens);

    echo '<html><body style="background:#111;color:#ccc;font-family:Arial;text-align:center;padding:60px;">
        <h2 style="color:#ff10f0;">Email Verified!</h2>
        <p>Your email has been verified successfully.</p>
        <p><a href="' . SITE_URL . '/admin.html" style="color:#ff10f0;">Go to Admin Panel</a></p>
    </body></html>';
    exit();
}

// ── Delegate Management ───────────────────────────────────────

function handleListDelegates() {
    $users = getUsers();
    $delegates = [];
    foreach ($users as $u) {
        $delegates[] = sanitizeUser($u);
    }
    respond(['success' => true, 'users' => $delegates]);
}

function handleAddDelegate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $email = trim($input['email'] ?? '');
    $role = $input['role'] ?? 'viewer';

    if (!$username || strlen($username) < 3) handleError('Username must be at least 3 characters');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) handleError('Valid email required');
    if (!in_array($role, ['manager', 'editor', 'viewer'])) handleError('Invalid role');
    if (findUserByUsername($username)) handleError('Username already taken');
    if (findUserByEmail($email)) handleError('Email already in use');

    // Generate a temporary password
    $tempPassword = bin2hex(random_bytes(6));
    $newUser = [
        'id' => 'delegate_' . bin2hex(random_bytes(4)),
        'username' => $username,
        'email' => $email,
        'password_hash' => password_hash($tempPassword, PASSWORD_BCRYPT),
        'role' => $role,
        'email_verified' => false,
        'created' => date('Y-m-d H:i:s'),
        'last_login' => null,
        'needs_password_change' => true
    ];

    $users = getUsers();
    $users[] = $newUser;
    saveUsers($users);

    // Send invite email
    $loginUrl = SITE_URL . '/admin.html';
    $body = '<p>Hi ' . htmlspecialchars($username) . ',</p>'
          . '<p>You\'ve been invited to the <strong>Stormy Marie</strong> admin panel as a <strong>' . htmlspecialchars($role) . '</strong>.</p>'
          . '<p style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;border:1px solid rgba(255,16,240,0.2);">'
          . '<strong>Username:</strong> ' . htmlspecialchars($username) . '<br>'
          . '<strong>Temporary Password:</strong> ' . htmlspecialchars($tempPassword) . '</p>'
          . '<p><a href="' . $loginUrl . '" style="display:inline-block;padding:12px 30px;background:#ff10f0;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Login Now</a></p>'
          . '<p style="color:#888;font-size:12px;">Please change your password after logging in.</p>';

    sendEmail($email, 'Admin Access Invitation - Stormy Marie', emailTemplate('You\'re Invited', $body));

    // Also send verification email
    sendVerificationEmail($newUser);

    respond(['success' => true, 'user' => sanitizeUser($newUser), 'message' => 'Delegate added. Invite email sent.']);
}

function handleUpdateDelegate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';
    $role = $input['role'] ?? null;

    if (!$id) handleError('User ID required');

    $targetUser = findUserById($id);
    if (!$targetUser) handleError('User not found');
    if ($targetUser['role'] === 'owner') handleError('Cannot modify owner');

    $updates = [];
    if ($role && in_array($role, ['manager', 'editor', 'viewer'])) {
        $updates['role'] = $role;
    }
    if (isset($input['email'])) {
        $email = trim($input['email']);
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($email !== $targetUser['email']) {
                $existing = findUserByEmail($email);
                if ($existing && $existing['id'] !== $id) handleError('Email already in use');
                $updates['email'] = $email;
                $updates['email_verified'] = false;
            }
        }
    }

    if (empty($updates)) handleError('No changes provided');

    $updated = updateUser($id, $updates);

    // Send verification if email changed
    if (isset($updates['email'])) {
        sendVerificationEmail($updated);
    }

    respond(['success' => true, 'user' => sanitizeUser($updated)]);
}

function handleRemoveDelegate() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (!$id) handleError('User ID required');

    $targetUser = findUserById($id);
    if (!$targetUser) handleError('User not found');
    if ($targetUser['role'] === 'owner') handleError('Cannot remove owner');

    deleteUser($id);
    respond(['success' => true, 'message' => 'Delegate removed']);
}

function handleResendInvite() {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = $input['id'] ?? '';

    if (!$id) handleError('User ID required');

    $user = findUserById($id);
    if (!$user) handleError('User not found');

    // Generate new temp password
    $tempPassword = bin2hex(random_bytes(6));
    updateUser($id, ['password_hash' => password_hash($tempPassword, PASSWORD_BCRYPT), 'needs_password_change' => true]);

    $loginUrl = SITE_URL . '/admin.html';
    $body = '<p>Hi ' . htmlspecialchars($user['username']) . ',</p>'
          . '<p>Your credentials for the <strong>Stormy Marie</strong> admin panel have been reset.</p>'
          . '<p style="background:rgba(0,0,0,0.3);padding:15px;border-radius:8px;border:1px solid rgba(255,16,240,0.2);">'
          . '<strong>Username:</strong> ' . htmlspecialchars($user['username']) . '<br>'
          . '<strong>New Password:</strong> ' . htmlspecialchars($tempPassword) . '</p>'
          . '<p><a href="' . $loginUrl . '" style="display:inline-block;padding:12px 30px;background:#ff10f0;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Login Now</a></p>';

    sendEmail($user['email'], 'New Login Credentials - Stormy Marie', emailTemplate('Credentials Reset', $body));

    if (!$user['email_verified']) {
        sendVerificationEmail($user);
    }

    respond(['success' => true, 'message' => 'New credentials sent to ' . $user['email']]);
}

// ── Upload Handlers (unchanged) ───────────────────────────────

function handleLogoUpload() {
    if (!isset($_FILES['file'])) {
        handleError('No file uploaded');
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        handleError('Upload error: ' . $file['error']);
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        handleError('File too large. Max 10MB.');
    }

    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_TYPES)) {
        handleError('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'logo.' . $ext;
    $destination = UPLOAD_DIR . $filename;

    foreach (glob(UPLOAD_DIR . 'logo.*') as $oldFile) {
        unlink($oldFile);
    }

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        respond([
            'success' => true,
            'filename' => $filename,
            'url' => 'images/' . $filename
        ]);
    }

    handleError('Failed to save file');
}

function handleHeroUpload() {
    if (!isset($_FILES['file'])) {
        handleError('No file uploaded');
    }

    $file = $_FILES['file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMsgs = [
            1 => 'File exceeds server limit',
            2 => 'File exceeds form limit',
            3 => 'Partial upload',
            4 => 'No file',
            6 => 'No temp folder',
            7 => 'Write failed',
            8 => 'Extension blocked'
        ];
        handleError('Upload error: ' . ($errorMsgs[$file['error']] ?? $file['error']));
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        handleError('File too large. Max 10MB.');
    }

    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, ALLOWED_TYPES)) {
        handleError('Invalid file type. Allowed: JPG, PNG, GIF, WebP');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'jpeg') $ext = 'jpg';
    $filename = 'hero.' . $ext;
    $destination = UPLOAD_DIR . $filename;

    foreach (glob(UPLOAD_DIR . 'hero.*') as $oldFile) {
        @unlink($oldFile);
    }

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        @chmod($destination, 0644);

        $settingsFile = __DIR__ . '/settings.json';
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
        }
        $settings['heroUrl'] = 'images/' . $filename;
        file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT));

        respond([
            'success' => true,
            'filename' => $filename,
            'url' => 'images/' . $filename . '?v=' . time()
        ]);
    }

    handleError('Failed to save file. Dir: ' . UPLOAD_DIR . ', Writable: ' . (is_writable(UPLOAD_DIR) ? 'yes' : 'no'));
}

function handleGalleryUpload() {
    if (!isset($_FILES['files'])) {
        if (isset($_FILES['file'])) {
            $_FILES['files'] = [
                'name' => [$_FILES['file']['name']],
                'type' => [$_FILES['file']['type']],
                'tmp_name' => [$_FILES['file']['tmp_name']],
                'error' => [$_FILES['file']['error']],
                'size' => [$_FILES['file']['size']]
            ];
        } else {
            handleError('No files uploaded');
        }
    }

    $uploaded = [];
    $errors = [];

    $files = $_FILES['files'];
    $fileCount = count($files['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        $name = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $error = $files['error'][$i];
        $size = $files['size'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "$name: Upload error";
            continue;
        }

        if ($size > MAX_FILE_SIZE) {
            $errors[] = "$name: File too large";
            continue;
        }

        $mimeType = mime_content_type($tmpName);
        if (!in_array($mimeType, ALLOWED_TYPES)) {
            $errors[] = "$name: Invalid file type";
            continue;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $filename = 'gallery_' . time() . '_' . $i . '.' . $ext;
        $destination = GALLERY_DIR . $filename;

        if (move_uploaded_file($tmpName, $destination)) {
            $uploaded[] = [
                'filename' => $filename,
                'url' => 'images/gallery/' . $filename
            ];
        } else {
            $errors[] = "$name: Failed to save";
        }
    }

    respond([
        'success' => count($uploaded) > 0,
        'uploaded' => $uploaded,
        'errors' => $errors
    ]);
}

function handleGalleryDelete() {
    $input = json_decode(file_get_contents('php://input'), true);
    $filename = $input['filename'] ?? $_GET['filename'] ?? '';

    if (empty($filename)) {
        handleError('No filename specified');
    }

    $filename = basename($filename);
    $filepath = GALLERY_DIR . $filename;

    if (!file_exists($filepath)) {
        handleError('File not found');
    }

    if (unlink($filepath)) {
        respond(['success' => true]);
    }

    handleError('Failed to delete file');
}

function handleGalleryList() {
    $images = [];

    if (is_dir(GALLERY_DIR)) {
        $files = glob(GALLERY_DIR . '*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
        foreach ($files as $file) {
            $filename = basename($file);
            $images[] = [
                'filename' => $filename,
                'url' => 'images/gallery/' . $filename,
                'size' => filesize($file),
                'modified' => filemtime($file)
            ];
        }
        usort($images, function($a, $b) {
            return $b['modified'] - $a['modified'];
        });
    }

    respond([
        'success' => true,
        'images' => $images
    ]);
}

function handleGetSettings() {
    $settingsFile = __DIR__ . '/settings.json';

    $defaults = [
        'tagline' => 'Sinfully seductive. Dripping wet. Addictive.',
        'reviewCount' => 132,
        'contactEmail' => 'contact@stormymarie.com',
        'logoUrl' => 'images/logo.png',
        'heroUrl' => ''
    ];

    if (file_exists($settingsFile)) {
        $settings = json_decode(file_get_contents($settingsFile), true);
        $settings = array_merge($defaults, $settings);
    } else {
        $settings = $defaults;
    }

    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (file_exists(UPLOAD_DIR . "hero.$ext")) {
            $settings['heroUrl'] = "images/hero.$ext";
            break;
        }
    }

    respond([
        'success' => true,
        'settings' => $settings
    ]);
}

function handleSaveSettings() {
    $input = json_decode(file_get_contents('php://input'), true);

    $settings = [
        'tagline' => $input['tagline'] ?? 'Sinfully seductive. Dripping wet. Addictive.',
        'reviewCount' => intval($input['reviewCount'] ?? 132),
        'contactEmail' => $input['contactEmail'] ?? 'contact@stormymarie.com'
    ];

    $settingsFile = __DIR__ . '/settings.json';

    if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
        respond(['success' => true, 'settings' => $settings]);
    }

    handleError('Failed to save settings');
}
?>

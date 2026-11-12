<?php
// ============================================================
// auth/student_auth.php
// Student sign-in for the online booking portal.
//  - Identity comes from the fees portal (fee_students) — the
//    current, authoritative student register.
//  - Student sessions use their own keys ($_SESSION['student']) and
//    can never satisfy the admin requireLogin() check.
// ============================================================
require_once __DIR__ . '/session.php';

const STUDENT_IDLE_TIMEOUT   = 1800;   // seconds of inactivity before sign-out
const STUDENT_MAX_FAILS      = 5;      // failures per admission number ...
const STUDENT_MAX_IP_FAILS   = 20;     // ... or per IP, within the window
const STUDENT_FAIL_WINDOW    = 15;     // minutes
const STUDENT_CODE_ALPHABET  = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

function studentNormalizeId(string $value): string
{
    return strtoupper(preg_replace('/\s+/', '', trim($value)));
}

function studentNormalizeSecret(string $value): string
{
    return strtoupper(preg_replace('/[\s\-]+/', '', trim($value)));
}

function studentClientIp(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

// ---------- throttling ----------

function studentIsThrottled(PDO $db, string $type, string $studentId): bool
{
    $byStudent = $db->prepare("
        SELECT COUNT(*) FROM student_auth_attempts
        WHERE attempt_type = ? AND student_id = ?
          AND attempted_at > UTC_TIMESTAMP() - INTERVAL " . STUDENT_FAIL_WINDOW . " MINUTE
    ");
    $byStudent->execute([$type, $studentId]);
    if ((int) $byStudent->fetchColumn() >= STUDENT_MAX_FAILS) {
        return true;
    }
    $byIp = $db->prepare("
        SELECT COUNT(*) FROM student_auth_attempts
        WHERE attempt_type = ? AND ip_address = ?
          AND attempted_at > UTC_TIMESTAMP() - INTERVAL " . STUDENT_FAIL_WINDOW . " MINUTE
    ");
    $byIp->execute([$type, studentClientIp()]);
    return (int) $byIp->fetchColumn() >= STUDENT_MAX_IP_FAILS;
}

function studentRecordFailure(PDO $db, string $type, string $studentId): void
{
    $db->prepare("
        INSERT INTO student_auth_attempts (attempt_type, student_id, ip_address, attempted_at)
        VALUES (?, ?, ?, UTC_TIMESTAMP())
    ")->execute([$type, substr($studentId, 0, 30), studentClientIp()]);
    // keep the table small
    $db->exec("DELETE FROM student_auth_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 2 DAY");
}

function studentClearFailures(PDO $db, string $type, string $studentId): void
{
    $db->prepare("DELETE FROM student_auth_attempts WHERE attempt_type = ? AND student_id = ?")
       ->execute([$type, substr($studentId, 0, 30)]);
}

// ---------- fees portal lookups ----------

/** Latest fees record for an admission number (fees portal is the source of truth). */
function studentFindFeeRecord(PDO $db, string $normalizedId): ?array
{
    $stmt = $db->prepare("
        SELECT fs.fee_student_id, fs.student_id, fs.full_name, fs.programme, fs.national_id,
               fs.is_active, fs.group_id, g.name AS group_name, g.year_label
        FROM fee_students fs
        JOIN fee_groups g ON g.group_id = fs.group_id
        WHERE UPPER(TRIM(fs.student_id)) = ?
        ORDER BY fs.fee_student_id DESC
        LIMIT 1
    ");
    $stmt->execute([$normalizedId]);
    return $stmt->fetch() ?: null;
}

/**
 * Find (or create) the inventory `users` row for a fees student and refresh
 * name / course from fees. Handles students created by admissions provisioning
 * or overpayment forwarding that were never linked.
 */
function studentResolveInventoryUser(PDO $db, array $fee): ?int
{
    $find = $db->prepare("SELECT user_id FROM users WHERE fee_student_id = ? LIMIT 1");
    $find->execute([$fee['fee_student_id']]);
    $userId = $find->fetchColumn();

    if (!$userId) {
        $byAdm = $db->prepare("SELECT user_id FROM users WHERE UPPER(TRIM(student_id)) = ? LIMIT 1");
        $byAdm->execute([studentNormalizeId($fee['student_id'])]);
        $userId = $byAdm->fetchColumn();
        if ($userId) {
            $db->prepare("UPDATE users SET fee_student_id = ? WHERE user_id = ?")
               ->execute([$fee['fee_student_id'], $userId]);
        }
    }

    if (!$userId) {
        require_once __DIR__ . '/../fees/partials/student_sync.php';
        $result = syncToInventory((int) $fee['fee_student_id'], [
            'student_id' => $fee['student_id'],
            'full_name'  => $fee['full_name'],
            'programme'  => $fee['programme'],
            'group_id'   => $fee['group_id'],
            'phone'      => '',
            'is_active'  => (int) $fee['is_active'],
        ], $db);
        $userId = (int) ($result['user_id'] ?? 0);
    }

    if (!$userId) {
        return null;
    }

    // Fees is authoritative for name, course and active status. Tier is left untouched.
    $db->prepare("UPDATE users SET full_name = ?, department = ?, is_active = ? WHERE user_id = ?")
       ->execute([$fee['full_name'], $fee['programme'] ?: null, (int) $fee['is_active'], $userId]);

    return (int) $userId;
}

// ---------- sign-in ----------

/** Returns ['fee' => ..., 'user_id' => ...] on success, or null with $error set. */
function studentAuthenticate(PDO $db, string $rawId, string $password, ?string &$error): ?array
{
    $id = studentNormalizeId($rawId);
    $generic = 'Admission number or password is incorrect. If this is your first time, activate your account first.';

    if ($id === '' || $password === '') {
        $error = 'Enter your admission number and password.';
        return null;
    }
    if (studentIsThrottled($db, 'login', $id)) {
        $error = 'Too many failed attempts. Please wait ' . STUDENT_FAIL_WINDOW . ' minutes and try again.';
        return null;
    }

    $fee = studentFindFeeRecord($db, $id);
    $account = null;
    if ($fee) {
        $stmt = $db->prepare("SELECT password_hash FROM student_accounts WHERE student_id = ?");
        $stmt->execute([$fee['student_id']]);
        $account = $stmt->fetch() ?: null;
    }

    // Always run a hash check so timing doesn't reveal whether the account exists.
    $hash = $account['password_hash'] ?? null;
    $verified = password_verify($password, $hash ?: password_hash('timing-equaliser', PASSWORD_DEFAULT));

    if (!$fee || !$hash || !$verified) {
        studentRecordFailure($db, 'login', $id);
        $error = $generic;
        return null;
    }
    if (!(int) $fee['is_active']) {
        $error = 'Your student record is not active. Contact the fees office.';
        return null;
    }

    $userId = studentResolveInventoryUser($db, $fee);
    if (!$userId) {
        $error = 'Your record could not be linked to the inventory register. Contact inventory staff.';
        return null;
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $db->prepare("UPDATE student_accounts SET password_hash = ? WHERE student_id = ?")
           ->execute([password_hash($password, PASSWORD_DEFAULT), $fee['student_id']]);
    }
    $db->prepare("UPDATE student_accounts SET last_login = UTC_TIMESTAMP() WHERE student_id = ?")
       ->execute([$fee['student_id']]);
    studentClearFailures($db, 'login', $id);

    return ['fee' => $fee, 'user_id' => $userId];
}

function studentStartSession(array $fee, int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['student'] = [
        'student_id'     => $fee['student_id'],
        'fee_student_id' => (int) $fee['fee_student_id'],
        'user_id'        => $userId,
    ];
    $_SESSION['student_last_activity'] = time();
}

function studentEndSession(): void
{
    unset($_SESSION['student'], $_SESSION['student_last_activity'], $_SESSION['student_flash'],
          $_SESSION['student_submit_key']);
    session_regenerate_id(true);
}

function studentRequireLogin(bool $json = false): void
{
    $expired = !empty($_SESSION['student_last_activity'])
        && (time() - $_SESSION['student_last_activity'] > STUDENT_IDLE_TIMEOUT);
    if ($expired) {
        studentEndSession();
    }
    if (empty($_SESSION['student'])) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Your session has expired. Reload the page and sign in again.']);
            exit;
        }
        header('Location: ' . BOOKING_ROOT . '/student/login.php' . ($expired ? '?timeout=1' : ''));
        exit;
    }
    $_SESSION['student_last_activity'] = time();
}

/** Fresh profile from the fees portal + inventory tier rules. Null if not usable. */
function studentLoadProfile(PDO $db): ?array
{
    $session = $_SESSION['student'] ?? null;
    if (!$session) {
        return null;
    }
    $fee = studentFindFeeRecord($db, studentNormalizeId($session['student_id']));
    if (!$fee) {
        return null;
    }
    $stmt = $db->prepare("
        SELECT u.user_id, u.is_active AS user_active, u.fines_owed,
               t.tier_id, t.name AS tier_name, t.can_reserve, t.can_kit, t.equip_loan_hrs, t.max_equipment
        FROM users u
        JOIN tiers t ON t.tier_id = u.tier_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    return array_merge($fee, $user, ['fee_active' => (int) $fee['is_active']]);
}

// ---------- activation ----------

function studentValidateNewPassword(string $password, string $confirm, string $admissionNo): ?string
{
    if (strlen($password) < 8) {
        return 'Choose a password of at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return 'Password must be 72 characters or fewer.';
    }
    if ($password !== $confirm) {
        return 'The two passwords do not match.';
    }
    if (strcasecmp($password, $admissionNo) === 0) {
        return 'Your password cannot be your admission number.';
    }
    return null;
}

/**
 * Sets a student's password. Proof is either:
 *  - a valid staff-issued activation code (first activation or reset), or
 *  - the national ID on file in fees (first activation only).
 * Returns an error string, or null on success.
 */
function studentActivate(PDO $db, string $rawId, string $proof, string $password, string $confirm): ?string
{
    $id = studentNormalizeId($rawId);
    $generic = 'We could not verify those details. Check your admission number and your national ID or activation code.';

    if ($id === '' || trim($proof) === '') {
        return 'Enter your admission number and your national ID or activation code.';
    }
    if ($passwordError = studentValidateNewPassword($password, $confirm, $id)) {
        return $passwordError;
    }
    if (studentIsThrottled($db, 'activate', $id)) {
        return 'Too many failed attempts. Please wait ' . STUDENT_FAIL_WINDOW . ' minutes and try again.';
    }

    $fee = studentFindFeeRecord($db, $id);
    $ok = false;
    if ($fee && (int) $fee['is_active']) {
        $stmt = $db->prepare("
            SELECT password_hash, activation_hash, activation_expires
            FROM student_accounts WHERE student_id = ? FOR UPDATE
        ");
        $db->beginTransaction();
        $stmt->execute([$fee['student_id']]);
        $account = $stmt->fetch() ?: null;
        $now = gmdate('Y-m-d H:i:s');

        $codeOk = $account && $account['activation_hash'] && $account['activation_expires'] > $now
            && hash_equals($account['activation_hash'], hash('sha256', studentNormalizeSecret($proof)));
        $idOk = !$codeOk
            && !($account && $account['password_hash'])          // first activation only
            && trim((string) $fee['national_id']) !== ''
            && hash_equals(studentNormalizeSecret($fee['national_id']), studentNormalizeSecret($proof));

        if ($codeOk || $idOk) {
            $db->prepare("
                INSERT INTO student_accounts (student_id, password_hash, activated_at)
                VALUES (?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
                    activation_hash = NULL, activation_expires = NULL,
                    activated_at = UTC_TIMESTAMP()
            ")->execute([$fee['student_id'], password_hash($password, PASSWORD_DEFAULT)]);
            $ok = true;
        }
        $db->commit();
    }

    if (!$ok) {
        studentRecordFailure($db, 'activate', $id);
        return $generic;
    }
    studentClearFailures($db, 'activate', $id);
    studentClearFailures($db, 'login', $id);
    return null;
}

/** Staff action: issue a one-time activation code (valid 7 days). Returns the plain code once. */
function studentIssueActivationCode(PDO $db, string $studentId): string
{
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= STUDENT_CODE_ALPHABET[random_int(0, strlen(STUDENT_CODE_ALPHABET) - 1)];
    }
    $db->prepare("
        INSERT INTO student_accounts (student_id, activation_hash, activation_expires)
        VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 7 DAY)
        ON DUPLICATE KEY UPDATE activation_hash = VALUES(activation_hash),
                                activation_expires = VALUES(activation_expires)
    ")->execute([$studentId, hash('sha256', $code)]);
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

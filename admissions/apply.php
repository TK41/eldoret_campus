<?php
// ============================================================
// admissions/apply.php   PUBLIC PAGE (no login required)
// Multi-step KIMC application form  streamlined version
// ============================================================
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../shared/formatters.php';

// Kenya administrative + postal reference data used to populate the
// County / Sub-County / Postal Code selects.
// Counties + postal codes: Posta Kenya postal code list / public Kenya
// administrative-unit data, lightly cleaned (deduped, trimmed).
// Sub-counties: derived from the current 290 electoral constituencies
// (constituency == sub-county in almost all of Kenya today), sourced from
// a public counties/constituencies/wards dataset, cross-checked against
// the county list above so every name matches exactly.
const KENYA_COUNTIES = [
    'Baringo', 'Bomet', 'Bungoma', 'Busia', 'Elgeyo Marakwet', 'Embu', 'Garissa', 'Homa Bay', 'Isiolo', 'Kajiado', 'Kakamega', 'Kericho', 'Kiambu', 'Kilifi', 'Kirinyaga', 'Kisii', 'Kisumu', 'Kitui', 'Kwale', 'Laikipia', 'Lamu', 'Machakos', 'Makueni', 'Mandera', 'Marsabit', 'Meru', 'Migori', 'Mombasa', 'Murang\'a', 'Nairobi', 'Nakuru', 'Nandi', 'Narok', 'Nyamira', 'Nyandarua', 'Nyeri', 'Samburu', 'Siaya', 'Taita Taveta', 'Tana River', 'Tharaka Nithi', 'Trans Nzoia', 'Turkana', 'Uasin Gishu', 'Vihiga', 'Wajir', 'West Pokot'
];

const KENYA_SUBCOUNTIES = [
    'Mombasa' => ['Changamwe', 'Jomvu', 'Kisauni', 'Likoni', 'Mvita', 'Nyali'],
    'Kwale' => ['Kinango', 'Lungalunga', 'Matuga', 'Msambweni'],
    'Kilifi' => ['Ganze', 'Kaloleni', 'Kilifi North', 'Kilifi South', 'Magarini', 'Malindi', 'Rabai'],
    'Tana River' => ['Bura', 'Galole', 'Garsen'],
    'Lamu' => ['Lamu East', 'Lamu West'],
    'Taita Taveta' => ['Mwatate', 'Taveta', 'Voi', 'Wundanyi'],
    'Garissa' => ['Balambala', 'Dadaab', 'Fafi', 'Garissa Township', 'Ijara', 'Lagdera'],
    'Wajir' => ['Eldas', 'Tarbaj', 'Wajir East', 'Wajir North', 'Wajir South', 'Wajir West'],
    'Mandera' => ['Banissa', 'Lafey', 'Mandera East', 'Mandera North', 'Mandera South', 'Mandera West'],
    'Marsabit' => ['Laisamis', 'Moyale', 'North Horr', 'Saku'],
    'Isiolo' => ['Isiolo North', 'Isiolo South'],
    'Meru' => ['Buuri', 'Central Imenti', 'Igembe Central', 'Igembe North', 'Igembe South', 'North Imenti', 'South Imenti', 'Tigania East', 'Tigania West'],
    'Tharaka Nithi' => ['Chuka/Igambang\'Om', 'Maara', 'Tharaka'],
    'Embu' => ['Manyatta', 'Mbeere North', 'Mbeere South', 'Runyenjes'],
    'Kitui' => ['Kitui Central', 'Kitui East', 'Kitui Rural', 'Kitui South', 'Kitui West', 'Mwingi Central', 'Mwingi North', 'Mwingi West'],
    'Machakos' => ['Kangundo', 'Kathiani', 'Machakos Town', 'Masinga', 'Matungulu', 'Mavoko', 'Mwala', 'Yatta'],
    'Makueni' => ['Kaiti', 'Kibwezi East', 'Kibwezi West', 'Kilome', 'Makueni', 'Mbooni'],
    'Nyandarua' => ['Kinangop', 'Kipipiri', 'Ndaragwa', 'Ol Jorok', 'Ol Kalou'],
    'Nyeri' => ['Kieni', 'Mathira', 'Mukurweini', 'Nyeri Town', 'Othaya', 'Tetu'],
    'Kirinyaga' => ['Gichugu', 'Kirinyaga Central', 'Mwea', 'Ndia'],
    'Murang\'a' => ['Gatanga', 'Kandara', 'Kangema', 'Kigumo', 'Kiharu', 'Maragwa', 'Mathioya'],
    'Kiambu' => ['Gatundu North', 'Gatundu South', 'Githunguri', 'Juja', 'Kabete', 'Kiambaa', 'Kiambu', 'Kikuyu', 'Lari', 'Limuru', 'Ruiru', 'Thika Town'],
    'Turkana' => ['Loima', 'Turkana Central', 'Turkana East', 'Turkana North', 'Turkana South', 'Turkana West'],
    'West Pokot' => ['Kacheliba', 'Kapenguria', 'Pokot South', 'Sigor'],
    'Samburu' => ['Samburu East', 'Samburu North', 'Samburu West'],
    'Trans Nzoia' => ['Cherangany', 'Endebess', 'Kiminini', 'Kwanza', 'Saboti'],
    'Uasin Gishu' => ['Ainabkoi', 'Kapseret', 'Kesses', 'Moiben', 'Soy', 'Turbo'],
    'Nakuru' => ['Bahati', 'Gilgil', 'Kuresoi North', 'Kuresoi South', 'Molo', 'Naivasha', 'Nakuru East', 'Nakuru West', 'Njoro', 'Rongai', 'Subukia'],
    'Nairobi' => ['Dagoretti North', 'Dagoretti South', 'Embakasi Central', 'Embakasi East', 'Embakasi North', 'Embakasi South', 'Embakasi West', 'Kamukunji', 'Kasarani', 'Kibra', 'Langata', 'Makadara', 'Mathare', 'Roysambu', 'Ruaraka', 'Starehe', 'Westlands'],
    'Elgeyo Marakwet' => ['Keiyo North', 'Keiyo South', 'Marakwet East', 'Marakwet West'],
    'Kakamega' => ['Butere', 'Khwisero', 'Likuyani', 'Lugari', 'Matungu', 'Mumias East', 'Mumias West', 'Nambale', 'Navakholo', 'Shinyalu'],
    'Homa Bay' => ['Kabondo Kasipul', 'Karachuonyo', 'Kasipul', 'Mbita', 'Ndhiwa', 'Rangwe', 'Suba'],
    'Busia' => ['Butula', 'Funyula', 'Nambale', 'Teso North', 'Teso South', 'Ugenya', 'Ugunja'],
    'Siaya' => ['Alego Usonga', 'Bondo', 'Gem', 'Rarieda', 'Ugenya', 'Ugunja'],
    'Migori' => ['Awendo', 'Kuria East', 'Kuria West', 'Nyatike', 'Rongo', 'Suna East', 'Suna West'],
    'Kisii' => ['Bobasi', 'Bomachoge Borabu', 'Bomachoge Chache', 'Kitutu Chache North', 'Kitutu Chache South', 'Nyaribari Chache', 'Nyaribari Masaba', 'South Mugirango'],
    'Nyamira' => ['Borabu', 'Manga', 'Masaba North', 'North Mugirango', 'West Mugirango'],
    'Bungoma' => ['Bumula', 'Butula', 'Kanduyi', 'Kimilili', 'Mt Elgon', 'Sirisia', 'Webuye East', 'Webuye West'],
    'Vihiga' => ['Emuhaya', 'Hamisi', 'Luanda', 'Sabatia', 'Vihiga']
];

const KENYA_POSTAL_CODES = ['00100', '00101', '00200', '00216', '00500', '00501', '00502', '00600', '00603', '01000', '02000', '03000', '04000', '05000', '06000', '07000', '08000', '09000'];
if (is_file(__DIR__ . '/intake.php')) {
    require_once __DIR__ . '/intake.php';
} elseif (!function_exists('getNextAdmissionsIntake')) {
    function getNextAdmissionsIntake($today = null): array {
        $today = $today ?: new DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $currentMonth = (int) $today->format('n');
        foreach ([3 => 'March', 5 => 'May', 9 => 'September'] as $month => $name) {
            if ($month >= $currentMonth) {
                return ['month' => $month, 'name' => $name, 'year' => $year, 'label' => $name . ' ' . $year];
            }
        }
        return ['month' => 3, 'name' => 'March', 'year' => $year + 1, 'label' => 'March ' . ($year + 1)];
    }
}

$nextIntake = getNextAdmissionsIntake();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function csrfOk(): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '');
}

//  Idempotency token 
// A per-page-load token that makes the final submission safe to retry.
// If a slow connection causes the browser (or an over-eager student) to
// send the exact same POST twice, we recognise the repeat by this token
// and replay the original result instead of creating a second admissions
// row / a second uq_ref collision. This never touches the database schema
//  it only lives in the PHP session.
if (empty($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(16));
}
if (!isset($_SESSION['processed_form_tokens']) || !is_array($_SESSION['processed_form_tokens'])) {
    $_SESSION['processed_form_tokens'] = [];
}

ini_set('display_errors', 0); error_reporting(0);

//  Field-format validation helpers 
// The same field doubles as National ID, Birth Certificate No., or Passport
// No., so digits-only is too strict  Kenyan passports are typically 12
// letters followed by 68 digits. Accept 69 alphanumeric characters that
// include at least one digit, which covers ID (89 digits), most birth
// certificate serials, and typical passport formats without accepting
// obvious junk (pure letters, or anything wildly too short/long).
function isValidIdentityNumber(string $v): bool {
    return (bool) preg_match('/^(?=.*\d)[A-Za-z0-9]{6,9}$/', $v);
}

// Strips spaces, dashes and a leading +254/254 so "0712 345 678" and
// "+254712345678" both normalise to a plain local-format string for
// storage and validation.
function normalizeKenyanPhone(string $v): string {
    $v = preg_replace('/[\s\-]/', '', $v);
    if (str_starts_with($v, '+254')) $v = '0' . substr($v, 4);
    elseif (str_starts_with($v, '254')) $v = '0' . substr($v, 3);
    return $v;
}
// Kenyan mobile numbers used for admissions contact: 10 digits, starting 07 or 01.
function isValidKenyanPhone(string $v): bool {
    return (bool) preg_match('/^(07|01)\d{8}$/', $v);
}

const MIN_APPLICANT_AGE = 15;
const MAX_APPLICANT_AGE = 100;

// Returns null if the DOB is acceptable, or an error message string if not.
function validateDob(string $dob): ?string {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $dob);
    if (!$d || $d->format('Y-m-d') !== $dob) {
        return 'Date of Birth is not a valid date.';
    }
    $today = new DateTimeImmutable('today');
    if ($d >= $today) {
        return 'Date of Birth cannot be today or in the future.';
    }
    $age = $today->diff($d)->y;
    if ($age < MIN_APPLICANT_AGE) {
        return 'Applicant must be at least ' . MIN_APPLICANT_AGE . ' years old.';
    }
    if ($age > MAX_APPLICANT_AGE) {
        return 'Please check the Date of Birth entered  the age calculated seems too high.';
    }
    return null;
}

$errors  = [];
$success = false;
$refNo   = '';
$updateMode = false;
$existingApp = null;
$existingDocs = [];

// Existing applications are edited only after reference number + DOB lookup.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['update']) && !empty($_GET['ref']) && !empty($_GET['dob'])) {
    try {
        $lookupDb = getDB();
        $lookup = $lookupDb->prepare('SELECT * FROM admissions WHERE reference_no = ? AND date_of_birth = ?');
        $lookup->execute([strtoupper(trim($_GET['ref'])), trim($_GET['dob'])]);
        $existingApp = $lookup->fetch();
        if ($existingApp) {
            $updateMode = true;
            $refNo = $existingApp['reference_no'];
            $docLookup = $lookupDb->prepare('SELECT * FROM admission_documents WHERE admission_id = ? ORDER BY doc_id');
            $docLookup->execute([$existingApp['admission_id']]);
            $existingDocs = $docLookup->fetchAll();
            $_POST = array_merge($_POST, [
                'program' => $existingApp['programme_type'],
                'study_mode' => $existingApp['study_mode'],
                'surname' => $existingApp['surname'],
                'middle_name' => $existingApp['middle_name'],
                'first_name' => $existingApp['first_name'],
                'date_of_birth' => $existingApp['date_of_birth'],
                'gender' => $existingApp['gender'],
                'nationality' => $existingApp['nationality'],
                'national_id' => $existingApp['national_id'],
                'mobile_no' => $existingApp['mobile_no'],
                'email' => $existingApp['email'],
                'po_box' => $existingApp['po_box'],
                'postal_code' => $existingApp['postal_code'],
                'city_town' => $existingApp['city_town'],
                'county' => $existingApp['county'],
                'sub_county' => $existingApp['sub_county'],
                'heard_via' => $existingApp['heard_via'],
                'declaration' => '1',
            ]);
        }
    } catch (Throwable $e) {
        error_log('Admissions update lookup error: ' . $e->getMessage());
    }
}

function parseIniSize(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $num  = (int) $value;
    if ($unit === 'g') return $num * 1024 * 1024 * 1024;
    if ($unit === 'm') return $num * 1024 * 1024;
    if ($unit === 'k') return $num * 1024;
    return $num;
}

function getUploadLimits(): array {
    return [
        'post_max' => parseIniSize(ini_get('post_max_size') ?: '8M'),
        'file_max' => parseIniSize(ini_get('upload_max_filesize') ?: '2M'),
    ];
}

function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return formatNumber($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return formatNumber($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return formatNumber($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

define('MAX_FILE_BYTES', 5 * 1024 * 1024);

function uploadFieldLabel(string $field): string {
    return [
        'doc_application_form' => 'Scanned Application Form',
        'doc_kcse'             => 'KCSE Certificate',
        'doc_kcpe'             => 'KCPE Certificate',
        'doc_birth_cert'       => 'Birth Certificate',
        'doc_national_id'      => 'National ID (Both Sides)',
    ][$field] ?? $field;
}

function handleUpload(string $field, string $docType, int $admId, PDO $db): ?string {
    if (empty($_FILES[$field]['tmp_name'])) return null;
    $f = $_FILES[$field];
    $label = uploadFieldLabel($field);
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => "is larger than the server limit of " . formatBytes(getUploadLimits()['file_max']) . ".",
            UPLOAD_ERR_FORM_SIZE  => 'is larger than the 5 MB limit.',
            UPLOAD_ERR_PARTIAL    => 'was only partially uploaded. Please choose it again.',
            UPLOAD_ERR_NO_FILE    => 'was not selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'could not be processed because the temporary upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'could not be saved by the server.',
            UPLOAD_ERR_EXTENSION  => 'was blocked by a server extension.',
        ];
        return "$label upload failed: " . ($uploadErrors[$f['error']] ?? "unknown upload error (code {$f['error']})");
    }

    //  Size check 
    $maxBytes = 5 * 1024 * 1024;
    if ($f['size'] > $maxBytes) return "$label exceeds the 5 MB limit. Choose a smaller PDF.";

    //  Extension check (first line of defence on mobile) 
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return "$label must be a PDF file. You selected a .$ext file.";
    }

    //  MIME check (server-side, handles spoofed extensions) 
    $mime = null;
    try {
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($f['tmp_name']) ?: null;
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($f['tmp_name']) ?: null;
        }
    } catch (Throwable $e) {
        // The PDF signature check below remains authoritative if MIME detection is unavailable.
        $mime = null;
    }
    if ($mime !== null && $mime !== 'application/pdf') {
        return "$label must be a valid PDF. The uploaded file appears to be {$mime}.";
    }

    //  Magic bytes check (reads actual file header  most reliable) 
    $handle = @fopen($f['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 5) : false;
    if ($handle) fclose($handle);
    if ($header !== '%PDF-') {
        return "$label must be a valid PDF. The selected file is not a readable PDF.";
    }

    //  Save file 
    $stored    = bin2hex(random_bytes(16)) . '.pdf';
    $uploadDir = __DIR__ . '/../uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    if (!move_uploaded_file($f['tmp_name'], $uploadDir . $stored))
        return "$label could not be saved. Please try uploading it again.";

    $db->prepare("
        INSERT INTO admission_documents (admission_id, doc_type, original_name, stored_name, file_size, mime_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$admId, $docType, $f['name'], $stored, $f['size'], $mime]);

    return null;
}

function hasExistingDocument(array $existingDocs, string $docType): bool {
    foreach ($existingDocs as $doc) {
        if ($doc['doc_type'] === $docType) return true;
    }
    return false;
}

function removeExistingDocument(string $docType, int $admId, PDO $db): void {
    $stmt = $db->prepare('SELECT stored_name FROM admission_documents WHERE admission_id = ? AND doc_type = ?');
    $stmt->execute([$admId, $docType]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $storedName) {
        $path = __DIR__ . '/../uploads/' . basename($storedName);
        if (is_file($path)) @unlink($path);
    }
    $db->prepare('DELETE FROM admission_documents WHERE admission_id = ? AND doc_type = ?')->execute([$admId, $docType]);
}

//  POST handler 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updateMode = ($_POST['update_mode'] ?? '') === '1';
    $isAjax = !empty($_POST['ajax']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

    // Replay check: if this exact form_token was already processed into a
    // successful application, don't run validation/insert again  just
    // hand back the same reference number. This is what makes it safe to
    // remove the "auto-resubmit on network error" behaviour in the JS below.
    $submittedToken = trim($_POST['form_token'] ?? '');
    $isReplay = (!$updateMode && $submittedToken !== '' && isset($_SESSION['processed_form_tokens'][$submittedToken]));

    if ($isReplay) {
        $replay  = $_SESSION['processed_form_tokens'][$submittedToken];
        $success = true;
        $refNo   = $replay['ref'];
        $_SESSION['admission_success_ref'] = $refNo;
        $_SESSION['admission_dob'] = $replay['dob'];
    } else {

    if ($updateMode) {
        $refNo = strtoupper(trim($_POST['reference_no'] ?? ''));
        $lookupDob = trim($_POST['lookup_dob'] ?? '');
        try {
            $db = getDB();
            $lookup = $db->prepare('SELECT * FROM admissions WHERE reference_no = ? AND date_of_birth = ?');
            $lookup->execute([$refNo, $lookupDob]);
            $existingApp = $lookup->fetch();
            if (!$existingApp) {
                $errors[] = 'Unable to verify this application. Please return to the status page and try again.';
            } else {
                $docLookup = $db->prepare('SELECT * FROM admission_documents WHERE admission_id = ? ORDER BY doc_id');
                $docLookup->execute([$existingApp['admission_id']]);
                $existingDocs = $docLookup->fetchAll();
            }
        } catch (Throwable $e) {
            error_log('Admissions update verification error: ' . $e->getMessage());
            $errors[] = 'Unable to verify this application at this time. Please try again later.';
        }
    }
    $limits = getUploadLimits();
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $contentLength > $limits['post_max']) {
        $errors[] = 'Your upload exceeds the server POST limit of ' . formatBytes($limits['post_max']) . '. Reduce file size or contact the administrator.';
    }

    if (!csrfOk()) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    }

    // Required fields
    $required = [
        'surname'        => 'Surname',
        'first_name'     => 'First name',
        'date_of_birth'  => 'Date of Birth',
        'nationality'    => 'Nationality',
        'gender'         => 'Gender',
        'national_id'    => 'National ID / Passport / Birth Certificate No.',
        'mobile_no'      => 'Mobile number',
        'email'          => 'Email Address',
        'po_box'         => 'P.O. Box',
        'postal_code'    => 'Postal Code',
        'city_town'      => 'City / Town',
        'county'         => 'County',
        'sub_county'     => 'Sub-County',
        'program'        => 'Programme',
        'heard_via'      => 'How you heard about us',
        'declaration'    => 'Declaration agreement',
    ];
    foreach ($required as $k => $label) {
        if (empty(trim($_POST[$k] ?? ''))) $errors[] = "$label is required.";
    }
    if (trim($_POST['heard_via'] ?? '') === 'other' && empty(trim($_POST['heard_other'] ?? ''))) {
        $errors[] = 'Please provide details for how you heard about us.';
    }

    //  Format validation (only checked when the field was actually filled in 
    //    the "required" loop above already flags empty fields) 
    $nationalIdInput = trim($_POST['national_id'] ?? '');
    if ($nationalIdInput !== '' && !isValidIdentityNumber($nationalIdInput)) {
        $errors[] = 'National ID / Passport / Birth Certificate No. must be 69 letters/numbers, including at least one digit.';
    }

    $normalizedPhone = normalizeKenyanPhone(trim($_POST['mobile_no'] ?? ''));
    if ($normalizedPhone !== '' && !isValidKenyanPhone($normalizedPhone)) {
        $errors[] = 'Mobile number must be 10 digits, starting with 07 or 01.';
    }

    $emailInput = trim($_POST['email'] ?? '');
    if ($emailInput !== '' && !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    $dobInput = trim($_POST['date_of_birth'] ?? '');
    if ($dobInput !== '') {
        $dobError = validateDob($dobInput);
        if ($dobError) $errors[] = $dobError;
    }

    // Required documents. During an update, an existing document satisfies the requirement.
    $reqDocs = [
        'doc_application_form' => 'Scanned Application Form',
        'doc_kcse'             => 'KCSE Certificate',
        'doc_kcpe'             => 'KCPE Certificate',
    ];
    foreach ($reqDocs as $field => $label) {
        $errorCode = $_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE;
        $hasFile   = !empty($_FILES[$field]['tmp_name']) && $errorCode === UPLOAD_ERR_OK;
        $docType = [
            'doc_application_form' => 'application_form',
            'doc_kcse' => 'kcse_cert',
            'doc_kcpe' => 'kcpe_cert',
        ][$field];
        if (!$hasFile && (!$updateMode || !hasExistingDocument($existingDocs, $docType))) {
            $errors[] = "$label is required.";
        }
    }
    // Birth cert OR national ID  at least one
    $hasBirth = isset($_FILES['doc_birth_cert']) && !empty($_FILES['doc_birth_cert']['tmp_name']) && $_FILES['doc_birth_cert']['error'] === UPLOAD_ERR_OK;
    $hasId    = isset($_FILES['doc_national_id'])  && !empty($_FILES['doc_national_id']['tmp_name']) && $_FILES['doc_national_id']['error'] === UPLOAD_ERR_OK;
    if (!$hasBirth && !$hasId && (!$updateMode || (!hasExistingDocument($existingDocs, 'birth_cert') && !hasExistingDocument($existingDocs, 'national_id'))))
        $errors[] = 'Please upload at least one of: Birth Certificate or National ID.';

    if (empty($errors) && !$updateMode) {
        $nationalId = trim($_POST['national_id'] ?? '');
        try {
            $db = getDB();
            $stmt = $db->prepare('SELECT COUNT(*) FROM admissions WHERE national_id = ?');
            $stmt->execute([$nationalId]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'An application with this ID number has already been submitted.';
            }
        } catch (Throwable $e) {
            error_log('Admissions duplicate check error: ' . $e->getMessage());
            $errors[] = 'Unable to validate your application at this time. Please try again later.';
        }
    }

    if (empty($errors)) {
        $program = trim($_POST['program'] ?? '');
        if ($program === 'certificate') {
            $programme_type = 'certificate';
            $programme_name = 'Certificate in Film Production (Module Based)';
        } elseif ($program === 'diploma') {
            $programme_type = 'diploma';
            $programme_name = 'Upgrading Diploma in Film Production';
        } else {
            $errors[] = 'Invalid programme selected.';
        }
    }

    if (empty($errors)) {
        $db = null;
        try {
            $db = getDB();
            $db->beginTransaction();

            if ($updateMode) {
                $admId = (int) $existingApp['admission_id'];
                $programme_type = trim($_POST['program'] ?? '');
                $programme_name = $programme_type === 'certificate'
                    ? 'Certificate in Film Production (Module Based)'
                    : 'Upgrading Diploma in Film Production';
                $db->prepare("UPDATE admissions SET programme_type=?, programme_name=?, study_mode=?, surname=?, middle_name=?, first_name=?, date_of_birth=?, gender=?, nationality=?, national_id=?, mobile_no=?, email=?, po_box=?, postal_code=?, city_town=?, county=?, sub_county=?, heard_via=?, declaration_agreed=1 WHERE admission_id=?")
                    ->execute([
                        $programme_type, $programme_name, trim($_POST['study_mode'] ?? 'regular'),
                        trim($_POST['surname']), trim($_POST['middle_name'] ?? ''), trim($_POST['first_name']),
                        $_POST['date_of_birth'] ?: null, $_POST['gender'] ?? null, trim($_POST['nationality'] ?? ''),
                        $nationalIdInput, $normalizedPhone, $emailInput,
                        trim($_POST['po_box'] ?? ''), trim($_POST['postal_code'] ?? ''), trim($_POST['city_town'] ?? ''),
                        trim($_POST['county'] ?? ''), trim($_POST['sub_county'] ?? ''),
                        trim($_POST['heard_via'] ?? '') === 'other' ? trim($_POST['heard_other'] ?? '') : trim($_POST['heard_via'] ?? ''),
                        $admId,
                    ]);
                $docMap = [
                    'doc_application_form' => 'application_form',
                    'doc_kcse' => 'kcse_cert',
                    'doc_kcpe' => 'kcpe_cert',
                    'doc_birth_cert' => 'birth_cert',
                    'doc_national_id' => 'national_id',
                ];
                foreach ($docMap as $field => $type) {
                    if (!empty($_FILES[$field]['tmp_name'])) {
                        removeExistingDocument($type, $admId, $db);
                        $err = handleUpload($field, $type, $admId, $db);
                        if ($err) $errors[] = $err;
                    }
                }
                if (empty($errors)) {
                    $db->commit();
                    $success = true;
                    $_SESSION['admission_success_ref'] = $refNo;
                    $_SESSION['admission_dob'] = trim($_POST['date_of_birth'] ?? '');
                } else {
                    $db->rollBack();
                }
            } else {
            // Reference number: KIMC-YYYY-NNNNN
            //
            // Generated with a retry loop rather than a single COUNT()+1 read.
            // Two applicants submitting at nearly the same instant can otherwise
            // both read the same "next" count before either INSERT commits, both
            // compute the identical reference number, and the second one then
            // fails with a uq_ref duplicate-key error (this was the original bug).
            // MySQL/InnoDB does not require the transaction to be rolled back
            // after a duplicate-key error, so we can simply try a new candidate
            // number and re-attempt the same INSERT inside the same transaction.
            $year = date('Y');
            $admId = null;
            $refNo = '';
            $maxRefAttempts = 8;

            for ($refAttempt = 0; $refAttempt < $maxRefAttempts; $refAttempt++) {
                $count = $db->query("SELECT COUNT(*) FROM admissions WHERE YEAR(submitted_at)=$year")->fetchColumn();
                $candidateRef = 'KIMC-' . $year . '-' . str_pad($count + 1 + $refAttempt, 5, '0', STR_PAD_LEFT);

                try {
                    $db->prepare("
                        INSERT INTO admissions (
                            reference_no, programme_type, programme_name, study_mode,
                            surname, middle_name, first_name,
                            date_of_birth, gender, nationality, national_id,
                            mobile_no, email,
                            po_box, postal_code, city_town, county, sub_county,
                            heard_via, declaration_agreed, ip_address
                        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $candidateRef,
                        $programme_type,
                        $programme_name,
                        trim($_POST['study_mode'] ?? 'regular'),
                        trim($_POST['surname']),
                        trim($_POST['middle_name'] ?? ''),
                        trim($_POST['first_name']),
                        $_POST['date_of_birth'] ?: null,
                        $_POST['gender'] ?? null,
                        trim($_POST['nationality'] ?? ''),
                        $nationalIdInput,
                        $normalizedPhone,
                        $emailInput,
                        trim($_POST['po_box'] ?? ''),
                        trim($_POST['postal_code'] ?? ''),
                        trim($_POST['city_town'] ?? ''),
                        trim($_POST['county'] ?? ''),
                        trim($_POST['sub_county'] ?? ''),
                        trim($_POST['heard_via'] ?? '') === 'other'
                            ? trim($_POST['heard_other'] ?? '')
                            : trim($_POST['heard_via'] ?? ''),
                        1,
                        $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                    $refNo = $candidateRef;
                    $admId = (int) $db->lastInsertId();
                    break;
                } catch (PDOException $dupErr) {
                    $isRefCollision = $dupErr->getCode() === '23000'
                        && stripos($dupErr->getMessage(), 'uq_ref') !== false;
                    if (!$isRefCollision) {
                        throw $dupErr;
                    }
                    // Another submission grabbed this exact number a moment ago 
                    // recompute and try the next candidate rather than failing.
                    continue;
                }
            }

            if ($admId === null) {
                throw new RuntimeException('Could not generate a unique reference number after ' . $maxRefAttempts . ' attempts.');
            }

            // Upload documents
            $docMap = [
                'doc_application_form' => 'application_form',
                'doc_kcse'             => 'kcse_cert',
                'doc_kcpe'             => 'kcpe_cert',
                'doc_birth_cert'       => 'birth_cert',
                'doc_national_id'      => 'national_id',
            ];
            foreach ($docMap as $field => $type) {
                $err = handleUpload($field, $type, $admId, $db);
                if ($err) $errors[] = $err;
            }

            if (empty($errors)) {
                $db->commit();
                $success = true;
                $_SESSION['admission_success_ref'] = $refNo;
                $_SESSION['admission_dob'] = trim($_POST['date_of_birth'] ?? '');

                // Remember this token as processed so a duplicate resubmission
                // (double-click, browser retry, flaky network) replays this
                // same result instead of creating a second application.
                if ($submittedToken !== '') {
                    $_SESSION['processed_form_tokens'][$submittedToken] = [
                        'ref' => $refNo,
                        'dob' => trim($_POST['date_of_birth'] ?? ''),
                    ];
                    // Keep the session small  we only ever need to recognise recent retries.
                    if (count($_SESSION['processed_form_tokens']) > 5) {
                        array_shift($_SESSION['processed_form_tokens']);
                    }
                    // A genuinely new application load should get a fresh token.
                    unset($_SESSION['form_token']);
                }
            } else {
                $db->rollBack();
                $refNo = '';
            }
            }
        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Admissions submit error: ' . $e->getMessage());
            $errors[] = 'An unexpected error occurred while submitting your application. Please try again later.';
            $refNo = '';
        }
    }

    } // end of !$isReplay branch

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'errors' => $errors, 'refNo' => $refNo]);
        exit;
    }
}

if (!$success && !empty($_SESSION['admission_success_ref'])) {
    $success = true;
    $refNo = $_SESSION['admission_success_ref'];
    // DOB kept in session for success screen  unset after render
    unset($_SESSION['admission_success_ref']);
}

// Check if the downloadable PDF exists
$pdfPath   = __DIR__ . '/001-Application-Form-Revised.pdf';
$pdfExists = file_exists($pdfPath);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $updateMode ? 'Update Application' : 'Apply' ?>  KIMC Eldoret Campus</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --navy:#0d1f3c; --navy-mid:#1a3a6b;
    --green:#065f46; --green-mid:#059669; --green-light:#34d399;
    --cream:#fafaf9; --white:#fff;
    --ink:#1a1a2e; --muted:#6b7280;
    --border:#e5e7eb; --border-focus:#059669;
    --error:#dc2626; --success:#16a34a;
    --radius:10px;
}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--cream);color:var(--ink);min-height:100vh}

/*  Top bar  */
.topbar{background:var(--navy);padding:0 24px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.topbar-brand{display:flex;align-items:center;gap:12px;text-decoration:none}
.topbar-brand img{height:36px;width:auto;object-fit:contain}
.brand-name{font-size:16px;font-weight:700;color:#fff;letter-spacing:-.3px}
.brand-sub{font-size:10px;color:rgba(255,255,255,.5);letter-spacing:.5px;text-transform:uppercase}
.btn-dl{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;
    background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;
    font-size:12px;font-weight:600;text-decoration:none;transition:background .2s}
.btn-dl:hover{background:rgba(255,255,255,.18)}
.btn-dl.disabled{opacity:.4;pointer-events:none;cursor:default}

/*  Hero  */
.hero{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 60%,#1e4d8c 100%);
    padding:40px 24px 36px;text-align:center;color:#fff}
.hero-badge{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;
    letter-spacing:1.5px;text-transform:uppercase;color:#f59e0b;margin-bottom:12px;
    background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);
    padding:4px 14px;border-radius:20px}
.hero h1{font-size:clamp(22px,4vw,32px);font-weight:700;margin-bottom:8px}
.hero p{font-size:14px;opacity:.7;max-width:480px;margin:0 auto}
.disclaimer-card{max-width:700px;margin:20px auto;padding:18px 24px;background:#fff;border:1px solid var(--border);border-radius:14px;color:var(--ink)}
.nisat-license-footer{text-align:center;padding:18px 24px;font-size:12px;color:var(--muted);border-top:1px solid var(--border);background:var(--white)}

/*  Step progress  */
.steps-wrap{background:#fff;border-bottom:1px solid var(--border);padding:0 24px;position:sticky;top:62px;z-index:40}
.steps{display:flex;max-width:700px;margin:0 auto}
.step{flex:1;display:flex;flex-direction:column;align-items:center;padding:14px 8px;
    font-size:11px;font-weight:600;color:var(--muted);cursor:pointer;
    border-bottom:3px solid transparent;transition:all .2s;text-transform:uppercase;letter-spacing:.5px;gap:4px}
.step.active{color:var(--green-mid);border-bottom-color:var(--green-mid)}
.step.done{color:var(--success)}
.step-num{width:22px;height:22px;border-radius:50%;border:2px solid currentColor;
    display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700}
.step.done .step-num{background:var(--success);border-color:var(--success);color:#fff}
.step.active .step-num{background:var(--green-mid);border-color:var(--green-mid);color:#fff}
@media(max-width:520px){.step span:last-child{display:none}}

/*  Form area  */
.form-wrap{max-width:700px;margin:0 auto;padding:28px 20px 60px}

/*  Section cards  */
.section-card{background:#fff;border:1px solid var(--border);border-radius:14px;margin-bottom:20px;overflow:hidden;display:none}
.section-card.active{display:block}
.section-hd{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.section-icon{font-size:20px}
.section-title{font-size:15px;font-weight:700}
.section-sub{font-size:12px;color:var(--muted);margin-top:1px}
.section-body{padding:22px 24px}

/*  Form fields  */
.form-grid{display:grid;gap:16px}
.form-grid.cols-2{grid-template-columns:1fr 1fr}
.form-grid.cols-3{grid-template-columns:1fr 1fr 1fr}
@media(max-width:560px){.form-grid.cols-2,.form-grid.cols-3{grid-template-columns:1fr}}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:12px;font-weight:600;color:var(--ink)}
.fg label .req{color:var(--error);margin-left:2px}
.fg input,.fg select,.fg textarea{
    padding:10px 12px;border:1.5px solid var(--border);border-radius:var(--radius);
    font-family:inherit;font-size:13px;color:var(--ink);background:#fff;
    transition:border-color .15s,box-shadow .15s;width:100%}
.fg input:focus,.fg select:focus,.fg textarea:focus{
    outline:none;border-color:var(--border-focus);box-shadow:0 0 0 3px rgba(5,150,105,.1)}
.fg select:disabled{background:var(--cream);color:var(--muted);cursor:not-allowed}
.field-hint{font-size:11px;color:var(--muted);margin-top:1px}
.field-error{font-size:11px;color:var(--error);margin-top:1px;display:none}
.fg input.input-error,.fg select.input-error{border-color:var(--error);box-shadow:0 0 0 3px rgba(220,38,38,.08)}
.divider-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;
    color:var(--muted);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)}
.check-row{display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer}
.check-row input[type=radio]{accent-color:var(--green-mid)}

/*  File upload  */
.file-upload{position:relative}
.file-upload input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;z-index:2;width:100%;height:100%}
.file-drop{border:2px dashed var(--border);border-radius:10px;padding:16px 20px;
    text-align:center;cursor:pointer;transition:border-color .2s,background .2s;position:relative}
.file-drop:hover{border-color:var(--green-mid);background:rgba(5,150,105,.03)}
.file-drop.has-file{border-color:var(--success);background:rgba(22,163,74,.04)}
.file-drop-icon{font-size:22px;margin-bottom:6px}
.file-drop-label{font-size:13px;font-weight:600;color:var(--ink)}
.file-drop-hint{font-size:11px;color:var(--muted);margin-top:2px}
.file-name{font-size:12px;color:var(--success);font-weight:600;margin-top:5px}
.doc-req{display:inline-block;background:rgba(220,38,38,.08);color:var(--error);
    font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:5px}
.doc-opt{display:inline-block;background:rgba(107,114,128,.08);color:var(--muted);
    font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:5px}

/*  Nav buttons  */
.nav-btns{display:flex;justify-content:space-between;align-items:center;margin-top:24px;gap:12px}
.btn{padding:11px 22px;border-radius:10px;font-family:inherit;font-size:14px;font-weight:600;
    cursor:pointer;border:none;transition:all .2s;display:inline-flex;align-items:center;gap:6px}
.btn-primary{background:linear-gradient(135deg,var(--green),var(--green-mid));color:#fff}
.btn-primary:hover{transform:translateY(-1px)}
.btn-ghost{background:#fff;color:var(--ink);border:1.5px solid var(--border)}
.btn-ghost:hover{background:var(--cream)}
.btn-submit{background:linear-gradient(135deg,var(--navy-mid),var(--navy));color:#fff;padding:13px 28px;font-size:15px}
.btn-submit:hover{transform:translateY(-1px)}
.btn-submit:disabled{opacity:.65;cursor:not-allowed;transform:none}

/*  Alerts  */
.alert{padding:14px 18px;border-radius:10px;margin-bottom:20px;font-size:13px}
.alert-error{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.2);color:#991b1b}
.alert-error ul{margin-top:6px;padding-left:18px}
.alert-error li{margin-bottom:3px}

/*  Success screen  */
.success-screen{background:#fff;border:1px solid var(--border);border-radius:16px;
    padding:48px 32px;text-align:center;max-width:520px;margin:40px auto}
.success-ref{background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.2);
    border-radius:10px;padding:14px 20px;margin:20px 0;display:inline-block}
.success-ref code{font-family:'Space Mono',monospace;font-size:20px;font-weight:700;color:var(--green)}
.success-steps{text-align:left;background:var(--cream);border-radius:10px;
    padding:16px 20px;margin-top:20px;font-size:13px;color:var(--muted);line-height:2.1}
    /*  Documents grid: 2-col on desktop, 1-col on mobile  */
@media (max-width: 600px) {
    .docs-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
</head>
<body>

<!-- Top bar -->
<header class="topbar">
    <a href="<?= APP_ROOT ?>/admissions/index.php" class="topbar-brand">
        <img src="<?= APP_ROOT ?>/assets/img/logo.png" alt="KIMC Logo">
    </a>
    <nav style="display:flex;gap:16px;align-items:center">
        <a href="<?= APP_ROOT ?>/admissions/index.php" class="btn-dl"> Admissions Portal</a>
        <?php if ($pdfExists): ?>
        <a href="<?= APP_ROOT ?>/admissions/001-Application-Form-Revised.pdf" download="001-Application-Form-Revised.pdf" class="btn-dl">
             Download Application Form (PDF)
        </a>
        <?php else: ?>
        <span class="btn-dl disabled" title="PDF form not yet uploaded"> Download Form</span>
        <?php endif; ?>
    </nav>
</header>

<?php
// Restore DOB from session for the success screen
if (empty($_SESSION['admission_dob']) && !empty($_POST['date_of_birth'])) {
    $_SESSION['admission_dob'] = trim($_POST['date_of_birth']);
}
$successDob = $_SESSION['admission_dob'] ?? '';
unset($_SESSION['admission_dob']);
?>
<?php if ($success): ?>
<!--  SUCCESS  -->
<div style="padding:40px 20px">
<div class="success-screen">
    <h2 style="font-size:22px;font-weight:700;margin-bottom:8px">Application Submitted!</h2>
    <p style="color:var(--muted);font-size:14px">Your application to KIMC Eldoret Campus has been received successfully.</p>

    <!-- Reference number  prominent, copyable -->
    <div class="success-ref" style="margin:20px 0">
        <div style="font-size:11px;color:var(--muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px">Your Reference Number</div>
        <code id="ref-code"><?= htmlspecialchars($refNo) ?></code>
        <button onclick="copyRef()" id="copy-btn"
            style="margin-top:10px;display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
            border:1.5px solid rgba(5,150,105,.3);border-radius:8px;background:#fff;
            color:#065f46;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit">
             Copy Reference Number
        </button>
    </div>

    <!-- Save credentials reminder -->
    <div style="background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.25);
        border-radius:10px;padding:14px 18px;text-align:left;margin-bottom:20px">
        <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px">
            Save these details  you will need them to check your status
        </div>
        <div style="font-size:13px;color:#78350f;line-height:2">
            Reference Number: <strong style="font-family:'Space Mono',monospace"><?= htmlspecialchars($refNo) ?></strong><br>
            Date of Birth: <strong><?= $successDob ? formatDate($successDob) : '(as entered in the form)' ?></strong><br>
            <span style="font-size:12px;opacity:.8">Use these to log in and track your application progress at any time.</span>
        </div>
    </div>

    <!-- Primary CTA  go to status page -->
    <a href="<?= APP_ROOT ?>/admissions/status.php?ref=<?= urlencode($refNo) ?>&dob=<?= urlencode($successDob) ?>"
        class="btn btn-primary"
        style="width:100%;justify-content:center;font-size:15px;padding:14px 24px;margin-bottom:12px;display:flex;text-decoration:none">
        View My Application Status 
    </a>

    <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-bottom:20px">
        <a href="<?= APP_ROOT ?>/admissions/" class="btn btn-ghost" style="font-size:13px;text-decoration:none">
             Back to Portal
        </a>
    </div>

    <div style="background:var(--cream);border-radius:10px;padding:14px 18px;text-align:left">
        <strong style="color:var(--ink);display:block;margin-bottom:6px;font-size:13px">What happens next?</strong>
        <div style="font-size:13px;color:var(--muted);line-height:2">
             Your documents are being reviewed by our admissions team.<br>
             We will contact you on the mobile number you provided.<br>
             Admission decisions are communicated within 510 working days.<br>
             Track your status anytime using your reference number and date of birth.
        </div>
    </div>
</div>
</div>

<script>
function copyRef() {
    const ref = document.getElementById('ref-code').textContent.trim();
    const btn = document.getElementById('copy-btn');
    navigator.clipboard?.writeText(ref).then(() => {
        btn.textContent = ' Copied!';
        btn.style.background = 'rgba(5,150,105,.08)';
        setTimeout(() => { btn.innerHTML = ' Copy Reference Number'; btn.style.background = '#fff'; }, 2000);
    }).catch(() => {
        const ta = document.createElement('textarea');
        ta.value = ref; document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
        btn.textContent = ' Copied!';
        setTimeout(() => { btn.innerHTML = ' Copy Reference Number'; }, 2000);
    });
}
</script>

<?php else: ?>

<!--  HERO  -->
<div class="hero">

    <!-- <div class="hero-badge"> Online Admission <?= date('Y') ?></div> -->

    <h1>Kenya Institute of Mass Communication (KIMC)</h1>

    <h2 style="color:#c1121f; font-weight:700; margin-top:6px;">
        ELDORET CAMPUS
    </h2>

    <h3 style="margin-top:10px; font-weight:600;">
        <?= $updateMode ? 'Update Existing Application' : 'Online Course Application Form' ?>
    </h3>

    <h4 style="margin-top:10px; font-weight:600; color:#white;">
        Apply Now for <?= htmlspecialchars($nextIntake['label']) ?> Intake
    </h4>

</div>

<div class="disclaimer-card">
    <p style="margin:0;font-size:14px;line-height:1.7;color:var(--muted)">
        <?= $updateMode
            ? 'Update the information or documents that need correction. Your existing application and reference number will be kept; this form will not create a second application.'
            : 'Kindly complete all the sections and upload the necessary documents. Once your submission has been successfully received, a reference number will be generated for your records. Please ensure that all information provided is accurate and complete to facilitate smooth processing of your application.' ?>
    </p>
</div>


<!--  STEPS  -->
<div class="steps-wrap">
    <div class="steps">
        <div class="step active" id="tab-1" onclick="goStep(1)"><span class="step-num">1</span><span>Personal</span></div>
        <div class="step"        id="tab-2" onclick="goStep(2)"><span class="step-num">2</span><span>Address</span></div>
        <div class="step"        id="tab-3" onclick="goStep(3)"><span class="step-num">3</span><span>Documents</span></div>
        <div class="step"        id="tab-4" onclick="goStep(4)"><span class="step-num">4</span><span>Declare</span></div>
    </div>
</div>

<div class="form-wrap">

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <strong>Please fix the following before submitting:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <div id="js-error-container" class="alert alert-error" style="display:none;">
        <strong>Please fix the following before submitting:</strong>
        <ul id="js-error-list"></ul>
    </div>

    <form method="POST" enctype="multipart/form-data" id="apply-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_token'] ?? '') ?>">
        <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_FILE_BYTES ?>">
        <?php if ($updateMode): ?>
        <input type="hidden" name="update_mode" value="1">
        <input type="hidden" name="reference_no" value="<?= htmlspecialchars($refNo) ?>">
        <input type="hidden" name="lookup_dob" value="<?= htmlspecialchars($_GET['dob'] ?? $existingApp['date_of_birth']) ?>">
        <?php endif; ?>

        <!-- 
             STEP 1  Programme & Personal Info
         -->
        <div class="section-card active" id="step-1">

            <!-- Programme -->
            <div class="section-hd">
                <span class="section-icon"></span>
                <div>
                    <div class="section-title">Programme Selection</div>
                    <div class="section-sub">Choose the programme you are applying for</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-grid">
                    <div class="fg">
                        <label>Programme <span class="req">*</span></label>
                        <select name="program" id="program" required onchange="updateRequirements()">
                            <option value=""> Select Programme </option>
                            <option value="certificate" <?= ($_POST['program']??'')==='certificate'?'selected':'' ?>>Certificate in Film Production (Module Based)</option>
                            <option value="diploma"     <?= ($_POST['program']??'')==='diploma'?'selected':'' ?>>Upgrading Diploma in Film Production</option>
                        </select>
                    </div>
                </div>
                <div id="requirements" style="margin-top:14px; padding:14px; background:rgba(5,150,105,.05); border:1px solid rgba(5,150,105,.2); border-radius:8px; display:none;">
                    <div class="divider-label">Entry Requirements</div>
                    <div id="req-content"></div>
                </div>
                <div style="margin-top:14px">
                    <div class="divider-label">Study Mode</div>
                    <div style="display:flex;gap:24px;flex-wrap:wrap">
                        <label class="check-row">
                            <input type="radio" name="study_mode" value="regular" <?= ($_POST['study_mode']??'regular')==='regular'?'checked':'' ?>>
                            Regular (Day)
                        </label>
                        <label class="check-row">
                            <input type="radio" name="study_mode" value="self_sponsored" <?= ($_POST['study_mode']??'')==='self_sponsored'?'checked':'' ?>>
                            Part Time (Evening / Weekend)
                        </label>
                    </div>
                </div>
            </div>

            <!-- Personal -->
            <div class="section-hd" style="border-top:1px solid var(--border)">
                <span class="section-icon"></span>
                <div>
                    <div class="section-title">Personal Information</div>
                    <div class="section-sub">As per your ID / passport / school certificates</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-grid cols-3">
                    <div class="fg">
                        <label>Surname <span class="req">*</span></label>
                        <input type="text" name="surname" required value="<?= htmlspecialchars($_POST['surname']??'') ?>">
                    </div>
                    <div class="fg">
                        <label>Middle Name</label>
                        <input type="text" name="middle_name" value="<?= htmlspecialchars($_POST['middle_name']??'') ?>">
                    </div>
                    <div class="fg">
                        <label>First Name <span class="req">*</span></label>
                        <input type="text" name="first_name" required value="<?= htmlspecialchars($_POST['first_name']??'') ?>">
                    </div>
                </div>
                <div class="form-grid cols-3" style="margin-top:14px">
                    <div class="fg">
                        <label>Date of Birth <span class="req">*</span></label>
                        <?php
                        $dobMax = (new DateTimeImmutable('today'))->modify('-' . MIN_APPLICANT_AGE . ' years')->format('Y-m-d');
                        $dobMin = (new DateTimeImmutable('today'))->modify('-' . MAX_APPLICANT_AGE . ' years')->format('Y-m-d');
                        ?>
                        <input type="date" name="date_of_birth" id="dob-input" required
                               min="<?= $dobMin ?>" max="<?= $dobMax ?>"
                               oninput="validateDobField(this)" onchange="validateDobField(this)"
                               value="<?= htmlspecialchars($_POST['date_of_birth']??'') ?>">
                        <div class="field-error" id="err-date_of_birth"></div>
                    </div>
                    <div class="fg">
                        <label>Nationality <span class="req">*</span></label>
                        <input type="text" name="nationality" required value="<?= htmlspecialchars($_POST['nationality']??'Kenyan') ?>">
                    </div>
                    <div class="fg">
                        <label>Gender <span class="req">*</span></label>
                        <select name="gender" required>
                            <option value=""> Select </option>
                            <option value="male"   <?= ($_POST['gender']??'')==='male'  ?'selected':'' ?>>Male</option>
                            <option value="female" <?= ($_POST['gender']??'')==='female'?'selected':'' ?>>Female</option>
                            <option value="other"  <?= ($_POST['gender']??'')==='other' ?'selected':'' ?>>Other</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid cols-2" style="margin-top:14px">
                    <div class="fg">
                        <label>National ID / Passport / Birth Certificate No. <span class="req">*</span></label>
                        <input type="text" name="national_id" id="national-id-input" required
                               maxlength="9" placeholder="e.g. 34567890, A1234567, or your Birth Certificate No."
                               oninput="validateNationalIdField(this)"
                               value="<?= htmlspecialchars($_POST['national_id']??'') ?>">
                        <div class="field-hint">Don't have a National ID or Passport yet? You may enter your Birth Certificate Number instead.</div>
                        <div class="field-error" id="err-national_id"></div>
                    </div>
                    <div class="fg">
                        <label>Mobile No. <span class="req">*</span></label>
                        <input type="tel" name="mobile_no" id="mobile-input" required
                               inputmode="numeric" placeholder="07XX XXX XXX or 01XX XXX XXX"
                               oninput="validateMobileField(this)"
                               value="<?= htmlspecialchars($_POST['mobile_no']??'') ?>">
                        <div class="field-error" id="err-mobile_no"></div>
                    </div>
                </div>
                <div class="form-grid cols-2" style="margin-top:14px">
                    <div class="fg" style="grid-column:1/-1">
                        <label>Email Address <span class="req">*</span></label>
                        <input type="email" name="email" id="email-input" required
                               oninput="validateEmailField(this)"
                               value="<?= htmlspecialchars($_POST['email']??'') ?>">
                        <div class="field-error" id="err-email"></div>
                    </div>
                </div>
            </div>

            <div class="section-body" style="border-top:1px solid var(--border)">
                <div class="nav-btns">
                    <span></span>
                    <button type="button" class="btn btn-primary" onclick="goStep(2)">Next: Address </button>
                </div>
            </div>
        </div>

        <!-- 
             STEP 2  Address
         -->
        <div class="section-card" id="step-2">
            <div class="section-hd">
                <span class="section-icon"></span>
                <div>
                    <div class="section-title">Address</div>
                    <div class="section-sub">Your current postal and physical address</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-grid cols-3">
                    <div class="fg">
                        <label>P.O. Box <span class="req">*</span></label>
                        <input type="text" name="po_box" required value="<?= htmlspecialchars($_POST['po_box']??'') ?>">
                    </div>
                    <div class="fg">
                        <label>Postal Code <span class="req">*</span></label>
                        <select name="postal_code" id="postal-select" required>
                            <option value=""> Select Postal Code </option>
                        </select>
                        <div class="field-hint">Select the postal code for your area.</div>
                    </div>
                    <div class="fg">
                        <label>City / Town <span class="req">*</span></label>
                        <input type="text" name="city_town" id="city-town-input" required value="<?= htmlspecialchars($_POST['city_town']??'') ?>">
                    </div>
                </div>
                <div class="form-grid cols-2" style="margin-top:14px">
                    <div class="fg">
                        <label>County <span class="req">*</span></label>
                        <select name="county" id="county-select" required onchange="updateSubCounties()">
                            <option value=""> Select County </option>
                        </select>
                    </div>
                    <div class="fg">
                        <label>Sub-County <span class="req">*</span></label>
                        <select name="sub_county" id="subcounty-select" required disabled>
                            <option value=""> Select County first </option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="section-body" style="border-top:1px solid var(--border)">
                <div class="nav-btns">
                    <button type="button" class="btn btn-ghost" onclick="goStep(1)"> Back</button>
                    <button type="button" class="btn btn-primary" onclick="goStep(3)">Next: Documents </button>
                </div>
            </div>
        </div>

        <!-- 
             STEP 3  Document Uploads
         -->
        <div class="section-card" id="step-3">
            <div class="section-hd">
                <span class="section-icon"></span>
                <div>
                    <div class="section-title">Upload Documents</div>
                    <div class="section-sub">PDF ONLY  Max 5MB per file</div>
                </div>
            </div>
            <div class="section-body">

                <?php if ($pdfExists): ?>
                <div style="background:rgba(5,150,105,.06);border:1px solid rgba(5,150,105,.2);border-radius:10px;padding:13px 16px;margin-bottom:20px;font-size:13px;color:#065f46;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
                    <span> <strong>Tip:</strong> Download, fill and sign the official form, then scan and upload it below.</span>
                    <a href="<?= APP_ROOT ?>/admissions/001-Application-Form-Revised.pdf" download="001-Application-Form-Revised.pdf"
                       style="font-size:12px;font-weight:700;color:#065f46;border:1px solid rgba(5,150,105,.3);padding:5px 12px;border-radius:7px;text-decoration:none;background:#fff;white-space:nowrap">
                         Download Form
                    </a>
                </div>
                <?php endif; ?>

                <div class="docs-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <?php
                    $docs = [
                        ['field'=>'doc_application_form','label'=>'Scanned Application Form','icon'=>'','req'=>true, 'hint'=>'Download, fill, sign, scan and upload'],
                        ['field'=>'doc_kcse',            'label'=>'KCSE Certificate',        'icon'=>'','req'=>true, 'hint'=>'KCSE'],
                        ['field'=>'doc_kcpe',            'label'=>'KCPE Certificate',        'icon'=>'','req'=>true, 'hint'=>'KCPE'],
                        ['field'=>'doc_birth_cert',      'label'=>'Birth Certificate',       'icon'=>'','req'=>false,'hint'=>'Required if no National ID uploaded'],
                        ['field'=>'doc_national_id',     'label'=>'National ID (Both Sides)','icon'=>'','req'=>false,'hint'=>'Required if no Birth Certificate uploaded'],
                    ];
                    foreach ($docs as $d):
                    ?>
                    <div class="fg">
                        <label>
                            <?= $d['icon'] ?> <?= $d['label'] ?>
                            <span class="<?= $updateMode || !$d['req'] ? 'doc-opt' : 'doc-req' ?>"><?= $updateMode ? 'Replace if needed' : ($d['req']?'Required':'Optional') ?></span>
                        </label>
                        <div class="file-upload">
                            <div class="file-drop" id="drop-<?= $d['field'] ?>">
                                <input type="file" name="<?= $d['field'] ?>"
                                       accept=".pdf"
                                       onchange="fileChosen(this,'<?= $d['field'] ?>')">
                                <div class="file-drop-icon"></div>
                                <div class="file-drop-label">Click or drag to upload</div>
                                <div class="file-drop-hint"><?= $d['hint'] ?></div>
                                <div class="file-name" id="fname-<?= $d['field'] ?>"></div>
                            </div>
                        </div>
                        <?php if ($updateMode):
                            $existingType = [
                                'doc_application_form' => 'application_form',
                                'doc_kcse' => 'kcse_cert',
                                'doc_kcpe' => 'kcpe_cert',
                                'doc_birth_cert' => 'birth_cert',
                                'doc_national_id' => 'national_id',
                            ][$d['field']];
                            $currentDoc = null;
                            foreach ($existingDocs as $document) {
                                if ($document['doc_type'] === $existingType) { $currentDoc = $document; break; }
                            }
                        ?>
                        <div style="font-size:11px;color:var(--muted);margin-top:5px">
                            <?= $currentDoc ? 'Currently uploaded: ' . htmlspecialchars($currentDoc['original_name']) : 'No document currently uploaded' ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:16px;background:rgba(201,168,76,.06);border:1px solid rgba(201,168,76,.2);border-radius:9px;padding:12px 16px;font-size:12px;color:#92400e">
                     <strong>Note:</strong> Upload at least one of Birth Certificate or National ID.
                </div>
            </div>
            <div class="section-body" style="border-top:1px solid var(--border)">
                <div class="nav-btns">
                    <button type="button" class="btn btn-ghost" onclick="goStep(2)"> Back</button>
                    <button type="button" class="btn btn-primary" onclick="goStep(4)">Next: Declaration </button>
                </div>
            </div>
        </div>

        <!-- 
             STEP 4  Declaration & Submit
         -->
        <div class="section-card" id="step-4">
            <div class="section-hd">
                <!-- <span class="section-icon"></span> -->
                <div>
                    <div class="section-title">Declaration &amp; Submit</div>
                    <div class="section-sub">Please read and agree before submitting</div>
                </div>
            </div>
            <div class="section-body">
                <div class="form-grid cols-1" style="gap:16px">
                    <div class="fg">
                        <label>How did you hear about us? <span class="req">*</span></label>
                        <select name="heard_via" required onchange="updateHeardVia()" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:var(--radius);font-family:inherit;font-size:13px;color:var(--ink);background:#fff">
                            <option value=""> Select an option </option>
                            <option value="facebook" <?= ($_POST['heard_via']??'')==='facebook' ? 'selected' : '' ?>>Facebook</option>
                            <option value="instagram" <?= ($_POST['heard_via']??'')==='instagram' ? 'selected' : '' ?>>Instagram</option>
                            <option value="twitter" <?= ($_POST['heard_via']??'')==='twitter' ? 'selected' : '' ?>>Twitter</option>
                            <option value="linkedin" <?= ($_POST['heard_via']??'')==='linkedin' ? 'selected' : '' ?>>LinkedIn</option>
                            <option value="youtube" <?= ($_POST['heard_via']??'')==='youtube' ? 'selected' : '' ?>>YouTube</option>
                            <option value="search_engine" <?= ($_POST['heard_via']??'')==='search_engine' ? 'selected' : '' ?>>Google</option>
                            <option value="referral" <?= ($_POST['heard_via']??'')==='referral' ? 'selected' : '' ?>>Referral</option>
                            <option value="other" <?= ($_POST['heard_via']??'')==='other' ? 'selected' : '' ?>>Other</option>
                        </select>
                        <input type="text" id="heard-other" name="heard_other" placeholder="Please describe" value="<?= htmlspecialchars($_POST['heard_other'] ?? '') ?>" style="margin-top:12px;display:none;width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:var(--radius);font-family:inherit;font-size:13px;color:var(--ink);background:#fff">
                    </div>
                    <label class="check-row" style="align-items:flex-start;gap:10px;
                        padding:16px 18px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer"
                        id="decl-wrap">
                        <input type="checkbox" name="declaration" value="1" id="decl-chk"
                               style="margin-top:2px;width:18px;height:18px;accent-color:var(--green-mid)"
                               <?= $updateMode ? 'checked' : '' ?>
                               onchange="this.closest('label').style.borderColor=this.checked?'#16a34a':'var(--border)'">
                        <span style="font-size:13px;line-height:1.7">
                            <strong>I declare</strong> that the information given in this application and all attached documents is true and accurate to the best of my knowledge.
                            I understand that any information found to be false will lead to automatic disqualification.
                        </span>
                    </label>
                </div>

                <div style="margin-top:16px;background:rgba(201,168,76,.07);border:1px solid rgba(201,168,76,.2);
                    border-radius:10px;padding:13px 16px;font-size:13px;color:#92400e">
                    <?= $updateMode ? 'Only the existing application will be updated. Review all changes before clicking' : 'Once submitted you cannot edit your application. Please review all information before clicking' ?> <strong><?= $updateMode ? 'Save Updates' : 'Submit' ?></strong>.
                </div>
            </div>
            <div class="section-body" style="border-top:1px solid var(--border)">
                <div class="nav-btns">
                    <button type="button" class="btn btn-ghost" onclick="goStep(3)"> Back</button>
                    <button type="submit" class="btn btn-submit" id="submit-btn"><?= $updateMode ? 'Save Application Updates' : 'Submit Application' ?></button>
                </div>
            </div>
        </div>

    </form>
</div>
<?php endif; ?>

<script>
const maxUploadBytes = 5 * 1024 * 1024;
const updateMode = <?= $updateMode ? 'true' : 'false' ?>;

// Kenya reference data (counties / sub-counties / postal codes), passed straight
// from the PHP constants above via json_encode  no duplicate data to keep in sync.
const KENYA_COUNTIES     = <?= json_encode(KENYA_COUNTIES) ?>;
const KENYA_SUBCOUNTIES  = <?= json_encode(KENYA_SUBCOUNTIES) ?>;
const KENYA_POSTAL_CODES = <?= json_encode(KENYA_POSTAL_CODES) ?>;
// Existing values when re-displaying a failed submission, or editing an existing application.
const PRESET_COUNTY      = <?= json_encode(trim($_POST['county'] ?? '')) ?>;
const PRESET_SUBCOUNTY   = <?= json_encode(trim($_POST['sub_county'] ?? '')) ?>;
const PRESET_POSTAL_CODE = <?= json_encode(trim($_POST['postal_code'] ?? '')) ?>;

function populateCountySelect() {
    const sel = document.getElementById('county-select');
    if (!sel) return;
    KENYA_COUNTIES.forEach(name => {
        const o = document.createElement('option');
        o.value = name; o.textContent = name;
        sel.appendChild(o);
    });
    // Preserve a previously-typed value even if it doesn't match the curated
    // list (older applications used a free-text field) rather than losing it.
    if (PRESET_COUNTY) {
        if (![...sel.options].some(o => o.value === PRESET_COUNTY)) {
            const o = document.createElement('option');
            o.value = PRESET_COUNTY; o.textContent = PRESET_COUNTY + ' (previously entered)';
            sel.appendChild(o);
        }
        sel.value = PRESET_COUNTY;
    }
}

function updateSubCounties(preselect) {
    const countySel = document.getElementById('county-select');
    const subSel = document.getElementById('subcounty-select');
    if (!countySel || !subSel) return;
    const county = countySel.value;
    const preset = preselect !== undefined ? preselect : (county === PRESET_COUNTY ? PRESET_SUBCOUNTY : '');
    subSel.innerHTML = '';
    if (!county) {
        subSel.disabled = true;
        subSel.innerHTML = '<option value=""> Select County first </option>';
        return;
    }
    subSel.disabled = false;
    const opt0 = document.createElement('option');
    opt0.value = ''; opt0.textContent = ' Select Sub-County ';
    subSel.appendChild(opt0);
    (KENYA_SUBCOUNTIES[county] || []).forEach(name => {
        const o = document.createElement('option');
        o.value = name; o.textContent = name;
        subSel.appendChild(o);
    });
    if (preset) {
        if (![...subSel.options].some(o => o.value === preset)) {
            const o = document.createElement('option');
            o.value = preset; o.textContent = preset + ' (previously entered)';
            subSel.appendChild(o);
        }
        subSel.value = preset;
    }
}

function populatePostalSelect() {
    const sel = document.getElementById('postal-select');
    if (!sel) return;
    KENYA_POSTAL_CODES.forEach(p => {
        const o = document.createElement('option');
        o.value = p;
        o.textContent = p;
        sel.appendChild(o);
    });
    if (PRESET_POSTAL_CODE) {
        if (![...sel.options].some(o => o.value === PRESET_POSTAL_CODE)) {
            const o = document.createElement('option');
            o.value = PRESET_POSTAL_CODE; o.textContent = PRESET_POSTAL_CODE + ' (previously entered)';
            sel.appendChild(o);
        }
        sel.value = PRESET_POSTAL_CODE;
    }
}
//  Instant field validation (mirrors the server-side checks) 
function showFieldError(input, message) {
    input.classList.add('input-error');
    const err = document.getElementById('err-' + input.name);
    if (err) { err.textContent = message; err.style.display = 'block'; }
}
function clearFieldError(input) {
    input.classList.remove('input-error');
    const err = document.getElementById('err-' + input.name);
    if (err) { err.textContent = ''; err.style.display = 'none'; }
}
function validateNationalIdField(input) {
    const v = input.value.trim();
    if (v === '') { clearFieldError(input); return true; }
    // Mirrors the server rule: 69 letters/numbers, at least one digit 
    // covers National ID/Birth Certificate (digits) and Passport (letters+digits).
    if (!/^(?=.*\d)[A-Za-z0-9]{6,9}$/.test(v)) {
        showFieldError(input, '69 letters/numbers, must include at least one digit.');
        return false;
    }
    clearFieldError(input);
    return true;
}
function validateMobileField(input) {
    const v = input.value.replace(/[\s\-]/g, '');
    if (v === '') { clearFieldError(input); return true; }
    if (!/^(07|01)\d{8}$/.test(v)) {
        showFieldError(input, 'Must be 10 digits, starting with 07 or 01.');
        return false;
    }
    clearFieldError(input);
    return true;
}
function validateEmailField(input) {
    const v = input.value.trim();
    if (v === '') { clearFieldError(input); return true; }
    // Same practical shape as PHP's FILTER_VALIDATE_EMAIL for everyday addresses.
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
        showFieldError(input, 'Enter a valid email address.');
        return false;
    }
    clearFieldError(input);
    return true;
}
function validateDobField(input) {
    const v = input.value;
    if (v === '') { clearFieldError(input); return true; }
    const dob = new Date(v + 'T00:00:00');
    const today = new Date(); today.setHours(0,0,0,0);
    if (isNaN(dob.getTime())) {
        showFieldError(input, 'Not a valid date.');
        return false;
    }
    if (dob >= today) {
        showFieldError(input, 'Cannot be today or in the future.');
        return false;
    }
    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age--;
    if (age < <?= MIN_APPLICANT_AGE ?>) {
        showFieldError(input, 'Applicant must be at least <?= MIN_APPLICANT_AGE ?> years old.');
        return false;
    }
    if (age > <?= MAX_APPLICANT_AGE ?>) {
        showFieldError(input, 'Please check this date  the age calculated seems too high.');
        return false;
    }
    clearFieldError(input);
    return true;
}
function humanReadableBytes(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return bytes + ' bytes';
}
function updateRequirements() {
    const program = document.getElementById('program').value;
    const reqDiv = document.getElementById('requirements');
    const reqContent = document.getElementById('req-content');
    if (program === 'certificate') {
        reqContent.innerHTML = '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6"><li>KCSE Mean Grade D+</li><li>D+ in English or Kiswahili</li></ul>';
        reqDiv.style.display = 'block';
    } else if (program === 'diploma') {
        reqContent.innerHTML = '<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.6"><li>KCSE Mean Grade D+</li><li>KIMC Certificate in Film Production (Module Based) with 6 months Industrial Attachment</li></ul>';
        reqDiv.style.display = 'block';
    } else {
        reqDiv.style.display = 'none';
    }
}
function updateHeardVia() {
    const sel = document.querySelector('select[name="heard_via"]');
    const other = document.getElementById('heard-other');
    if (!sel || !other) return;
    if (sel.value === 'other') {
        other.style.display = 'block';
        other.setAttribute('required', 'required');
    } else {
        other.style.display = 'none';
        other.removeAttribute('required');
    }
}
function displayClientErrors(errors) {
    const container = document.getElementById('js-error-container');
    const list = document.getElementById('js-error-list');
    if (!container || !list) return;
    list.innerHTML = errors.map(err => '<li>' + err.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</li>').join('');
    container.style.display = 'block';
    container.scrollIntoView({behavior:'smooth', block:'start'});
}
function clearClientErrors() {
    const container = document.getElementById('js-error-container');
    const list = document.getElementById('js-error-list');
    if (!container || !list) return;
    list.innerHTML = '';
    container.style.display = 'none';
}
function clientFieldLabel(input) {
    const label = input.closest('.fg')?.querySelector('label');
    return label ? label.textContent.replace(/[*]/g, '').replace(/Required|Optional|Replace if needed/g, '').trim() : input.name;
}
function fileChosen(input, field) {
    const drop  = document.getElementById('drop-'+field);
    const fname = document.getElementById('fname-'+field);
    if (input.files?.[0]) {
        const file = input.files[0];
        const ext  = file.name.split('.').pop().toLowerCase();
        if (ext !== 'pdf') {
            drop.classList.remove('has-file');
            drop.classList.add('required-missing');
            fname.textContent = ' This document must be a PDF. Please choose a PDF file.';
            fname.style.color = '#dc2626';
            input.value = ''; // clear the selection
            return;
        }
        if (file.size > maxUploadBytes) {
            drop.classList.remove('has-file');
            drop.classList.add('required-missing');
            fname.textContent = ' This file is larger than 5 MB. Please choose a smaller PDF.';
            fname.style.color = '#dc2626';
            input.value = '';
            return;
        }
        drop.classList.add('has-file');
        drop.classList.remove('required-missing');
        fname.style.color = '';
        fname.textContent = ' ' + file.name + ' (' + Math.round(file.size/1024) + ' KB)';
    } else {
        drop.classList.remove('has-file');
        fname.textContent = '';
    }
}
updateHeardVia();
updateRequirements();
populateCountySelect();
updateSubCounties(PRESET_SUBCOUNTY);
populatePostalSelect();
let currentStep = 1;
function goStep(n) {
    for (let i = 1; i <= 4; i++) {
        document.getElementById('step-'+i)?.classList.remove('active');
        const t = document.getElementById('tab-'+i);
        if (t) { t.classList.remove('active','done'); if (i < n) t.classList.add('done'); }
    }
    document.getElementById('step-'+n)?.classList.add('active');
    document.getElementById('tab-'+n)?.classList.add('active');
    currentStep = n;
    window.scrollTo({top:0,behavior:'smooth'});
}
const submitButton = document.getElementById('submit-btn');
let isSubmitting = false;
document.getElementById('apply-form')?.addEventListener('submit', function(e) {
    // Guard against a double-click / double-tap firing this handler twice
    // before the button has a chance to visually disable.
    if (isSubmitting) { e.preventDefault(); return; }

    clearClientErrors();
    const decl = document.getElementById('decl-chk');
    const errors = [];
    this.querySelectorAll('[required]:not([type="file"])').forEach(input => {
        if (!String(input.value || '').trim()) {
            errors.push(clientFieldLabel(input) + ' is required.');
        }
    });
    if (!decl?.checked) {
        errors.push('Please tick the declaration checkbox before submitting.');
    }
    // Re-run the instant field validators one more time in case a field was
    // never blurred/typed into after being pre-filled (e.g. autofill).
    const nationalIdEl = document.getElementById('national-id-input');
    const mobileEl = document.getElementById('mobile-input');
    const emailEl = document.getElementById('email-input');
    const dobEl = document.getElementById('dob-input');
    if (nationalIdEl && nationalIdEl.value.trim() && !validateNationalIdField(nationalIdEl)) {
        errors.push('National ID / Passport / Birth Certificate No. must be 69 letters/numbers, including at least one digit.');
    }
    if (mobileEl && mobileEl.value.trim() && !validateMobileField(mobileEl)) {
        errors.push('Mobile number must be 10 digits, starting with 07 or 01.');
    }
    if (emailEl && emailEl.value.trim() && !validateEmailField(emailEl)) {
        errors.push('Please enter a valid email address.');
    }
    if (dobEl && dobEl.value.trim() && !validateDobField(dobEl)) {
        errors.push('Please check the Date of Birth entered.');
    }
    document.querySelectorAll('input[type="file"]').forEach(input => {
        if (input.files?.[0]) {
            const file = input.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            if (ext !== 'pdf') errors.push(clientFieldLabel(input) + ' must be a PDF file.');
            if (file.size > maxUploadBytes) {
                errors.push(clientFieldLabel(input) + ` exceeds the maximum upload size of ${humanReadableBytes(maxUploadBytes)}.`);
            }
        }
    });
    if (errors.length > 0) {
        e.preventDefault();
        displayClientErrors(errors);
        const firstError = errors[0];
        if (/document|certificate|national id|application form|pdf|file/i.test(firstError)) goStep(3);
        else if (/address|box|postal|city|county/i.test(firstError)) goStep(2);
        else if (/declaration|heard about/i.test(firstError)) goStep(4);
        else goStep(1);
        return;
    }
    if (!window.fetch) {
        isSubmitting = true;
        if (submitButton) { submitButton.disabled = true; submitButton.textContent = ' Submitting'; }
        return;
    }
    e.preventDefault();
    isSubmitting = true;
    const form = this;
    const formData = new FormData(form);
    formData.append('ajax', '1');
    if (submitButton) { submitButton.disabled = true; submitButton.textContent = ' Submitting'; }
    fetch(form.action || window.location.href, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.text().then(text => ({ status: response.status, ok: response.ok, text })))
    .then(({ status, ok, text }) => {
        let data;
        try {
            data = JSON.parse(text);
        } catch (err) {
            if (status >= 200 && status < 300) {
                form.submit();
                return;
            }
            throw err;
        }
        if (data.success) {
            // Redirect to status page with ref + dob pre-loaded
            const dobInput = form.querySelector('input[name="date_of_birth"]');
            const dob = dobInput ? dobInput.value : '';
            window.location.href = ('<?= APP_ROOT ?>/admissions/status.php?ref=') + encodeURIComponent(data.refNo) + '&dob=' + encodeURIComponent(dob);
            return;
        }
        isSubmitting = false;
        if (submitButton) { submitButton.disabled = false; submitButton.textContent = updateMode ? 'Save Application Updates' : 'Submit Application'; }
        displayClientErrors(data.errors || ['Submission failed. Please check your details and try again.']);
    })
    .catch(() => {
        // A network/timeout error here does NOT necessarily mean the request
        // never reached the server  the response may simply have been lost
        // on the way back (very common on flaky mobile data). We deliberately
        // do NOT auto-resubmit the form here, since that used to be able to
        // create a second application. Instead we let the student retry
        // manually; the hidden form_token makes a manual retry safe even if
        // the first attempt actually landed on the server.
        isSubmitting = false;
        if (submitButton) { submitButton.disabled = false; submitButton.textContent = updateMode ? 'Save Application Updates' : 'Submit Application'; }
        displayClientErrors(['A network error occurred. Please check your connection  it is safe to click submit again.']);
    });
});
<?php if (!empty($errors)): ?>
goStep(<?= !empty(array_filter($errors, fn($e) => strpos($e, 'form') !== false || strpos($e, 'KCSE') !== false || strpos($e, 'KCPE') !== false || strpos($e, 'Birth') !== false || strpos($e, 'ID') !== false)) ? 3 : 1 ?>);
<?php endif; ?>
</script>
<footer class="nisat-license-footer">&copy; 2026 Nisat. All rights reserved. Licensed to KIMC Eldoret.</footer>
</body>
</html>

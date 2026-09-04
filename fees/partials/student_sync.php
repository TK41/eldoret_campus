<?php

function generateKimcEmail(string $fullName, PDO $db): string {
    $base = trim(strtolower($fullName));
    $base = preg_replace('/[^a-z0-9]+/', '', $base);
    if ($base === '') {
        $base = 'student';
    }

    $candidate = $base . '@kimc.ac.ke';
    $suffix = 1;
    $check = $db->prepare("SELECT 1 FROM users WHERE email = ? LIMIT 1");

    while (true) {
        $check->execute([$candidate]);
        if (!$check->fetch()) {
            return $candidate;
        }
        $suffix++;
        $candidate = $base . $suffix . '@kimc.ac.ke';
    }
}

function determineTierId(array $studentData, PDO $db): int {
    $tierMap = [
        'certificate'  => 1,
        'cert'         => 1,
        'diploma'      => 2,
        'dip'          => 2,
        'postgraduate' => 3,
        'post grad'    => 3,
        'post-grad'    => 3,
    ];

    if (!empty($studentData['group_id'])) {
        $stmt = $db->prepare("SELECT programme FROM fee_groups WHERE group_id = ?");
        $stmt->execute([$studentData['group_id']]);
        $group = $stmt->fetch();
        if ($group) {
            $prog = strtolower($group['programme']);
            foreach ($tierMap as $keyword => $tierId) {
                if (strpos($prog, $keyword) !== false) {
                    return $tierId;
                }
            }
        }
    }

    if (!empty($studentData['programme'])) {
        $prog = strtolower($studentData['programme']);
        foreach ($tierMap as $keyword => $tierId) {
            if (str_contains($prog, $keyword)) {
                return $tierId;
            }
        }
    }

    return 1;
}

function syncToInventory(int $feeStudentId, array $studentData, PDO $db): array {
    $existing = $db->prepare("SELECT * FROM users WHERE fee_student_id = ? LIMIT 1");
    $existing->execute([$feeStudentId]);
    $user = $existing->fetch();

    $tierId = determineTierId($studentData, $db);
    $email  = null;

    if ($user) {
        if ($user['full_name'] !== $studentData['full_name']) {
            $email = generateKimcEmail($studentData['full_name'], $db);
        } else {
            $email = $user['email'];
        }

        $stmt = $db->prepare(
            "UPDATE users
             SET full_name = ?, email = ?, phone = ?, department = ?, tier_id = ?, is_active = ?
             WHERE user_id = ?"
        );
        $stmt->execute([
            $studentData['full_name'],
            $email,
            $studentData['phone'] ?: null,
            $studentData['programme'] ?: null,
            $tierId,
            $studentData['is_active'] ? 1 : 0,
            $user['user_id'],
        ]);

        return ['user_id' => $user['user_id'], 'email' => $email];
    }

    $duplicate = $db->prepare("SELECT user_id FROM users WHERE student_id = ? LIMIT 1");
    $duplicate->execute([$studentData['student_id']]);
    if ($duplicate->fetch()) {
        return ['user_id' => 0, 'email' => null];
    }

    $email = generateKimcEmail($studentData['full_name'], $db);
    $stmt = $db->prepare(
        "INSERT INTO users (student_id, full_name, email, phone, department, tier_id, fee_student_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->execute([
        $studentData['student_id'],
        $studentData['full_name'],
        $email,
        $studentData['phone'] ?: null,
        $studentData['programme'] ?: null,
        $tierId,
        $feeStudentId,
    ]);

    return ['user_id' => $db->lastInsertId(), 'email' => $email];
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

require_api_login();
require_csrf_token();

$mysqli = db();
$sql = 'SELECT d.id AS department_id, d.name AS department_name, ct.id AS concern_type_id, ct.name AS concern_type_name
        FROM departments d
        LEFT JOIN concern_types ct ON ct.department_id = d.id AND ct.active = 1
        WHERE d.active = 1
        ORDER BY d.name ASC, ct.name ASC';
$res = $mysqli->query($sql);

if (!$res) {
    json_response(['ok' => false, 'error' => 'Failed to load departments.'], 500);
}

$departments = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $deptId = (int) $row['department_id'];
        if (!isset($departments[$deptId])) {
            $departments[$deptId] = [
                'id' => $deptId,
                'name' => (string) $row['department_name'],
                'types' => [],
            ];
        }
        if ($row['concern_type_id'] !== null) {
            $departments[$deptId]['types'][] = [
                'id' => (int) $row['concern_type_id'],
                'name' => (string) $row['concern_type_name'],
            ];
        }
    }
}

json_response(['ok' => true, 'departments' => array_values($departments)]);

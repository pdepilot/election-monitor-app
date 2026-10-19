<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';

/** Roles that require explicit geographic scope (empty scope = no data access). */
const SCOPE_ROLES = ['supervisor', 'collation_officer', 'viewer'];
const SCOPE_MANAGERS = ['super_admin', 'admin'];

function scope_schema_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            db()->query('SELECT user_id, ward_id FROM user_ward_scopes LIMIT 0');
            db()->query('SELECT user_id, polling_unit_id FROM user_unit_scopes LIMIT 0');
            $ready = true;
        } catch (Throwable $e) {
            $ready = false;
        }
    }

    return $ready;
}

function scope_is_manager(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), SCOPE_MANAGERS, true);
}

function scope_is_scoped_role(string $role): bool
{
    return in_array($role, SCOPE_ROLES, true);
}

/**
 * Organisation-wide roles bypass geographic tables. Scoped roles never fall back to all wards.
 */
function scope_is_org_wide(array $user): bool
{
    return scope_is_manager($user);
}

/**
 * @return list<int>
 */
function scope_ward_ids_for(int $userId): array
{
    if (!scope_schema_ready() || $userId < 1) {
        return [];
    }
    $stmt = db()->prepare('SELECT ward_id FROM user_ward_scopes WHERE user_id = :id ORDER BY ward_id');
    $stmt->execute(['id' => $userId]);
    $ids = [];
    foreach ($stmt as $row) {
        $ids[] = (int) $row['ward_id'];
    }

    return $ids;
}

/**
 * @return list<int>
 */
function scope_unit_ids_for(int $userId): array
{
    if (!scope_schema_ready() || $userId < 1) {
        return [];
    }
    $stmt = db()->prepare('SELECT polling_unit_id FROM user_unit_scopes WHERE user_id = :id ORDER BY polling_unit_id');
    $stmt->execute(['id' => $userId]);
    $ids = [];
    foreach ($stmt as $row) {
        $ids[] = (int) $row['polling_unit_id'];
    }

    return $ids;
}

/**
 * Polling unit IDs the user may access: union of ward-scoped units and explicit unit scopes.
 * Returns null for org-wide managers (no SQL filter). Empty list means no access.
 *
 * @param array{id: int, role: string} $user
 * @return list<int>|null
 */
function scope_accessible_unit_ids(array $user): ?array
{
    if (scope_is_org_wide($user)) {
        return null;
    }
    if ($user['role'] === 'polling_agent') {
        $stmt = db()->prepare('SELECT id FROM polling_units WHERE agent_user_id = :id AND is_active = 1');
        $stmt->execute(['id' => (int) $user['id']]);
        $ids = [];
        foreach ($stmt as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
    if (!scope_is_scoped_role((string) $user['role']) || !scope_schema_ready()) {
        return [];
    }
    $userId = (int) $user['id'];
    $wards = scope_ward_ids_for($userId);
    $units = scope_unit_ids_for($userId);
    $ids = $units;
    if ($wards !== []) {
        $placeholders = implode(',', array_fill(0, count($wards), '?'));
        $stmt = db()->prepare("SELECT id FROM polling_units WHERE ward_id IN ({$placeholders})");
        foreach ($wards as $i => $wid) {
            $stmt->bindValue($i + 1, $wid, PDO::PARAM_INT);
        }
        $stmt->execute();
        foreach ($stmt as $row) {
            $ids[] = (int) $row['id'];
        }
    }

    return array_values(array_unique($ids));
}

/**
 * Ward IDs with full ward-level access (explicit ward assignment only).
 * Unit-only scopes do not grant ward aggregates or ward collation.
 *
 * @param array{id: int, role: string} $user
 * @return list<int>|null null = all wards
 */
function scope_accessible_ward_ids(array $user): ?array
{
    if (scope_is_org_wide($user)) {
        return null;
    }
    if ($user['role'] === 'polling_agent') {
        $stmt = db()->prepare(
            'SELECT DISTINCT ward_id FROM polling_units WHERE agent_user_id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => (int) $user['id']]);
        $ids = [];
        foreach ($stmt as $row) {
            $ids[] = (int) $row['ward_id'];
        }

        return $ids;
    }
    if (!scope_is_scoped_role((string) $user['role']) || !scope_schema_ready()) {
        return [];
    }

    return scope_ward_ids_for((int) $user['id']);
}

/**
 * Ward IDs useful for list filters: wards that contain at least one accessible unit.
 *
 * @param array{id: int, role: string} $user
 * @return list<int>|null null = all wards
 */
function scope_filter_ward_ids(array $user): ?array
{
    if (scope_is_org_wide($user)) {
        return null;
    }
    $unitIds = scope_accessible_unit_ids($user);
    if ($unitIds === null) {
        return null;
    }
    if ($unitIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($unitIds), '?'));
    $stmt = db()->prepare("SELECT DISTINCT ward_id FROM polling_units WHERE id IN ({$placeholders})");
    foreach ($unitIds as $i => $uid) {
        $stmt->bindValue($i + 1, $uid, PDO::PARAM_INT);
    }
    $stmt->execute();
    $ids = [];
    foreach ($stmt as $row) {
        $ids[] = (int) $row['ward_id'];
    }

    return $ids;
}

/**
 * @param array{id: int, role: string} $user
 * @param array<string, mixed> $unit must include id and preferably ward_id, agent_user_id, is_active
 */
function scope_can_unit(array $user, array $unit): bool
{
    $role = (string) ($user['role'] ?? '');
    if (scope_is_org_wide($user)) {
        return true;
    }
    if ($role === 'polling_agent') {
        return (int) ($unit['is_active'] ?? 0) === 1
            && (int) ($unit['agent_user_id'] ?? 0) === (int) $user['id'];
    }
    if (!scope_is_scoped_role($role)) {
        return false;
    }
    $unitId = (int) ($unit['id'] ?? 0);
    if ($unitId < 1) {
        return false;
    }
    $allowed = scope_accessible_unit_ids($user);

    return is_array($allowed) && in_array($unitId, $allowed, true);
}

/**
 * @param array{id: int, role: string} $user
 */
function scope_can_ward(array $user, int $wardId): bool
{
    if ($wardId < 1) {
        return false;
    }
    if (scope_is_org_wide($user)) {
        return true;
    }
    $allowed = scope_accessible_ward_ids($user);

    return is_array($allowed) && in_array($wardId, $allowed, true);
}

/**
 * SQL fragment restricting polling_units alias to the user's scope.
 * Empty accessible set yields an always-false predicate (fail closed).
 *
 * @param array{id: int, role: string} $user
 * @return array{sql: string, params: array<string, int>}
 */
function scope_sql_units(array $user, string $alias = 'pu'): array
{
    $ids = scope_accessible_unit_ids($user);
    if ($ids === null) {
        return ['sql' => '1=1', 'params' => []];
    }
    if ($ids === []) {
        return ['sql' => '1=0', 'params' => []];
    }
    $params = [];
    $parts = [];
    foreach ($ids as $i => $id) {
        $key = 'scope_u' . $i;
        $parts[] = ':' . $key;
        $params[$key] = $id;
    }

    return ['sql' => $alias . '.id IN (' . implode(',', $parts) . ')', 'params' => $params];
}

/**
 * @param array{id: int, role: string} $user
 * @return array{sql: string, params: array<string, int>}
 */
function scope_sql_wards(array $user, string $alias = 'w'): array
{
    return scope_sql_id_list(scope_accessible_ward_ids($user), $alias, 'scope_w');
}

/**
 * Filter-dropdown wards (includes wards that appear only via unit scopes).
 *
 * @param array{id: int, role: string} $user
 * @return array{sql: string, params: array<string, int>}
 */
function scope_sql_filter_wards(array $user, string $alias = 'w'): array
{
    return scope_sql_id_list(scope_filter_ward_ids($user), $alias, 'scope_fw');
}

/**
 * @param list<int>|null $ids
 * @return array{sql: string, params: array<string, int>}
 */
function scope_sql_id_list(?array $ids, string $alias, string $prefix): array
{
    if ($ids === null) {
        return ['sql' => '1=1', 'params' => []];
    }
    if ($ids === []) {
        return ['sql' => '1=0', 'params' => []];
    }
    $params = [];
    $parts = [];
    foreach ($ids as $i => $id) {
        $key = $prefix . $i;
        $parts[] = ':' . $key;
        $params[$key] = $id;
    }

    return ['sql' => $alias . '.id IN (' . implode(',', $parts) . ')', 'params' => $params];
}

/**
 * @return array{ok: bool, error: string}
 */
function scope_assign_ward(int $userId, int $wardId, int $actorId): array
{
    if (!scope_schema_ready()) {
        return ['ok' => false, 'error' => 'Scope assignment is unavailable until the migration is applied.'];
    }
    $user = scope_load_scoped_user($userId);
    if ($user === null) {
        return ['ok' => false, 'error' => 'That account cannot receive geographic scope assignments.'];
    }
    $ward = db()->prepare('SELECT id, name FROM wards WHERE id = :id LIMIT 1');
    $ward->execute(['id' => $wardId]);
    $wardRow = $ward->fetch();
    if (!is_array($wardRow)) {
        return ['ok' => false, 'error' => 'That ward was not found.'];
    }
    try {
        $insert = db()->prepare(
            'INSERT INTO user_ward_scopes (user_id, ward_id, assigned_by) VALUES (:user, :ward, :actor)'
        );
        $insert->execute(['user' => $userId, 'ward' => $wardId, 'actor' => $actorId > 0 ? $actorId : null]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'That ward is already assigned to this account.'];
        }
        throw $e;
    }
    auth_audit(
        $actorId,
        'scope_assign_ward',
        $userId,
        'Assigned ward ' . $wardRow['name'] . ' (#' . $wardId . ') to ' . $user['username'] . '.'
    );

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function scope_revoke_ward(int $userId, int $wardId, int $actorId): array
{
    if (!scope_schema_ready()) {
        return ['ok' => false, 'error' => 'Scope assignment is unavailable until the migration is applied.'];
    }
    $user = scope_load_scoped_user($userId);
    if ($user === null) {
        return ['ok' => false, 'error' => 'That account cannot receive geographic scope assignments.'];
    }
    $delete = db()->prepare('DELETE FROM user_ward_scopes WHERE user_id = :user AND ward_id = :ward');
    $delete->execute(['user' => $userId, 'ward' => $wardId]);
    if ($delete->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'That ward assignment was not found.'];
    }
    auth_audit($actorId, 'scope_revoke_ward', $userId, 'Revoked ward #' . $wardId . ' from ' . $user['username'] . '.');

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function scope_assign_unit(int $userId, int $unitId, int $actorId, ?int $expectedWardId = null): array
{
    if (!scope_schema_ready()) {
        return ['ok' => false, 'error' => 'Scope assignment is unavailable until the migration is applied.'];
    }
    $user = scope_load_scoped_user($userId);
    if ($user === null) {
        return ['ok' => false, 'error' => 'That account cannot receive geographic scope assignments.'];
    }
    if ((string) $user['role'] === 'supervisor') {
        return ['ok' => false, 'error' => 'Supervisors are assigned by ward. Use a ward assignment instead.'];
    }
    $unit = db()->prepare(
        'SELECT id, unit_code, name, ward_id FROM polling_units WHERE id = :id LIMIT 1'
    );
    $unit->execute(['id' => $unitId]);
    $unitRow = $unit->fetch();
    if (!is_array($unitRow)) {
        return ['ok' => false, 'error' => 'That polling unit was not found.'];
    }
    if ($expectedWardId !== null && $expectedWardId > 0 && (int) $unitRow['ward_id'] !== $expectedWardId) {
        return ['ok' => false, 'error' => 'That polling unit does not belong to the selected ward.'];
    }
    try {
        $insert = db()->prepare(
            'INSERT INTO user_unit_scopes (user_id, polling_unit_id, assigned_by) VALUES (:user, :unit, :actor)'
        );
        $insert->execute(['user' => $userId, 'unit' => $unitId, 'actor' => $actorId > 0 ? $actorId : null]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'That polling unit is already assigned to this account.'];
        }
        throw $e;
    }
    auth_audit(
        $actorId,
        'scope_assign_unit',
        $userId,
        'Assigned unit ' . $unitRow['unit_code'] . ' (#' . $unitId . ') to ' . $user['username'] . '.'
    );

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function scope_revoke_unit(int $userId, int $unitId, int $actorId): array
{
    if (!scope_schema_ready()) {
        return ['ok' => false, 'error' => 'Scope assignment is unavailable until the migration is applied.'];
    }
    $user = scope_load_scoped_user($userId);
    if ($user === null) {
        return ['ok' => false, 'error' => 'That account cannot receive geographic scope assignments.'];
    }
    $delete = db()->prepare('DELETE FROM user_unit_scopes WHERE user_id = :user AND polling_unit_id = :unit');
    $delete->execute(['user' => $userId, 'unit' => $unitId]);
    if ($delete->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'That polling-unit assignment was not found.'];
    }
    auth_audit($actorId, 'scope_revoke_unit', $userId, 'Revoked unit #' . $unitId . ' from ' . $user['username'] . '.');

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{id: int, username: string, role: string, full_name: string}|null
 */
function scope_load_scoped_user(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, username, role, full_name, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row) || (int) $row['is_active'] !== 1 || !scope_is_scoped_role((string) $row['role'])) {
        return null;
    }

    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'role' => (string) $row['role'],
        'full_name' => (string) $row['full_name'],
    ];
}

/**
 * @return list<array{id: int, username: string, full_name: string, role: string}>
 */
function scope_list_scoped_users(): array
{
    $roles = "'" . implode("','", SCOPE_ROLES) . "'";
    $rows = db()->query(
        "SELECT id, username, full_name, role FROM users
         WHERE is_active = 1 AND role IN ({$roles})
         ORDER BY role, full_name, username"
    )->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'role' => (string) $row['role'],
        ];
    }

    return $out;
}

/**
 * @return array{wards: list<array{id: int, name: string, assigned_at: string}>, units: list<array{id: int, unit_code: string, name: string, ward_id: int, ward_name: string, assigned_at: string}>}
 */
function scope_assignments_for(int $userId): array
{
    $wards = [];
    $units = [];
    if (!scope_schema_ready()) {
        return ['wards' => $wards, 'units' => $units];
    }
    $wStmt = db()->prepare(
        'SELECT s.ward_id AS id, w.name, s.assigned_at
         FROM user_ward_scopes s
         INNER JOIN wards w ON w.id = s.ward_id
         WHERE s.user_id = :id
         ORDER BY w.name'
    );
    $wStmt->execute(['id' => $userId]);
    foreach ($wStmt as $row) {
        $wards[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'assigned_at' => (string) $row['assigned_at'],
        ];
    }
    $uStmt = db()->prepare(
        'SELECT s.polling_unit_id AS id, pu.unit_code, pu.name, pu.ward_id, w.name AS ward_name, s.assigned_at
         FROM user_unit_scopes s
         INNER JOIN polling_units pu ON pu.id = s.polling_unit_id
         INNER JOIN wards w ON w.id = pu.ward_id
         WHERE s.user_id = :id
         ORDER BY w.name, pu.unit_code'
    );
    $uStmt->execute(['id' => $userId]);
    foreach ($uStmt as $row) {
        $units[] = [
            'id' => (int) $row['id'],
            'unit_code' => (string) $row['unit_code'],
            'name' => (string) $row['name'],
            'ward_id' => (int) $row['ward_id'],
            'ward_name' => (string) $row['ward_name'],
            'assigned_at' => (string) $row['assigned_at'],
        ];
    }

    return ['wards' => $wards, 'units' => $units];
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/results.php';

/**
 * @return array{id: int, username: string, email: string, full_name: string, role: string, last_login_at?: ?string}
 */
function agent_require(): array
{
    $user = auth_user();
    if ($user === null) {
        redirect_to('portal/login.php');
    }
    if ($user['role'] !== 'polling_agent') {
        render_forbidden($user);
        exit;
    }

    return $user;
}

/**
 * Active polling units currently assigned to this agent.
 * The assignment is read from the database on each call.
 *
 * @return list<array<string, mixed>>
 */
function agent_assigned_units(int $userId): array
{
    $statement = db()->prepare(
        "SELECT pu.id, pu.unit_code, pu.name, pu.lga_name,
                w.name AS ward_name,
                r.id AS result_id, r.status, r.accredited_voters, r.rejected_ballots,
                r.submitted_at, r.updated_at,
                COALESCE(v.valid_votes, 0) AS valid_votes
         FROM polling_units pu
         INNER JOIN wards w ON w.id = pu.ward_id
         LEFT JOIN polling_unit_results r ON r.polling_unit_id = pu.id
         LEFT JOIN (
            SELECT result_id, SUM(votes) AS valid_votes
            FROM polling_unit_result_votes
            GROUP BY result_id
         ) v ON v.result_id = r.id
         WHERE pu.agent_user_id = :agent AND pu.is_active = 1
         ORDER BY w.name, pu.unit_code"
    );
    $statement->bindValue(':agent', $userId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Candidate scores saved on one result sheet for a unit assigned to this agent.
 *
 * @return list<array{party: string, full_name: string, votes: int}>
 */
function agent_result_scores(int $resultId, int $agentUserId): array
{
    if ($resultId < 1 || $agentUserId < 1) {
        return [];
    }
    $statement = db()->prepare(
        'SELECT c.party, c.full_name, v.votes
         FROM polling_unit_result_votes v
         INNER JOIN candidates c ON c.id = v.candidate_id
         INNER JOIN polling_unit_results r ON r.id = v.result_id
         INNER JOIN polling_units pu ON pu.id = r.polling_unit_id
         WHERE v.result_id = :id AND pu.agent_user_id = :agent
         ORDER BY c.sort_order, c.party, c.full_name'
    );
    $statement->bindValue(':id', $resultId, PDO::PARAM_INT);
    $statement->bindValue(':agent', $agentUserId, PDO::PARAM_INT);
    $statement->execute();
    $rows = [];
    foreach ($statement as $row) {
        $rows[] = [
            'party' => (string) $row['party'],
            'full_name' => (string) $row['full_name'],
            'votes' => (int) $row['votes'],
        ];
    }

    return $rows;
}

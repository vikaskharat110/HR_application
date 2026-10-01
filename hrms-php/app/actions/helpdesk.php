<?php
/**
 * Helpdesk — port of lib/actions/helpdesk.ts. Every ticket lookup is scoped
 * to the caller's company (A4 — the original detail page read any ticket id).
 */
declare(strict_types=1);

const TICKET_CATEGORIES = ['IT', 'Payroll', 'Finance', 'Facilities', 'HR Policy', 'Other'];
const TICKET_PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];
const TICKET_STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
/** FR-110: category → owning queue role (everything else goes to HR). */
const TICKET_QUEUE_ROLE = ['IT' => 'IT_ADMIN', 'Payroll' => 'PAYROLL_ADMIN', 'Finance' => 'FINANCE'];
const TICKET_QUEUE_OWNERS = ['IT_ADMIN', 'PAYROLL_ADMIN', 'FINANCE'];

/** A ticket in the caller's company, or null. */
function find_company_ticket(array $auth, string $ticketId): ?array
{
    return is_valid_id($ticketId) ? db_one('SELECT * FROM Ticket WHERE id = ? AND companyId = ?', [$ticketId, $auth['companyId'] ?? '']) : null;
}

/** @return string redirect to the new ticket */
function action_create_ticket(array $auth, array $in): string
{
    if (!$auth['employeeId'] || !$auth['companyId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $category = v_enum((string)($in['category'] ?? ''), TICKET_CATEGORIES, 'category');
    $priority = v_enum((string)($in['priority'] ?? 'MEDIUM'), TICKET_PRIORITIES, 'priority');
    $subject = v_required(trim((string)($in['subject'] ?? '')), 'Subject', 255);
    $description = v_max(normalize_line_endings((string)($in['description'] ?? '')), 'Description', 20000);
    $queueRole = TICKET_QUEUE_ROLE[$category] ?? 'HR';
    $id = db_tx(function () use ($auth, $category, $priority, $subject, $description, $queueRole) {
        $owner = db_one('SELECT u.id, e.id AS employeeId FROM `User` u LEFT JOIN Employee e ON e.userId = u.id
                          WHERE u.companyId = ? AND u.role = ? ORDER BY u.createdAt, u.id LIMIT 1', [$auth['companyId'], $queueRole]);
        $id = db_insert('Ticket', ['companyId' => $auth['companyId'], 'raisedById' => $auth['employeeId'], 'category' => $category,
            'subject' => $subject, 'description' => $description, 'priority' => $priority, 'assignedToId' => $owner['employeeId'] ?? null]);
        if ($owner) {
            notify($owner['id'], 'New helpdesk ticket', "{$auth['name']} raised: $subject", "/dashboard/helpdesk/$id");
        }
        audit($auth, 'CREATE', 'Ticket', $id);
        return $id;
    });
    return "/dashboard/helpdesk/$id";
}

function action_add_ticket_comment(array $auth, string $ticketId, string $message): void
{
    if (!$auth['employeeId']) {
        throw new UserError('No employee record linked to this account.');
    }
    $message = trim($message);
    if ($message === '') {
        return; // original: empty replies are ignored
    }
    v_max($message, 'Message', 5000);
    $t = find_company_ticket($auth, $ticketId) ?? throw new UserError('Ticket not found.');
    if (!is_managerial($auth) && $t['raisedById'] !== $auth['employeeId'] && $t['assignedToId'] !== $auth['employeeId']) {
        throw new UserError('You are not authorized to comment on this ticket.');
    }
    db_tx(function () use ($auth, $t, $message) {
        db_insert('TicketComment', ['ticketId' => $t['id'], 'authorId' => $auth['employeeId'], 'message' => $message]);
        // Notify the first of (raiser, assignee) who isn't the author.
        foreach ([employee_user_id($t['raisedById']), employee_user_id($t['assignedToId'])] as $uid) {
            if ($uid && $uid !== $auth['sub']) {
                notify($uid, 'New comment on your ticket', $message, "/dashboard/helpdesk/{$t['id']}");
                break;
            }
        }
    });
}

function action_update_ticket_status(array $auth, string $ticketId, string $status): void
{
    if (!has_role($auth, ['COMPANY_ADMIN', 'HR', 'MANAGER', 'IT_ADMIN', 'PAYROLL_ADMIN', 'FINANCE'])) {
        throw new UserError('You are not authorized to update tickets.');
    }
    $status = v_enum($status, TICKET_STATUSES, 'status');
    $t = find_company_ticket($auth, $ticketId) ?? throw new UserError('Ticket not found.');
    if (has_role($auth, TICKET_QUEUE_OWNERS) && $t['assignedToId'] !== $auth['employeeId']) {
        throw new UserError('You can only update tickets assigned to your queue.');
    }
    db_tx(function () use ($auth, $t, $status) {
        db_update('Ticket', ['status' => $status, 'resolvedAt' => in_array($status, ['RESOLVED', 'CLOSED'], true) ? now() : null], 'id = ?', [$t['id']]);
        audit($auth, 'STATUS_CHANGE', 'Ticket', $t['id'], ['status' => $status]);
    });
}

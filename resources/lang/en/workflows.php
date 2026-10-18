<?php

declare(strict_types=1);

return [
    'system' => 'System',

    'errors' => [
        'guard' => 'This transition is not possible right now.',
        'unknown' => 'The transition ":transition" does not exist.',
        'invalid_state' => '":transition" is not possible from the state ":state".',
        'unauthorized' => 'You are not allowed to do this.',
        'awaiting_approval' => 'This record is waiting for approval.',
        'no_approvers' => 'Nobody can approve the step ":step".',
        'not_pending' => 'This approval is no longer pending.',
        'comment_required' => 'Please add a comment.',
        'invalid_delegate' => 'This person cannot take over the approval.',
        'missing_record' => 'The record no longer exists.',
        'direct_change' => 'The ":column" attribute changes only through workflow transitions.',
    ],

    'diagram' => [
        'title' => ':workflow workflow',
        'summary' => ':states states and :transitions transitions.',
        'current' => 'Current state: :state.',
        'current_short' => 'current state',
        'initial' => 'initial state',
        'no_transitions' => 'no transitions',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'from_any' => ':transition (from any state)',
    ],

    'notifications' => [
        'open' => 'Open',
        'transitioned' => [
            'title' => ':record is now :state',
            'body' => ':name: :transition.',
        ],
        'requested' => [
            'title' => ':record needs your approval',
            'body' => ':name asked for ":transition" (:step).',
        ],
        'approved' => [
            'title' => ':record was approved',
            'body' => '":transition" was approved; it is now :state.',
        ],
        'rejected' => [
            'title' => ':record was rejected',
            'body' => ':name rejected ":transition".',
        ],
        'delegated' => [
            'title' => ':record needs your approval',
            'body' => ':name handed the step ":step" over to you.',
        ],
        'escalated' => [
            'title' => 'Escalated: :record needs your approval',
            'body' => ':name did not decide on ":step" in time.',
        ],
        'reminder' => [
            'title' => 'Reminder: :record is waiting for you',
            'body' => 'Your decision on ":step" is still pending.',
            'overdue' => 'Your decision on ":step" is overdue.',
        ],
    ],

    'actions' => [
        'group' => 'Workflow',
        'transition' => [
            'description' => 'The record will move to :state.',
            'approval_description' => 'This needs approval (:steps) before the record moves to :state.',
            'submit_for_approval' => 'Send for approval',
            'requested' => 'Sent for approval',
            'done' => 'Moved to :state',
        ],
        'approve' => [
            'label' => 'Approve',
            'heading' => 'Approve',
            'submit' => 'Approve',
            'done' => 'Approved',
            'summary' => ':name asked for ":transition" (:step).',
        ],
        'reject' => [
            'label' => 'Reject',
            'heading' => 'Reject',
            'submit' => 'Reject',
            'done' => 'Rejected',
        ],
        'delegate' => [
            'label' => 'Delegate',
            'heading' => 'Delegate this approval',
            'description' => 'The person you choose decides instead of you.',
            'submit' => 'Delegate',
            'done' => 'Approval delegated',
        ],
        'reassign' => [
            'label' => 'Reassign',
            'heading' => 'Reassign an approval',
            'approval' => 'Approval',
            'submit' => 'Reassign',
            'done' => 'Approval reassigned',
        ],
        'withdraw' => [
            'label' => 'Withdraw request',
            'heading' => 'Withdraw the approval request?',
            'description' => 'Pending approvals are cancelled and the record goes back to where it was.',
            'submit' => 'Withdraw',
            'done' => 'Request withdrawn',
        ],
        'diagram' => [
            'label' => 'View workflow',
            'close' => 'Close',
        ],
    ],

    'fields' => [
        'comment' => 'Comment',
        'comment_required' => 'Explain your decision…',
        'comment_optional' => 'Add a comment (optional)…',
        'approver' => 'Approver',
    ],

    'columns' => [
        'state' => 'State',
        'awaiting_approval' => 'Awaiting approval',
    ],

    'statuses' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'delegated' => 'Delegated',
        'escalated' => 'Escalated',
        'skipped' => 'Skipped',
    ],

    'timeline' => [
        'label' => 'Workflow history',
        'empty' => 'No workflow history yet.',
        'due' => 'Due',
        'pending' => 'Waiting for approval: :transition (:step)',
        'pending_any' => 'Step :step of :steps. One approval is enough.',
        'pending_all' => 'Step :step of :steps. Everyone has to approve.',
        'events' => [
            'created' => ':name created it',
            'transitioned' => ':name: :transition',
            'transitioned_approved' => ':transition approved',
            'requested' => ':name asked for approval: :transition',
            'approved' => ':name approved (:step)',
            'rejected' => ':name rejected (:step)',
            'delegated' => ':name delegated :step to :to',
            'reassigned' => ':name reassigned :step from :from to :to',
            'escalated' => ':step escalated from :from to :to',
            'cancelled' => ':name withdrew the request: :transition',
        ],
        'kinds' => [
            'created' => 'Created',
            'transitioned' => 'Transition',
            'requested' => 'Approval requested',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'delegated' => 'Delegated',
            'reassigned' => 'Reassigned',
            'escalated' => 'Escalated',
            'cancelled' => 'Withdrawn',
        ],
    ],

    'inbox' => [
        'title' => 'Approvals',
        'subheading' => 'Everything waiting for your decision.',
        'navigation' => 'Approvals',
        'badge_tooltip' => 'Waiting for your approval',
        'columns' => [
            'record' => 'Record',
            'step' => 'Step',
            'requester' => 'Requested by',
            'requested' => 'Requested',
            'due' => 'Due',
        ],
        'no_due_date' => 'No due date',
        'filters' => [
            'overdue' => 'Overdue only',
        ],
        'empty_heading' => 'Nothing to approve',
        'empty_description' => 'Approvals assigned to you will show up here.',
        'bulk_approve' => [
            'label' => 'Approve selected',
            'heading' => 'Approve the selected items?',
            'description' => 'Each item is checked again before it is approved.',
            'done' => '{0} Nothing was approved|{1} :count item approved|[2,*] :count items approved',
            'skipped' => '{1} :count item could not be approved|[2,*] :count items could not be approved',
        ],
    ],

    'mail_templates' => [
        'group' => 'Workflows',
        'notification' => 'Workflow notification',
    ],
];

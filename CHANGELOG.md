# Changelog

All notable changes to `alfasix/alfakit-workflows` are documented in this file.

## Unreleased

- Initial release: `Workflow`, `State`, `Transition` and `ApprovalStep` definitions validated on first use, the `HasWorkflow` trait (initial state, guarded state column, `transition()`, `canTransition()`, history and request queries, `whereWorkflowState()`), transitions applied in a transaction on the locked row with guards, policies, forms, comments, `using()` / `after()` hooks and notifications; single, any-of, all-of and sequential approval steps with `onlyWhen()`, due dates, reminders, delegation, reassignment, withdrawal and escalation (`alfakit-workflows:process`); `WorkflowActions`, `WorkflowTimeline`, `WorkflowDiagram` (accessible server-side SVG), `WorkflowStateColumn`, `WorkflowStateFilter`, `WorkflowStateEntry` and the `ApprovalsInbox` page with bulk approve; `TransitionApplied`, `ApprovalRequested`, `ApprovalGranted`, `ApprovalRejected` and `ApprovalDelegated` events; database and optional mail notifications; tenant-scoped requests, inbox and candidates; a full audit trail in plugin tables; translations in 13 locales.

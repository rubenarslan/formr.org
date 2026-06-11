// Admin GET-render pages to sweep for CSP violations.
//
// Only render-only pages — NO state-changing GETs (delete_*, empty_run,
// snip, reset, expire, mass-mail send, export_*), several of which execute
// on GET. `needs: 'run' | 'survey'` entries are templated with names the
// crawler discovers from the list pages (so the sweep is read-only — it does
// not create or delete anything on the shared dev instance).
//
// This list is a curated starting point; the csp-sweep workflow's
// enumeration phase can regenerate/extend it from the Admin*Controller
// classes.

module.exports = [
    // account / global
    { path: '/admin', needs: null },
    { path: '/admin/account', needs: null },
    { path: '/admin/account/manage_two_factor', needs: null },
    { path: '/admin/osf', needs: null },
    { path: '/admin/account/', needs: null },
    { path: '/admin/account/login', needs: null },
    { path: '/admin/account/register', needs: null },
    { path: '/admin/account/reset-password', needs: null },
    { path: '/admin/account/two-factor', needs: null },
    { path: '/admin/account/manage-two-factor', needs: null },
    { path: '/admin/account/setup-two-factor', needs: null },

    // run — list + create form (no name needed)
    { path: '/admin/run', needs: null },
    { path: '/admin/run/add_run', needs: null },
    { path: '/admin/run/list', needs: null },

    // run — detail pages (need a run name)
    { path: '/admin/run/{run}', needs: 'run' },
    { path: '/admin/run/{run}/settings', needs: 'run' },
    { path: '/admin/run/{run}/user_overview', needs: 'run' },
    { path: '/admin/run/{run}/upload_files', needs: 'run' },
    { path: '/admin/run/{run}/email_log', needs: 'run' },
    { path: '/admin/run/{run}/push_message_log', needs: 'run' },
    { path: '/admin/run/{run}/sessions_queue', needs: 'run' },
    { path: '/admin/run/{run}/random_groups', needs: 'run' },
    { path: '/admin/run/{run}/user_detail', needs: 'run' },
    { path: '/admin/run/{run}/create_new_named_session', needs: 'run' },
    { path: '/admin/run/{run}/rename_run', needs: 'run' },
    { path: '/admin/run/{run}/overview', needs: 'run' },

    // survey — list + create form
    { path: '/admin/survey', needs: null },
    { path: '/admin/survey/add_survey', needs: null },
    { path: '/admin/survey/list', needs: null },

    // survey — detail pages (need a survey name)
    { path: '/admin/survey/{survey}', needs: 'survey' },
    { path: '/admin/survey/{survey}/show_item_table', needs: 'survey' },
    { path: '/admin/survey/{survey}/show_itemdisplay', needs: 'survey' },
    { path: '/admin/survey/{survey}/show_results', needs: 'survey' },
    { path: '/admin/survey/{survey}/upload_items', needs: 'survey' },
    { path: '/admin/survey/{survey}/delete_results', needs: 'survey' },
    { path: '/admin/survey/{survey}/delete_study', needs: 'survey' },

    // mail
    { path: '/admin/mail', needs: null },

    // advanced (superadmin — may 403; crawler tolerates non-200)
    { path: '/admin/advanced/info', needs: null },
    { path: '/admin/advanced/active_users', needs: null },
    { path: '/admin/advanced/user_management', needs: null },
    { path: '/admin/advanced/runs_management', needs: null },
    { path: '/admin/advanced/timing', needs: null },
    { path: '/admin/advanced/test-opencpu', needs: null },
    { path: '/admin/advanced/test-opencpu-speed', needs: null },
    { path: '/admin/advanced/user-management', needs: null },
    { path: '/admin/advanced/active-users', needs: null },
    { path: '/admin/advanced/runs-management', needs: null },
    { path: '/admin/advanced/content-settings', needs: null },
    { path: '/admin/advanced/user-details', needs: null },
];

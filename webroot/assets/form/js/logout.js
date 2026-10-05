// The page a participant's logout shows (RunController::logoutAction): the
// run's offline queue goes with the session, however the participant got
// there — the link on the run's settings page, a typed or bookmarked
// address, a link in a label. Queued answers are plain text on the device.
// The database belongs to the origin, which on a path-routed study domain
// every run shares, so the run's own entries go (wipeQueueForRun); on a
// study subdomain that is all of them.
import { queueDatabaseExists, wipeQueueForRun } from './offline/queue.js';

const scope = document.querySelector('[data-fmr-wipe-queue]');
if (scope) {
    // Without a database there is nothing to delete, and wiping would
    // create one.
    queueDatabaseExists()
        .then((exists) => (exists ? wipeQueueForRun(scope.dataset.fmrWipeQueue) : null))
        .catch(() => {});
}

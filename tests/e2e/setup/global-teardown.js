// Ends the notification pause global-setup.js started (Notification::paused).
const fs = require('node:fs');
const path = require('node:path');

module.exports = async () => {
    try { fs.unlinkSync(path.resolve(__dirname, '../../../.notifications_paused')); } catch (e) { /* already gone */ }
};

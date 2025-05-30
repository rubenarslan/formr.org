import 'vanilla-cookieconsent/dist/cookieconsent.css'; // Import the CSS
import { showPreferences } from 'vanilla-cookieconsent';
import { initializePushNotifications, urlBase64ToUint8Array } from './components/PWAInstaller.js';


// Helper function to make AJAX calls (using fetch API with async/await)
async function ajaxRequest(url, method = 'POST', body = null) {
    const options = {
        method: method,
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded', // Standard for PHP form posts
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    if (body) {
        const urlParams = new URLSearchParams(body);
        options.body = urlParams.toString();
        console.log('Request body being sent:', options.body);
    }
    try {
        const response = await fetch(url, options);
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: `HTTP error! status: ${response.status}` }));
            throw new Error(errorData.error || `HTTP error! status: ${response.status}`);
        }
        return await response.json();
    } catch (error) {
        console.error('AJAX request failed:', error);
        throw error; // Re-throw to be caught by caller
    }
}

/**
 * Initializes the event listener for the 'Manage Cookie Preferences' button.
 */
function initializeCookieConsentButton() {
    const manageCookiesButton = document.getElementById('manage_cookies_button');
    if (manageCookiesButton) {
        manageCookiesButton.addEventListener('click', () => {
            showPreferences();
        });
    }
}

let subscriptionBeingProcessed = null;

$(document).ready(() => {
    initializeCookieConsentButton();
    initializePushNotifications();
    
    // Listen for push subscription changes from PWAInstaller
    document.addEventListener('pushSubscriptionChanged', async (event) => {
        const { action, subscription, subscriptionJson } = event.detail;
        if (action === 'subscribed' && subscriptionBeingProcessed !== subscriptionJson) {
            subscriptionBeingProcessed = subscriptionJson;
            
            // Find the settings page subscription input and session token
            const subscriptionInput = document.getElementById('push_subscription_json_settings');

            console.log('Subscription JSON:', subscriptionJson);
            
            if (subscriptionInput) {
                try {
                    // Save subscription to server
                    const basePath = (() => {
                        const parts = window.location.pathname.split('/').filter(Boolean);
                        // Remove 'settings' if it's the last part to get run root
                        if (parts.length > 1 && parts[parts.length - 1] === 'settings') {
                            parts.pop();
                        }
                        return parts.length > 0 ? '/' + parts.join('/') + '/' : '/';
                    })();

                    await ajaxRequest(basePath + 'ajax_save_push_subscription', 'POST', {
                        subscription: subscriptionJson
                    });
                    
                    // Update the hidden input with the subscription
                    subscriptionInput.value = subscriptionJson;
                    
                    console.log('Push subscription saved to server successfully');
                } catch (error) {
                    console.error('Failed to save push subscription to server:', error);
                } finally {
                }
            } else {
                console.warn('Could not find subscription input for saving push subscription');
                subscriptionBeingProcessed = null;
            }
        }
    });
});

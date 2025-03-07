import $ from 'jquery';
import QRCodeStyling from "qr-code-styling";
import 'add-to-homescreen/dist/add-to-homescreen.min.css';
import AddToHomeScreen from 'add-to-homescreen/dist/add-to-homescreen.min.js';

// Add German translations to window.formrLanguage
window.formrTranslations.de = {
    // Installation related
    'Installation component not available. Please try again later.': 'Installationskomponente nicht verfügbar. Bitte versuchen Sie es später erneut.',
    'You are currently using the installed app.': 'Sie verwenden derzeit die installierte App.',
    'Installed': 'Installiert',
    "You've already installed this app. Try opening this page in the installed app.": 'Sie haben diese App bereits installiert. Öffnen Sie diese Seite in der installierten App.',
    "You've already installed this app. Try opening this page in the installed app. If you have uninstalled the app, please just click this button again.": 'Sie haben diese App bereits installiert. Öffnen Sie diese Seite in der installierten App. Wenn Sie die App deinstalliert haben, klicken Sie einfach erneut auf diese Schaltfläche.',
    "This app is not available for installation. Maybe you already installed the app or you need to switch to a different browser.": 'Diese App kann nicht installiert werden. Möglicherweise haben Sie die App bereits installiert oder müssen zu einem anderen Browser wechseln.',
    "Cannot install app": 'App kann nicht installiert werden',
    'Add this app to your home screen for easier access.': 'Fügen Sie diese App zu Ihrem Startbildschirm hinzu für einfacheren Zugriff.',
    'Follow the instructions to add this app to your home screen.': 'Folgen Sie den Anweisungen, um diese App zu Ihrem Startbildschirm hinzuzufügen.',

    // Push notification related
    'Sorry, your browser does not support push notifications.': 'Ihr Browser unterstützt leider keine Push-Benachrichtigungen.',
    'Sorry, push notifications require iOS 16.4 or later.': 'Push-Benachrichtigungen erfordern iOS 16.4 oder höher.',
    'Service worker not registered. Please reload the page and try again.': 'Service Worker nicht registriert. Bitte laden Sie die Seite neu und versuchen Sie es erneut.',
    'Push notifications are enabled.': 'Push-Benachrichtigungen sind aktiviert.',
    'Test notification': 'Test-Benachrichtigung',
    'Show troubleshooting tips': 'Fehlerbehebungstipps anzeigen',
    'Hide troubleshooting tips': 'Fehlerbehebungstipps ausblenden',
    'Notifications Enabled': 'Benachrichtigungen aktiviert',
    'Click the button to enable push notifications.': 'Klicken Sie auf die Schaltfläche, um Push-Benachrichtigungen zu aktivieren.',
    'Enable Notifications': 'Benachrichtigungen aktivieren',
    'You have declined push notifications. You can enable them in your browser settings.': 'Sie haben Push-Benachrichtigungen abgelehnt. Sie können sie in Ihren Browser-Einstellungen aktivieren.',
    'Notifications Blocked': 'Benachrichtigungen blockiert',
    'Processing...': 'Wird verarbeitet...',
    'Error': 'Fehler',
    'Push notifications are already enabled.': 'Push-Benachrichtigungen sind bereits aktiviert.',
    'Push notifications enabled successfully!': 'Push-Benachrichtigungen erfolgreich aktiviert!',
    "A test notification was sent. If you didn't see it, your system settings might be blocking notifications.": 'Eine Test-Benachrichtigung wurde gesendet. Wenn Sie sie nicht sehen, blockieren möglicherweise Ihre Systemeinstellungen Benachrichtigungen.',
    'Note for Android users:': 'Hinweis für Android-Benutzer:',
    'On some Android devices, you may need to restart your browser or add this app to your home screen for notifications to work properly.': 'Auf einigen Android-Geräten müssen Sie möglicherweise Ihren Browser neu starten oder diese App zum Startbildschirm hinzufügen, damit Benachrichtigungen richtig funktionieren.',
    'Server configuration error. Please contact support.': 'Serverkonfigurationsfehler. Bitte kontaktieren Sie den Support.',
    'There was an error setting up push notifications. Please try again later.': 'Beim Einrichten der Push-Benachrichtigungen ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.',
    'This is a test notification. If you can see this, notifications are working!': 'Dies ist eine Test-Benachrichtigung. Wenn Sie diese sehen können, funktionieren die Benachrichtigungen!',

    // OS-specific help content
    'On Windows, notifications might be blocked by:': 'Unter Windows können Benachrichtigungen blockiert sein durch:',
    'Open': 'Öffnen',
    'Settings': 'Einstellungen',
    'System': 'System',
    'Notifications & actions': 'Benachrichtigungen & Aktionen',
    'Make sure': 'Stellen Sie sicher, dass',
    'Get notifications from apps and other senders': 'Benachrichtigungen von Apps und anderen Absendern erhalten',
    'is ON': 'aktiviert ist',
    'Scroll down and ensure your browser is enabled': 'Scrollen Sie nach unten und stellen Sie sicher, dass Ihr Browser aktiviert ist',
    'Check if': 'Prüfen Sie, ob',
    'Focus assist': 'Fokusassistent',
    'is turned off or configured to allow notifications': 'ausgeschaltet ist oder so konfiguriert ist, dass Benachrichtigungen zugelassen werden',
    'After changing settings, please reload this page and try again': 'Laden Sie nach dem Ändern der Einstellungen diese Seite neu und versuchen Sie es erneut',

    'On macOS, notifications might be blocked by:': 'Unter macOS können Benachrichtigungen blockiert sein durch:',
    'System Preferences': 'Systemeinstellungen',
    'Notifications': 'Benachrichtigungen',
    'Find and select your browser (Safari, Chrome, etc.)': 'Suchen und wählen Sie Ihren Browser (Safari, Chrome, etc.)',
    'Ensure': 'Stellen Sie sicher, dass',
    'Allow Notifications': 'Benachrichtigungen zulassen',
    'is checked': 'aktiviert ist',
    'Check that': 'Prüfen Sie, ob',
    'Do Not Disturb': 'Nicht stören',
    'is turned off': 'ausgeschaltet ist',

    // Phone request related
    'You are using a mobile device. You can proceed with the survey.': 'Sie verwenden ein mobiles Gerät. Sie können mit der Umfrage fortfahren.',
    'Please scan this QR code with your mobile device to continue the survey on your phone:': 'Bitte scannen Sie diesen QR-Code mit Ihrem Mobilgerät, um die Umfrage auf Ihrem Telefon fortzusetzen:',
    'Or open this link on your phone:': 'Oder öffnen Sie diesen Link auf Ihrem Telefon:',
    'Once you scan the QR code, you can continue the survey on your phone.': 'Sobald Sie den QR-Code gescannt haben, können Sie die Umfrage auf Ihrem Telefon fortsetzen.',
    'This step requires you to continue on a mobile device before proceeding.': 'Dieser Schritt erfordert, dass Sie auf einem mobilen Gerät fortfahren, bevor Sie fortfahren können.',
    'Please complete this required step before continuing.': 'Bitte führen Sie diesen erforderlichen Schritt aus, bevor Sie fortfahren.',

    // New translations
    'Installation not working? Try switching to a supported browser like Chrome or Safari.': 'Installation funktioniert nicht? Versuchen Sie es mit einem unterstützten Browser wie Chrome oder Safari.',
    'Switch Browser': 'Browser wechseln',
    'If you are having trouble installing the app, this browser may not be fully supported. Please use Chrome, Safari or Firefox for the best experience.': 'Wenn Sie Probleme beim Installieren der App haben, kann dieser Browser möglicherweise nicht vollständig unterstützt werden. Bitte verwenden Sie Chrome, Safari oder Firefox für die beste Erfahrung.',
    'You need to install this study\'s app on your phone to receive notifications on the go': 'Sie müssen die App dieser Studie auf Ihrem Telefon installieren, um unterwegs Benachrichtigungen zu erhalten',
    'Installation is not supported in your browser.': 'Installation wird in Ihrem Browser nicht unterstützt.',
    'Installation failed. You can try again or add to home screen manually from your browser menu.': 'Installation fehlgeschlagen. Sie können es erneut versuchen oder manuell über Ihr Browser-Menü zum Startbildschirm hinzufügen.',
    'This step is required. Please add the app to your home screen to continue.': 'Dieser Schritt ist erforderlich. Bitte fügen Sie die App zu Ihrem Startbildschirm hinzu, um fortzufahren.',
    'You can add the app to your home screen at any time.': 'Sie können die App jederzeit zu Ihrem Startbildschirm hinzufügen.',
    'Follow the instructions to add this app to your home screen.': 'Folgen Sie den Anweisungen, um diese App zu Ihrem Startbildschirm hinzuzufügen.',
    'Please complete this required step before continuing.': 'Bitte führen Sie diesen erforderlichen Schritt aus, bevor Sie fortfahren.',
    'Scan this QR code with your phone to continue on your mobile device.': 'Scannen Sie diesen QR-Code mit Ihrem Telefon, um auf Ihrem mobilen Gerät fortzufahren.',
    'Link': 'Link',
    'Preparing installation...': 'Installation wird vorbereitet...',
    'Configuration Error': 'Konfigurationsfehler',
    'Copied!': 'Kopiert!',
};

// Use the centralized translation helper
function t(text) {
    return window.formrLanguage.translate(text);
}

// Helper function to convert VAPID public key
function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Unified Push Notification Manager using jQuery
const PushNotificationManager = {
    // Check if browser supports push notifications
    isSupported: function() {
        return ('Notification' in window) && ('serviceWorker' in navigator) && ('PushManager' in window);
    },

    // Check iOS version compatibility
    isIOSCompatible: function() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (!isIOS) return true;
        
        // Extract iOS version from user agent
        const versionMatch = navigator.userAgent.match(/OS (\d+)_(\d+)/);
        if (versionMatch && versionMatch[1] && versionMatch[2]) {
            const majorVersion = parseInt(versionMatch[1]);
            const minorVersion = parseInt(versionMatch[2]);
            
            // Return true if iOS 16.4 or later
            return (majorVersion > 16) || (majorVersion === 16 && minorVersion >= 4);
        }
        return false; // If we can't determine version, assume incompatible
    },

    // Get existing service worker registration using navigator.serviceWorker.ready
    getRegistration: async function() {
        try {
            // Use navigator.serviceWorker.ready which returns a promise that resolves
            // only when an active service worker is available
            return await navigator.serviceWorker.ready;
        } catch (error) {
            console.error('Error getting service worker registration:', error);
            return null;
        }
    },

    // Check current subscription status
    checkSubscription: async function(registration) {
        if (!registration) return { subscribed: false, reason: 'no_registration' };
        
        try {
            const subscription = await registration.pushManager.getSubscription();
            return { 
                subscribed: !!subscription, 
                subscription: subscription
            };
        } catch (error) {
            console.error('Error checking subscription:', error);
            return { subscribed: false, reason: 'error', error };
        }
    },

    // Subscribe to push notifications
    subscribe: async function(registration) {
        if (!registration) return { success: false, reason: 'no_registration' };
        
        try {
            // Request notification permission
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                return { success: false, reason: 'permission_denied' };
            }
            
            // Validate VAPID key
            if (!window.vapidPublicKey || typeof window.vapidPublicKey !== 'string' || window.vapidPublicKey.length < 10) {
                console.error('Invalid VAPID public key');
                return { success: false, reason: 'invalid_config' };
            }

            // Subscribe to push manager
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(window.vapidPublicKey)
            });
            
            // Store subscription status in localStorage
            localStorage.setItem('push-notification-subscribed', 'true');
                        
            return { success: true, subscription };
        } catch (error) {
            console.error('Error subscribing to push:', error);
            return { success: false, reason: 'subscription_error', error };
        }
    }
};

// Add browser detection helper functions
async function isBraveBrowser() {
    return (navigator.brave && await navigator.brave.isBrave()) || false;
}

function isIOSDevice() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
}

// Update the state of installation button and related UI elements
async function updateInstallButtonState() {
    const $wrapper = $('.add-to-homescreen-wrapper');
    const $button = $wrapper.find('.add-to-homescreen');
    const $status = $wrapper.find('.status-message');
    const $hiddenInput = $wrapper.find('input');

    // When first called, store the default text on the button in a data attribute.
    if (!$button.data('default-text')) {
        $button.data('default-text', $button.html());
    }

    // Check for Brave on iOS
    const isBrave = await isBraveBrowser();
    const isIOS = isIOSDevice();
    
    if (isBrave && isIOS) {
        $hiddenInput.val('unsupported_browser');
        $status.html(t('Brave browser on iOS does not support adding to home screen. Please use Safari, Chrome or Firefox for the best experience.'));
        addBrowserSwitchUI($wrapper);
        $button.prop('disabled', true);
        $button.hide();
        return;
    }

    if (!window.AddToHomeScreenInstance && !window.AddToHomeScreen) {
        $hiddenInput.val('no_support');
        $status.html(t('Installation component not available. Please try again later.'));
        $button.prop('disabled', true);
        addBrowserSwitchUI($wrapper);
        return;
    }
    
    // Check if we're in standalone mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
        window.matchMedia('(display-mode: fullscreen)').matches || 
        window.navigator.standalone;

    if (isStandalone) { // App is already installed
        $hiddenInput.val('installed');
        $status.html(t('You are currently using the installed app.'));
        $wrapper.closest('.form-group').addClass('formr_answered');
        $button.removeClass('btn-primary').addClass('btn-success');
        $button.prop('disabled', true);
        $button.html(`<i class="fa fa-check"></i> ${t('Installed')}`);
        localStorage.setItem('pwa-app-installed', 'true');
        $hiddenInput[0].setCustomValidity('');
        return;
    } else if (localStorage.getItem('pwa-app-installed') === 'true') { // App is installed according to localStorage
        $hiddenInput.val('already_added');
        $status.html(t("You've already installed this app. Try opening this page in the installed app. If you have uninstalled the app, please just click this button again."));
        $wrapper.closest('.form-group').addClass('formr_answered');
        $button.removeClass('btn-primary').addClass('btn-success');
        $button.html(`<i class="fa fa-check"></i> ${t('Installed')}`);
    } else { // App is not installed
        $hiddenInput.val('not_started');
        // If not already installed, set platform-specific text.
        $status.html(`<p>${t('Add this app to your home screen for easier access.')}</p>`);
        $button.prop('disabled', false);
        $button.html($button.data('default-text'));
    }
}

export function initializePWAInstaller() {
    console.log('Initializing PWA Installer');
    initializeAddToHomeScreen();

    // Check for display mode changes
    window.addEventListener('DOMContentLoaded', updateInstallButtonState);
    window.addEventListener('visibilitychange', updateInstallButtonState);
    window.addEventListener('focus', updateInstallButtonState);
    window.addEventListener('appinstalled', function(e) {
        console.log('App was installed', e);
        localStorage.setItem('pwa-app-installed', 'true');
        updateInstallButtonState();
    });
    
    updateInstallButtonState();

    const isRequired = $('.add-to-homescreen-wrapper').closest('.form-group').hasClass('required');
    if(isRequired) {
        $('.add-to-homescreen-wrapper input')[0].setCustomValidity(t('Please complete this required step before continuing.'));
    }
    // Button click handler
    $('.add-to-homescreen').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        
        if ($btn.prop('disabled')) {
            return false;
        }
        
        const $wrapper = $btn.closest('.add-to-homescreen-wrapper');
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input');
        
        // Start installation timeout handler
        const timeoutHandler = handleInstallTimeout($wrapper);
        timeoutHandler.start();
        
        // Store the active timeout handler globally so it can be cleared by event handlers
        window.activeTimeoutHandler = timeoutHandler;

        // Show the add-to-homescreen dialog
        console.log('Showing add-to-homescreen dialog');
        if(window.deferredPrompt) {
            window.deferredPrompt.prompt();
        } else {
            window.AddToHomeScreenInstance.show();
        }
        
        $hiddenInput.val('prompted');
        $status.html(t('Preparing installation...'));
        $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + t('Processing...'));
        
        return false;
    });
}

// Function to initialize the add-to-homescreen library
function initializeAddToHomeScreen() {
    console.log('Initializing add-to-homescreen library');
    
    // Check if AddToHomeScreen is available
    if (!window.AddToHomeScreen) {
        console.error('AddToHomeScreen not available in window object');
        return;
    }
    
    // Get app name from manifest
    let appName = document.title;
    
    // Get app icon from manifest or use apple-touch-icon
    let appIconUrl = '/apple-touch-icon.png';
    
    // Try to get the app name and icon from the manifest
    const manifestLink = document.querySelector('link[rel="manifest"]');
    if (manifestLink) {
        fetch(manifestLink.href)
            .then(response => response.json())
            .then(data => {
                if (data.name) {
                    appName = data.name;
                } else if (data.short_name) {
                    appName = data.short_name;
                }
                
                if (data.icons && data.icons.length > 0) {
                    // Find a suitable icon (prefer 192x192 or larger)
                    const suitableIcon = data.icons.find(icon => 
                        icon.sizes && (icon.sizes.includes('192x192') || icon.sizes.includes('512x512'))
                    );
                    
                    if (suitableIcon) {
                        appIconUrl = suitableIcon.src;
                    }
                }
                
                initializeWithAppInfo(appName, appIconUrl);
            })
            .catch(error => {
                console.error('Error fetching manifest:', error);
                initializeWithAppInfo(appName, appIconUrl);
            });
    } else {
        console.log('No manifest link found, using default values');
        initializeWithAppInfo(appName, appIconUrl);
    }
}

// Initialize with app info
function initializeWithAppInfo(appName, appIconUrl) {
    console.log('Initializing with app info:', { appName, appIconUrl });
    
    window.AddToHomeScreenInstance = window.AddToHomeScreen({
        appName: appName,
        appIconUrl: appIconUrl,
        maxModalDisplayCount: -1,
        assetUrl: '/assets/build/assets/img/',
        displayOptions: { showMobile: true, showDesktop: true },
        allowClose: true
    });
    
    console.log('AddToHomeScreenInstance initialized successfully');
    
    // Handle beforeinstallprompt event for PWA installation
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        window.deferredPrompt = e;
        updateInstallButtonState();
    });
    
    // Update the install button state now that we have initialized
    updateInstallButtonState();
}

// Handle when the add-to-homescreen dialog is closed
function handleAddToHomeScreenClosed(installed) {
    $('.add-to-homescreen-wrapper').each(function() {
        var $wrapper = $(this);
        var $status = $wrapper.find('.status-message');
        var $hiddenInput = $wrapper.find('input');
        var $button = $wrapper.find('.add-to-homescreen');
        var isRequired = $wrapper.closest('.form-group').hasClass('required');
        
        if (installed) {
            updateInstallButtonState();
        } else {
            $hiddenInput.val('declined');
            if (isRequired) {
                $status.html(t('This step is required. Please add the app to your home screen to continue.'));
                $wrapper.closest('.form-group').removeClass('formr_answered');
            } else {
                $status.html(t('You can add the app to your home screen at any time.'));
                $wrapper.closest('.form-group').addClass('formr_answered');
            }
            $button.html('<i class="fa fa-plus-square"></i> ' + t('Add to Home Screen'));
            addBrowserSwitchUI($wrapper);
        }
    });
}

webshim.ready('forms forms-ext dom-extend form-validators', function() {
    webshim.refreshCustomValidityRules();
});

// Helper function to check if we're in standalone mode
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || 
        window.matchMedia('(display-mode: fullscreen)').matches || 
        window.navigator.standalone;
}

export function initializePushNotifications() {
    $('.push-notification-wrapper').each(async function() {
        const $wrapper = $(this);
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input');
        const $button = $wrapper.find('.push-notification-permission');
        const isRequired = $wrapper.closest('.form-group').hasClass('required');
        if(isRequired) {
            $hiddenInput[0].setCustomValidity(t('Please complete this required step before continuing.'));
        }

        if (!PushNotificationManager.isSupported()) {
            $hiddenInput.val('not_supported');
            $status.html(t('Sorry, your browser does not support push notifications.'));
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        }

        if (!PushNotificationManager.isIOSCompatible()) {
            $hiddenInput.val('ios_version_not_supported');
            $status.html(t('Sorry, push notifications require iOS 16.4 or later.'));
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        }

        const registration = await PushNotificationManager.getRegistration();
        if (!registration) {
            $hiddenInput.val('no_service_worker');
            $status.html(t('Service worker not registered. Please reload the page and try again.'));
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        }

        if (localStorage.getItem('push-notification-subscribed') === 'true') {
            const subResult = await PushNotificationManager.checkSubscription(registration);
            
            if (subResult.subscribed) {
                $hiddenInput.val(JSON.stringify(subResult.subscription));
                $hiddenInput[0].setCustomValidity('');
                $status.html(`
                    <div>
                        <p>${t('Push notifications are enabled.')}</p>
                        <button type="button" class="btn btn-sm btn-default test-notification-button"><i class="fa fa-bell"></i> ${t('Test notification')}</button>
                        <button type="button" class="btn btn-link show-notification-help">${t('Show troubleshooting tips')}</button>
                    </div>
                `);
                
                // Add click handlers for buttons
                $wrapper.find('.test-notification-button').on('click', async function() {
                    await sendTestNotification(registration);
                });
                
                $wrapper.find('.show-notification-help').on('click', function() {
                    showNotificationHelp($wrapper);
                });
                
                $button.removeClass('btn-primary').addClass('btn-success');
                $button.prop('disabled', true);
                $button.html(`<i class="fa fa-check"></i> ${t('Notifications Enabled')}`);
                $wrapper.closest('.form-group').addClass('formr_answered');
                return;
            }
        }

        const existingPermission = Notification.permission;
        
        if (existingPermission === 'granted') {
            const subResult = await PushNotificationManager.checkSubscription(registration);
            
            if (subResult.subscribed) {
                const subscriptionJson = JSON.stringify(subResult.subscription);
                $hiddenInput.val(subscriptionJson);
                $hiddenInput[0].setCustomValidity('');
                $status.html(`
                    <div>
                        <p>${t('Push notifications are enabled.')}</p>
                        <button type="button" class="btn btn-sm btn-default test-notification-button"><i class="fa fa-bell"></i> ${t('Test notification')}</button>
                        <button type="button" class="btn btn-link show-notification-help">${t('Show troubleshooting tips')}</button>
                    </div>
                `);
                
                $wrapper.find('.test-notification-button').on('click', async function() {
                    await sendTestNotification(registration);
                });
                
                $wrapper.find('.show-notification-help').on('click', function() {
                    showNotificationHelp($wrapper);
                });
                
                $button.removeClass('btn-primary').addClass('btn-success');
                $button.prop('disabled', true);
                $button.html(`<i class="fa fa-check"></i> ${t('Notifications Enabled')}`);
                $wrapper.closest('.form-group').addClass('formr_answered');
                
                localStorage.setItem('push-notification-subscribed', 'true');
            } else {
                $hiddenInput.val('no_subscription');
                $status.html(t('Click the button to enable push notifications.'));
                $button.html(`<i class="fa fa-bell"></i> ${t('Enable Notifications')}`);
            }
        } else if (existingPermission === 'denied') {
            $hiddenInput.val('permission_denied');
            $status.html(t('You have declined push notifications. You can enable them in your browser settings.'));
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            $button.html(`<i class="fa fa-times"></i> ${t('Notifications Blocked')}`);
        }
    });

    $('.push-notification-permission').click(async function(e) {
        e.preventDefault();
        const $btn = $(this);
        
        if ($btn.prop('disabled')) {
            return false;
        }
        
        const $wrapper = $btn.closest('.push-notification-wrapper');
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input');

        try {
            $btn.html(`<i class="fa fa-spinner fa-spin"></i> ${t('Processing...')}`);
            
            const registration = await PushNotificationManager.getRegistration();
            if (!registration) {
                $hiddenInput.val('no_service_worker');
                $status.html(t('Service worker not registered. Please reload the page and try again.'));
                $btn.html(`<i class="fa fa-exclamation-triangle"></i> ${t('Error')}`);
                return false;
            }
            
            const subResult = await PushNotificationManager.checkSubscription(registration);
            if (subResult.subscribed) {
                const subscriptionJson = JSON.stringify(subResult.subscription);
                $hiddenInput.val(subscriptionJson);
                $hiddenInput[0].setCustomValidity('');
                $status.html(`
                    <div>
                        <p>${t('Push notifications are already enabled.')}</p>
                        <button type="button" class="btn btn-sm btn-default test-notification-button"><i class="fa fa-bell"></i> ${t('Test notification')}</button>
                        <button type="button" class="btn btn-link show-notification-help">${t('Show troubleshooting tips')}</button>
                    </div>
                `);
                
                $wrapper.find('.test-notification-button').on('click', async function() {
                    await sendTestNotification(registration);
                });
                
                $wrapper.find('.show-notification-help').on('click', function() {
                    showNotificationHelp($wrapper);
                });
                
                $btn.removeClass('btn-primary').addClass('btn-success');
                $btn.prop('disabled', true);
                $btn.html(`<i class="fa fa-check"></i> ${t('Notifications Enabled')}`);
                $wrapper.closest('.form-group').addClass('formr_answered');
                localStorage.setItem('push-notification-subscribed', 'true');
                return false;
            }

            const result = await PushNotificationManager.subscribe(registration);
            
            if (result.success) {
                const subscriptionJson = JSON.stringify(result.subscription);
                $hiddenInput.val(subscriptionJson);
                $hiddenInput[0].setCustomValidity('');

                let platformSpecificNote = '';
                
                if (/android/i.test(navigator.userAgent)) {
                    platformSpecificNote = `
                        <p><strong>${t('Note for Android users:')}</strong> ${t('On some Android devices, you may need to restart your browser or add this app to your home screen for notifications to work properly.')}</p>
                    `;
                }
                
                $status.html(`
                    <div>
                        <p><strong>${t('Push notifications enabled successfully!')}</strong></p>
                        <p>${t("A test notification was sent. If you didn't see it, your system settings might be blocking notifications.")}</p>
                        ${platformSpecificNote}
                        <button type="button" class="btn btn-sm btn-default test-notification-button"><i class="fa fa-bell"></i> ${t('Test notification')}</button>
                        <button type="button" class="btn btn-link show-notification-help">${t('Show troubleshooting tips')}</button>
                    </div>
                `);
                
                $wrapper.find('.test-notification-button').on('click', async function() {
                    await sendTestNotification(registration);
                });
                
                $wrapper.find('.show-notification-help').on('click', function() {
                    showNotificationHelp($wrapper);
                });
                
                await sendTestNotification(registration);
                
                $btn.removeClass('btn-primary').addClass('btn-success');
                $btn.prop('disabled', true);
                $btn.html(`<i class="fa fa-check"></i> ${t('Notifications Enabled')}`);
                $wrapper.closest('.form-group').addClass('formr_answered');
            } else if (result.reason === 'permission_denied') {
                $hiddenInput.val('permission_denied');
                $status.html(t('You have declined push notifications. You can enable them later in your browser settings.'));
                $btn.prop('disabled', true);
                $btn.removeClass('btn-primary').addClass('btn-default');
                $btn.html(`<i class="fa fa-times"></i> ${t('Notifications Blocked')}`);
            } else if (result.reason === 'invalid_config') {
                $hiddenInput.val('invalid_config');
                $status.html(t('Server configuration error. Please contact support.'));
                $btn.html(`<i class="fa fa-exclamation-triangle"></i> ${t('Configuration Error')}`);
            } else {
                $hiddenInput.val('error');
                $status.html(t('There was an error setting up push notifications. Please try again later.'));
                $btn.html(`<i class="fa fa-exclamation-triangle"></i> ${t('Error')}`);
            }
            
        } catch (error) {
            console.error('Error during push notification setup:', error);
            $hiddenInput.val('error');
            $status.html(t('There was an error setting up push notifications. Please try again later.'));
            $btn.html(`<i class="fa fa-exclamation-triangle"></i> ${t('Error')}`);
        }
        
        return false;
    });
}

// Add this as a global function in the file
function showNotificationHelp($wrapper) {
    const userAgent = navigator.userAgent.toLowerCase();
    let helpContent = '';
    
    if (/windows/.test(userAgent)) {
        helpContent = `
            <div class="notification-help">
                <p>${t('On Windows, notifications might be blocked by:')}</p>
                <ol>
                    <li>${t('Open')} <strong>${t('Settings')}</strong> &gt; <strong>${t('System')}</strong> &gt; <strong>${t('Notifications & actions')}</strong></li>
                    <li>${t('Make sure')} <strong>${t('Get notifications from apps and other senders')}</strong> ${t('is ON')}</li>
                    <li>${t('Scroll down and ensure your browser is enabled')}</li>
                    <li>${t('Check if')} <strong>${t('Focus assist')}</strong> ${t('is turned off or configured to allow notifications')}</li>
                    <li>${t('After changing settings, please reload this page and try again')}</li>
                </ol>
            </div>`;
    } else if (/macintosh/.test(userAgent)) {
        helpContent = `
            <div class="notification-help">
                <p>${t('On macOS, notifications might be blocked by:')}</p>
                <ol>
                    <li>${t('Open')} <strong>${t('System Preferences')}</strong> &gt; <strong>${t('Notifications')}</strong></li>
                    <li>${t('Find and select your browser (Safari, Chrome, etc.)')}</li>
                    <li>${t('Ensure')} <strong>${t('Allow Notifications')}</strong> ${t('is checked')}</li>
                    <li>${t('Check that')} <strong>${t('Do Not Disturb')}</strong> ${t('is turned off')}</li>
                    <li>${t('After changing settings, please reload this page and try again')}</li>
                </ol>
            </div>`;
    } else if (/android/.test(userAgent)) {
        helpContent = `
            <div class="notification-help">
                <p>${t('On Android, notifications might be blocked by:')}</p>
                <ol>
                    <li>${t('Open')} <strong>${t('Settings')}</strong> &gt; <strong>${t('Apps')}</strong> ${t('or')} <strong>${t('Applications')}</strong></li>
                    <li>${t('Find and tap your browser app (Chrome, Firefox, etc.)')}</li>
                    <li>${t('Tap')} <strong>${t('Notifications')}</strong> ${t('and ensure they are')} <strong>${t('Allowed')}</strong></li>
                    <li>${t('Check if')} <strong>${t('Do Not Disturb')}</strong> ${t('mode is enabled (under Sound settings)')}</li>
                    <li>${t('Some manufacturers have additional battery optimization settings that can block notifications')}</li>
                    <li>${t('Try adding this app to your home screen for better notification support')}</li>
                    <li>${t('On some devices, you may need to restart Chrome after enabling notifications')}</li>
                    <li>${t('After changing settings, please reload this page and try again')}</li>
                </ol>
            </div>`;
    } else if (/iphone|ipad|ipod/.test(userAgent)) {
        helpContent = `
            <div class="notification-help">
                <p>${t('On iOS, notifications might be blocked by:')}</p>
                <ol>
                    <li>${t('Open')} <strong>${t('Settings')}</strong> &gt; <strong>${t('Notifications')}</strong></li>
                    <li>${t('Find and tap on Safari (or your browser app)')}</li>
                    <li>${t('Enable')} <strong>${t('Allow Notifications')}</strong></li>
                    <li>${t('Ensure')} <strong>${t('Focus')}</strong> ${t('mode is not blocking notifications')}</li>
                    <li>${t('For home screen apps, check')} <strong>${t('Settings')}</strong> &gt; <strong>${t('Screen Time')}</strong> &gt; <strong>${t('Content & Privacy Restrictions')}</strong></li>
                    <li>${t('After changing settings, please reload this page and try again')}</li>
                </ol>
            </div>`;
    } else {
        helpContent = `
            <div class="notification-help">
                <p>${t('To enable notifications:')}</p>
                <ol>
                    <li>${t('Check your system notification settings')}</li>
                    <li>${t('Ensure notifications are allowed for this browser')}</li>
                    <li>${t('Disable Do Not Disturb or similar modes')}</li>
                    <li>${t('After changing settings, please reload this page and try again')}</li>
                </ol>
            </div>`;
    }
    
    // Add some basic styling if not already added
    if (!$('style.notification-help-styles').length) {
        $('head').append(`
            <style class="notification-help-styles">
                .notification-help {
                    margin-top: 10px;
                    padding: 10px;
                    border-left: 3px solid #f0ad4e;
                    background-color: #fcf8e3;
                }
                .notification-help p {
                    font-weight: bold;
                    margin-bottom: 10px;
                }
                .notification-help ol {
                    padding-left: 20px;
                }
                .notification-help li {
                    margin-bottom: 5px;
                }
                .notification-help-container {
                    margin-top: 10px;
                }
            </style>
        `);
    }
    
    // Create or update help container
    let $helpContainer = $wrapper.find('.notification-help-container');
    if ($helpContainer.length === 0) {
        $helpContainer = $('<div class="notification-help-container"></div>');
        $wrapper.find('.status-message').after($helpContainer);
    }
    
    $helpContainer.html(helpContent);
    
    // Replace show button with hide button
    $wrapper.find('.show-notification-help')
        .text(t('Hide troubleshooting tips'))
        .removeClass('show-notification-help')
        .addClass('hide-notification-help');
    
    // Add click handler for the hide button
    $wrapper.find('.hide-notification-help').off('click').on('click', function() {
        $helpContainer.empty();
        $(this)
            .text(t('Show troubleshooting tips'))
            .removeClass('hide-notification-help')
            .addClass('show-notification-help');
        
        // Re-attach show handler
        $(this).off('click').on('click', function() {
            showNotificationHelp($wrapper);
        });
    });
}

// Add this function at the global level in the file
async function sendTestNotification(registration) {
    if (!registration) {
        console.error('Cannot send test notification: no service worker registration');
        return false;
    }
    
    try {
        if (registration.showNotification) {
            await registration.showNotification(t('Test notification'), {
                body: t('This is a test notification. If you can see this, notifications are working!'),
                icon: '/favicon.ico',
                tag: 'test-notification'
            });
            
            // Close the notification after 5 seconds (for supported browsers)
            setTimeout(async () => {
                try {
                    const notifications = await registration.getNotifications({tag: 'test-notification'});
                    notifications.forEach(notification => notification.close());
                } catch (e) {
                    console.log('Could not close notification automatically, this is normal on some platforms');
                }
            }, 5000);
            
            return true;
        } else {
            // Fallback to using the Notification constructor directly
            const testNotification = new Notification(t('Test notification'), {
                body: t('This is a test notification. If you can see this, notifications are working!'),
                icon: '/favicon.ico',
                tag: 'test-notification',
                requireInteraction: false
            });
            
            // Add click event handler that does nothing
            testNotification.onclick = (e) => {
                e.preventDefault();
                return false;
            };
            
            // Close the test notification after 5 seconds
            setTimeout(() => {
                testNotification.close();
            }, 5000);
            
            return true;
        }
    } catch (error) {
        console.error('Error creating test notification:', error);
        return false;
    }
}

// Function to detect if the user is on a mobile device
function isMobileDevice() {
    const mobileRegex = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i;
    return mobileRegex.test(navigator.userAgent);
}

// Function to generate the redirect link with code parameter if needed
function generateRedirectLink() {
    // Get the current URL
    let currentUrl = window.location.href;
    
    // Check if _formr_code input exists
    const formrCodeInput = document.querySelector('input[name="_formr_code"]');
    if (formrCodeInput && formrCodeInput.value) {
        // Append the code as a query parameter
        const code = formrCodeInput.value;
        
        // Check if URL already has parameters
        const hasParams = currentUrl.includes('?');
        if (hasParams) {
            currentUrl += `&code=${encodeURIComponent(code)}`;
        } else {
            currentUrl += `?code=${encodeURIComponent(code)}`;
        }
    }
    
    return currentUrl;
}

// Function to generate a QR code with the current page URL
function generateQRCode(container, logoUrl) {
    // Get the redirect link
    const currentUrl = generateRedirectLink();
    
    // Create a new QR Code instance
    const qrCode = new QRCodeStyling({
        width: 250,
        height: 250,
        type: "svg",
        data: currentUrl,
        dotsOptions: {
            color: "#000000",
            type: "rounded"
        },
        backgroundOptions: {
            color: "#ffffff",
        },
        cornersSquareOptions: {
            color: "#2196F3",
            type: "extra-rounded"
        },
        cornersDotOptions: {
            color: "#2196F3",
            type: "dot"
        },
        imageOptions: {
            crossOrigin: "anonymous",
            margin: 10,
            imageSize: 0.4
        }
    });
    
    // Set the logo if available
    if (logoUrl) {
        qrCode.update({
            image: logoUrl
        });
    }
    
    // Clear the container and append the QR code
    container.innerHTML = '';
    qrCode.append(container);
    
    // Return the URL for display
    return currentUrl;
}

// Function to get the logo URL from the manifest
async function getLogoUrlFromManifest() {
    try {
        const manifestLink = document.querySelector('link[rel="manifest"]');
        if (!manifestLink) return null;
        
        const response = await fetch(manifestLink.href);
        if (!response.ok) return null;
        
        const manifest = await response.json();
        if (!manifest.icons || !manifest.icons.length) return null;
        
        // Get the icon with the largest size (usually the 512x512 one)
        const sortedIcons = [...manifest.icons].sort((a, b) => {
            const sizeA = parseInt(a.sizes.split('x')[0], 10) || 0;
            const sizeB = parseInt(b.sizes.split('x')[0], 10) || 0;
            return sizeB - sizeA;
        });
        
        // Get the absolute URL of the icon
        const iconPath = sortedIcons[0].src;
        const baseUrl = window.location.origin;
        return new URL(iconPath, baseUrl).href;
    } catch (error) {
        console.error('Error getting logo from manifest:', error);
        return null;
    }
}

// Function to initialize the RequestPhone item
export function initializeRequestPhone(force_show_guide = false) {
    $('.request-phone-wrapper').each(function() {
        const $wrapper = $(this);
        const $qrContainer = $wrapper.find('.qr-code-container');
        const $statusMessage = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input');
        const force_show = force_show_guide || $wrapper.data('force-show') === true;
        const isRequired = $wrapper.closest('.form-group').hasClass('required');
        if(isRequired) {
            $hiddenInput[0].setCustomValidity(t('Please complete this required step before continuing.'));
        }

        // Add CSS class for styling if it's an unsupported browser case
        if (force_show) {
            $wrapper.addClass('unsupported-browser');
        }

        // Check if we're on a mobile device
        const isMobile = isMobileDevice();
        
        // Three conditions to handle:
        // 1. Unsupported mobile browser (force_show is true AND mobile)
        // 2. Desktop browser (not mobile OR forced show)
        // 3. Supported mobile browser (mobile and not forced)
        
        if (force_show && isMobile) {
            // Case 1: Unsupported mobile browser - show guidance to switch browsers
            $statusMessage.html(t('If you are having trouble installing the app, this browser may not be fully supported. Please use Chrome, Safari or Firefox for the best experience.'));
            
            // Create link for browser switch
            const redirectLink = generateRedirectLink();
            let link = `<a href="${redirectLink}" class="btn">
                        <i class="fa fa-link"></i> ${t('Open in different browser')}
                    </a>`;
            if(isIOSDevice()) {
                link = `<a href="x-safari-${redirectLink}" class="btn">
                        <i class="fa fa-safari"></i> ${t('Open in Safari')}
                    </a>` + link;
            }
            const $browserSwitchLink = $(
                `<div class="browser-switch-links btn-group">
                    ${link}
                    <button class="btn copy-on-click" data-copy-link="${redirectLink}">
                        <i class="fa fa-copy"></i> ${t('Copy link')}
                    </button>
                </div>`
            );
            $statusMessage.after($browserSwitchLink);
            
            // Attach click handler to the button
            $browserSwitchLink.find('button.copy-on-click').on('click', function(e) {
                copyToClipboard($(this).data('copy-link'), this);
            });
            
            // Hide and disable QR code container
            $qrContainer.hide().empty();
            
        } else if (!isMobile || force_show) {
            // Case 2: Desktop browser or forced display - show QR code
            if (!$qrContainer.data('initialized')) {
                $statusMessage.html(t('Scan this QR code with your phone to continue on your mobile device.'));
                $hiddenInput.val('desktop');
                
                // Show QR container
                $qrContainer.show();
                
                // Generate QR code
                getLogoUrlFromManifest().then(logoUrl => {
                    generateQRCode($qrContainer[0], logoUrl);
                    $qrContainer.data('initialized', true);
                });
                
                // Add text instruction about manual copy
                const redirectLink = generateRedirectLink();
                const $manualCopy = $(
                    `<div class="manual-copy-link">
                        <p>${t('Or copy this link to your phone:')}</p>
                        <div class="btn-group">
                            <a href="${redirectLink}" class="btn">
                                <i class="fa fa-link"></i> ${t('Link')}
                            </a>
                            <button class="btn btn-default copy-link-btn" type="button" data-copy-link="${redirectLink}">
                                <i class="fa fa-copy"></i> ${t('Copy')}
                            </button>
                        </div>
                    </div>`
                );
                $statusMessage.after($manualCopy);
                
                // Attach click handler to the manual copy button
                $manualCopy.find('button.copy-link-btn').on('click', function(e) {
                    copyToClipboard($(this).data('copy-link'), this);
                });
            }
        } else {
            // Case 3: Supported mobile browser
            $statusMessage.html(t('You are already on a mobile device. You can continue.'));
            $hiddenInput.val('mobile');
            
            // Hide QR code container in this case
            $qrContainer.hide();
            
            // Add a continue button
            if (!$wrapper.find('.continue-btn').length) {
                const $continueBtn = $(`
                    <button class="btn btn-success continue-btn">
                        <i class="fa fa-check"></i> ${t('Continue')}
                    </button>`
                );
                $statusMessage.after($continueBtn);
                
                // Mark as completed when continue is clicked
                $continueBtn.on('click', function() {
                    $hiddenInput.val('completed');
                    $wrapper.addClass('completed');
                    $(this).prop('disabled', true).html(`<i class="fa fa-check"></i> ${t('Completed')}`);
                });
            }
        }
    });
}

// Add badge clearing function
async function clearAppBadge() {
  if ('setAppBadge' in navigator) {
    try {
      await navigator.clearAppBadge();
      console.log('App badge cleared on page load');
    } catch (error) {
      console.error('Error clearing app badge:', error);
    }
  }
}

// Function to check and handle pending notifications
async function handlePendingNotifications() {
  if (!('serviceWorker' in navigator)) return;
  
  try {
    const registration = await navigator.serviceWorker.ready;
    const notifications = await registration.getNotifications();
    
    // Close notifications but don't reload immediately
    if (notifications.length > 0) {
      console.log('Found pending notifications:', notifications.length);
      notifications.forEach(notification => notification.close());
      
      // Set a flag in localStorage to indicate notifications were closed
      localStorage.setItem('notifications-closed', 'true');
      return true;
    }
    return false;
  } catch (error) {
    console.error('Error checking notifications:', error);
    return false;
  }
}

// Add service worker message handler at the top level
if ('serviceWorker' in navigator) {
  // Clear badge when page loads
  clearAppBadge();
  
  // Check for pending notifications and handle page initialization
  handlePendingNotifications().then(hadNotifications => {
    // Only reload if we had notifications AND we're not already handling a post-notification reload
    if (hadNotifications && !localStorage.getItem('handling-notification-reload')) {
      localStorage.setItem('handling-notification-reload', 'true');
      window.location.reload();
    } else {
      // Clear the handling flag if we're done with the reload
      localStorage.removeItem('handling-notification-reload');
      localStorage.removeItem('notifications-closed');
    }
  });
  
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data.type === 'NOTIFICATION_CLICK' && event.data.action === 'reload') {
      console.log('Received reload message from service worker');
      localStorage.setItem('handling-notification-reload', 'true');
      window.location.reload();
    } else if (event.data.type === 'NEW_NOTIFICATION') {
      console.log('New notification received');
    }
  });
  
  // Handle page visibility changes
  ['visibilitychange', 'focus', 'pageshow'].forEach(eventType => {
    window.addEventListener(eventType, () => {
      handlePendingNotifications().then(hadNotifications => {
        if (hadNotifications && !localStorage.getItem('handling-notification-reload')) {
          localStorage.setItem('handling-notification-reload', Date.now());
          window.location.reload();
        } else {
          // Cleanup stale flag after 30s
          const timestamp = parseInt(localStorage.getItem('handling-notification-reload'), 10);
          if (timestamp && (Date.now() - timestamp > 30000)) {
            localStorage.removeItem('handling-notification-reload');
          }
        }
      });
    });
    document.addEventListener('DOMContentLoaded', () => {
        handlePendingNotifications().then(hadNotifications => {
          if (hadNotifications) {
            window.location.reload();
          }
        });
      });
  });
}

// Add new function to handle installation timeout
function handleInstallTimeout($wrapper) {
    const timeoutDuration = 15000; // 20 seconds
    let installTimeoutId = null;

    return {
        start: () => {
            installTimeoutId = setTimeout(() => {
                const $status = $wrapper.find('.status-message');
                const $button = $wrapper.find('.add-to-homescreen');
                
                // Clear any existing content and add the browser switch UI
                $status.html(t('Installation not working? Try switching to a supported browser like Chrome or Safari.'));
                addBrowserSwitchUI($wrapper);
                
                // Reset button state
                $button.prop('disabled', false);
                $button.html($button.data('default-text'));
            }, timeoutDuration);
        },
        clear: () => {
            if (installTimeoutId) {
                clearTimeout(installTimeoutId);
                installTimeoutId = null;
            }
        }
    };
}

// Add new function to create browser switch UI
function addBrowserSwitchUI($wrapper) {
    // Remove any existing browser switch UI
    if($(".browser-switch-ui").length > 0) {
        return;
    }

    // Create new browser switch UI
    const $browserSwitchUI = $(`
        <div class="browser-switch-ui form-group form-row required item-request_phone">
            <label class="control-label" for="item532"> ${t('You need to install this study\'s app on your phone to receive notifications on the go')} </label>
            <div class="controls">
				<div class="controls-inner">
					<div class="request-phone-wrapper">
                        <input type="text" name="request_phone" value="1" style="display: none;" />
                        <p class="instructions"></p>
                        <div class="qr-code-container"></div>
                        <div class="status-message">${t('Scan this QR code with your phone to continue on your mobile device.')}</div>
                    </div>
                </div>
            </div>
        </div>
    `);

    // Add it after the status message
//    $wrapper.closest('.form-group').hide();
    $wrapper.closest('.form-group').after($browserSwitchUI);

    // Initialize only the new request-phone-wrapper
    initializeRequestPhone(true);
}

// Define copyToClipboard function for copying text to clipboard with UI feedback
function copyToClipboard(text, btn) {
    var originalText = btn ? btn.innerHTML : '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text)
            .then(function() {
                console.log('Text copied to clipboard');
                if (btn) {
                    btn.innerHTML = '<i class="fa fa-check"></i> ' + t('Copied!');
                    setTimeout(function() { btn.innerHTML = originalText; }, 2000);
                }
            })
            .catch(function(err) {
                console.error('Failed to copy text: ', err);
            });
    }
}
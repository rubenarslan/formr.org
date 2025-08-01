import $ from 'jquery';
import { showPreferences } from 'vanilla-cookieconsent';
import QRCodeStyling from "qr-code-styling";
import 'add-to-homescreen/dist/add-to-homescreen.min.css';
import AddToHomeScreen from 'add-to-homescreen/dist/add-to-homescreen.min.js';
import '@khmyznikov/pwa-install';
    
// Add German translations to window.formrLanguage
window.formrTranslations.de = {
    // Installation related
    'Installation component not available. Please try again later.': 'Installationskomponente nicht verfügbar. Bitte versuchen Sie es später erneut.',
    'You are currently using the installed app.': 'Sie verwenden derzeit die installierte App.',
    'Installed': 'Installiert',
    "You've already installed this app. Try opening this page in the installed app. If you have uninstalled the app, please just click this button again.": 'Sie haben diese App bereits installiert. Öffnen Sie diese Seite in der installierten App. Wenn Sie die App deinstalliert haben, klicken Sie einfach erneut auf diese Schaltfläche.',
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
    'Open in different browser': 'In anderem Browser öffnen',
    'Open in Safari': 'In Safari öffnen',
    'Copy link': 'Link kopieren',
    'Continue': 'Fortfahren',
    'Completed': 'Abgeschlossen',
    'On Android, notifications might be blocked by:': 'Unter Android können Benachrichtigungen blockiert sein durch:',
    'Apps': 'Apps',
    'Applications': 'Anwendungen',
    'or': 'oder',
    'Find and tap your browser app (Chrome, Firefox, etc.)': 'Suchen und tippen Sie auf Ihre Browser-App (Chrome, Firefox, usw.)',
    'Tap': 'Tippen Sie auf',
    'and ensure they are': 'und stellen Sie sicher, dass sie',
    'Allowed': 'Erlaubt sind',
    'Some manufacturers have additional battery optimization settings that can block notifications': 'Einige Hersteller haben zusätzliche Batterieoptimierungseinstellungen, die Benachrichtigungen blockieren können',
    'Try adding this app to your home screen for better notification support': 'Versuchen Sie, diese App zu Ihrem Startbildschirm hinzuzufügen, um eine bessere Benachrichtigungsunterstützung zu erhalten',
    'On some devices, you may need to restart Chrome after enabling notifications': 'Auf einigen Geräten müssen Sie möglicherweise Chrome neu starten, nachdem Sie Benachrichtigungen aktiviert haben',
    'Brave browser on iOS does not support adding to home screen. Please use Safari, Chrome or Firefox for the best experience.': 'Der Brave-Browser unter iOS unterstützt das Hinzufügen zum Startbildschirm nicht. Bitte verwenden Sie Safari, Chrome oder Firefox für die beste Erfahrung.',
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
    },

    // Unsubscribe from push notifications
    unsubscribe: async function(registration) {
        if (!registration) return { success: false, reason: 'no_registration' };
        
        try {
            const subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                return { success: true, reason: 'already_unsubscribed' };
            }
            
            const unsubscribed = await subscription.unsubscribe();
            if (unsubscribed) {
                // Remove subscription status from localStorage
                localStorage.removeItem('push-notification-subscribed');
                return { success: true };
            } else {
                return { success: false, reason: 'unsubscribe_failed' };
            }
        } catch (error) {
            console.error('Error unsubscribing from push:', error);
            return { success: false, reason: 'unsubscribe_error', error };
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

    let isBrave = false;
    try {
        isBrave = await isBraveBrowser();
    } catch (error) {
        console.error('Brave browser check failed:', error);
        // Fallback: Assume not Brave and use default behavior
        isBrave = false;
    }

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
        if($hiddenInput.length > 0) {
            $hiddenInput[0].setCustomValidity('');
        }
        if (window.activeTimeoutHandler) {
            window.activeTimeoutHandler.clear();
            window.activeTimeoutHandler = null;
        }
    
        return;
    } else if (localStorage.getItem('pwa-app-installed') === 'true') { // App is installed according to localStorage
        $hiddenInput.val('already_added');
        const redirect_link = generateRedirectLink();
        let appName = document.title;
        $status.html(t("You've already installed this app. Try closing your browser and opening the app named " + appName + " from your home screen. If you have uninstalled the app, please just click this button again.")
        );
        //  + 
        //    `<a href="web-formrpwaa://test" class="btn btn-primary" target="_blank">${t('Open app')}</a>`
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

function initialize_pwa_install_element() {
    // Initialize pwa-install element
    var installer = document.createElement('pwa-install');

    // Get manifest URL from link tag
    var manifestLink = document.querySelector('link[rel="manifest"]');
    if (manifestLink) {
        installer.setAttribute('manifest-url', manifestLink.href);
    }
    
    installer.setAttribute('use-local-storage', 'true');
    installer.hideDialog();
    document.body.appendChild(installer);

    // Handle beforeinstallprompt event for PWA installation
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        window.deferredPrompt = e;
        if (installer) {
            installer.externalPromptEvent = e;
        }
        updateInstallButtonState();
    });

    if (installer) {
        // Installation successful
        installer.addEventListener('pwa-install-success-event', function(e) {
			console.log('Installation successful:', e.detail);
			localStorage.setItem('pwa-app-installed', 'true');

            updateInstallButtonState();
			
			installer.hideDialog();
		});
        
        // Installation failed
        installer.addEventListener('pwa-install-fail-event', function(e) {
            console.log('Installation failed:', e.detail);
            
            $('.add-to-homescreen-wrapper').each(function() {
                var $wrapper = $(this);
                var $status = $wrapper.find('.status-message');
                var $hiddenInput = $wrapper.find('input');
                var $button = $wrapper.find('.add-to-homescreen');
                
                $hiddenInput.val('failed');
                $status.html(t('Installation failed. You can try again or add to home screen manually from your browser menu.'));
                $button.html('<i class="fa fa-plus-square"></i> ' + t('Add to Home Screen'));
                addBrowserSwitchUI($wrapper);
            });
            
            installer.hideDialog();
        });
        
        // User choice result
		installer.addEventListener('pwa-user-choice-result-event', function(e) {
			console.log('User choice:', e.detail);
			var accepted = (e.detail.userChoiceResult === 'accepted');
			

			$('.add-to-homescreen-wrapper').each(function() {
				var $wrapper = $(this);
				var $status = $wrapper.find('.status-message');
				var $hiddenInput = $wrapper.find('input');
				var $button = $wrapper.find('.add-to-homescreen');
				var isRequired = $wrapper.closest('.form-group').hasClass('required');
				
				if (accepted) {
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
			
			installer.hideDialog();
		});
    }

    return installer;
}

export async function initializePWAInstaller() {
    if($('.add-to-homescreen').length == 0) {
        return;
    }
    console.log('Initializing PWA Installer');
    await initializeAddToHomeScreen();
    const installer = initialize_pwa_install_element();

    // Check for display mode changes
    window.addEventListener('DOMContentLoaded', updateInstallButtonState);
    window.addEventListener('visibilitychange', updateInstallButtonState);
    window.addEventListener('focus', updateInstallButtonState);
    window.addEventListener('appinstalled', function(e) {
        console.log('App was installed', e);
        localStorage.setItem('pwa-app-installed', 'true');
        updateInstallButtonState();
    });
    
    const isRequired = $('.add-to-homescreen-wrapper').closest('.form-group').hasClass('required');
    if(isRequired) {
        $('.add-to-homescreen-wrapper input')[0].setCustomValidity(t('Please complete this required step before continuing.'));
    }

    updateInstallButtonState();
    
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
        
        // Clear any existing timeout handler before starting a new one
        if (window.activeTimeoutHandler) {
            window.activeTimeoutHandler.clear();
        }
        
        // Start installation timeout handler
        const timeoutHandler = handleInstallTimeout($wrapper);
        timeoutHandler.start();
        
        // Store the active timeout handler globally so it can be cleared by event handlers
        window.activeTimeoutHandler = timeoutHandler;

        // Show the add-to-homescreen dialog
        console.log('Showing add-to-homescreen dialog');
        let prompted = false;
        if(window.deferredPrompt && installer) {
            prompted = true;
            installer.showDialog();
        } else {
            const add_instance = window.AddToHomeScreenInstance.show();
            if(!add_instance.canBeStandAlone && !installer) {
                tryDifferentBrowser($wrapper, t('Your browser may not support adding to the home screen. Check the menu to be sure or try a different browser.'));
                timeoutHandler.clear();
            } else if(!add_instance.canBeStandAlone) {
                installer.showDialog();
            } else {
                prompted = true;
            }
        }

        if(prompted) {
            $hiddenInput.val('prompted');
            $status.html(t('Preparing installation...'));
            $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + t('Processing...'));
        }
        
        return false;
    });
}

// Function to initialize the add-to-homescreen library
async function initializeAddToHomeScreen() {
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
    if (!manifestLink) {
        console.log('No manifest link found, using default values');
        initializeWithAppInfo(appName, appIconUrl);
        return;
    }

    try {
        const response = await fetch(manifestLink.href);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        
        const data = await response.json();
        
        // Process manifest data
        appName = data.name || data.short_name || appName;
        
        if (data.icons?.length) {
            const suitableIcon = data.icons.find(icon => 
                icon.sizes?.includes('192x192') || icon.sizes?.includes('512x512')
            );
            appIconUrl = suitableIcon?.src || appIconUrl;
        }
        
        initializeWithAppInfo(appName, appIconUrl);
        
    } catch (error) {
        console.error('Error processing manifest:', error);
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
    
    // Update the install button state now that we have initialized
    updateInstallButtonState();
}

// Handle when the add-to-homescreen dialog is closed
function handleAddToHomeScreenClosed(installed) {
    // Clear the timeout handler when dialog is closed
    
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
        const $button = $wrapper.find('button.push-notification-permission');
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
                addNotificationControls($wrapper, registration, {
                    customMessage: t('Push notifications are enabled.'),
                    showUnsubscribeButton: true
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
                addNotificationControls($wrapper, registration, {
                    customMessage: t('Push notifications are enabled.'),
                    showUnsubscribeButton: true
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
            addNotificationControls($wrapper, registration, {
                showTestButton: false,
                customMessage: t('You have declined push notifications. You can enable them in your browser settings.')
            });
            $button.removeClass('btn-primary').addClass('btn-default');
            $button.html(`<i class="fa fa-times"></i> ${t('Notifications Blocked. Click again after enabling in browser settings.')}`);
        }
    });

    $('.push-notification-permission')
        .off('click.formrPushNotification')
        .on('click.formrPushNotification', async function(e) {
        e.preventDefault();
        const $btn = $(this);
        
        if ($btn.prop('disabled')) {
            return false;
        }
        
        const $wrapper = $btn.closest('.push-notification-wrapper');
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input');

        try {
            $btn.html('<i class="fa fa-spinner fa-spin"></i> ' + t('Processing...'));
            
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
                addNotificationControls($wrapper, registration, {
                    customMessage: t('Push notifications are already enabled.'),
                    showUnsubscribeButton: true
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
                // Fire custom event for subscription success
                const subscriptionEvent = new CustomEvent('pushSubscriptionChanged', {
                    detail: {
                        action: 'subscribed',
                        subscription: result.subscription,
                        subscriptionJson: subscriptionJson
                    }
                });
                document.dispatchEvent(subscriptionEvent);

                let platformSpecificNote = '';
                if (/android/i.test(navigator.userAgent)) {
                    platformSpecificNote = `
                        <p><strong>${t('Note for Android users:')}</strong> ${t('On some Android devices, you may need to restart your browser or add this app to your home screen for notifications to work properly.')}</p>
                    `;
                }
                
                addNotificationControls($wrapper, registration, {
                    customMessage: t('Push notifications enabled successfully!'),
                    additionalContent: `
                        <p>${t("A test notification was sent. If you didn't see it, your system settings might be blocking notifications.")}</p>
                        ${platformSpecificNote}
                    `
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

// Helper function to add notification controls (test button and troubleshooting)
function addNotificationControls($wrapper, registration, options = {}) {
    const {
        showTestButton = true,
        showUnsubscribeButton = false,
        customMessage = '',
        additionalContent = ''
    } = options;

    const $status = $wrapper.find('.status-message');
    
    let buttonsHtml = '';
    if (showTestButton) {
        buttonsHtml += `<button type="button" class="btn btn-default test-notification-button"><i class="fa fa-bell"></i> ${t('Test notification')}</button>`;
    }
    if (showUnsubscribeButton) {
        buttonsHtml += `<button type="button" class="btn btn-warning unsubscribe-notification-button"><i class="fa fa-bell-slash"></i> ${t('Disable Notifications')}</button>`;
    }
    buttonsHtml += `<button type="button" class="btn btn-link show-notification-help"><i class="fa fa-exclamation-triangle"></i> ${t('Show troubleshooting tips')}</button>`;

    $status.html(`
        <div>
            ${customMessage ? `<p>${customMessage}</p>` : ''}
            ${additionalContent}
            <div class="notification-controls btn-group">
                ${buttonsHtml}
            </div>
        </div>
    `);

    if (showTestButton) {
        $wrapper.find('.test-notification-button').on('click', async function() {
            await sendTestNotification(registration);
        });
    }

    if (showUnsubscribeButton) {
        $wrapper.find('.unsubscribe-notification-button').on('click', async function() {
            await handleUnsubscribe($wrapper, registration);
        });
    }

    $wrapper.find('.show-notification-help').on('click', function() {
        showNotificationHelp($wrapper);
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
                tag: 'test-notification',
                requireInteraction: false
            });
            
            
            // Close the test notification after 5 seconds
            setTimeout(() => {
                testNotification.close();
            }, 10000);
            
            return true;
        }
    } catch (error) {
        console.error('Error creating test notification:', error);
        return false;
    }
}

// Handle unsubscribe from push notifications
async function handleUnsubscribe($wrapper, registration) {
    const $status = $wrapper.find('.status-message');
    const $hiddenInput = $wrapper.find('input');
    const $button = $wrapper.find('button.push-notification-permission');
    
    try {
        // Show processing state
        $wrapper.find('.unsubscribe-notification-button').html('<i class="fa fa-spinner fa-spin"></i> ' + t('Processing...'));

        const result = await PushNotificationManager.unsubscribe(registration);
        
        if (result.success) {
            // Make AJAX call to delete push subscription from database
            try {

                const basePath = (() => {
                    const parts = window.location.pathname.split('/').filter(Boolean);
                    // Remove 'settings' if it's the last part to get run root
                    if (parts.length > 1 && parts[parts.length - 1] === 'settings') {
                        parts.pop();
                    }
                    return parts.length > 0 ? '/' + parts.join('/') + '/' : '/';
                })();

                const response = await fetch(basePath + 'ajax_delete_push_subscription', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }
                });

                const data = await response.json();
                
                if (!data.success) {
                    console.warn('Failed to delete subscription from database:', data.message);
                    // Continue with UI update even if database deletion failed
                }
            } catch (ajaxError) {
                console.error('Error deleting subscription from database:', ajaxError);
                // Continue with UI update even if database deletion failed
            }
            
            // Clear the hidden input
            $hiddenInput.val('');
            
            // Fire custom event for unsubscription success
            const unsubscriptionEvent = new CustomEvent('pushSubscriptionChanged', {
                detail: {
                    action: 'unsubscribed'
                }
            });
            document.dispatchEvent(unsubscriptionEvent);
            
            // Reset the UI to initial state
            $status.html(t('Click the button to enable push notifications.'));
            $button.removeClass('btn-success').addClass('btn-primary');
            $button.prop('disabled', false);
            $button.html(`<i class="fa fa-bell"></i> ${t('Enable Notifications')}`);
            $wrapper.closest('.form-group').removeClass('formr_answered');
            
            console.log('Push notifications unsubscribed successfully');
        } else {
            console.error('Failed to unsubscribe from push notifications:', result.reason);
            $status.html(t('There was an error disabling push notifications. Please try again later.'));
            $wrapper.find('.unsubscribe-notification-button').html(`<i class="fa fa-bell-slash"></i> ${t('Disable Notifications')}`);
        }
        
    } catch (error) {
        console.error('Error during push notification unsubscribe:', error);
        $status.html(t('There was an error disabling push notifications. Please try again later.'));
        $wrapper.find('.unsubscribe-notification-button').html(`<i class="fa fa-bell-slash"></i> ${t('Disable Notifications')}`);
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
            $statusMessage.html(t('If you are having trouble installing the app, this browser may not be fully supported. Chrome on Android and Safari on iOS are best supported.'));
            
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

// Helper function for badge management 
/**
 * Manages the app badge using the Badging API if available
 * @param {number|null} count - The badge count to set, or null to clear
 * @returns {Promise<void>}
 */
async function manageBadge(count) {
    if (!('setAppBadge' in navigator)) {
      console.log('Badging API not supported in this environment');
      return;
    }
  
    try {
      if (count && count > 0) {
        await navigator.setAppBadge(count);
      } else {
        await navigator.clearAppBadge();
      }
    } catch (error) {
      console.error('Error managing badge:', error);
    }
  }
  
  
// Function to check and handle pending notifications
async function handlePendingNotifications() {
    try {
        const survey_open = $('.run_unit_type_Survey').length > 0;
        await manageBadge(survey_open ? 1 : 0);

        const registration = await navigator.serviceWorker.getRegistration();
        if (!registration) return false;

        const notifications = await registration.getNotifications();
        console.log('Found', notifications.length, 'pending notifications');
        
        // Handle timestamps immediately
        for (const notification of notifications) {
            if (notification.data?.timestamp) {
                reload_invalidated(notification.data.timestamp);
            }
        }

        if (notifications.length > 0) {
            // Close all notifications after 5 seconds
            setTimeout(() => {
                for (const notification of notifications) {
                    notification.close();
                }
            }, 5000);
            
            console.log(`Closed ${notifications.length} notifications`);
            localStorage.setItem('notifications-closed', Date.now());
        }

        return notifications.length > 0;
    } catch (error) {
        console.error('Error handling pending notifications:', error);
        return false;
    }
}

function reload_invalidated(timestamp) {
    localStorage.setItem('state-invalidated', timestamp);
    if(!localStorage.getItem('handling-reload') &&
        (!localStorage.getItem('last-reload-timestamp') || timestamp > parseInt(localStorage.getItem('last-reload-timestamp'), 10))) {
      localStorage.setItem('last-reload-timestamp', Date.now() + 200);
      localStorage.setItem('handling-reload', 'true');
      setTimeout(() => {
        console.log('Reloading page at', timestamp);
        if (isIOSDevice()) {
            window.focus();
            window.location.href = window.location.href;
        } else {
            window.location.reload();
        }
      }, 100);
    } else if(timestamp < parseInt(localStorage.getItem('last-reload-timestamp'), 10)) {
        localStorage.removeItem('state-invalidated');
    }
  }

// remember when we last reloaded the page
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOMContentLoaded');
    localStorage.setItem('last-reload-timestamp', Date.now());
    localStorage.removeItem('handling-reload');
});

// Add service worker message handler at the top level
if ('serviceWorker' in navigator) {
    console.log('serviceWorker in navigator, try to attach message handler');
    // Listen for messages from the service worker
    navigator.serviceWorker.addEventListener('message', (event) => {
        console.log('Received message from service worker', event.data);
        if(event.data.type === 'STATE_INVALIDATED') {
            console.log('Received STATE_INVALIDATED message from service worker');
            reload_invalidated(event.data.timestamp);
        } else if (event.data.type === 'NOTIFICATION_CLICK' && event.data.action === 'reload') {
            console.log('Received reload message from service worker');
            reload_invalidated(event.data.timestamp);
        }
    });

    // Check for pending notifications and handle page initialization
    // Handle page visibility changes    
    ['visibilitychange', 'focus', 'pageshow'].forEach(eventType => {
        window.addEventListener(eventType, () => {
            console.log('Event type', eventType, 'document.hidden', document.hidden);
            if(!document.hidden && localStorage.getItem('state-invalidated')) {
               reload_invalidated(localStorage.getItem('state-invalidated'));
            }
            handlePendingNotifications();
    });
    });
    
}

// Add new function to handle installation timeout
function handleInstallTimeout($wrapper) {
    const timeoutDuration = 15000; // 15 seconds
    let installTimeoutId = null;

    return {
        start: () => {
            installTimeoutId = setTimeout(() => {
                tryDifferentBrowser($wrapper);
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
/**
 * Try different browser
 * @param {*} $wrapper 
 * @param {*} message 
 */
function tryDifferentBrowser($wrapper, message) {
    const $status = $wrapper.find('.status-message');
    const $button = $wrapper.find('.add-to-homescreen');
    
    // Clear any existing content and add the browser switch UI
    if(!message) {
        message = t('Installation not working? Try switching to a supported browser like Chrome or Safari.');
    }
    $status.html(message);
    addBrowserSwitchUI($wrapper);
    
    // Reset button state
    $button.prop('disabled', false);
    $button.html($button.data('default-text'));
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

// on domready, add a last updated time to the page
document.addEventListener('DOMContentLoaded', () => {
    const current_time = "2025-03-10 17:14:00";
    const $lastUpdated = $('<div class="last-updated">Last updated: ' + current_time + '</div>');
    $('.monkey_bar a.label').before($lastUpdated);
});

// =========================
// RequestCookie item logic
// =========================

export function initializeRequestCookie() {
    $('.request-cookie-wrapper').each(function() {
        const $wrapper = $(this);
        const $hidden = $wrapper.find('input');
        const $status = $wrapper.find('.status-message');
        const $button = $wrapper.find('button.request-cookie');
        const isRequired = $wrapper.closest('.form-group').hasClass('required');

        if (isRequired && $hidden.length) {
            $hidden[0].setCustomValidity(t('Please enable functional cookies to continue.'));
        }

        function hasFunctionalConsent() {
            const cookie = document.cookie.split('; ').find(row => row.startsWith('formrcookieconsent='));
            if (!cookie) return false;
            try {
                const val = decodeURIComponent(cookie.split('=')[1]);
                return val.indexOf('"necessary","functionality"') !== -1;
            } catch (e) {
                return false;
            }
        }

        function markAnswered() {
            if ($hidden.length) {
                $hidden.val('consent_given');
                $hidden[0].setCustomValidity('');
            }
            if ($status.length) {
                $status.html(t('Functional cookies enabled. You can continue.'));
            }
            if ($button.length) {
                $button.prop('disabled', true)
                       .removeClass('btn-primary')
                       .addClass('btn-success')
                       .html('<i class="fa fa-check"></i> ' + t('Enabled'));
            }
            $wrapper.closest('.form-group').addClass('formr_answered');
        }

        // If consent already exists, mark answered immediately
        if (hasFunctionalConsent()) {
            markAnswered();
            return;
        }

        // Click handler to open preferences dialog
        $button.off('click.requestCookie').on('click.requestCookie', function(e) {
            e.preventDefault();
            if (typeof showPreferences === 'function') {
                showPreferences();
            }
        });

        // Poll for consent changes (e.g., after dialog interaction)
        const intervalId = setInterval(() => {
            if (hasFunctionalConsent()) {
                markAnswered();
                clearInterval(intervalId);
            }
        }, 1000);
    });
}

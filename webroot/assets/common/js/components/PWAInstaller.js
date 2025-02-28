import $ from 'jquery';

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

// Update the state of installation button and related UI elements
function updateInstallButtonState() {
    const $wrapper = $('.add-to-homescreen-wrapper');
    const $button = $wrapper.find('.add-to-homescreen');
    const $status = $wrapper.find('.status-message');
    const $hiddenInput = $wrapper.find('input[type="hidden"]');
    const installer = document.querySelector('pwa-install');

    // When first called, store the default text on the button in a data attribute.
    if (!$button.data('default-text')) {
        $button.data('default-text', $button.html());
    }

    if (!installer) {
        $hiddenInput.val('no_support');
        $status.html('Installation component not available. Please try again later.');
        $button.prop('disabled', true);
        return;
    }
    
    // Check if we're in standalone mode
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
        window.matchMedia('(display-mode: fullscreen)').matches || 
        window.navigator.standalone;

    if (isStandalone) {
        $hiddenInput.val('already_added');
        $status.html('You are currently using the installed app.');
        $wrapper.closest('.form-group').addClass('formr_answered');
        $button.removeClass('btn-primary').addClass('btn-success');
        $button.prop('disabled', true);
        $button.html('<i class="fa fa-check"></i> Installed');
        localStorage.setItem('pwa-app-installed', 'true');
        return;
    }
    
    // Check if the app is already installed on Chrome/Android using isRelatedAppsInstalled().
    // Use localStorage as a fallback (commonly for iOS).
    if (installer.isRelatedAppsInstalled) {
        $hiddenInput.val('already_added');
        $status.html("You've already installed this app. Try opening this page in the installed app.");
        $wrapper.closest('.form-group').addClass('formr_answered');
        $button.removeClass('btn-primary').addClass('btn-success');
        $button.html('<i class="fa fa-check"></i> Installed');
        $button.attr('disabled', true);
    } else if (localStorage.getItem('pwa-app-installed') === 'true') {
        $hiddenInput.val('already_added');
        $status.html("You've already installed this app. Try opening this page in the installed app. If you have uninstalled the app, please just click this button again.");
        $wrapper.closest('.form-group').addClass('formr_answered');
        $button.removeClass('btn-primary').addClass('btn-success');
        $button.html('<i class="fa fa-check"></i> Installed');
    } else if (!installer.isInstallAvailable) {
        $hiddenInput.val('cannot_install');
        $status.html("This app is not available for installation. Maybe you already installed the app or you need to switch to a different browser.");
        $button.prop('disabled', true);
        $button.html("Cannot install app");
    } else {
        $hiddenInput.val('not_started');
        // If not already installed, set platform-specific text.
        $status.html('<p>Add this app to your home screen for easier access.</p>');
        $button.prop('disabled', false);
        $button.html($button.data('default-text'));
    }
}

export function initializePWAInstaller() {
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
    
    // Handle beforeinstallprompt event
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        if (installer) {
            installer.externalPromptEvent = e;
        }
    });
    
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

    // Button click handler
    $('.add-to-homescreen').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        
        if ($btn.prop('disabled')) {
            return false;
        }
        
        const $wrapper = $btn.closest('.add-to-homescreen-wrapper');
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input[type="hidden"]');
        
        if (!installer) {
            $hiddenInput.val('no_support');
            $status.html('Installation is not supported in your browser.');
            return false;
        }
        
        installer.showDialog();
        const res = installer.showDialog(true);
        $hiddenInput.val('prompted');
        $status.html('Preparing installation...');
        $btn.html('<i class="fa fa-spinner fa-spin"></i> Processing...');
        

        
        return false;
    });

    if (installer) {
        // Installation successful
        installer.addEventListener('pwa-install-success-event', function(e) {
			console.log('Installation successful:', e.detail);
			localStorage.setItem('pwa-app-installed', 'true');

            updateInstallButtonState();
			
			installer.hideDial
		});
        
        // Installation failed
        installer.addEventListener('pwa-install-fail-event', function(e) {
            console.log('Installation failed:', e.detail);
            
            $('.add-to-homescreen-wrapper').each(function() {
                var $wrapper = $(this);
                var $status = $wrapper.find('.status-message');
                var $hiddenInput = $wrapper.find('input[type="hidden"]');
                var $button = $wrapper.find('.add-to-homescreen');
                
                $hiddenInput.val('failed');
                $status.html('Installation failed. You can try again or add to home screen manually from your browser menu.');
                $button.html('<i class="fa fa-plus-square"></i> Add to Home Screen');
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
				var $hiddenInput = $wrapper.find('input[type="hidden"]');
				var $button = $wrapper.find('.add-to-homescreen');
				var isRequired = $wrapper.closest('.form-group').hasClass('required');
				
				if (accepted) {
					updateInstallButtonState();
				} else {
					$hiddenInput.val('declined');
					if (isRequired) {
						$status.html('This step is required. Please add the app to your home screen to continue.');
						$wrapper.closest('.form-group').removeClass('formr_answered');
					} else {
						$status.html('You can add the app to your home screen at any time.');
						$wrapper.closest('.form-group').addClass('formr_answered');
					}
					$button.html('<i class="fa fa-plus-square"></i> Add to Home Screen');
				}
			});
			
			installer.hideDialog();
		});
        
        // How to install shown
        installer.addEventListener('pwa-install-how-to-event', function(e) {
            console.log('How to install shown:', e.detail);
            
            $('.add-to-homescreen-wrapper').each(function() {
                var $wrapper = $(this);
                var $status = $wrapper.find('.status-message');
                var $hiddenInput = $wrapper.find('input[type="hidden"]');
                
                $hiddenInput.val('instructed');
                $status.html('Follow the instructions to add this app to your home screen.');
            });
        });
        
        // Installation availability detected
        installer.addEventListener('pwa-install-available-event', function(e) {
            console.log('Installation available:', e.detail);
            
            updateInstallButtonState();
        });
    }

    // Add form validation for required add-to-homescreen items
    $('form.main_formr_survey').on('submit', function(e) {
        var $form = $(this);
        var $requiredHomescreen = $form.find('.form-group.required .add-to-homescreen');
        
        if ($requiredHomescreen.length) {
            var isValid = true;
            $requiredHomescreen.each(function() {
                var $input = $(this).closest('.add-to-homescreen-wrapper').find('input[type="hidden"]');
                var value = $input.val();
                
                var isIOSInstructed = (installer && 
                    (installer.isAppleMobilePlatform || installer.isAppleDesktopPlatform) && 
                    value === 'instructed');
                    
                if (!value || 
                    (['not_started', 'declined', 'failed', 'prompted'].indexOf(value) !== -1) || 
                    (value === 'no_support' && !(installer && installer.isUnderStandaloneMode)) && 
                    !isIOSInstructed) {
                    isValid = false;
                    $(this).closest('.form-group').find('.status-message')
                        .html('<strong style="color: red;">Please complete this required step before continuing.</strong>');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
        }
    });
}

export function initializePushNotifications() {
    // Initialize push notification state for all push notification elements
    $('.push-notification-wrapper').each(async function() {
        var $wrapper = $(this);
        var $status = $wrapper.find('.status-message');
        var $hiddenInput = $wrapper.find('input[type="hidden"]');
        var $button = $wrapper.find('.push-notification-permission');
        var isRequired = $wrapper.closest('.form-group').hasClass('required');

        // Check if the browser supports notifications and service workers
        if (!PushNotificationManager.isSupported()) {
            $hiddenInput.val('not_supported');
            $status.html('Sorry, your browser does not support push notifications.');
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        }

        // Check iOS compatibility
        if (!PushNotificationManager.isIOSCompatible()) {
            $hiddenInput.val('ios_version_not_supported');
            $status.html('Sorry, push notifications require iOS 16.4 or later.');
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        }

        // Get existing service worker registration
        const registration = await PushNotificationManager.getRegistration();
        if (!registration) {
            $hiddenInput.val('no_service_worker');
            $status.html('Service worker not registered. Please reload the page and try again.');
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            return;
        } else {
            console.log('Service worker registered');
        }

        // Check localStorage first for subscription status
        if (localStorage.getItem('push-notification-subscribed') === 'true') {
            const subResult = await PushNotificationManager.checkSubscription(registration);
            
            if (subResult.subscribed) {
                $hiddenInput.val(JSON.stringify(subResult.subscription));
                $status.html('Push notifications are enabled.');
                $button.removeClass('btn-primary').addClass('btn-success');
                $button.prop('disabled', true);
                $button.html('<i class="fa fa-check"></i> Notifications Enabled');
                $wrapper.closest('.form-group').addClass('formr_answered');
                return;
            } else {
                localStorage.removeItem('push-notification-subscribed');
            }
        }

        // Check current permission state
        const existingPermission = Notification.permission;
        
        if (existingPermission === 'granted') {
            const subResult = await PushNotificationManager.checkSubscription(registration);
            
            if (subResult.subscribed) {
                const subscriptionJson = JSON.stringify(subResult.subscription);
                $hiddenInput.val(subscriptionJson);
                $status.html('Push notifications are enabled.');
                $button.removeClass('btn-primary').addClass('btn-success');
                $button.prop('disabled', true);
                $button.html('<i class="fa fa-check"></i> Notifications Enabled');
                $wrapper.closest('.form-group').addClass('formr_answered');
                
                localStorage.setItem('push-notification-subscribed', 'true');
            } else {
                $hiddenInput.val('no_subscription');
                $status.html('Click the button to enable push notifications.');
                $button.html('<i class="fa fa-bell"></i> Enable Notifications');
            }
        } else if (existingPermission === 'denied') {
            $hiddenInput.val('permission_denied');
            $status.html('You have declined push notifications. You can enable them in your browser settings.');
            $button.prop('disabled', true);
            $button.removeClass('btn-primary').addClass('btn-default');
            $button.html('<i class="fa fa-times"></i> Notifications Blocked');
        }
    });

    // Push Notification Permission functionality
    $('.push-notification-permission').click(async function(e) {
        e.preventDefault();
        const $btn = $(this);
        
        if ($btn.prop('disabled')) {
            return false;
        }
        
        const $wrapper = $btn.closest('.push-notification-wrapper');
        const $status = $wrapper.find('.status-message');
        const $hiddenInput = $wrapper.find('input[type="hidden"]');

        if (!PushNotificationManager.isSupported()) {
            $hiddenInput.val('not_supported');
            $status.html('Sorry, your browser does not support push notifications.');
            $btn.prop('disabled', true);
            $btn.removeClass('btn-primary').addClass('btn-default');
            return false;
        }

        if (!PushNotificationManager.isIOSCompatible()) {
            $hiddenInput.val('ios_version_not_supported');
            $status.html('Sorry, push notifications require iOS 16.4 or later.');
            $btn.prop('disabled', true);
            $btn.removeClass('btn-primary').addClass('btn-default');
            return false;
        }

        try {
            $btn.html('<i class="fa fa-spinner fa-spin"></i> Processing...');
            
            const registration = await PushNotificationManager.getRegistration();
            if (!registration) {
                $hiddenInput.val('no_service_worker');
                $status.html('Service worker not registered. Please reload the page and try again.');
                $btn.html('<i class="fa fa-exclamation-triangle"></i> Error');
                return false;
            }
            
            const subResult = await PushNotificationManager.checkSubscription(registration);
            if (subResult.subscribed) {
                const subscriptionJson = JSON.stringify(subResult.subscription);
                $hiddenInput.val(subscriptionJson);
                $status.html('Push notifications are already enabled.');
                $btn.removeClass('btn-primary').addClass('btn-success');
                $btn.prop('disabled', true);
                $btn.html('<i class="fa fa-check"></i> Notifications Enabled');
                $wrapper.closest('.form-group').addClass('formr_answered');
                localStorage.setItem('push-notification-subscribed', 'true');
                return false;
            }

            const result = await PushNotificationManager.subscribe(registration);
            
            if (result.success) {
                const subscriptionJson = JSON.stringify(result.subscription);
                $hiddenInput.val(subscriptionJson);
                $status.html('Thank you! You will now receive push notifications.');
                $btn.removeClass('btn-primary').addClass('btn-success');
                $btn.prop('disabled', true);
                $btn.html('<i class="fa fa-check"></i> Notifications Enabled');
                $wrapper.closest('.form-group').addClass('formr_answered');
            } else if (result.reason === 'permission_denied') {
                $hiddenInput.val('permission_denied');
                $status.html('You have declined push notifications. You can enable them later in your browser settings.');
                $btn.prop('disabled', true);
                $btn.removeClass('btn-primary').addClass('btn-default');
                $btn.html('<i class="fa fa-times"></i> Notifications Blocked');
            } else if (result.reason === 'invalid_config') {
                $hiddenInput.val('invalid_config');
                $status.html('Server configuration error. Please contact support.');
                $btn.html('<i class="fa fa-exclamation-triangle"></i> Configuration Error');
            } else {
                $hiddenInput.val('error');
                $status.html('There was an error setting up push notifications. Please try again later.');
                $btn.html('<i class="fa fa-exclamation-triangle"></i> Error');
            }
            
        } catch (error) {
            console.error('Error during push notification setup:', error);
            $hiddenInput.val('error');
            $status.html('There was an error setting up push notifications. Please try again later.');
            $btn.html('<i class="fa fa-exclamation-triangle"></i> Error');
        }
        
        return false;
    });
} 
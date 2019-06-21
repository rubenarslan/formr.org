const SW_VERSION = 'formr-v0.0.9'

notification_options = {
   "body": "This is a formr notification.",
   "icon": "assets/site/img/logo.png",
   "image": "assets/site/img/logo.png",
   "vibrate": [5,0,5,0,5],
   "requireInteraction": true,
   tag: 'renotify',
   renotify: true
}
                  


self.addEventListener('install', function(event) {
   console.log('Formr-Service-Worker installed');
});

self.addEventListener('activate', function(event) {
   console.log(`Formr-Service-Worker Version ${SW_VERSION} activated`);


   self.registration.showNotification('Benachrichtigung vom SW', notification_options);

});


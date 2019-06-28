const SW_VERSION = 'formr-v0.0.15'

notification_options = {
   "body": "This is a formr notification.",
   "icon": "assets/site/img/logo.png",
   "image": "assets/site/img/logo.png",
   "vibrate": [5,0,5,0,5],
   "requireInteraction": true,
   tag: 'renotify',
   renotify: true,
//   actions: [
 //     {action: 'open', title: 'Go to survey'}
  // ]
}

function dispatchNotification() {
   console.log('dispatchingNotification');
   self.registration.showNotification('Please continue your Survey', notification_options);
   
}
                  


self.addEventListener('install', function(event) {
   console.log(`Formr-Service-Worker Version ${SW_VERSION} installed`);
});

self.addEventListener('activate', function(event) {
   console.log(`Formr-Service-Worker Version ${SW_VERSION} activated`);
});


self.addEventListener('push', function(event) {
   console.log('Got a push event');
   if (!(self.Notification && self.Notification.permission === 'granted')) {
      return;
   }
   dispatchNotification();
});

self.addEventListener('notificationclick', function(event) {
   var notification = event.notification;
   var action = event.action;

   console.log(notification);

   if (action === 'confirm') {
      console.log('Confirm was chosen');
      notification.close();
   } else {
      console.log(action);
      event.waitUntil(
         clients.matchAll()
            .then(function(clis) {
               var client = clis.find(function(c) {
                  return c.visibilitystate === 'visible';
               });

               if (client !== undefined) {
                  client.navigate('https://www.uni-muenster.de/PsyTD/formr-entwicklung/NeuesQueuingSystemTest');
                  client.focus();
               } else {
                  clients.openWindow('https://www.uni-muenster.de/PsyTD/formr-entwicklung/NeuesQueuingSystemTest');
               }
               notification.close();
            })
       );
    }

});

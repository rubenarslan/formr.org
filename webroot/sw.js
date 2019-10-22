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
   self.registration.showNotification('Please continue your Survey', notification_options);
   
}
                  


self.addEventListener('install', function(event) {
   console.log(`Formr-Service-Worker Version ${SW_VERSION} installed`);
});

self.addEventListener('activate', function(event) {
   console.log(`Formr-Service-Worker Version ${SW_VERSION} activated`);
});


self.addEventListener('push', function(event) {
   if (!(self.Notification && self.Notification.permission === 'granted')) {
      return;
   }

   if (event.data) {
      console.log('there is data...');
      var data = event.data.json();
      console.log(data);
      console.log(data.msg);
      console.log(data.url);
      }
      let notification_options = {
         "icon": "assets/site/img/logo.png",
         "image": "assets/site/img/logo.png",
         "vibrate": [300,100,400],
         "requireInteraction": true,
         "data": data,
         tag: 'renotify',
         renotify: true,
      }

      self.registration.showNotification(data.msg, notification_options);

   //dispatchNotification();
});

self.addEventListener('notificationclick', function(event) {
   var notification = event.notification;
   var action = event.action;

   if (event.notification.data) {
      console.log(event.notification.data);
      console.log(event.notification.data.msg);
      console.log(event.notification.data.url);
   }

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
                  client.navigate(event.notification.data.url);
                  client.focus();
               } else {
                  clients.openWindow(event.notification.data.url);
               }
               notification.close();
            })
       );
    }

});

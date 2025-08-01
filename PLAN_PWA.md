# Turning formr into a PWA

## Change the studies view
- [x] Add a service worker
  - [ ] Workbox?
- [x] Add a manifest.json
- [x] Add icon 
- [x] Add a splash screen
- [x] Point to app for autogenerating relevant images, simplify uploading folder.
- [x] Add relevant settings to @application/Controller/AdminSurveyController.php and @templates/admin/survey/index.php

## Add item to request adding to home screen
- [x] Item inheriting from @application/Model/Item/Item.php
   - [x] A button to request adding to home screen, 
   - [x] guide/QR code to switch to a browser that supports PWA-homescreen (e.g., Safari on iOS)
   - [x] Store whether it has been added to home screen
- [x] Item to request permission to send push notifications, inheriting from @application/Model/Item/Item.php
   - [x] A button to request push permission
   - [x] Store whether permission has been granted

## Add new model for push notifications
- [x] Similar to @application/Services/OpenCPU.php
  - [x] [Based on this library](https://github.com/web-push-libs/web-push-php)

## Add run module for push notifications
- [x] New module based on @application/Model/RunUnit/Email.php
- [x] Add relevant logic to @application/Controller/RunSession.php
- [x] Add relevant logic to @application/Controller/UnitSession.php
- [x] Add relevant logic to @application/Controller/RunUnit.php
- [x] Add logging of push notifications to @application/Controller/AdminRunController.php

Probably need to log when users no longer receive push notifications. What to do in that case? Notify admins?

## Problems:
- [x] App reloads when people open the app after having received a push message. Push messages are then cleared.
- [x] Badge counts
- [x] Vibrate/Silent properties are respected, time-to-live is enforced as expiring old notifications. All notifications with the same title have the same tag.
- [x] Some browsers (e.g., iOS Brave don't allow for adding to home screen)
- [x] Submit button is deactivated upon trying to submit a required home screen/phone item, better integrate with existing form validation API?
- [x] Should hide the inputs again
- [x] Switch to https://github.com/philfung/add-to-homescreen ?

## Add documentation
- [x] Add documentation to @templates/public/documentation/

## Improvements
- [x] Make badge depend on whether a survey is active, clear if not.

- [ ] Update texts re uploads
- [ ] Update texts re users can be deleted if they don't comply
- [ ] Test user account deletion
- [x] Fix push message settings bug
- [x] Correct privacy policy link in settings
- [x] RequestCookie item
- [ ] Datenschutzmuster anpassen


# Turning formr into a PWA

## Change the studies view
- [x] Add a service worker
  - [ ] [push.js](https://github.com/Nickersoft/push.js)?
  - [ ] Workbox?
- [x] Add a manifest.json
- [x] Add icon 
- [ ] Add a splash screen
- [ ] Autogenerate relevant images?
- [x] Add relevant settings to @application/Controller/AdminSurveyController.php and @templates/admin/survey/index.php

## Add item to request adding to home screen
- [x] Item inheriting from @application/Model/Item/Item.php
   - [x] A button to request adding to home screen, 
   - [ ] guide/QR code to switch to a browser that supports PWA-homescreen (e.g., Safari on iOS)
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

## Add documentation
- [ ] Add documentation to @templates/public/documentation/
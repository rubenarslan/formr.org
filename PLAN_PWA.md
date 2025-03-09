# Turning formr into a PWA

## Change the studies view
- [ ] Add a service worker
  - [ ] [push.js](https://github.com/Nickersoft/push.js)?
  - [ ] Workbox?
- [ ] Add a manifest.json
- [ ] Add icon and a splash screen
  - [ ] Autogenerate relevant images?
- [ ] Add relevant settings to @application/Controller/AdminSurveyController.php and @templates/admin/survey/index.php

## Add item to request adding to home screen
- [ ] Item inheriting from @application/Model/Item/Item.php
   - [ ] A button to request adding to home screen, guide/QR code to switch to a browser that supports PWA-homescreen (e.g., Safari on iOS)
   - [ ] Store whether it has been added to home screen
- [ ] Item to request permission to send push notifications, inheriting from @application/Model/Item/Item.php
   - [ ] A button to request push permission
   - [ ] Store whether permission has been granted

## Add new model for push notifications
- [ ] Similar to @application/Services/OpenCPU.php
  - [ ] [Based on this library](https://github.com/web-push-libs/web-push-php)

## Add run module for push notifications
- [ ] New module based on @application/Model/RunUnit/Email.php
- [ ] Add relevant logic to @application/Controller/RunSession.php
- [ ] Add relevant logic to @application/Controller/UnitSession.php
- [ ] Add relevant logic to @application/Controller/RunUnit.php

Probably need to log when users no longer receive push notifications. What to do in that case? Notify admins?

## Add documentation
- [ ] Add documentation to @templates/public/documentation/
// node --test tests/js  (npm run test:js)
// What the PWA and phone-request items take the participant's device for
// (webroot/assets/common/js/platform.js).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isIOSDevice, isIOSPushCapable, isMobileDevice } from '../../webroot/assets/common/js/platform.js';

const device = (userAgent, maxTouchPoints = 0) => ({ userAgent, maxTouchPoints });
const SAFARI_DESKTOP = (v) => `Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/${v} Safari/605.1.15`;
const IPHONE = (os) => `Mozilla/5.0 (iPhone; CPU iPhone OS ${os} like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1`;
const ipad = (v) => device(SAFARI_DESKTOP(v), 5);
const mac = device(SAFARI_DESKTOP('17.4'));
const android = device('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36', 5);
const touchLaptop = device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36', 10);

test('an iPad asking for the desktop site is an iOS device, a Mac is not', () => {
    assert.equal(isIOSDevice(ipad('17.4')), true);
    assert.equal(isIOSDevice(mac), false);
    assert.equal(isIOSDevice(device(IPHONE('17_4'), 5)), true);
    assert.equal(isIOSDevice(device('Mozilla/5.0 (iPad; CPU OS 15_0 like Mac OS X) AppleWebKit/605.1.15', 5)), true, 'an iPad with a mobile user agent');
    assert.equal(isIOSDevice(android), false);
    assert.equal(isIOSDevice(touchLaptop), false);
});

test('web push needs iOS 16.4, read from the system or, on an iPad, Safari', () => {
    assert.equal(isIOSPushCapable(device(IPHONE('16_4'), 5)), true);
    assert.equal(isIOSPushCapable(device(IPHONE('16_3'), 5)), false);
    assert.equal(isIOSPushCapable(ipad('17.4')), true);
    assert.equal(isIOSPushCapable(ipad('16.3')), false);
    assert.equal(isIOSPushCapable(device('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15', 5)), false, 'no version to read');
    assert.equal(isIOSPushCapable(mac), true, 'not iOS: not this check');
    assert.equal(isIOSPushCapable(android), true);
});

test('an iPad is a mobile device, a touch-screen laptop is not', () => {
    assert.equal(isMobileDevice(ipad('17.4')), true);
    assert.equal(isMobileDevice(android), true);
    assert.equal(isMobileDevice(mac), false);
    assert.equal(isMobileDevice(touchLaptop), false);
});

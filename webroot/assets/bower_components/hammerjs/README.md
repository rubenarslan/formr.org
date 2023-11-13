# Hammer Time!

![Thors Hammer crashing down](http://33.media.tumblr.com/85d64e2f846797ab471480cff3f33d4b/tumblr_mzms7yMfum1s75u1lo2_500.gif)

## On your click times!

#### Response time on user actions is important

Keeping visual response to under 100ms means your users will not notice the delay. The UI will feel quick and responsive giving users the impression they are doing the work instead of the application. Try the boxes below to see the response time on mobile devices.

The first box uses `touch-action:none;` to remove the 300ms delay. The second box has no `touch-action` property set. On touch screens you will see a noticeable difference in the response time of the background and in the outputting of the end vs click events.

The `touch-action` css property is part of the Pointer Events spec [http://www.w3.org/TR/pointerevents/#the-touch-action-css-property](http://www.w3.org/TR/pointerevents/#the-touch-action-css-property)

Unfourtanitly not all common browsers support touch action yet ( [caniuse](http://caniuse.com/#feat=css-touch-action) ) so hammer-time works by partially polyfills this property. The only supported value is `none`, `manipulation`, or `auto`

### Advantages...

*   Size Hammer-time is very very small only 417 bytes gzipped
*   Easy to use no special libraries or events to bind. Hammer-time just speeds up the native events so you can use your favorite event library like jQuery or just plain old `addEventListener`
*   Based on real standards, Hammer-time is a polyfill so it is a complete noop on browsers which support native `touch-action`
*   Avoids target mismatches between the `touchend` and `click` events

### Gotchas...

*   **Only works when applied directly to the style attribute on an element not to a stylesheet**
*   Does not prevent scrolling or other behaviors which happen on move or double tap zoom
*   You cannot set the touch-action property via `element.style[ touch-action ]` browsers that do not support touch action will ignore this
*   Removing the touch-action property from an existing element is not supported, Hammer-time has no way of knowing the difference between you removing the property and it being removed as a result or browser sanitization. Instead of removing the property completely simply change it to the default value of auto
*   Direct manipulation of the style property in a loop on elements with touch-action set from JavaScript ( JS animations for example ) should be avoided. Because of how browsers sanitize the style attribute when setting properties we use a mutation observe to restore the the touch action property when it is removed
*	To properly support IE10 you need to add both `touch-action` and `-ms-touch-action`

To read more about UI response times and how this effects user experience see [http://www.nngroup.com/articles/response-times-3-important-limits/](http://www.nngroup.com/articles/response-times-3-important-limits/)

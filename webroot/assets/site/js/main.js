(function () {

    'use strict';

    // iPad and iPod detection	
    var isiPad = function () {
        return (navigator.platform.indexOf("iPad") != -1);
    };

    var isiPhone = function () {
        return (
                (navigator.platform.indexOf("iPhone") != -1) ||
                (navigator.platform.indexOf("iPod") != -1)
                );
    };

    // Go to next section
    var gotToNextSection = function () {
        var el = $('.fmr-learn-more'),
                w = el.width(),
                divide = -w / 2;
        el.css('margin-left', divide);
    };

    // Loading page
    var loaderPage = function () {
        $(".fmr-loader").fadeOut("slow");
    };

    // FullHeight
    var fullHeight = function () {
        if (!isiPad() && !isiPhone()) {
            var offset = $('.js-fullheight.full').length ? 0 : 49,
                minHeight = $(window).height() - offset;
            $('.js-fullheight').css('min-height', minHeight);
            $('.run-container').css('min-height', $(window).height() - 1);
            
            minHeight += offset;
            var cHeight = $('#fmr-hero .fmr-intro').height();
            var _mTop = (minHeight >= cHeight) ? ((minHeight - cHeight) / 2) + 5 : 90;
            var mTop = _mTop > 90 ? _mTop : 90;
            $('#fmr-hero .fmr-intro .fmr-intro-text').css('padding-top', mTop);
            console.log(minHeight, cHeight, _mTop);
        }
    };
    $(window).resize(fullHeight);

    var toggleBtnColor = function () {

        return;
        /*
        if ($('#fmr-hero').length > 0) {
            $('#fmr-hero').waypoint(function (direction) {
                if (direction === 'down') {
                    $('.fmr-nav-toggle').addClass('dark');
                }
            }, {offset: -$('#fmr-hero').height()});

            $('#fmr-hero').waypoint(function (direction) {
                if (direction === 'up') {
                    $('.fmr-nav-toggle').removeClass('dark');
                }
            }, {
                offset: function () {
                    return -$(this.element).height() + 0;
                }
            });
        }

        */
    };


    // Scroll Next
    var scrollNext = function () {
        $('body').on('click', '.scroll-btn', function (e) {
            e.preventDefault();

            $('html, body').animate({
                scrollTop: $($(this).closest('[data-next="yes"]').next()).offset().top
            }, 1000, 'easeInOutExpo');
            return false;
        });
    };

    // Click outside of offcanvass
    var mobileMenuOutsideClick = function () {

        $(document).click(function (e) {
            var container = $("#fmr-offcanvas, .js-fmr-nav-toggle");
            if (!container.is(e.target) && container.has(e.target).length === 0) {

                if ($('body').hasClass('offcanvas-visible')) {

                    $('body').removeClass('offcanvas-visible');
                    $('.js-fmr-nav-toggle').removeClass('active');

                }


            }
        });

    };


    // Offcanvas
    var offcanvasMenu = function () {
        $('body').prepend('<div id="fmr-offcanvas" />');
        $('#fmr-offcanvas').prepend('<ul id="fmr-side-links">');
        $('body').prepend('<a href="#" class="js-fmr-nav-toggle fmr-nav-toggle"><i></i></a>');

        $('.left-menu li, .right-menu li').each(function () {

            var $this = $(this);

            $('#fmr-offcanvas ul').append($this.clone());

        });
    };

    // Burger Menu
    var burgerMenu = function () {

        $('body').on('click', '.js-fmr-nav-toggle', function (event) {
            var $this = $(this);

            $('body').toggleClass('fmr-overflow offcanvas-visible');
            $this.toggleClass('active');
            event.preventDefault();

        });

        $(window).resize(function () {
            if ($('body').hasClass('offcanvas-visible')) {
                $('body').removeClass('offcanvas-visible');
                $('.js-fmr-nav-toggle').removeClass('active');
            }
        });

        $(window).scroll(function () {
            if ($('body').hasClass('offcanvas-visible')) {
                $('body').removeClass('offcanvas-visible');
                $('.js-fmr-nav-toggle').removeClass('active');
            }
        });

    };


    var testimonialFlexslider = function () {
        var $flexslider = $('.flexslider');
        $flexslider.flexslider({
            animation: "fade",
            manualControls: ".flex-control-nav li",
            directionNav: false,
            smoothHeight: true,
            useCSS: false /* Chrome fix*/
        });
    };


    var goToTop = function () {

        $('.js-gotop').on('click', function (event) {

            event.preventDefault();

            $('html, body').animate({
                scrollTop: $('html').offset().top
            }, 500);

            return false;
        });

    };


    // Document on load.
    $(function () {
        gotToNextSection();
        loaderPage();
        fullHeight();
        //toggleBtnColor();
        scrollNext();
        mobileMenuOutsideClick();
        offcanvasMenu();
        burgerMenu();
        //testimonialFlexslider();
        goToTop();

    });


}());
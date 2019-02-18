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

    // FullHeight
    var fullHeight = function () {
        //if (!isiPad() && !isiPhone()) {
            var offset = $('.js-fullheight.full').length ? 0 : 49,
                minHeight = Math.min($(window).height(), 620) - offset;
            $('.js-fullheight').css('min-height', minHeight);
            $('.run-container').css('min-height', $(window).height() - 1);
            
            minHeight += offset;
            var cHeight = $('.fmr-intro').height();
            var _mTop = (minHeight >= cHeight) ? ((minHeight - cHeight) / 2) + 5 : 120;
            var mTop = _mTop > 120 ? _mTop : 120;
            $('#fmr-hero .fmr-intro .fmr-intro-text').css('padding-top', mTop);
			$('.js-fullheight.elongate').height(cHeight + 15);
        //}
    };
    $(window).resize(fullHeight);


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

        $('#formr-nav .nav li').each(function () {

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

    var goToTop = function () {
        $('.js-gotop').on('click', function (event) {
            event.preventDefault();
            $('html, body').animate({
                scrollTop: $('html').offset().top
            }, 500);

            return false;
        });
    };

	var resizeRmarkdowniFrame = function() {
		function rmarkdown_iframe() {
			if (isiPhone() || isiPad()) {
				var $win = $(window);
				$('.rmarkdown_iframe').css({
					width: $win.width(),
					height: $win.height()
				});
			}
		}

		rmarkdown_iframe();
		$(window).bind('resize', rmarkdown_iframe);
	};

    var material = function() {
        if ($.material) {
            $.material.init();
        }
    };
    
    var runContainer = function() {
        $('.progress-container').css('width', $('.run-container').width());
    };

	var Account = function() {};
	Account.prototype.init = function() {
		var account = this;
		this.container = $('.fmr-account-info');
		if (!this.container.length) {
			return;
		}
		if (this.container.is('.show-form')) {
			this.edit(false);
		}

		this.container.find('.edit-info-btn').click(function(e) {
			e.preventDefault();
			account.edit();
		});
		this.container.find('.cancel-edit-btn').click(function(e) {
			e.preventDefault();
			account.edit(true);
		});
	};
	Account.prototype.edit = function(cancel) {
		var account = this;
		if (cancel === true) {
			account.container.find('.read-info').show();
			account.container.find('.edit-info').hide();
		} else {
			account.container.find('.read-info').hide();
			account.container.find('.edit-info').show();
		}
		fullHeight();
	};
	Account.prototype.save = function() {
		
	};


    // Document on load.
    $(function () {
        gotToNextSection();
        fullHeight();
        scrollNext();
        mobileMenuOutsideClick();
        offcanvasMenu();
        burgerMenu();
        goToTop();
        material();
        runContainer();
		resizeRmarkdowniFrame();
		(new Account()).init();
    });


}());
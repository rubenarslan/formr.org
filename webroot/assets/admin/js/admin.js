//import $ from 'jquery';

$(document).ready(function() {
	// Select all checkbox in 'Users overview'
	$('#user-overview-select-all').click(function() {
		var $select = $(this);
		var $checkboxes = $select.parents('table').find('.ba-select-session');

		if ($select.is(':checked')) {
			$checkboxes.prop('checked', true);
		} else {
			$checkboxes.prop('checked', false);
		}
	});

	// Click dashboard link
	$('.dashboard-link').each(function(i, l) {
		var click = $(l).data('click');
		if (click == 1) {
			$(l).trigger('click');
		}
	});

});

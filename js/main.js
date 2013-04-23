$.webshims.polyfill('forms forms-ext');
$(document).ready(function() {
	
	$('div.btn-radio button.btn').off('click').click(function(event){
		var $btn = $(this);
		$('#'+$btn.attr('data-for')).attr('checked',true); // couple with its radio button
		var all_buttons = $btn.closest('div.btn-group').find('button.btn'); // find all buttons
		all_buttons.removeClass('btn-checked'); // uncheck all
		$btn.addClass('btn-checked'); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
		$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons
	});
	
	$('div.btn-checkbox button.btn').off('click').click(function(event){
		var $btn = $(this);
		var checked = $('#'+$btn.attr('data-for')).attr('checked');
		$('#'+$btn.attr('data-for')).attr('checked',!checked); // couple with its radio button
		$btn.toggleClass('btn-checked',!checked); // check this one
		return false;
	}).each(function() {
		var $btn = $(this);
		$btn.closest('div.btn-group').removeClass('hidden'); // show special buttons
		$btn.closest('.controls').find('label').addClass('hidden'); // hide normal radio buttons
	});
	
	$('.hastooltip').tooltip();
});
$(function(){
    $('textarea.big_ace_editor').each(function(i, elm)
    {
       var textarea = $(elm);
       var mode = textarea.data('editor');

       var editDiv = $('<div>', {
           position: 'absolute',
           width: "100%",
           height: textarea.height(),
           'class': textarea.attr('class')
       }).insertBefore(textarea);

       textarea.css('display', 'none');

       var editor = ace.edit(editDiv[0]);
       editor.setOptions({
           minLines: textarea.attr('rows') ? textarea.attr('rows') : 3,
           maxLines: 200
       });
       editor.setTheme("ace/theme/textmate");
       var session = editor.getSession();
       session.setValue(textarea.val());
   
       session.setUseWrapMode(true);
       session.setMode("ace/mode/" + mode);
       
       var form =$(this).parents('form');
       editor.on('change',function() { form.trigger("change") });
       form.on('ajax_submission',function()
       {
           textarea.val(session.getValue());
       });
       webshim.ready('forms-ext dom-extend',function() {
           webshim.addShadowDom(textarea, editor);
       });
       
    });
    $(".save_settings").each(function(i, elm)
    {
        $(elm).prop("disabled",true);
        var form =$(this).parents('form');
        form
        .change(function()
        {
            $(elm).prop("disabled",false);
        })
        .submit(function() { return false; });
       $(elm).click(function(e){
           form.trigger("ajax_submission");
           e.preventDefault();
           	$.ajax(
           		{
           			url: form.attr('action'),
           			dataType: 'html',
           			data: form.serialize(),
           			method: 'POST',
           		})
           		.done(function(data)
           		{
                    if(data != '')
                        $(data).insertBefore(form);
                    $(elm).prop("disabled",true);
                })
               .fail(function(e, x, settings, exception) {
                   ajaxErrorHandling(e, x, settings, exception);
               });
           
           return false;
       });
    });
});
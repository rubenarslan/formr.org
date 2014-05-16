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
    });
});
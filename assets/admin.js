jQuery(function($){$('.ccsm-select').on('click',function(e){e.preventDefault();var row=$(this).closest('.ccsm-media');var picker=wp.media({title:'Selecionar mídia',button:{text:'Usar este arquivo'},multiple:false});picker.on('select',function(){var item=picker.state().get('selection').first().toJSON();row.find('input[type=hidden]').val(item.id);row.find('.ccsm-media-preview').text(item.filename||'Arquivo #'+item.id)});picker.open()});$('.ccsm-clear').on('click',function(e){e.preventDefault();var row=$(this).closest('.ccsm-media');row.find('input[type=hidden]').val('0');row.find('.ccsm-media-preview').text('Nenhum arquivo selecionado')})});

jQuery(function($){
 function updateProvider(){
   var active=$('#ccsm-provider').val();
   $('.ccsm-provider-section').each(function(){
     $(this).toggle($(this).attr('data-provider')===active);
   });
 }
 $('#ccsm-provider').on('change',updateProvider);
 updateProvider();
});

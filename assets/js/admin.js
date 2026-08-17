(function ($) {
  'use strict';

  function refreshRows() {
    var type = $('#ship-modal-content_type').val();
    $('.ship-modal-html-row').toggle(type === 'html');
    $('.ship-modal-image-row').toggle(type === 'image');
    $('.ship-modal-delay-row').toggle($('#ship-modal-trigger').val() === 'auto');
    $('.ship-modal-trigger-text-row').toggle($('#ship-modal-trigger').val() === 'manual');
  }

  $(function () {
    refreshRows();
    $('#ship-modal-content_type, #ship-modal-trigger').on('change', refreshRows);
    var frame;
    $('#ship-modal-select-image').on('click', function (event) {
      event.preventDefault();
      if (frame) { frame.open(); return; }
      frame = wp.media({ title: 'モーダル画像を選択', button: { text: 'この画像を使用' }, multiple: false, library: { type: 'image' } });
      frame.on('select', function () {
        var attachment = frame.state().get('selection').first().toJSON();
        $('#ship-modal-image-id').val(attachment.id);
        $('#ship-modal-image-preview').html('<img src="' + attachment.url.replace(/"/g, '&quot;') + '" alt="" style="max-width:100%;height:auto;">');
      });
      frame.open();
    });
    $('#ship-modal-remove-image').on('click', function (event) {
      event.preventDefault();
      $('#ship-modal-image-id').val('');
      $('#ship-modal-image-preview').empty();
    });
  });
})(jQuery);

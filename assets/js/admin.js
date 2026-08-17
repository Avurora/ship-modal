(function ($) {
  'use strict';

  function refreshRows() {
    var type = $('#ship-modal-content_type').val();
    $('.ship-modal-html-row').toggle(type === 'html' || type === 'hybrid');
    $('.ship-modal-single-image-row').toggle(type === 'image' || type === 'hybrid');
    $('.ship-modal-hybrid-image-row').toggle(type === 'hybrid');
    $('.ship-modal-pages-row').toggle(type === 'pager');
    $('.ship-modal-delay-row').toggle($('#ship-modal-trigger').val() === 'auto');
    $('.ship-modal-trigger-text-row').toggle($('#ship-modal-trigger').val() === 'manual');
  }

  $(function () {
    refreshRows();
    $('#ship-modal-content_type, #ship-modal-trigger').on('change', refreshRows);
    var frame;
    function selectImage(targetId, previewId) {
      var currentFrame = frame;
      if (currentFrame) {
        currentFrame.off('select.shipModal');
      }
      currentFrame = wp.media({ title: 'モーダル画像を選択', button: { text: 'この画像を使用' }, multiple: false, library: { type: 'image' } });
      frame = currentFrame;
      currentFrame.on('select.shipModal', function () {
        var attachment = currentFrame.state().get('selection').first().toJSON();
        $('#' + targetId).val(attachment.id);
        $('#' + previewId).html('<img src="' + attachment.url.replace(/"/g, '&quot;') + '" alt="" style="max-width:100%;height:auto;">');
      });
      currentFrame.open();
    }
    $('#ship-modal-select-image').on('click', function (event) {
      event.preventDefault();
      selectImage('ship-modal-image-id', 'ship-modal-image-preview');
    });
    $('#ship-modal-remove-image').on('click', function (event) {
      event.preventDefault();
      $('#ship-modal-image-id').val('');
      $('#ship-modal-image-preview').empty();
    });
    $(document).on('click', '.ship-modal-page-select-image', function (event) {
      event.preventDefault();
      selectImage($(this).data('target-id'), $(this).data('target-preview'));
    });
    $(document).on('click', '.ship-modal-remove-page', function (event) {
      event.preventDefault();
      var rows = $('.ship-modal-page-row');
      if (rows.length > 1) {
        $(this).closest('.ship-modal-page-row').remove();
      } else {
        $(this).closest('.ship-modal-page-row').find('input, textarea').val('');
        $(this).closest('.ship-modal-page-row').find('.ship-modal-page-preview').empty();
      }
    });
    $('#ship-modal-add-page').on('click', function (event) {
      event.preventDefault();
      var index = $('.ship-modal-page-row').length;
      var template = $('#ship-modal-page-template').html().replace(/__INDEX__/g, String(index)).replace(/__NUMBER__/g, String(index + 1));
      $('#ship-modal-pages').append(template);
    });
  });
})(jQuery);

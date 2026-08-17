(function () {
  'use strict';

  var config = window.ShipModalConfig || {};
  var activeModal = null;
  var previousFocus = null;

  function storageKey(modal) {
    return 'ship-modal-' + modal.id;
  }

  function canShow(modal) {
    var frequency = modal.dataset.frequency || 'session';
    if (frequency === 'always') return true;
    var store = frequency === 'session' ? window.sessionStorage : window.localStorage;
    try {
      var value = store.getItem(storageKey(modal));
      if (!value) return true;
      if (frequency === 'day') return value !== new Date().toISOString().slice(0, 10);
      return false;
    } catch (e) {
      return true;
    }
  }

  function markShown(modal) {
    var frequency = modal.dataset.frequency || 'session';
    if (frequency === 'always') return;
    var store = frequency === 'session' ? window.sessionStorage : window.localStorage;
    try {
      store.setItem(storageKey(modal), frequency === 'day' ? new Date().toISOString().slice(0, 10) : '1');
    } catch (e) { /* storage disabled */ }
  }

  function track(modal, event) {
    if (!config.ajaxUrl || !config.nonce) return;
    var body = new URLSearchParams({
      action: 'ship_modal_event',
      nonce: config.nonce,
      modal_id: modal.dataset.postId || '',
      event: event
    });
    if (navigator.sendBeacon) {
      navigator.sendBeacon(config.ajaxUrl, body);
    } else {
      fetch(config.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' });
    }
  }

  function focusable(modal) {
    return modal.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
  }

  function openModal(modal) {
    if (!modal || !canShow(modal)) return;
    previousFocus = document.activeElement;
    activeModal = modal;
    modal.hidden = false;
    document.body.classList.add('ship-modal-open');
    window.requestAnimationFrame(function () { modal.classList.add('is-open'); });
    markShown(modal);
    track(modal, 'impression');
    var close = modal.querySelector('.ship-modal__close');
    if (close) close.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('is-open');
    window.setTimeout(function () { modal.hidden = true; }, 220);
    document.body.classList.remove('ship-modal-open');
    if (previousFocus && previousFocus.focus) previousFocus.focus();
    activeModal = null;
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-ship-modal-target]');
    if (trigger) {
      event.preventDefault();
      openModal(document.getElementById(trigger.dataset.shipModalTarget));
      return;
    }
    var close = event.target.closest('[data-ship-modal-close]');
    if (close) {
      var modal = close.closest('.ship-modal');
      if (modal && (close.classList.contains('ship-modal__backdrop') ? modal.dataset.closeOverlay === '1' : true)) closeModal(modal);
      return;
    }
    var link = event.target.closest('.ship-modal__link');
    if (link) track(link.closest('.ship-modal'), 'click');
  });

  document.addEventListener('keydown', function (event) {
    if (!activeModal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(activeModal);
    }
    if (event.key === 'Tab') {
      var elements = focusable(activeModal);
      if (!elements.length) return;
      var first = elements[0];
      var last = elements[elements.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ship-modal[data-auto-open="1"]').forEach(function (modal) {
      var delay = Math.max(0, parseInt(modal.dataset.delay || '0', 10)) * 1000;
      window.setTimeout(function () { openModal(modal); }, delay);
    });
  });
})();

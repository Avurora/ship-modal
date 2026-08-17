(function () {
  'use strict';

  var config = window.ShipModalConfig || {};
  var activeModal = null;
  var previousFocus = null;

  function storageKey(modal) {
    return 'ship-modal-' + (modal.dataset.postId || modal.id);
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

  function showPage(modal, index) {
    var container = modal.querySelector('.ship-modal__pages');
    if (!container) return;
    var panels = Array.prototype.slice.call(container.querySelectorAll('[data-ship-modal-page-panel]'));
    if (!panels.length) return;
    var nextIndex = Math.max(0, Math.min(panels.length - 1, index));
    panels.forEach(function (panel, panelIndex) {
      var active = panelIndex === nextIndex;
      panel.hidden = !active;
      panel.classList.toggle('is-active', active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    container.querySelectorAll('[data-ship-modal-page]').forEach(function (button) {
      var active = parseInt(button.dataset.shipModalPage, 10) === nextIndex;
      button.classList.toggle('is-active', active);
      if (active) button.setAttribute('aria-current', 'true');
      else button.removeAttribute('aria-current');
    });
    var previous = container.querySelector('[data-ship-modal-page-prev]');
    var next = container.querySelector('[data-ship-modal-page-next]');
    if (previous) previous.disabled = nextIndex === 0;
    if (next) next.disabled = nextIndex === panels.length - 1;
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

  document.addEventListener('click', function (event) {
    var pageButton = event.target.closest('[data-ship-modal-page], [data-ship-modal-page-prev], [data-ship-modal-page-next]');
    if (!pageButton) return;
    var modal = pageButton.closest('.ship-modal');
    var container = pageButton.closest('.ship-modal__pages');
    if (!modal || !container) return;
    var current = container.querySelector('.ship-modal__page.is-active');
    var currentIndex = current ? parseInt(current.dataset.shipModalPagePanel, 10) : 0;
    if (pageButton.hasAttribute('data-ship-modal-page-prev')) currentIndex--;
    else if (pageButton.hasAttribute('data-ship-modal-page-next')) currentIndex++;
    else currentIndex = parseInt(pageButton.dataset.shipModalPage, 10);
    showPage(modal, currentIndex);
  });

  document.addEventListener('keydown', function (event) {
    if (!activeModal) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      closeModal(activeModal);
    }
    if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      var pages = activeModal.querySelector('.ship-modal__pages');
      if (pages) {
        var current = pages.querySelector('.ship-modal__page.is-active');
        var currentIndex = current ? parseInt(current.dataset.shipModalPagePanel, 10) : 0;
        showPage(activeModal, currentIndex + (event.key === 'ArrowRight' ? 1 : -1));
      }
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

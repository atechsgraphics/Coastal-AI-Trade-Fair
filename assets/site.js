/* Coastal AI Summit & SME Trade Fair — public site behaviour
   1. Mobile navigation   2. Sticky header   3. Live countdown   4. Scroll reveal */
(function () {
  'use strict';

  /* -------------------------------------------------- 1. Mobile navigation */
  var nav = document.getElementById('mainNav');
  var toggle = document.querySelector('.menu-toggle');
  // Must match the breakpoint the drawer is styled at. The row of links needs
  // about 1000px once the sign-in button is in it, so the drawer takes over
  // for tablets as well, not only phones.
  var mobile = window.matchMedia('(max-width: 980px)');

  if (nav && toggle) {
    var backdrop = document.createElement('div');
    backdrop.className = 'nav-backdrop';
    document.body.appendChild(backdrop);

    var lastFocus = null;

    function setMenu(open, restoreFocus) {
      open = Boolean(open) && mobile.matches;
      nav.classList.toggle('open', open);
      document.body.classList.toggle('menu-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');

      if (open) {
        lastFocus = document.activeElement;
        var first = nav.querySelector('a');
        if (first) { window.requestAnimationFrame(function () { first.focus(); }); }
      } else if (restoreFocus !== false && lastFocus && lastFocus.focus) {
        lastFocus.focus();
        lastFocus = null;
      }
    }

    toggle.addEventListener('click', function () {
      setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    backdrop.addEventListener('click', function () { setMenu(false); });

    Array.prototype.forEach.call(nav.querySelectorAll('a'), function (link) {
      link.addEventListener('click', function () { setMenu(false, false); });
    });

    document.addEventListener('keydown', function (event) {
      if (!nav.classList.contains('open')) { return; }

      if (event.key === 'Escape') {
        event.preventDefault();
        setMenu(false);
        return;
      }

      if (event.key === 'Tab') {
        var items = [toggle].concat(Array.prototype.slice.call(nav.querySelectorAll('a')));
        var first = items[0];
        var last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
    });

    mobile.addEventListener('change', function () { setMenu(false, false); });
  }

  /* ------------------------------------------------------- 2. Sticky header */
  var header = document.getElementById('siteHeader');
  if (header) {
    var onScroll = function () {
      header.classList.toggle('scrolled', window.scrollY > 24);
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* -------------------------------------------------------- 3. Countdown */
  var clocks = document.querySelectorAll('[data-countdown]');

  if (clocks.length) {
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };

    var render = function (root) {
      var start = new Date(root.getAttribute('data-start')).getTime();
      var end = new Date(root.getAttribute('data-end')).getTime();
      var now = Date.now();
      var clock = root.querySelector('[data-countdown-clock]');
      var message = root.querySelector('[data-countdown-message]');
      var label = root.querySelector('[data-countdown-label]');

      if (isNaN(start) || isNaN(end)) { return true; }

      // Event finished or currently running: swap the clock for a message.
      if (now >= start) {
        var text = now <= end
          ? root.getAttribute('data-live')
          : root.getAttribute('data-done');
        root.classList.remove('is-upcoming');
        root.classList.add(now <= end ? 'is-running' : 'is-finished');
        if (clock) { clock.hidden = true; }
        if (label) { label.parentNode.hidden = true; }
        if (message) {
          message.hidden = false;
          message.textContent = text;
        }
        return true; // nothing left to tick
      }

      var diff = Math.floor((start - now) / 1000);
      var values = {
        days: Math.floor(diff / 86400),
        hours: Math.floor(diff / 3600) % 24,
        minutes: Math.floor(diff / 60) % 60,
        seconds: diff % 60
      };

      Object.keys(values).forEach(function (key) {
        var cell = root.querySelector('[data-cd="' + key + '"]');
        if (!cell) { return; }
        var next = key === 'days' ? String(values[key]) : pad(values[key]);
        if (cell.textContent !== next) {
          cell.textContent = next;
          var unit = cell.parentNode;
          unit.classList.remove('tick');
          void unit.offsetWidth; // restart the animation
          unit.classList.add('tick');
        }
      });

      return false;
    };

    var tick = function () {
      var allDone = true;
      Array.prototype.forEach.call(clocks, function (root) {
        if (!render(root)) { allDone = false; }
      });
      if (allDone) { clearInterval(timer); }
    };

    tick();
    var timer = setInterval(tick, 1000);
  }

  /* ------------------------------------------------------ 4. Scroll reveal */
  var revealables = document.querySelectorAll('.reveal');

  if (revealables.length) {
    if (!('IntersectionObserver' in window)) {
      Array.prototype.forEach.call(revealables, function (el) { el.classList.add('in'); });
    } else {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('in');
            observer.unobserve(entry.target);
          }
        });
      }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

      Array.prototype.forEach.call(revealables, function (el) { observer.observe(el); });
    }
  }

  /* ------------------------------------------------ 5. Booking form total
     Mirrors the server-side pricing in inc/booking.php, including the rule
     that the SME masterclass ticket is free once a stall is booked. The
     server always recalculates, so this is only a live preview. */
  var totalBox = document.querySelector('[data-total-box]');

  if (totalBox) {
    var totalOut = totalBox.querySelector('[data-total]');
    var totalNote = totalBox.querySelector('[data-total-note]');
    var cards = document.querySelectorAll('[data-option]');

    var formatMoney = function (value) {
      return 'N$ ' + value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    };

    var recalculate = function () {
      var hasStall = false;

      Array.prototype.forEach.call(cards, function (card) {
        var box = card.querySelector('input[type="checkbox"]');
        if (box && box.checked && box.getAttribute('data-kind') === 'stall') {
          hasStall = true;
        }
      });

      var total = 0;
      var freeApplied = false;

      Array.prototype.forEach.call(cards, function (card) {
        var box = card.querySelector('input[type="checkbox"]');
        var qtyField = card.querySelector('input[type="number"]');
        if (!box || !box.checked) { return; }

        var quantity = Math.max(1, Math.min(50, parseInt(qtyField && qtyField.value, 10) || 1));
        var price = parseFloat(box.getAttribute('data-price')) || 0;

        if (hasStall && box.getAttribute('data-free-with-stall') === '1') {
          price = 0;
          freeApplied = true;
        }

        total += price * quantity;
      });

      if (totalOut) { totalOut.textContent = formatMoney(total); }
      if (totalNote) { totalNote.hidden = !freeApplied; }
    };

    document.addEventListener('change', function (event) {
      if (event.target.closest('[data-option]')) { recalculate(); }
    });
    document.addEventListener('input', function (event) {
      if (event.target.closest('[data-option]')) { recalculate(); }
    });

    // The checkbox stays in the tab order (it is only visually hidden), so the
    // space bar toggles it natively and the change listener above picks it up.
    recalculate();
  }

  /* Pre-fill the enquiry form when arriving from a package or stall card. */
  var params = new URLSearchParams(window.location.search);
  var wanted = params.get('option');
  if (wanted) {
    var optionField = document.querySelector('[name="option_key"]');
    if (optionField) { optionField.value = wanted; }
    var interest = document.querySelector('[name="interest"]');
    if (interest && params.get('interest')) { interest.value = params.get('interest'); }
  }
}());

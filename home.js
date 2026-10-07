(function () {
  'use strict';
  clearTimeout(window.__revealFallback);

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Scroll reveal
  var items = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('in');
          io.unobserve(en.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    items.forEach(function (el, i) {
      var sibs = el.parentElement.querySelectorAll(':scope > .reveal');
      el.style.setProperty('--d', Math.max(0, Array.prototype.indexOf.call(sibs, el)) * 90 + 'ms');
      io.observe(el);
    });
  } else {
    items.forEach(function (el) { el.classList.add('in'); });
  }

  // Rotating "currently bad at" list
  var rot = document.getElementById('rotator');
  if (rot && !reduce) {
    var words = ['cooking rice', 'parallel parking', 'small talk', 'going to bed on time',
      'knowing when to quit', 'sourdough', 'staying off my phone', 'this website'];
    var n = 0;
    setInterval(function () {
      rot.classList.add('out');
      setTimeout(function () {
        n = (n + 1) % words.length;
        rot.textContent = words[n];
        rot.classList.remove('out');
      }, 350);
    }, 2600);
  }

  // Newsletter signup
  var form = document.getElementById('signup-form');
  if (!form) return;
  var btn = document.getElementById('submit-btn');
  var msg = document.getElementById('form-msg');
  var input = document.getElementById('email-input');

  function show(text, state) {
    msg.textContent = text;
    msg.className = 'form-note is-' + state;
    msg.hidden = false;
  }

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    if (!input.checkValidity()) {
      show('Please enter a valid email address.', 'error');
      input.focus();
      return;
    }
    var email = input.value;
    btn.disabled = true;
    btn.textContent = 'Sending…';
    form.classList.add('is-busy');

    try {
      var res = await fetch('/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: email })
      });
      var data = await res.json();
      if (res.ok && data.ok) {
        show('Almost there — check your inbox to confirm.', 'ok');
        form.reset();
      } else {
        show(data.error || 'Something went wrong. Try again.', 'error');
      }
    } catch (err) {
      show('Network error. Try again.', 'error');
    }

    form.classList.remove('is-busy');
    btn.disabled = false;
    btn.textContent = 'Sign me up';
  });
})();

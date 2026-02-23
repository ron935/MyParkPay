/**
 * MyParkPay - Event Ticketing Platform
 * Main JavaScript
 * Adapted from DC Metro Construction site pattern
 * Uses var for older browser compatibility
 */

(function () {
    'use strict';

    /* ============================================================
       0. GLOBALS & REDUCED MOTION DETECTION
       ============================================================ */
    var prefersReducedMotion =
        window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var ticking = false; // rAF guard for scroll handler

    var turnstileToken = ''; // Cloudflare Turnstile token storage

    /* ============================================================
       1. CONSOLIDATED SCROLL HANDLER (requestAnimationFrame)
       ============================================================ */
    function onScroll() {
        if (ticking) return;
        ticking = true;

        window.requestAnimationFrame(function () {
            var scrollY = window.pageYOffset || document.documentElement.scrollTop;

            handleHeaderScroll(scrollY);
            handleParallax(scrollY);
            handleBackToTop(scrollY);
            handleActiveNav(scrollY);

            ticking = false;
        });
    }

    /* --- 1a. Header scroll effect --- */
    function handleHeaderScroll(scrollY) {
        var header = document.querySelector('header');
        if (!header) return;

        if (scrollY > 100) {
            header.classList.add('scrolled');
        } else {
            header.classList.remove('scrolled');
        }
    }

    /* --- 1b. Parallax floating shapes --- */
    function handleParallax(scrollY) {
        if (prefersReducedMotion) return;

        var shapesContainer = document.querySelector('.hero-shapes');
        if (shapesContainer) {
            shapesContainer.style.transform = 'translateY(' + (scrollY * 0.15) + 'px)';
        }
    }

    /* --- 1c. Back-to-top button --- */
    function handleBackToTop(scrollY) {
        var btn = document.querySelector('.back-to-top');
        if (!btn) return;

        if (scrollY > 500) {
            btn.classList.add('visible');
        } else {
            btn.classList.remove('visible');
        }
    }

    /* --- 1d. Active nav highlighting --- */
    function handleActiveNav(scrollY) {
        var sections = document.querySelectorAll('section[id]');
        var navLinks = document.querySelectorAll('.nav-menu a[href^="#"]');
        if (!sections.length || !navLinks.length) return;

        var currentId = '';
        var headerOffset = 120;

        for (var i = 0; i < sections.length; i++) {
            var section = sections[i];
            var sectionTop = section.offsetTop - headerOffset;
            var sectionHeight = section.offsetHeight;

            if (scrollY >= sectionTop && scrollY < sectionTop + sectionHeight) {
                currentId = section.getAttribute('id');
            }
        }

        for (var j = 0; j < navLinks.length; j++) {
            navLinks[j].classList.remove('active');
            if (navLinks[j].getAttribute('href') === '#' + currentId) {
                navLinks[j].classList.add('active');
            }
        }
    }

    /* ============================================================
       2. MOBILE MENU TOGGLE
       ============================================================ */
    function initMobileMenu() {
        var toggle = document.querySelector('.nav-toggle');
        var nav = document.querySelector('.nav-menu');
        if (!toggle || !nav) return;

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            nav.classList.toggle('active');
            toggle.classList.toggle('active');

            // Body scroll lock (works on iOS too)
            if (!expanded) {
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.top = '-' + window.pageYOffset + 'px';
            } else {
                var scrollY = document.body.style.top;
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.top = '';
                window.scrollTo(0, parseInt(scrollY || '0') * -1);
            }
        });

        function closeMenu() {
            nav.classList.remove('active');
            toggle.classList.remove('active');
            toggle.setAttribute('aria-expanded', 'false');
            var scrollY = document.body.style.top;
            document.body.style.overflow = '';
            document.body.style.position = '';
            document.body.style.width = '';
            document.body.style.top = '';
            window.scrollTo(0, parseInt(scrollY || '0') * -1);
        }

        // Click outside to close
        document.addEventListener('click', function (e) {
            if (
                nav.classList.contains('active') &&
                !nav.contains(e.target) &&
                !toggle.contains(e.target)
            ) {
                closeMenu();
            }
        });

        // Close on nav link click (mobile)
        var links = nav.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function () {
                closeMenu();
            });
        }
    }

    /* ============================================================
       3. ANIMATED COUNTERS (IntersectionObserver)
       ============================================================ */
    function initCounters() {
        var counters = document.querySelectorAll('[data-count]');
        if (!counters.length) return;

        if (!('IntersectionObserver' in window)) {
            // Fallback: just set the numbers immediately
            for (var f = 0; f < counters.length; f++) {
                counters[f].textContent = counters[f].getAttribute('data-count');
            }
            return;
        }

        var counterObserver = new IntersectionObserver(
            function (entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        animateCounter(entries[i].target);
                        counterObserver.unobserve(entries[i].target);
                    }
                }
            },
            { threshold: 0.3 }
        );

        for (var c = 0; c < counters.length; c++) {
            counterObserver.observe(counters[c]);
        }
    }

    function animateCounter(el) {
        var target = parseInt(el.getAttribute('data-count'), 10);
        if (isNaN(target)) return;

        var prefix = el.getAttribute('data-prefix') || '';
        var suffix = el.getAttribute('data-suffix') || '';
        var duration = prefersReducedMotion ? 0 : 1800;
        var startTime = null;

        if (duration === 0) {
            el.textContent = prefix + formatNumber(target) + suffix;
            return;
        }

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            // Ease-out cubic
            var eased = 1 - Math.pow(1 - progress, 3);
            var current = Math.floor(eased * target);
            el.textContent = prefix + formatNumber(current) + suffix;

            if (progress < 1) {
                window.requestAnimationFrame(step);
            } else {
                el.textContent = prefix + formatNumber(target) + suffix;
            }
        }

        window.requestAnimationFrame(step);
    }

    function formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /* ============================================================
       4. SMOOTH SCROLL FOR ANCHOR LINKS
       ============================================================ */
    function initSmoothScroll() {
        var anchors = document.querySelectorAll('a[href^="#"]');

        for (var i = 0; i < anchors.length; i++) {
            anchors[i].addEventListener('click', function (e) {
                var href = this.getAttribute('href');
                if (href === '#' || href === '#!') return;

                var target = document.querySelector(href);
                if (!target) return;

                e.preventDefault();

                var header = document.querySelector('header');
                var headerHeight = header ? header.offsetHeight : 0;
                var targetTop =
                    target.getBoundingClientRect().top +
                    (window.pageYOffset || document.documentElement.scrollTop) -
                    headerHeight;

                if (prefersReducedMotion || !('scrollBehavior' in document.documentElement.style)) {
                    window.scrollTo(0, targetTop);
                } else {
                    window.scrollTo({
                        top: targetTop,
                        behavior: 'smooth'
                    });
                }

                // Update URL hash without jump
                if (window.history && window.history.pushState) {
                    window.history.pushState(null, '', href);
                }
            });
        }

        // Back-to-top button click
        var backToTop = document.querySelector('.back-to-top');
        if (backToTop) {
            backToTop.addEventListener('click', function (e) {
                e.preventDefault();
                if (prefersReducedMotion) {
                    window.scrollTo(0, 0);
                } else {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }
    }

    /* ============================================================
       5. SCROLL-TRIGGERED ANIMATIONS (IntersectionObserver)
       ============================================================ */
    function initScrollAnimations() {
        var animatedSelectors =
            '.service-card, .value-card, .team-card, .testimonial-card, ' +
            '.why-feature, .timeline-item, .pricing-card, .use-case-card, .stat-item';

        var animatedEls = document.querySelectorAll(animatedSelectors);
        if (!animatedEls.length) return;

        if (prefersReducedMotion) {
            // Show everything immediately
            for (var r = 0; r < animatedEls.length; r++) {
                animatedEls[r].classList.add('animate-in');
            }
            return;
        }

        if (!('IntersectionObserver' in window)) {
            for (var f = 0; f < animatedEls.length; f++) {
                animatedEls[f].classList.add('animate-in');
            }
            return;
        }

        var animObserver = new IntersectionObserver(
            function (entries) {
                for (var i = 0; i < entries.length; i++) {
                    if (entries[i].isIntersecting) {
                        var el = entries[i].target;
                        // Stagger delay based on sibling index
                        var parent = el.parentElement;
                        var siblings = parent
                            ? parent.querySelectorAll(el.tagName + '.' + el.classList[0])
                            : [];
                        var index = 0;
                        for (var s = 0; s < siblings.length; s++) {
                            if (siblings[s] === el) {
                                index = s;
                                break;
                            }
                        }
                        el.style.transitionDelay = (index * 0.1) + 's';
                        el.classList.add('animate-in');
                        animObserver.unobserve(el);
                    }
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
        );

        for (var a = 0; a < animatedEls.length; a++) {
            animatedEls[a].classList.add('animate-ready');
            animObserver.observe(animatedEls[a]);
        }
    }

    /* ============================================================
       6. PRICING TOGGLE (Monthly / Annual)
       ============================================================ */
    function initPricingToggle() {
        var toggle = document.querySelector('.pricing-toggle');
        if (!toggle) return;

        var isAnnual = false;

        // Find all pricing elements that have both data attributes
        function updatePrices() {
            var priceEls = document.querySelectorAll('[data-monthly][data-annual]');

            for (var i = 0; i < priceEls.length; i++) {
                var el = priceEls[i];
                var monthly = el.getAttribute('data-monthly');
                var annual = el.getAttribute('data-annual');

                if (isAnnual) {
                    el.textContent = annual;
                    showSavingsBadge(el, monthly, annual);
                } else {
                    el.textContent = monthly;
                    removeSavingsBadge(el);
                }
            }
        }

        function showSavingsBadge(priceEl, monthlyStr, annualStr) {
            // Parse numeric values (strip currency symbols, commas)
            var monthlyVal = parseFloat(monthlyStr.replace(/[^0-9.]/g, ''));
            var annualVal = parseFloat(annualStr.replace(/[^0-9.]/g, ''));

            if (isNaN(monthlyVal) || isNaN(annualVal) || monthlyVal === 0) return;

            var savings = Math.round(((monthlyVal - annualVal) / monthlyVal) * 100);
            if (savings <= 0) return;

            // Remove any existing badge first
            removeSavingsBadge(priceEl);

            var badge = document.createElement('span');
            badge.className = 'savings-badge';
            badge.textContent = 'Save ' + savings + '%';
            priceEl.parentNode.insertBefore(badge, priceEl.nextSibling);
        }

        function removeSavingsBadge(priceEl) {
            var existing = priceEl.parentNode.querySelector('.savings-badge');
            if (existing) {
                existing.parentNode.removeChild(existing);
            }
        }

        toggle.addEventListener('click', function () {
            isAnnual = !isAnnual;
            toggle.classList.toggle('annual-active', isAnnual);

            // Update aria / toggle label states
            var monthlyLabel = toggle.querySelector('.toggle-monthly');
            var annualLabel = toggle.querySelector('.toggle-annual');
            if (monthlyLabel) monthlyLabel.classList.toggle('active', !isAnnual);
            if (annualLabel) annualLabel.classList.toggle('active', isAnnual);

            updatePrices();
        });

        // Initialise with monthly prices
        updatePrices();
    }

    /* ============================================================
       7. CONTACT FORM VALIDATION & SUBMISSION
       ============================================================ */
    function initContactForm() {
        var form = document.querySelector('#contact-form');
        if (!form) return;

        // --- Test fill button (only when ?test in URL) ---
        if (window.location.search.indexOf('test') !== -1) {
            createTestFillButton(form);
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            clearErrors(form);

            if (!validateForm(form)) return;

            submitForm(form);
        });
    }

    /* --- 7a. Validation --- */
    function validateForm(form) {
        var valid = true;

        // Name
        var name = form.querySelector('[name="name"]');
        if (name && name.value.trim().length < 2) {
            showError(name, 'Please enter your full name.');
            valid = false;
        }

        // Email
        var email = form.querySelector('[name="email"]');
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (email && !emailPattern.test(email.value.trim())) {
            showError(email, 'Please enter a valid email address.');
            valid = false;
        }

        // Phone (optional but validate format if provided)
        var phone = form.querySelector('[name="phone"]');
        if (phone && phone.value.trim() !== '') {
            var phoneClean = phone.value.replace(/[\s\-().+]/g, '');
            if (!/^\d{7,15}$/.test(phoneClean)) {
                showError(phone, 'Please enter a valid phone number.');
                valid = false;
            }
        }

        // Organization name
        var org = form.querySelector('[name="organization"]');
        if (org && org.value.trim().length < 2) {
            showError(org, 'Please enter your organization name.');
            valid = false;
        }

        // Message
        var message = form.querySelector('[name="message"]');
        if (message && message.value.trim().length < 10) {
            showError(message, 'Please enter a message (at least 10 characters).');
            valid = false;
        }

        // Turnstile CAPTCHA
        var turnstileInput = form.querySelector('[name="cf-turnstile-response"]');
        var tokenValue = turnstileToken || (turnstileInput ? turnstileInput.value : '');
        if (!tokenValue) {
            showFormMessage(form, 'Please complete the CAPTCHA verification.', 'error');
            valid = false;
        }

        return valid;
    }

    function showError(input, message) {
        input.classList.add('input-error');
        var errorEl = document.createElement('div');
        errorEl.className = 'field-error';
        errorEl.textContent = message;
        input.parentNode.appendChild(errorEl);
    }

    function clearErrors(form) {
        var errors = form.querySelectorAll('.field-error');
        for (var i = 0; i < errors.length; i++) {
            errors[i].parentNode.removeChild(errors[i]);
        }
        var errorInputs = form.querySelectorAll('.input-error');
        for (var j = 0; j < errorInputs.length; j++) {
            errorInputs[j].classList.remove('input-error');
        }
        var formMsg = form.querySelector('.form-message');
        if (formMsg) formMsg.parentNode.removeChild(formMsg);
    }

    function showFormMessage(form, message, type) {
        // Remove existing message first
        var existing = form.querySelector('.form-message');
        if (existing) existing.parentNode.removeChild(existing);

        var msgEl = document.createElement('div');
        msgEl.className = 'form-message form-message--' + type;
        msgEl.textContent = message;
        form.appendChild(msgEl);
    }

    /* --- 7b. Form submission via fetch --- */
    function submitForm(form) {
        var submitBtn = form.querySelector('[type="submit"]');
        var originalText = submitBtn ? submitBtn.textContent : 'Submit';

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending...';
        }

        var formData = new FormData(form);

        // Ensure Turnstile token is included
        if (turnstileToken && !formData.get('cf-turnstile-response')) {
            formData.append('cf-turnstile-response', turnstileToken);
        }

        fetch('send-quote.php', {
            method: 'POST',
            body: formData
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    showFormMessage(form, data.message || 'Thank you! Your message has been sent successfully.', 'success');
                    form.reset();
                    turnstileToken = '';
                    // Reset Turnstile widget if available
                    if (window.turnstile) {
                        window.turnstile.reset();
                    }
                } else {
                    showFormMessage(
                        form,
                        data.message || 'Something went wrong. Please try again.',
                        'error'
                    );
                }
            })
            .catch(function () {
                showFormMessage(
                    form,
                    'A network error occurred. Please check your connection and try again.',
                    'error'
                );
            })
            .finally(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
    }

    /* --- 7c. Test fill button --- */
    function createTestFillButton(form) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-test-fill';
        btn.textContent = 'Test Fill';
        btn.style.cssText =
            'position:fixed;bottom:20px;right:20px;z-index:9999;' +
            'padding:8px 16px;background:#ff6b35;color:#fff;border:none;' +
            'border-radius:4px;cursor:pointer;font-size:14px;';

        btn.addEventListener('click', function () {
            var fields = {
                name: 'Jane Doe',
                email: 'jane.doe@example.com',
                phone: '(555) 123-4567',
                organization: 'Acme Events Inc.',
                message:
                    'Hi, I am interested in learning more about MyParkPay for our upcoming outdoor concert series. We expect approximately 5,000 attendees.'
            };

            for (var key in fields) {
                if (fields.hasOwnProperty(key)) {
                    var input = form.querySelector('[name="' + key + '"]');
                    if (input) input.value = fields[key];
                }
            }
        });

        document.body.appendChild(btn);
    }

    /* ============================================================
       8. TESTIMONIAL AUTO-SLIDER (Mobile < 992px)
       ============================================================ */
    function initTestimonialSlider() {
        var track = document.querySelector('.testimonial-slider, .testimonials-grid');
        if (!track) return;

        var cards = track.querySelectorAll('.testimonial-card');
        if (cards.length < 2) return;

        var currentIndex = 0;
        var interval = null;
        var SLIDE_INTERVAL = 5000;

        function isMobile() {
            return window.innerWidth < 992;
        }

        function showSlide(index) {
            for (var i = 0; i < cards.length; i++) {
                cards[i].classList.remove('slide-active');
                cards[i].style.display = 'none';
            }
            cards[index].classList.add('slide-active');
            cards[index].style.display = '';
        }

        function nextSlide() {
            currentIndex = (currentIndex + 1) % cards.length;
            showSlide(currentIndex);
        }

        function startAutoSlide() {
            if (prefersReducedMotion) return;
            stopAutoSlide();
            showSlide(currentIndex);
            interval = setInterval(nextSlide, SLIDE_INTERVAL);
        }

        function stopAutoSlide() {
            if (interval) {
                clearInterval(interval);
                interval = null;
            }
        }

        function resetCards() {
            for (var i = 0; i < cards.length; i++) {
                cards[i].classList.remove('slide-active');
                cards[i].style.display = '';
            }
        }

        function handleResize() {
            if (isMobile()) {
                startAutoSlide();
            } else {
                stopAutoSlide();
                resetCards();
            }
        }

        // Pause on hover / focus
        track.addEventListener('mouseenter', stopAutoSlide);
        track.addEventListener('focusin', stopAutoSlide);
        track.addEventListener('mouseleave', function () {
            if (isMobile()) startAutoSlide();
        });
        track.addEventListener('focusout', function () {
            if (isMobile()) startAutoSlide();
        });

        window.addEventListener('resize', debounce(handleResize, 200));
        handleResize();
    }

    /* ============================================================
       9. LAZY LOADING IMAGES (data-src)
       ============================================================ */
    function initLazyLoading() {
        var lazyImages = document.querySelectorAll('img[data-src]');
        if (!lazyImages.length) return;

        if ('IntersectionObserver' in window) {
            var lazyObserver = new IntersectionObserver(
                function (entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (entries[i].isIntersecting) {
                            var img = entries[i].target;
                            img.src = img.getAttribute('data-src');
                            var srcset = img.getAttribute('data-srcset');
                            if (srcset) img.srcset = srcset;
                            img.removeAttribute('data-src');
                            img.removeAttribute('data-srcset');
                            img.classList.add('lazy-loaded');
                            lazyObserver.unobserve(img);
                        }
                    }
                },
                { rootMargin: '200px 0px' }
            );

            for (var i = 0; i < lazyImages.length; i++) {
                lazyObserver.observe(lazyImages[i]);
            }
        } else {
            // Fallback: load all images immediately
            for (var j = 0; j < lazyImages.length; j++) {
                lazyImages[j].src = lazyImages[j].getAttribute('data-src');
                lazyImages[j].removeAttribute('data-src');
            }
        }
    }

    /* ============================================================
       UTILITY: Debounce
       ============================================================ */
    function debounce(fn, delay) {
        var timer = null;
        return function () {
            var context = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(context, args);
            }, delay);
        };
    }

    /* ============================================================
       TURNSTILE CAPTCHA CALLBACKS (Global)
       ============================================================ */
    window.onTurnstileSuccess = function (token) {
        turnstileToken = token;
        // Also populate hidden input if present
        var hiddenInput = document.querySelector('[name="cf-turnstile-response"]');
        if (hiddenInput) hiddenInput.value = token;
    };

    window.onTurnstileExpired = function () {
        turnstileToken = '';
        var hiddenInput = document.querySelector('[name="cf-turnstile-response"]');
        if (hiddenInput) hiddenInput.value = '';
    };

    /* ============================================================
       INITIALISE EVERYTHING ON DOMContentLoaded
       ============================================================ */
    document.addEventListener('DOMContentLoaded', function () {
        // Attach consolidated scroll handler
        window.addEventListener('scroll', onScroll, { passive: true });

        // Fire once on load to set initial states
        onScroll();

        // Initialise modules
        initMobileMenu();
        initCounters();
        initSmoothScroll();
        initScrollAnimations();
        initPricingToggle();
        initContactForm();
        initTestimonialSlider();
        initLazyLoading();
    });
})();

/**
 * jorsas-landing.js
 * Scroll animations, form AJAX submission, smooth scroll
 * Requires jQuery (already loaded by Gerow theme footer assets)
 */
(function ($) {
    'use strict';

    /* ─── Intersection Observer: fade-in reveal ─── */
    const revealTargets = [
        '.jt-hero__inner',
        '.jt-feature-strip__item',
        '.jt-adv-row',
        '.jt-who__card',
        '.jt-approach__card',
        '.jt-contact-section__left',
        '.jt-contact-section__right',
    ];

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('jt-visible');
                        observer.unobserve(entry.target);
                    }
                });
            },
            { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
        );

        document.querySelectorAll(revealTargets.join(',')).forEach(function (el) {
            observer.observe(el);
        });
    } else {
        /* Fallback: just make everything visible */
        document.querySelectorAll(revealTargets.join(',')).forEach(function (el) {
            el.classList.add('jt-visible');
        });
    }

    /* ─── Smooth scroll for anchor CTAs ─── */
    $('a[href^="#jt-"]').on('click', function (e) {
        var target = $(this.getAttribute('href'));
        if (target.length) {
            e.preventDefault();
            var headerH = $('#sticky-header').outerHeight() || 80;
            $('html, body').animate(
                { scrollTop: target.offset().top - headerH - 20 },
                600,
                'swing'
            );
        }
    });

    /* ─── Contact form AJAX submission ─── */
    var $form    = $('#jt-contact-form');
    var $msg     = $('#jt-form-message');
    var $submit  = $('#jt-contact-submit');

    if ($form.length) {
        $form.on('submit', function (e) {
            e.preventDefault();

            var formData = $form.serialize();
            var action   = $form.attr('action');

            /* If action is # (no contact plugin), open mailto fallback */
            if (!action || action === '#') {
                var name    = $form.find('[name="name"]').val();
                var email   = $form.find('[name="email"]').val();
                var phone   = $form.find('[name="phone"]').val();
                var company = $form.find('[name="company"]').val();
                window.location.href =
                    'mailto:info@jorsastech.com'
                    + '?subject=Enquiry from ' + encodeURIComponent(name)
                    + '&body=' + encodeURIComponent(
                        'Name: '    + name    + '\n' +
                        'Email: '   + email   + '\n' +
                        'Phone: '   + phone   + '\n' +
                        'Company: ' + company
                    );
                return;
            }

            /* AJAX submit to Botble contact plugin */
            $submit.prop('disabled', true).find('span').text('Sending…');
            $msg.hide().removeClass('jt-form-message--success jt-form-message--error');

            $.ajax({
                url: action,
                method: 'POST',
                data: formData,
                success: function (response) {
                    $form[0].reset();
                    $msg
                        .addClass('jt-form-message--success')
                        .text(response.message || 'Thank you! We\'ll be in touch shortly.')
                        .show();
                },
                error: function (xhr) {
                    var errMsg = 'Something went wrong. Please try again.';
                    try {
                        var res = JSON.parse(xhr.responseText);
                        if (res && res.message) errMsg = res.message;
                    } catch (ignore) {}
                    $msg
                        .addClass('jt-form-message--error')
                        .text(errMsg)
                        .show();
                },
                complete: function () {
                    $submit.prop('disabled', false).find('span').text('Get in Touch');
                },
            });
        });
    }

})(jQuery);

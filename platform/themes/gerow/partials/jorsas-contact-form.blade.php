@php
    $formAction = is_plugin_active('contact')
        ? route('public.send-contact')
        : '#';
@endphp

<form
    id="jt-contact-form"
    method="POST"
    action="{{ $formAction }}"
    class="jt-contact-form"
    novalidate
>
    @csrf

    {{-- Honeypot anti-spam --}}
    <input type="text" name="website_url" style="display:none" tabindex="-1" autocomplete="off">

    <div class="jt-form-field">
        <input
            type="text"
            name="name"
            id="jt-field-name"
            class="jt-input"
            placeholder="{{ __('Your Name') }}"
            required
        >
    </div>

    <div class="jt-form-field">
        <input
            type="email"
            name="email"
            id="jt-field-email"
            class="jt-input"
            placeholder="{{ __('Email Address') }}"
            required
        >
    </div>

    <div class="jt-form-field">
        <input
            type="tel"
            name="phone"
            id="jt-field-phone"
            class="jt-input"
            placeholder="{{ __('Phone Number') }}"
        >
    </div>

    <div class="jt-form-field">
        <input
            type="text"
            name="company"
            id="jt-field-company"
            class="jt-input"
            placeholder="{{ __('Company') }}"
        >
    </div>

    @if (is_plugin_active('captcha') && Captcha::isEnabled())
        <div class="jt-form-field jt-captcha">
            {!! Captcha::display() !!}
        </div>
    @endif

    <div class="jt-form-field">
        <button type="submit" id="jt-contact-submit" class="jt-btn jt-btn-cta">
            <span>{{ __('Get in Touch') }}</span>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
            </svg>
        </button>
    </div>

    <div id="jt-form-message" class="jt-form-message" aria-live="polite" style="display:none"></div>
</form>

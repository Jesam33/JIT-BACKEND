@php
    Theme::set('headerFixed', true);
    Theme::addBodyAttributes(['class' => 'jt-landing-body']);

    // Register landing-page-specific assets
    Theme::asset()->usePath()->add('jorsas-landing-css', 'css/jorsas-landing.css');
    Theme::asset()->container('footer')->usePath()->add('jorsas-landing-js', 'js/jorsas-landing.js', dependencies: ['jquery']);
@endphp

{!! Theme::partial('header') !!}

<main class="jt-main">
    {!! Theme::content() !!}
</main>

{!! Theme::partial('footer') !!}

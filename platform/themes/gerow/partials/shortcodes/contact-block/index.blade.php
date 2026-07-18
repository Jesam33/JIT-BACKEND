@php
    $style = $shortcode->style ?: 'style-1';
@endphp

@if ($style === 'style-2')
    @php
        $bgImage = $shortcode->background_image ? RvMedia::getImageUrl($shortcode->background_image) : '';
    @endphp
    <section class="cta-area" @if($bgImage) style="background: url('{{ $bgImage }}') center/cover no-repeat !important;" @endif>
        <div class="container">
            <div class="cta-content">
                @if ($subtitle = $shortcode->subtitle)
                    <span class="label" style="font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:var(--brand-primary,#0055FF);margin-bottom:16px;display:block">{{ $subtitle }}</span>
                @endif
                @if ($title = $shortcode->title)
                    <h2>{!! BaseHelper::clean($title) !!}</h2>
                @endif
                <div class="cta-actions">
                    @if (($buttonLabel = $shortcode->button_label) && ($buttonUrl = $shortcode->button_url))
                        <a href="{{ $buttonUrl }}" class="cta-btn">
                            {!! BaseHelper::clean($buttonLabel) !!}
                            <svg class="arrow-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14M12 5l7 7-7 7"/>
                            </svg>
                        </a>
                    @endif
                    @if ($hotline = $shortcode->hotline)
                        <span class="cta-hotline">{{ __('Or call') }}: <strong>{{ $hotline }}</strong></span>
                    @endif
                </div>
            </div>
        </div>
    </section>
@elseif (in_array($style, ['style-1','style-3']))
    <section style="overflow: unset !important;" @class([
            'cta-area' => $style === 'style-1',
        ])
    >
        <div class="container">
            <div
                @class([
                    'cta-inner-wrap' => $style === 'style-1',
                    'cta-inner-wrap-three' => $style === 'style-3',
                ])
                @if ($bgImage = $shortcode->background_image)
                    data-background="{{ RvMedia::getImageUrl($bgImage) }}"
                @endif
            >
                <div class="row align-items-center">
                    <div class="col-lg-9">
                        <div class="cta-content">
                            <div class="cta-info-wrap">
                                <div class="icon">
                                    <i class="flaticon-phone-call"></i>
                                </div>
                                <div class="content">
                                    @if ($subtitle = $shortcode->subtitle)
                                        <span>{!! BaseHelper::clean($subtitle) !!}</span>
                                    @endif

                                    @if ($hotline = $shortcode->hotline)
                                        <a href="tel:{{ $hotline }}" dir="ltr">{!! BaseHelper::clean($hotline) !!}</a>
                                    @endif
                                </div>
                            </div>

                            @if ($title = $shortcode->title)
                                <h2 class="title">{!! BaseHelper::clean($title) !!}</h2>
                            @endif
                        </div>
                    </div>
                    <div class="col-lg-3">
                        @if (($buttonLabel = $shortcode->button_label) && ($buttonUrl = $shortcode->button_url))
                            <div class="cta-btn text-end">
                                <a
                                    href="{{ $buttonUrl }}"
                                    @class([
                                        'btn',
                                        'btn-three' => $style === 'style-3',
                                    ])
                                >
                                    {!! BaseHelper::clean($buttonLabel) !!}
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@else
    {!! Theme::partial('shortcodes.contact-block.styles.' . $style, compact('shortcode')) !!}
@endif

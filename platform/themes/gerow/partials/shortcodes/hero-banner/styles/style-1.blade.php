<section class="section onprem-hero">
    <div class="w-layout-blockcontainer container w-container">
        <div class="onprem-container">
            <div class="onprem-hero-wrap">
                <div class="on-prem-hero-content">
                    @if ($subtitle = $shortcode->subtitle)
                        <div class="tag-product">
                            <div class="text-15-medium white">{{ $subtitle }}</div>
                        </div>
                    @endif

                    @if ($title = $shortcode->title)
                        <h1 class="h1 white center">{!! BaseHelper::clean($title) !!}</h1>
                    @endif

                    @if ($description = $shortcode->description)
                        <div class="text-17-regular opacity-60 center">{!! BaseHelper::clean(nl2br($description)) !!}</div>
                    @endif

                    @if ($buttonLabel = $shortcode->button_label)
                        <a href="{{ $buttonUrl = $shortcode->button_url ?: '#contact' }}" class="btn-yellow w-inline-block">
                            <div class="text-16-regular">{{ $buttonLabel }}</div>
                            <div class="arrow-container">
                                <svg width="17" height="18" viewBox="0 0 17 18" fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd" d="M1.42807 0.767578H16.9999V17.2324H14.9999V4.17343L2.13388 16.9764L0.723145 15.5587L13.5773 2.76758H1.42807V0.767578Z" fill="currentColor"/>
                                </svg>
                            </div>
                        </a>
                    @endif
                </div>

                @if ($image = $shortcode->image)
                    <div class="on-prem-usecases-wrap">
                        <div class="on-prem-usecase-item">
                            <div class="hero-main-image">
                                {{ RvMedia::image($image, $shortcode->title) }}
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($bgImage = $shortcode->background_image)
        {{ RvMedia::image($bgImage, $shortcode->title, attributes: ['class' => 'on-prem-bg web']) }}
    @endif
</section>

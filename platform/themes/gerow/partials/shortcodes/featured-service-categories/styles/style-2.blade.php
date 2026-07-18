<section class="features-area-two">
    <div class="container">
        <div class="section-title-wrap text-center">
            @if ($subtitle = $shortcode->subtitle)
                <span class="sub-title">{{ $subtitle }}</span>
            @endif

            @if ($title = $shortcode->title)
                <h2 class="title">{!! BaseHelper::clean($title) !!}</h2>
            @endif
        </div>
        @if ($categories->isNotEmpty())
            <div class="services-row">
                @foreach ($categories as $category)
                    <div class="service-card">
                        @php
                            $icon = $category->getMetaData('icon', true);
                        @endphp
                        @if ($iconImage = $category->image)
                            <div class="icon-wrap">
                                {{ RvMedia::image($iconImage, $category->name) }}
                            </div>
                        @elseif ($icon)
                            <div class="icon-wrap">
                                <i class="{{ $icon }}"></i>
                            </div>
                        @endif
                        <h4>{{ $category->name }}</h4>
                        @if ($description = $category->description)
                            <p>{!! BaseHelper::clean(nl2br($description)) !!}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</section>